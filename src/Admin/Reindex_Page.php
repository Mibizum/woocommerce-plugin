<?php
/**
 * Reindex panel (the "Reindex" section of the Mibizum settings tab).
 *
 * Ports the Magento Reindex screen. Shows the last full reindex date + status,
 * live counters (changes pending), and a "Resync all products" action. Plus a
 * "Connected catalogs" overview when there is more than one scope.
 *
 * The action goes through the REST controller + Action Scheduler, so the admin
 * request is never blocked by a long drain.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Admin;

use Mibizum\Search\Indexer\Queue;
use Mibizum\Search\Scope\Scope_Resolver;
use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Reindex_Page {

	/** @var Settings */
	private $settings;

	/** @var Queue */
	private $queue;

	/** @var Scope_Resolver */
	private $scopes;

	public function __construct( Settings $settings, Queue $queue, Scope_Resolver $scopes ) {
		$this->settings = $settings;
		$this->queue    = $queue;
		$this->scopes   = $scopes;
	}

	/**
	 * @return void
	 */
	public function register() {
		// Rendering is invoked by Settings_Tab for the 'reindex' section.
	}

	/**
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$stats     = $this->queue->stats();
		$last_at   = get_option( 'mibizum_search_last_full_at', '' );
		$last_stat = get_option( 'mibizum_search_last_full_status', '' );
		$connected = $this->settings->is_enabled_anywhere();

		$config = wp_json_encode(
			array(
				'restBase' => esc_url_raw( rest_url( Rest_Controller::NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
		?>
		<div id="mbz-reindex" data-config="<?php echo esc_attr( $config ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Connection', 'mibizum-search' ); ?></th>
					<td>
						<?php echo $connected
							? '<span style="color:#1d6b3a;font-weight:600;">' . esc_html__( 'Connected', 'mibizum-search' ) . '</span>'
							: '<span style="color:#a4342b;font-weight:600;">' . esc_html__( 'Not connected', 'mibizum-search' ) . '</span>'; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last full reindex', 'mibizum-search' ); ?></th>
					<td>
						<?php
						echo $last_at ? esc_html( $last_at ) : esc_html__( 'Never', 'mibizum-search' );
						echo $last_stat ? ' (' . esc_html( $last_stat ) . ')' : '';
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Changes pending indexing', 'mibizum-search' ); ?></th>
					<td><strong id="mbz-rx-pending"><?php echo (int) $stats['pending']; ?></strong></td>
				</tr>
			</table>

			<?php $this->render_connected_catalogs(); ?>

			<p>
				<button type="button" class="button button-primary" id="mbz-rx-resync" <?php disabled( ! $connected ); ?>>
					<?php esc_html_e( 'Resync all products', 'mibizum-search' ); ?>
				</button>
				<button type="button" class="button" id="mbz-rx-refresh">
					<?php esc_html_e( 'Refresh', 'mibizum-search' ); ?>
				</button>
				<span id="mbz-rx-msg" style="margin-left:10px;"></span>
			</p>
		</div>

		<script>
		( function () {
			var root = document.getElementById( 'mbz-reindex' );
			if ( ! root ) { return; }
			var CFG = JSON.parse( root.getAttribute( 'data-config' ) );
			function api( path, method ) {
				return fetch( CFG.restBase + path, {
					method: method,
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce }
				} ).then( function ( r ) { return r.json().catch( function () { return {}; } ); } );
			}
			function refresh() {
				api( '/reindex/stats', 'GET' ).then( function ( d ) {
					if ( d && d.queue ) {
						document.getElementById( 'mbz-rx-pending' ).textContent = Number( d.queue.pending || 0 ).toLocaleString();
					}
				} );
			}
			var resync = document.getElementById( 'mbz-rx-resync' );
			if ( resync ) {
				resync.addEventListener( 'click', function () {
					document.getElementById( 'mbz-rx-msg' ).textContent = '<?php echo esc_js( __( 'Scheduling…', 'mibizum-search' ) ); ?>';
					api( '/reindex/full', 'POST' ).then( function ( res ) {
						document.getElementById( 'mbz-rx-msg' ).textContent = ( res && res.success )
							? '<?php echo esc_js( __( 'Reindex scheduled.', 'mibizum-search' ) ); ?>'
							: ( ( res && res.message ) || 'Error' );
						refresh();
					} );
				} );
			}
			document.getElementById( 'mbz-rx-refresh' ).addEventListener( 'click', refresh );
			setInterval( refresh, 5000 );
		} )();
		</script>
		<?php
	}

	/**
	 * Connected catalogs overview, shown only with more than one scope.
	 *
	 * @return void
	 */
	private function render_connected_catalogs() {
		$scopes = $this->scopes->all_scopes();
		if ( count( $scopes ) <= 1 ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Connected catalogs', 'mibizum-search' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:680px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Scope', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Data source', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mibizum-search' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $scopes as $scope ) {
			$enabled = $this->settings->is_enabled( $scope['key'] );
			$slug    = (string) $this->settings->get_data_source_slug( $scope['key'] );
			echo '<tr>';
			echo '<td>' . esc_html( $scope['label'] ) . '</td>';
			echo '<td>' . esc_html( $slug ? $slug : '-' ) . '</td>';
			echo '<td>' . ( $enabled
				? esc_html__( 'Connected', 'mibizum-search' )
				: esc_html__( 'Not configured', 'mibizum-search' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
