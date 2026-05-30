<?php
/**
 * Indexing operation queue.
 *
 * Ports Mibizum_Sync_Model_Indexer_Queue. A custom table backs the queue so we
 * keep the Magento semantics that Action Scheduler alone does not give us:
 *   - UNIQUE (product_id, operation): saving a product many times leaves one
 *     pending upsert (implicit debounce).
 *   - upsert and delete are disjoint: a delete overrides a pending upsert.
 *   - deletes carry the SKU captured pre delete (documents are keyed by SKU and
 *     the product is gone by drain time).
 *   - optimistic locking: the worker claims rows with a lock token + timeout.
 *   - admin counters: pending / oldest, for the reindex panel and wizard.
 *
 * Action Scheduler is the runner that drains this queue (see Scheduler). The
 * lifecycle observer does a cheap insert here, never a blocking HTTP call.
 *
 * SQL is kept portable across MySQL/MariaDB and the SQLite integration used in
 * dev (SELECT then INSERT/UPDATE for single rows; INSERT IGNORE for bulk).
 *
 * Table: {$wpdb->prefix}mibizum_search_index_queue
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Indexer;

defined( 'ABSPATH' ) || exit;

class Queue {

	const OP_UPSERT = 'upsert';
	const OP_DELETE = 'delete';

	/** Visibility timeout: a locked row becomes claimable again after this. */
	const LOCK_TIMEOUT = 300;

	/** @return string Fully prefixed table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mibizum_search_index_queue';
	}

	/**
	 * Enqueue an upsert and drop any pending delete for the product.
	 *
	 * @param int    $product_id
	 * @param string $reason
	 * @return void
	 */
	public function enqueue_upsert( $product_id, $reason = 'product_save' ) {
		$this->enqueue( (int) $product_id, self::OP_UPSERT, $reason, null );
		$this->remove_opposite( (int) $product_id, self::OP_DELETE );
	}

	/**
	 * Enqueue a delete (capturing the SKU) and drop any pending upsert.
	 *
	 * @param int    $product_id
	 * @param string $sku
	 * @param string $reason
	 * @return void
	 */
	public function enqueue_delete( $product_id, $sku, $reason = 'product_delete' ) {
		$this->enqueue( (int) $product_id, self::OP_DELETE, $reason, (string) $sku );
		$this->remove_opposite( (int) $product_id, self::OP_UPSERT );
	}

	/**
	 * Bulk enqueue upserts (full reindex). Chunked INSERT IGNORE so existing
	 * pending rows are kept (the unique key dedupes).
	 *
	 * @param int[]  $product_ids
	 * @param string $reason
	 * @return int Rows attempted.
	 */
	public function enqueue_bulk_upsert( array $product_ids, $reason = 'full_reindex' ) {
		global $wpdb;
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		if ( empty( $product_ids ) ) {
			return 0;
		}
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$count = 0;

		foreach ( array_chunk( $product_ids, 200 ) as $chunk ) {
			$values       = array();
			$placeholders = array();
			foreach ( $chunk as $pid ) {
				$placeholders[] = '(%s, %d, %s, %s, 0)';
				$values[]       = self::OP_UPSERT;
				$values[]       = $pid;
				$values[]       = $reason;
				$values[]       = $now;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "INSERT IGNORE INTO {$table} (operation, product_id, reason, enqueued_at, attempts) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
			$count += count( $chunk );
		}
		return $count;
	}

	/**
	 * Claim up to $batch_size unlocked rows, reserving them with a worker token.
	 *
	 * @param int    $batch_size
	 * @param string $worker_token
	 * @return array[] queue_id, operation, product_id, sku, reason, attempts.
	 */
	public function claim_batch( $batch_size, $worker_token ) {
		global $wpdb;
		$table = self::table();

		// 1. Recycle expired locks.
		$expired = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at = NULL, locked_by = NULL WHERE locked_at IS NOT NULL AND locked_at < %s", $expired ) );

		// 2. Select unlocked candidates (oldest first).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT queue_id FROM {$table} WHERE locked_at IS NULL ORDER BY enqueued_at ASC LIMIT %d", (int) $batch_size ) );
		if ( empty( $ids ) ) {
			return array();
		}

		// 3. Reserve.
		$now          = gmdate( 'Y-m-d H:i:s' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $now, $worker_token ), array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at = %s, locked_by = %s, attempts = attempts + 1 WHERE locked_at IS NULL AND queue_id IN ({$placeholders})", $args ) );

		// 4. Return the rows we hold.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		return $wpdb->get_results( $wpdb->prepare( "SELECT queue_id, operation, product_id, sku, reason, attempts FROM {$table} WHERE locked_by = %s", $worker_token ), ARRAY_A );
	}

	/**
	 * Delete completed rows.
	 *
	 * @param int[] $queue_ids
	 * @return int
	 */
	public function complete( array $queue_ids ) {
		global $wpdb;
		if ( empty( $queue_ids ) ) {
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $queue_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE queue_id IN ({$placeholders})", array_map( 'intval', $queue_ids ) ) );
	}

	/**
	 * Release rows for retry, storing the error. Discards rows past max_attempts.
	 *
	 * @param int[]  $queue_ids
	 * @param string $error
	 * @param int    $max_attempts
	 * @return int Discarded (dead lettered) count.
	 */
	public function fail( array $queue_ids, $error, $max_attempts = 10 ) {
		global $wpdb;
		if ( empty( $queue_ids ) ) {
			return 0;
		}
		$table        = self::table();
		$ids          = array_map( 'intval', $queue_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// Discard rows that already exceeded max_attempts.
		$discard_args = array_merge( array( (int) $max_attempts ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$discarded = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE attempts >= %d AND queue_id IN ({$placeholders})", $discard_args ) );

		// Release the rest.
		$release_args = array_merge( array( substr( (string) $error, 0, 5000 ), (int) $max_attempts ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at = NULL, locked_by = NULL, last_error = %s WHERE attempts < %d AND queue_id IN ({$placeholders})", $release_args ) );

		return $discarded;
	}

	/**
	 * Queue stats for the admin panel / wizard.
	 *
	 * @return array{total:int,pending:int,locked:int,oldest_pending_seconds:?int}
	 */
	public function stats() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN locked_at IS NULL THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN locked_at IS NOT NULL THEN 1 ELSE 0 END) AS locked,
				MIN(CASE WHEN locked_at IS NULL THEN enqueued_at END) AS oldest
			FROM {$table}",
			ARRAY_A
		);

		$oldest_seconds = null;
		if ( ! empty( $row['oldest'] ) ) {
			$ts = strtotime( $row['oldest'] . ' UTC' );
			if ( $ts ) {
				$oldest_seconds = max( 0, time() - $ts );
			}
		}
		return array(
			'total'                  => isset( $row['total'] ) ? (int) $row['total'] : 0,
			'pending'                => isset( $row['pending'] ) ? (int) $row['pending'] : 0,
			'locked'                 => isset( $row['locked'] ) ? (int) $row['locked'] : 0,
			'oldest_pending_seconds' => $oldest_seconds,
		);
	}

	// --- Internals -------------------------------------------------------

	/**
	 * Portable upsert for a single row (SELECT then INSERT or UPDATE).
	 *
	 * @param int         $product_id
	 * @param string      $operation
	 * @param string      $reason
	 * @param string|null $sku
	 * @return void
	 */
	private function enqueue( $product_id, $operation, $reason, $sku ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT queue_id FROM {$table} WHERE product_id = %d AND operation = %s LIMIT 1", $product_id, $operation ) );

		$data = array(
			'operation'   => $operation,
			'product_id'  => $product_id,
			'reason'      => $reason,
			'enqueued_at' => $now,
		);
		if ( null !== $sku ) {
			$data['sku'] = $sku;
		}

		if ( $existing ) {
			$update = array(
				'reason'      => $reason,
				'enqueued_at' => $now,
			);
			if ( null !== $sku ) {
				$update['sku'] = $sku;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $update, array( 'queue_id' => (int) $existing ) );
		} else {
			$data['attempts'] = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
		}
	}

	/**
	 * Remove a pending (unlocked) row of the opposite operation.
	 *
	 * @param int    $product_id
	 * @param string $operation
	 * @return void
	 */
	private function remove_opposite( $product_id, $operation ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom queue table; trusted constant table name; all values placeholder-bound.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE product_id = %d AND operation = %s AND locked_at IS NULL", $product_id, $operation ) );
	}
}
