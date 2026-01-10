<?php
/**
 * Plugin Name: Media Double Check
 * Description: Double-checks files flagged as "not found" by Media Cleaner using deep search queries.
 * Version: 1.0.0
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Double_Check {

	private $table_scan;

	public function __construct() {
		global $wpdb;
		$this->table_scan = $wpdb->prefix . "mclean_scan";
		$this->table_mdc  = $wpdb->prefix . "mdc_results";

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// AJAX Actions
		add_action( 'wp_ajax_mdc_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_mdc_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_mdc_stop_scan', array( $this, 'ajax_stop_scan' ) );
		
		// Background Process Hook
		add_action( 'mdc_cron_batch', array( $this, 'process_batch' ) );
	}

	public function activate() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_mdc} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) UNSIGNED NOT NULL,
			filename varchar(255) NOT NULL,
			matches_count int(11) DEFAULT 0,
			matches_data longtext DEFAULT NULL,
			checked_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function register_menu() {
		add_menu_page(
			'Media Double Check',
			'Media Double Check',
			'manage_options',
			'media-double-check',
			array( $this, 'render_admin_page' ),
			'dashicons-search',
			30
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_media-double-check' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mdc-admin-css', plugins_url( 'assets/admin.css', __FILE__ ) );
		wp_enqueue_script( 'mdc-admin-js', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), '1.0.0', true );
		wp_localize_script( 'mdc-admin-js', 'mdc_params', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'mdc_nonce' ),
		) );
	}

	public function render_admin_page() {
		global $wpdb;
		$status = get_option( 'mdc_scan_status', 'idle' );
		$progress = get_option( 'mdc_scan_progress', array( 'offset' => 0, 'total' => 0 ) );
		
		// Pagination & Filter Logic
		$per_page = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$filter = isset( $_GET['mdc_filter'] ) ? sanitize_text_field( $_GET['mdc_filter'] ) : 'all';
		
		$where = "";
		if ( $filter === 'not_found' ) {
			$where = " WHERE matches_count = 0";
		} elseif ( $filter === 'used' ) {
			$where = " WHERE matches_count > 0";
		}

		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_mdc} $where" );
		$total_pages = ceil( $total_items / $per_page );
		$offset = ( $current_page - 1 ) * $per_page;
		
		$results = $wpdb->get_results( $wpdb->prepare( 
			"SELECT * FROM {$this->table_mdc} $where ORDER BY checked_at DESC LIMIT %d, %d",
			$offset, $per_page
		) );
		?>
		<div class="wrap mdc-wrap">
			<h1>Media Double Check</h1>
			<p>Double-checking files flagged by <strong>Media Cleaner</strong> using deep search.</p>
			
			<div class="mdc-actions">
				<?php if ( $status === 'running' ) : ?>
					<button id="mdc-stop-scan" class="button button-secondary">Stop Scan</button>
				<?php else : ?>
					<button id="mdc-start-scan" class="button button-primary">Start New Deep Scan</button>
				<?php endif; ?>
				
				<span id="mdc-status"><?php echo $status === 'running' ? 'Scanning in progress...' : 'Ready.'; ?></span>
				
				<div id="mdc-progress-bar-container" style="<?php echo $status === 'running' ? '' : 'display:none;'; ?> width: 250px; background: #eee; height: 12px; border-radius: 6px; overflow: hidden; margin-left: 20px;">
					<div id="mdc-progress-bar" style="width: <?php echo $progress['total'] > 0 ? ( $progress['offset'] / $progress['total'] * 100 ) : 0; ?>%; background: #4f46e5; height: 100%; transition: width 0.3s;"></div>
				</div>
				<span id="mdc-progress-text" style="<?php echo $status === 'running' ? '' : 'display:none;'; ?> margin-left: 10px; font-weight: 600;">
					<?php echo $progress['offset']; ?> / <?php echo $progress['total']; ?>
				</span>
			</div>

			<div id="mdc-results-container">
				<div class="mdc-results-header">
					<div class="mdc-results-title">
						<h2>Deep Scan Results</h2>
						<form method="get" class="mdc-filter-form">
							<input type="hidden" name="page" value="media-double-check">
							<select name="mdc_filter" onchange="this.form.submit()">
								<option value="all" <?php selected( $filter, 'all' ); ?>>Show All</option>
								<option value="not_found" <?php selected( $filter, 'not_found' ); ?>>Show Truly Unused (Not Found)</option>
								<option value="used" <?php selected( $filter, 'used' ); ?>>Show Used (Matches Found)</option>
							</select>
						</form>
					</div>
					<?php if ( $total_pages > 1 ) : ?>
						<div class="mdc-pagination">
							<span class="displaying-num"><?php echo $total_items; ?> items</span>
							<?php
							echo paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							) );
							?>
						</div>
					<?php endif; ?>
				</div>

				<table class="wp-list-table widefat fixed striped mdc-custom-table">
					<thead>
						<tr>
							<th style="width: 80px;">ID</th>
							<th>Filename</th>
							<th style="width: 150px;">Status</th>
							<th>Matches Found</th>
						</tr>
					</thead>
					<tbody id="mdc-results-body">
						<?php if ( empty( $results ) ) : ?>
							<tr><td colspan="4">No results yet. Click "Start New Deep Scan" to begin.</td></tr>
						<?php else : ?>
							<?php foreach ( $results as $row ) : ?>
								<?php $this->render_row( $row->attachment_id, $row->filename, json_decode( $row->matches_data, true ) ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div id="mdc-loading-overlay" style="display:none;">
				<div class="mdc-loader-content">
					<span class="spinner is-active"></span>
					<p>Updating results...</p>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_start_scan() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		global $wpdb;

		// Reset state
		update_option( 'mdc_scan_status', 'running' );
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_scan} WHERE issue = 'NO_CONTENT' AND type = 1" );
		update_option( 'mdc_scan_progress', array( 'offset' => 0, 'total' => (int)$total ) );

		// Clear old results if starting fresh
		$wpdb->query( "TRUNCATE TABLE {$this->table_mdc}" );

		// Schedule background process
		if ( ! wp_next_scheduled( 'mdc_cron_batch' ) ) {
			wp_schedule_single_event( time(), 'mdc_cron_batch' );
		}

		wp_send_json_success();
	}

	public function ajax_stop_scan() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		update_option( 'mdc_scan_status', 'idle' );
		wp_clear_scheduled_hook( 'mdc_cron_batch' );
		wp_send_json_success();
	}

	public function ajax_check_status() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		$status = get_option( 'mdc_scan_status', 'idle' );
		$progress = get_option( 'mdc_scan_progress', array( 'offset' => 0, 'total' => 0 ) );
		
		wp_send_json_success( array(
			'status'   => $status,
			'offset'   => $progress['offset'],
			'total'    => $progress['total'],
			'percent'  => $progress['total'] > 0 ? round( ( $progress['offset'] / $progress['total'] ) * 100, 2 ) : 0
		) );
	}

	public function process_batch() {
		$status = get_option( 'mdc_scan_status', 'idle' );
		if ( $status !== 'running' ) return;

		global $wpdb;
		$progress = get_option( 'mdc_scan_progress' );
		$offset = $progress['offset'];
		$batch_size = 10;

		$items = $wpdb->get_results( $wpdb->prepare( 
			"SELECT * FROM {$this->table_scan} WHERE issue = 'NO_CONTENT' AND type = 1 LIMIT %d, %d",
			$offset, $batch_size
		) );

		if ( empty( $items ) ) {
			update_option( 'mdc_scan_status', 'idle' );
			return;
		}

		foreach ( $items as $item ) {
			$attachment_id = $item->postId;
			$attachment_path = get_attached_file( $attachment_id );
			if ( ! $attachment_path ) {
                $attachment_path = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file'", $attachment_id ) );
			}
            if ( ! $attachment_path ) continue;

			$filename = pathinfo( $attachment_path, PATHINFO_FILENAME );
			$matches = $this->perform_deep_check( $attachment_id, $filename );

			$wpdb->replace( $this->table_mdc, array(
				'attachment_id' => $attachment_id,
				'filename'      => $filename,
				'matches_count' => count( $matches ),
				'matches_data'  => json_encode( $matches ),
				'checked_at'    => current_time( 'mysql' )
			) );
		}

		// Update progress
		$new_offset = $offset + count( $items );
		update_option( 'mdc_scan_progress', array( 'offset' => $new_offset, 'total' => $progress['total'] ) );

		if ( $new_offset < $progress['total'] ) {
			// Schedule next batch immediately
			wp_schedule_single_event( time(), 'mdc_cron_batch' );
		} else {
			update_option( 'mdc_scan_status', 'idle' );
		}
	}

	private function render_row( $id, $filename, $matches ) {
		$count = count( $matches );
		$is_used = $count > 0;
		$status = $is_used ? '<span class="mdc-status-danger">USED ('.$count.')</span>' : '<span class="mdc-status-safe">NOT FOUND</span>';
		?>
		<tr>
			<td><?php echo $id; ?></td>
			<td><strong><?php echo esc_html( $filename ); ?></strong></td>
			<td><?php echo $status; ?></td>
			<td>
				<?php if ( $is_used ) : ?>
					<button class="mdc-toggle-matches button button-small" data-id="<?php echo $id; ?>">Toggle Matches</button>
					<div id="mdc-matches-<?php echo $id; ?>" class="mdc-matches-list" style="display:none;">
						<?php foreach ( $matches as $m ) : ?>
							<div class="mdc-match-item">
								<span class="mdc-match-tag mdc-tag-<?php echo $m['source']; ?>"><?php echo $m['source']; ?></span>
								<strong><?php echo esc_html( $m['match_type'] ); ?>:</strong> <?php echo esc_html( $m['label'] ); ?> (ID: <?php echo $m['item_id']; ?>)
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<em>No matches.</em>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function perform_deep_check( $id, $filename ) {
		global $wpdb;

		// Escape for LIKE
		$search_filename = '%' . $wpdb->esc_like( $filename ) . '%';
		$search_id_serialized = '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%';

		$queries = array();

		// 1. CHECK POSTS
		$queries[] = $wpdb->prepare(
			"SELECT 'posts' AS source, ID AS item_id, post_title AS label, post_type AS subtype, 'Post Content' AS match_type
			 FROM {$wpdb->posts}
			 WHERE (post_content LIKE %s OR post_excerpt LIKE %s)
			 AND ID != %d",
			$search_filename, $search_filename, $id
		);

		// 2. CHECK POSTMETA
		$queries[] = $wpdb->prepare(
			"SELECT 'postmeta' AS source, post_id AS item_id, meta_key AS label, '-' AS subtype, 'Metadata/Gallery/BeBuilder' AS match_type
			 FROM {$wpdb->postmeta}
			 WHERE (
			    meta_value LIKE %s
			    OR meta_value = %s
			    OR meta_value LIKE %s
			    OR (meta_key = '_product_image_gallery' AND meta_value REGEXP %s)
			 )
			 AND post_id != %d",
			$search_filename, (string)$id, $search_id_serialized, '(^|, )' . $id . '(, |$)', $id
		);

		// 3. CHECK OPTIONS
		$queries[] = $wpdb->prepare(
			"SELECT 'options' AS source, option_id AS item_id, option_name AS label, '-' AS subtype, 'Global Theme Settings' AS match_type
			 FROM {$wpdb->options}
			 WHERE option_value LIKE %s 
			 OR option_value LIKE %s",
			$search_filename, $search_id_serialized
		);

		// 4. CHECK TERMMETA
		$queries[] = $wpdb->prepare(
			"SELECT 'termmeta' AS source, term_id AS item_id, meta_key AS label, '-' AS subtype, 'Category/Attribute/Brand' AS match_type
			 FROM {$wpdb->termmeta}
			 WHERE meta_value = %s 
			 OR meta_value LIKE %s",
			(string)$id, $search_filename
		);

		$all_matches = array();
		foreach ( $queries as $q ) {
			$res = $wpdb->get_results( $q, ARRAY_A );
			if ( ! empty( $res ) ) {
				$all_matches = array_merge( $all_matches, $res );
			}
		}

		return $all_matches;
	}
}

new Media_Double_Check();
