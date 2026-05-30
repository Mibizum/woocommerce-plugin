<?php
/**
 * Queue worker.
 *
 * Ports Mibizum_Sync_Model_Indexer_Worker. Processes a queue batch: claims
 * rows, fans each product out to every connected scope (one data source per
 * scope), maps it in that scope's context, and pushes in chunks. Deletes are
 * fanned out to every destination by document id.
 *
 * A queue row completes only when it succeeded in ALL of its target
 * destinations; otherwise it is released to retry (re pushing to an already OK
 * destination is an idempotent upsert). Destinations are deduped by signature so
 * a single scope store pushes exactly once.
 *
 * Safe disable: with no connected scope the worker claims nothing.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Indexer;

use Mibizum\Search\Http\Index_Client;
use Mibizum\Search\Scope\Scope_Resolver;
use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Worker {

	/** Products sent to the engine in a single HTTP call. */
	const PUSH_CHUNK = 50;

	/** @var Queue */
	private $queue;

	/** @var Product_Mapper */
	private $mapper;

	/** @var Index_Client */
	private $client;

	/** @var Scope_Resolver */
	private $scopes;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct(
		Queue $queue,
		Product_Mapper $mapper,
		Index_Client $client,
		Scope_Resolver $scopes,
		Settings $settings,
		Logger $logger
	) {
		$this->queue    = $queue;
		$this->mapper   = $mapper;
		$this->client   = $client;
		$this->scopes   = $scopes;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Process one batch.
	 *
	 * @param int|null $batch_size
	 * @return array{processed:int,succeeded:int,failed:int,deleted:int}
	 */
	public function process_batch( $batch_size = null ) {
		$result = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'deleted'   => 0,
		);

		$destinations = $this->scopes->destinations();
		if ( empty( $destinations ) ) {
			return $result; // safe disable.
		}

		$batch_size = null === $batch_size ? $this->settings->get_batch_size() : (int) $batch_size;
		$token      = $this->worker_token();
		$entries    = $this->queue->claim_batch( $batch_size, $token );
		if ( empty( $entries ) ) {
			return $result;
		}
		$result['processed'] = count( $entries );

		$upserts = array();
		$deletes = array();
		foreach ( $entries as $entry ) {
			if ( Queue::OP_UPSERT === $entry['operation'] ) {
				$upserts[] = $entry;
			} else {
				$deletes[] = $entry;
			}
		}

		if ( ! empty( $deletes ) ) {
			$this->process_deletes( $destinations, $deletes, $result );
		}
		if ( ! empty( $upserts ) ) {
			$this->process_upserts( $destinations, $upserts, $result );
		}

		$this->logger->info(
			sprintf(
				'worker batch processed=%d succeeded=%d failed=%d deleted=%d destinations=%d',
				$result['processed'],
				$result['succeeded'],
				$result['failed'],
				$result['deleted'],
				count( $destinations )
			)
		);
		return $result;
	}

	/**
	 * Drain the whole queue until empty.
	 *
	 * @param int $max_batches Defensive cap.
	 * @return array
	 */
	public function drain( $max_batches = 1000 ) {
		$totals = array(
			'batches'   => 0,
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'deleted'   => 0,
		);
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$r = $this->process_batch();
			++$totals['batches'];
			$totals['processed'] += $r['processed'];
			$totals['succeeded'] += $r['succeeded'];
			$totals['failed']    += $r['failed'];
			$totals['deleted']   += $r['deleted'];
			if ( 0 === $r['processed'] ) {
				break;
			}
		}
		return $totals;
	}

	/**
	 * Deletes fan out to every destination, by document id captured at enqueue.
	 *
	 * @param array $destinations
	 * @param array $delete_entries
	 * @param array $result
	 * @return void
	 */
	private function process_deletes( $destinations, $delete_entries, array &$result ) {
		$ids       = array();
		$queue_ids = array();
		foreach ( $delete_entries as $entry ) {
			$queue_ids[] = (int) $entry['queue_id'];
			$ids[]       = Product_Mapper::document_id( isset( $entry['sku'] ) ? $entry['sku'] : '', (int) $entry['product_id'] );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( empty( $ids ) ) {
			$this->queue->complete( $queue_ids );
			$result['deleted']   += count( $queue_ids );
			$result['succeeded'] += count( $queue_ids );
			return;
		}

		$all_ok = true;
		foreach ( $destinations as $dest ) {
			$scope = $dest['scope'];
			$this->scopes->with_scope(
				$scope,
				function () use ( $ids, $scope, &$all_ok ) {
					try {
						$this->client->delete_documents_by_ids( $ids, $scope['key'] );
					} catch ( \Exception $e ) {
						$all_ok = false;
						$this->logger->error( 'worker delete failed in a destination: ' . $e->getMessage() );
					}
				}
			);
		}

		if ( $all_ok ) {
			$this->queue->complete( $queue_ids );
			$result['deleted']   += count( $queue_ids );
			$result['succeeded'] += count( $queue_ids );
		} else {
			$this->queue->fail( $queue_ids, 'delete failed in at least one destination', $this->settings->get_max_attempts() );
			$result['failed'] += count( $queue_ids );
		}
	}

	/**
	 * Upserts: per destination scope, map products in scope and push in chunks.
	 *
	 * @param array $destinations
	 * @param array $upsert_entries
	 * @param array $result
	 * @return void
	 */
	private function process_upserts( $destinations, $upsert_entries, array &$result ) {
		$target = array();
		$ok     = array();
		$failed = array();
		$skip   = array();

		// Target count: destinations where a translation of the product exists.
		foreach ( $upsert_entries as $entry ) {
			$qid = (int) $entry['queue_id'];
			$pid = (int) $entry['product_id'];
			$tc  = 0;
			foreach ( $destinations as $dest ) {
				if ( null !== $this->scopes->translated_product_id( $pid, $dest['scope'] ) ) {
					++$tc;
				}
			}
			$target[ $qid ] = $tc;
			$ok[ $qid ]     = 0;
			$failed[ $qid ] = false;
			if ( 0 === $tc ) {
				$skip[] = $qid;
			}
		}

		foreach ( $destinations as $dest ) {
			$scope = $dest['scope'];
			$this->scopes->with_scope(
				$scope,
				function () use ( $scope, $upsert_entries, $target, &$ok, &$failed ) {
					$docs       = array();
					$doc_queue  = array();
					$delete_ids = array();

					foreach ( $upsert_entries as $entry ) {
						$qid = (int) $entry['queue_id'];
						$pid = (int) $entry['product_id'];
						if ( 0 === $target[ $qid ] ) {
							continue;
						}
						$tpid = $this->scopes->translated_product_id( $pid, $scope );
						if ( null === $tpid ) {
							continue;
						}
						$product = wc_get_product( $tpid );
						if ( ! $product ) {
							++$ok[ $qid ]; // gone since enqueue; counts as reached.
							continue;
						}
						$doc = $this->mapper->map( $product );
						if ( null === $doc ) {
							$delete_ids[] = Product_Mapper::document_id( $product->get_sku(), $tpid );
							++$ok[ $qid ];
							continue;
						}
						$docs[]                  = $doc;
						$doc_queue[ $doc['id'] ] = $qid;
					}

					if ( ! empty( $delete_ids ) ) {
						try {
							$this->client->delete_documents_by_ids( $delete_ids, $scope['key'] );
						} catch ( \Exception $e ) {
							$this->logger->warning( 'worker per destination delete failed: ' . $e->getMessage() );
						}
					}

					foreach ( array_chunk( $docs, self::PUSH_CHUNK ) as $chunk ) {
						try {
							$this->client->index_documents( $chunk, $scope['key'] );
							foreach ( $chunk as $doc ) {
								++$ok[ $doc_queue[ $doc['id'] ] ];
							}
						} catch ( \Exception $e ) {
							foreach ( $chunk as $doc ) {
								$failed[ $doc_queue[ $doc['id'] ] ] = true;
							}
							$this->logger->error( 'worker upsert chunk failed in a destination: ' . $e->getMessage() );
						}
					}
				}
			);
		}

		$complete_ids = $skip;
		$fail_ids     = array();
		foreach ( $upsert_entries as $entry ) {
			$qid = (int) $entry['queue_id'];
			if ( 0 === $target[ $qid ] ) {
				continue;
			}
			if ( ! $failed[ $qid ] && $ok[ $qid ] >= $target[ $qid ] ) {
				$complete_ids[] = $qid;
			} else {
				$fail_ids[] = $qid;
			}
		}

		if ( ! empty( $complete_ids ) ) {
			$this->queue->complete( $complete_ids );
			$result['succeeded'] += count( $complete_ids );
		}
		if ( ! empty( $fail_ids ) ) {
			$this->queue->fail( $fail_ids, 'upsert incomplete or failed in at least one destination', $this->settings->get_max_attempts() );
			$result['failed'] += count( $fail_ids );
		}
	}

	/**
	 * @return string
	 */
	private function worker_token() {
		$host = function_exists( 'gethostname' ) ? gethostname() : 'wp';
		return $host . '-' . getmypid() . '-' . substr( md5( microtime( true ) . wp_rand() ), 0, 6 );
	}
}
