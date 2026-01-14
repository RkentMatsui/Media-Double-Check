<?php
/**
 * Plugin Name: Media Double Check
 * Description: Double-checks files flagged as "not found" by Media Cleaner using deep search queries.
 * Version: 1.0.3
 * Author: Rowielokent Matsui <devkenmatsui@gmail.com>
 * Author URI: https://github.com/RkentMatsui
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fetch plugin version from header
$plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );
define( 'MDC_VERSION', $plugin_data['Version'] );

#[AllowDynamicProperties]
class Media_Double_Check {

	public $table_scan;
	public $table_mdc;

	public function __construct() {
		global $wpdb;
		$this->table_scan = $wpdb->prefix . "mclean_scan";
		$this->table_mdc  = $wpdb->prefix . "mdc_results";

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// AJAX Actions
		add_action( 'wp_ajax_mdc_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_mdc_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_mdc_stop_scan', array( $this, 'ajax_stop_scan' ) );
		add_action( 'wp_ajax_mdc_trash_attachment', array( $this, 'ajax_trash_attachment' ) );
		add_action( 'wp_ajax_mdc_restore_attachment', array( $this, 'ajax_restore_attachment' ) );
		add_action( 'wp_ajax_mdc_exclude_attachment', array( $this, 'ajax_exclude_attachment' ) );
		add_action( 'wp_ajax_mdc_include_attachment', array( $this, 'ajax_include_attachment' ) );
		add_action( 'wp_ajax_mdc_bulk_trash', array( $this, 'ajax_bulk_trash' ) );
		add_action( 'wp_ajax_mdc_bulk_action_selected', array( $this, 'ajax_bulk_action_selected' ) );
		
		// Background Process Hook
		add_action( 'mdc_cron_batch', array( $this, 'process_batch' ) );

		// Manual Sync Hooks
		add_action( 'wp_ajax_mdc_manual_sync_mc', array( $this, 'ajax_manual_sync_mc' ) );
		add_action( 'wp_ajax_mdc_force_resume', array( $this, 'ajax_force_resume' ) );
	}

	public function sync_with_media_cleaner( $attachment_id, $action ) {
		global $wpdb;
		$table_mc = $wpdb->prefix . 'mclean_scan';

		// Check if MC table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_mc ) ) !== $table_mc ) {
			return;
		}

		$data = array();
		if ( $action === 'trash' ) {
			$data = array( 'deleted' => 1, 'ignored' => 0 );
		} elseif ( $action === 'exclude' ) {
			$data = array( 'ignored' => 1 );
		} elseif ( $action === 'include' ) {
			$data = array( 'ignored' => 0, 'deleted' => 0 );
		}

		if ( ! empty( $data ) ) {
			$wpdb->update( $table_mc, $data, array( 'postId' => $attachment_id ) );
		}
	}

	public function log( $message, $type = 'info' ) {
		$logs = get_option( 'mdc_logs', array() );
		$new_log = array(
			'time' => current_time( 'mysql' ),
			'type' => $type,
			'msg'  => $message
		);
		array_unshift( $logs, $new_log );
		$logs = array_slice( $logs, 0, 50 ); // Keep last 50
		update_option( 'mdc_logs', $logs );
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
			is_excluded tinyint(1) DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function register_menu() {
		global $wpdb;
		// Migration: Add is_excluded if missing
		$column = $wpdb->get_results( $wpdb->prepare( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'is_excluded'", $this->table_mdc ) );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE {$this->table_mdc} ADD is_excluded tinyint(1) DEFAULT 0" );
		}

		add_menu_page(
			'Media Double Check',
			'Media Double Check',
			'manage_options',
			'media-double-check',
			array( $this, 'render_admin_page' ),
			'dashicons-search',
			30
		);

		add_submenu_page(
			'media-double-check',
			'Settings',
			'Settings',
			'manage_options',
			'mdc-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'mdc_settings_group', 'mdc_settings' );
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_media-double-check' !== $hook && 'media-double-check_page_mdc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mdc-admin-css', plugins_url( 'assets/admin.css', __FILE__ ), array(), MDC_VERSION );
		wp_enqueue_script( 'mdc-admin-js', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), MDC_VERSION, true );
		wp_localize_script( 'mdc-admin-js', 'mdc_params', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'mdc_nonce' ),
		) );
	}

	public function render_settings_page() {
		$defaults = array(
			'woocommerce' => 1,
			'elementor'   => 1,
			'divi'        => 1,
			'bebuilder'   => 1,
			'yoast'       => 1,
			'acf'         => 1,
			'global_meta' => 1,
		);
		$settings = get_option( 'mdc_settings', $defaults );
		?>
		<div class="wrap mdc-wrap">
			<h1>Media Double Check Settings</h1>
			<p>Select which plugins and builders to check during the deep scan.</p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'mdc_settings_group' ); ?>
				<div class="mdc-settings-card">
					<table class="form-table">
						<tr>
							<th scope="row">WooCommerce</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[woocommerce]" value="1" <?php checked( 1, @$settings['woocommerce'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Check product galleries, thumbnails, and category icons.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Elementor</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[elementor]" value="1" <?php checked( 1, @$settings['elementor'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Check Elementor JSON data (`_elementor_data`) for images.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Divi Builder</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[divi]" value="1" <?php checked( 1, @$settings['divi'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Check Divi shortcodes in post content.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">BeBuilder / Muffin Builder</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[bebuilder]" value="1" <?php checked( 1, @$settings['bebuilder'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Check BeTheme's builder data in post meta.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Yoast SEO / SEO Press</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[yoast]" value="1" <?php checked( 1, @$settings['yoast'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Check OpenGraph images and Twitter cards.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Advanced Custom Fields (ACF)</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[acf]" value="1" <?php checked( 1, @$settings['acf'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Deep scan all ACF specific meta keys.</p>
							</td>
						</tr>
						<tr class="mdc-divider"><td colspan="2"><hr></td></tr>
						<tr>
							<th scope="row">Global Post Meta Search</th>
							<td>
								<label class="mdc-switch">
									<input type="checkbox" name="mdc_settings[global_meta]" value="1" <?php checked( 1, @$settings['global_meta'] ); ?>>
									<span class="mdc-slider"></span>
								</label>
								<p class="description">Search all remaining post meta values for ID or filename.</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save Settings', 'button-primary mdc-save-btn' ); ?>
				</div>
			</form>

			<div class="mdc-settings-card" style="margin-top: 30px; max-width: 800px;">
				<h2>System Health & Logs</h2>
				<?php
				$status = get_option( 'mdc_scan_status', 'idle' );
				$last_active = get_option( 'mdc_last_batch_time', 0 );
				$logs = get_option( 'mdc_logs', array() );
				?>
				<div class="mdc-health-stats" style="display: flex; gap: 40px; margin-bottom: 20px; padding: 15px; background: #fff7ed; border-radius: 8px;">
					<div>
						<strong>Last Worker Activity:</strong><br>
						<span><?php echo $last_active ? human_time_diff( $last_active ) . ' ago' : 'Never'; ?></span>
					</div>
					<div>
						<strong>Scan Status:</strong><br>
						<span style="color: <?php echo $status === 'running' ? '#f97316' : '#64748b'; ?>; font-weight: bold;">
							<?php echo strtoupper( $status ); ?>
						</span>
					</div>
					<div style="margin-left: auto; display: flex; gap: 10px;">
						<button id="mdc-force-resume" class="button button-warning" <?php echo $status !== 'running' ? 'disabled' : ''; ?>>Force Worker Resume</button>
						<button id="mdc-manual-sync-mc" class="button button-secondary">Sync with Media Cleaner</button>
					</div>
				</div>
				<p class="description" style="margin-bottom: 20px;">
					Click "Sync" to ensure all files marked as Trashed or Excluded in this plugin are also hidden/flagged in Media Cleaner.
				</p>

				<div class="mdc-log-viewer" style="background: #1e293b; color: #cbd5e1; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 11px; max-height: 300px; overflow-y: auto;">
					<?php if ( empty( $logs ) ) : ?>
						<div style="color: #64748b;">No logs recorded yet.</div>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<div style="margin-bottom: 4px; border-bottom: 1px solid #334155; padding-bottom: 4px;">
								<span style="color: #64748b;"><?php echo $log['time']; ?></span> 
								[<span style="color: <?php echo $log['type'] === 'error' ? '#f87171' : ( $log['type'] === 'warning' ? '#fbbf24' : '#60a5fa' ); ?>;"><?php echo strtoupper( $log['type'] ); ?></span>] 
								<?php echo esc_html( $log['msg'] ); ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<p class="description">Showing last 50 activity events.</p>
			</div>
		</div>
		<?php
	}

	public function render_admin_page() {
		global $wpdb;
		$status = get_option( 'mdc_scan_status', 'idle' );
		$progress = get_option( 'mdc_scan_progress', array( 'offset' => 0, 'total' => 0 ) );
		
		// Pagination & Filter Logic
		$per_page = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$filter = isset( $_GET['mdc_filter'] ) ? sanitize_text_field( $_GET['mdc_filter'] ) : 'all';
		
		$where = " WHERE p.post_status != 'trash' AND m.is_excluded = 0";
		if ( $filter === 'not_found' ) {
			$where = " WHERE p.post_status != 'trash' AND m.is_excluded = 0 AND m.matches_count = 0";
		} elseif ( $filter === 'used' ) {
			$where = " WHERE p.post_status != 'trash' AND m.is_excluded = 0 AND m.matches_count > 0";
		} elseif ( $filter === 'trash' ) {
			$where = " WHERE p.post_status = 'trash'";
		} elseif ( $filter === 'excluded' ) {
			$where = " WHERE m.is_excluded = 1";
		}

		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_mdc} m JOIN {$wpdb->posts} p ON m.attachment_id = p.ID $where" );
		$total_pages = ceil( $total_items / $per_page );
		$offset = ( $current_page - 1 ) * $per_page;
		
		$results = $wpdb->get_results( $wpdb->prepare( 
			"SELECT m.*, p.post_status FROM {$this->table_mdc} m 
			 JOIN {$wpdb->posts} p ON m.attachment_id = p.ID 
			 $where ORDER BY m.checked_at DESC LIMIT %d, %d",
			$offset, $per_page
		) );
		?>
		<div class="wrap mdc-wrap">
			<h1>Media Double Check</h1>
			<p>Double-checking files flagged by <strong>Media Cleaner</strong> using deep search.</p>
			
			<div class="mdc-actions">
				<?php if ( $status === 'running' ) : ?>
					<button id="mdc-stop-scan" class="button button-secondary">Stop Scan</button>
					<button id="mdc-resume-scan" class="button button-warning" style="display:none;">Resume Scan</button>
				<?php else : ?>
					<button id="mdc-start-scan" class="button button-primary">Start New Deep Scan</button>
				<?php endif; ?>
				
				<button id="mdc-toggle-selection" class="button button-secondary">Bulk Select Mode</button>
				
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
								<option value="all" <?php selected( $filter, 'all' ); ?>>Show All (Active)</option>
								<option value="not_found" <?php selected( $filter, 'not_found' ); ?>>Show Truly Unused</option>
								<option value="used" <?php selected( $filter, 'used' ); ?>>Show Used</option>
								<option value="trash" <?php selected( $filter, 'trash' ); ?>>Show Internal Trash</option>
								<option value="excluded" <?php selected( $filter, 'excluded' ); ?>>Show Excluded (Safe)</option>
							</select>
						</form>
					</div>
					<div class="mdc-pagination-container">
						<?php if ( $filter === 'not_found' && ! empty( $results ) ) : ?>
							<button id="mdc-bulk-trash" class="button button-secondary mdc-trash-all-btn">Bulk Move to Trash</button>
						<?php endif; ?>
						<a href="<?php echo admin_url( 'upload.php?mode=list&post_status=trash&post_type=attachment' ); ?>" class="mdc-view-trash-link" target="_blank">View Media Trash &raquo;</a>
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
				</div>

				<table class="wp-list-table widefat fixed striped mdc-custom-table">
					<thead>
						<tr>
							<th class="mdc-col-cb" style="display:none; width: 40px;"><input type="checkbox" id="mdc-cb-select-all"></th>
							<th style="width: 80px;">ID</th>
							<th>Filename</th>
							<th style="width: 150px;">Status</th>
							<th>Matches Found</th>
						</tr>
					</thead>
					<tbody id="mdc-results-body">
						<?php if ( empty( $results ) ) : ?>
							<tr><td colspan="5">No results yet. Click "Start New Deep Scan" to begin.</td></tr>
						<?php else : ?>
							<?php foreach ( $results as $row ) : ?>
								<?php $this->render_row( $row->attachment_id, $row->filename, json_decode( $row->matches_data, true ), $row->post_status, $row->is_excluded ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div id="mdc-bulk-actions-bar" style="display:none;">
				<div class="mdc-bulk-info">
					<span class="mdc-selected-count">0 items selected</span>
				</div>
				<div class="mdc-bulk-buttons">
					<button id="mdc-bulk-trash-selected" class="button button-secondary mdc-trash-all-btn">Move Selected to Trash</button>
					<button id="mdc-bulk-exclude-selected" class="button button-secondary">Exclude Selected</button>
					<button id="mdc-bulk-include-selected" class="button button-secondary" style="display:none;">Include Selected</button>
					<button id="mdc-cancel-selection" class="button button-link">Cancel</button>
				</div>
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
		$last_active = get_option( 'mdc_last_batch_time', 0 );
		$is_stalled = false;

		// If running but no activity for 60 seconds, it might be stalled
		if ( $status === 'running' && $last_active && ( time() - $last_active ) > 60 ) {
			$is_stalled = true;
		}
		
		wp_send_json_success( array(
			'status'      => $status,
			'offset'      => $progress['offset'],
			'total'       => $progress['total'],
			'percent'     => $progress['total'] > 0 ? round( ( $progress['offset'] / $progress['total'] ) * 100, 2 ) : 0,
			'is_stalled'  => $is_stalled,
			'last_active' => $last_active ? human_time_diff( $last_active ) . ' ago' : 'Never'
		) );
	}

	public function ajax_force_resume() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		update_option( 'mdc_scan_status', 'running' );
		wp_clear_scheduled_hook( 'mdc_cron_batch' );
		wp_schedule_single_event( time(), 'mdc_cron_batch' );
		
		$this->log( 'Manual Worker Resume triggered.', 'info' );
		wp_send_json_success( 'Worker re-kicked successfully.' );
	}

	public function ajax_trash_attachment() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		$id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		// Update post status to trash
		$result = wp_update_post( array(
			'ID'          => $id,
			'post_status' => 'trash'
		) );

		if ( $result ) {
			$this->sync_with_media_cleaner( $id, 'trash' );
			wp_send_json_success();
		}

		wp_send_json_error( 'Trashing failed.' );
	}

	public function ajax_restore_attachment() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		$id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		// Update post status back to inherit (attachments use inherit)
		$result = wp_update_post( array(
			'ID'          => $id,
			'post_status' => 'inherit'
		) );

		if ( $result ) {
			$this->sync_with_media_cleaner( $id, 'include' );
			wp_send_json_success();
		}

		wp_send_json_error( 'Restore failed.' );
	}

	public function ajax_exclude_attachment() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		$id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		global $wpdb;
		$wpdb->update( $this->table_mdc, array( 'is_excluded' => 1 ), array( 'attachment_id' => $id ) );
		$this->sync_with_media_cleaner( $id, 'exclude' );
		wp_send_json_success();
	}

	public function ajax_include_attachment() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		$id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		global $wpdb;
		$wpdb->update( $this->table_mdc, array( 'is_excluded' => 0 ), array( 'attachment_id' => $id ) );
		$this->sync_with_media_cleaner( $id, 'include' );
		wp_send_json_success();
	}

	public function ajax_bulk_trash() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		global $wpdb;
		// Trash all items with 0 matches that are NOT already trashed and NOT excluded
		$ids = $wpdb->get_col( "SELECT m.attachment_id FROM {$this->table_mdc} m JOIN {$wpdb->posts} p ON m.attachment_id = p.ID WHERE m.matches_count = 0 AND p.post_status != 'trash' AND m.is_excluded = 0" );
		
		if ( empty( $ids ) ) wp_send_json_error( 'No unused items found.' );

		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_update_post( array( 'ID' => $id, 'post_status' => 'trash' ) ) ) {
				$this->sync_with_media_cleaner( $id, 'trash' );
				$count++;
			}
		}

		wp_send_json_success( array( 'count' => $count ) );
	}

	public function ajax_manual_sync_mc() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		global $wpdb;
		$table_mc = $wpdb->prefix . 'mclean_scan';

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_mc ) ) !== $table_mc ) {
			wp_send_json_error( 'Media Cleaner table not found.' );
		}

		// 1. Sync MDC Trashed items -> MC
		$trashed_ids = $wpdb->get_col( "SELECT attachment_id FROM {$this->table_mdc} WHERE attachment_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash')" );
		foreach ( $trashed_ids as $id ) {
			$wpdb->update( $table_mc, array( 'deleted' => 1 ), array( 'postId' => $id ) );
		}

		// 2. Sync MDC Excluded items -> MC
		$excluded_ids = $wpdb->get_col( "SELECT attachment_id FROM {$this->table_mdc} WHERE is_excluded = 1" );
		foreach ( $excluded_ids as $id ) {
			$wpdb->update( $table_mc, array( 'ignored' => 1 ), array( 'postId' => $id ) );
		}

		// 3. Optional: Sync MC Trashed -> MDC results (mark as trash/excluded if mismatch)
		// We'll leave this for now as it's more complex, but the above covers the main user request.

		wp_send_json_success( 'Synchronization complete.' );
	}

	public function ajax_bulk_action_selected() {
		check_ajax_referer( 'mdc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( $_POST['bulk_action'] ) : '';

		if ( empty( $ids ) ) wp_send_json_error( 'No IDs selected.' );

		global $wpdb;
		$count = 0;

		foreach ( $ids as $id ) {
			if ( $bulk_action === 'trash' ) {
				if ( wp_update_post( array( 'ID' => $id, 'post_status' => 'trash' ) ) ) {
					$this->sync_with_media_cleaner( $id, 'trash' );
					$count++;
				}
			} elseif ( $bulk_action === 'exclude' ) {
				$wpdb->update( $this->table_mdc, array( 'is_excluded' => 1 ), array( 'attachment_id' => $id ) );
				$this->sync_with_media_cleaner( $id, 'exclude' );
				$count++;
			} elseif ( $bulk_action === 'include' ) {
				$wpdb->update( $this->table_mdc, array( 'is_excluded' => 0 ), array( 'attachment_id' => $id ) );
				$this->sync_with_media_cleaner( $id, 'include' );
				$count++;
			}
		}

		wp_send_json_success( array( 'count' => $count ) );
	}

	public function process_batch() {
		$status = get_option( 'mdc_scan_status', 'idle' );
		if ( $status !== 'running' ) return;

		global $wpdb;
		$progress = get_option( 'mdc_scan_progress' );
		$offset = $progress['offset'];
		$batch_size = 10;

		update_option( 'mdc_last_batch_time', time() );
		$this->log( "Starting batch at offset $offset", 'info' );

		try {
			$table_mc = $wpdb->prefix . 'mclean_scan';
			$has_mc = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_mc ) ) === $table_mc;

			// Filter: Only check items that are NOT deleted or ignored in Media Cleaner
			$where_filter = "WHERE issue = 'NO_CONTENT' AND type = 1";
			if ( $has_mc ) {
				$where_filter .= " AND (deleted = 0 AND ignored = 0)";
			}

			$items = $wpdb->get_results( $wpdb->prepare( 
				"SELECT * FROM {$this->table_scan} $where_filter LIMIT %d, %d",
				$offset, $batch_size
			) );

			if ( empty( $items ) ) {
				$this->log( "No more items found. Finishing scan.", 'info' );
				update_option( 'mdc_scan_status', 'idle' );
				return;
			}

			foreach ( $items as $item ) {
				$attachment_id = $item->postId;
				
				try {
					$attachment_path = get_attached_file( $attachment_id );
					if ( ! $attachment_path ) {
						$attachment_path = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file'", $attachment_id ) );
					}
					
					if ( ! $attachment_path ) {
						$this->log( "ID $attachment_id: No file path found. Skipping.", 'warning' );
						continue;
					}

					$filename = pathinfo( $attachment_path, PATHINFO_FILENAME );
					$matches = $this->perform_deep_check( $attachment_id, $filename );

					$wpdb->replace( $this->table_mdc, array(
						'attachment_id' => $attachment_id,
						'filename'      => $filename,
						'matches_count' => count( $matches ),
						'matches_data'  => json_encode( $matches ),
						'checked_at'    => current_time( 'mysql' )
					) );
				} catch ( Exception $e ) {
					$this->log( "Error processing item $attachment_id: " . $e->getMessage(), 'error' );
				}
			}

			// Update progress
			$new_offset = $offset + count( $items );
			update_option( 'mdc_scan_progress', array( 'offset' => $new_offset, 'total' => $progress['total'] ) );

			if ( $new_offset < $progress['total'] ) {
				wp_schedule_single_event( time() + 1, 'mdc_cron_batch' );
			} else {
				$this->log( "Scan complete. Processed $new_offset items.", 'success' );
				update_option( 'mdc_scan_status', 'idle' );
			}
		} catch ( Exception $e ) {
			$this->log( "Batch Level Error: " . $e->getMessage(), 'error' );
			// Wait a bit and try again
			wp_schedule_single_event( time() + 5, 'mdc_cron_batch' );
		}
	}

	private function render_row( $id, $filename, $matches, $post_status = '', $is_excluded = 0 ) {
		$count = count( $matches );
		$is_used = $count > 0;
		$is_trashed = ( $post_status === 'trash' );
		
		if ( $is_trashed ) {
			$status_label = '<span class="mdc-status-danger mdc-status-trashed">TRASHED</span>';
		} elseif ( $is_excluded ) {
			$status_label = '<span class="mdc-status-safe mdc-status-excluded">EXCLUDED (SAFE)</span>';
		} else {
			$status_label = $is_used ? '<span class="mdc-status-danger">USED ('.$count.')</span>' : '<span class="mdc-status-safe">NOT FOUND</span>';
		}
		?>
		<tr class="<?php echo $is_trashed ? 'mdc-row-trashed' : ''; ?> <?php echo $is_excluded ? 'mdc-row-excluded' : ''; ?>">
			<td class="mdc-col-cb" style="display:none;"><input type="checkbox" class="mdc-row-cb" value="<?php echo $id; ?>"></td>
			<td><?php echo $id; ?></td>
			<td><strong><?php echo esc_html( $filename ); ?></strong></td>
			<td><?php echo $status_label; ?></td>
			<td>
				<?php if ( $is_trashed ) : ?>
					<div class="mdc-row-actions">
						<em>Moved to internal trash.</em>
						<button class="mdc-restore-btn button button-secondary" data-id="<?php echo $id; ?>">Restore</button>
					</div>
				<?php elseif ( $is_excluded ) : ?>
					<div class="mdc-row-actions">
						<em>Marked as safe (excluded).</em>
						<button class="mdc-include-btn button button-secondary" data-id="<?php echo $id; ?>">Include Again</button>
					</div>
				<?php elseif ( $is_used ) : ?>
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
					<div class="mdc-row-actions">
						<em>No matches found.</em>
						<div class="mdc-btn-group">
							<button class="mdc-trash-btn button button-link-delete" data-id="<?php echo $id; ?>">Move to Trash</button>
							<span class="mdc-btn-sep">|</span>
							<button class="mdc-exclude-btn mdc-action-link" data-id="<?php echo $id; ?>">Exclude</button>
						</div>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function get_ignored_keys_where( $col = 'meta_key' ) {
		$ignored_patterns = array(
			// From Examples & Initial patterns
			'_transient_%',
			'itsec_%',
			'sgo_%',
			'_wc_var_prices_%',
			'_wp_session_%',
			'%_hashes',
			'%_amount%',
			'%_price%',
			'%_CAD',
			'%_canada',
			'%_tax%',
			'flickr_data_array',
			// From Real Data Scan
			'partner',
			'product',
			'product_id',
			'_edit_last',
			'created_by',
			'_approved_by',
			'_customer_user',
			'_date_%',
			'_wcpdf_%',
			'_ga_%',
			'_wc_order_attribution_%',
			'_wc_braintree_%',
			'zoho_order_id',
			'%_phone',
			'%_postcode',
			'shipped_date',
			'manual_delivered_date',
			'jetpack_sync_%',
			'stellarwp_%',
			'_yoast_wpseo_estimated-reading-time-minutes',
			'_menu_item_menu_item_parent',
			'_menu_item_object_id',
			'total_sales',
			'_stock',
			'_dp_original',
			'_is_copy',
			'paid',
			'expired',
			'is_soon',
			'order_approved',
			'is_overdue',
			// New patterns from Port 10016
			'_wt_import_key',
			'_order_number',
			'wpmm_%',
			'mfn-post-love',
			'%_count',
			'_thankyou_%',
			'_imagify_%',
			'_botiga_%',
			'%_size',
			'power_%',
			'power',
			'fb_product_%',
			'invoice_id',
			'siteground_optimizer_%',
			'sg_security_%',
			'_download_%',
			'wf_order_%'
		);
		
		$wheres = array();
		foreach ( $ignored_patterns as $pattern ) {
			// Don't blacklist if it looks like a media key anyway
			if ( stripos($pattern, 'image') !== false || stripos($pattern, 'icon') !== false || stripos($pattern, 'thumb') !== false || stripos($pattern, 'logo') !== false ) {
				continue;
			}
			$wheres[] = "{$col} NOT LIKE '" . esc_sql( $pattern ) . "'";
		}
		
		return " AND (" . implode( ' AND ', $wheres ) . ")";
	}

	private function perform_deep_check( $id, $filename ) {
		global $wpdb;

		$defaults = array(
			'woocommerce' => 1,
			'elementor'   => 1,
			'divi'        => 1,
			'bebuilder'   => 1,
			'yoast'       => 1,
			'acf'         => 1,
			'global_meta' => 1,
		);
		$settings = get_option( 'mdc_settings', $defaults );

		// Escape for LIKE
		$search_filename = '%' . $wpdb->esc_like( $filename ) . '%';
		$search_id_serialized_str = '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%';
		$search_id_serialized_int = '%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%';
		$search_id_comma_left     = '%' . $wpdb->esc_like( ',' . $id ) . '%';
		$search_id_comma_right    = '%' . $wpdb->esc_like( $id . ',' ) . '%';
		$search_id_elementor      = '%' . $wpdb->esc_like( ': ' . $id ) . '%'; 
		
		$search_id_raw = '%' . $wpdb->esc_like( $id ) . '%'; // Still used for post_content

		$queries = array();

		// 1. CHECK POSTS (Always check content for ID/Filename)
		$queries[] = $wpdb->prepare(
			"SELECT 'posts' AS source, ID AS item_id, post_title AS label, post_type AS subtype, 'Post Content' AS match_type
			 FROM {$wpdb->posts}
			 WHERE (post_content LIKE %s OR post_excerpt LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
			 AND ID != %d",
			$search_filename, $search_filename, $search_id_serialized_str, $search_id_raw, $id
		);

		// 2. CHECK POSTMETA (Conditional)
		$meta_keys = array();
		
		if ( ! empty( $settings['woocommerce'] ) ) {
			$meta_keys[] = '_product_image_gallery';
			$meta_keys[] = '_thumbnail_id';
		}
		if ( ! empty( $settings['elementor'] ) ) {
			$meta_keys[] = '_elementor_data';
		}
		if ( ! empty( $settings['bebuilder'] ) ) {
			$meta_keys[] = 'mfn-page-items-meta';
		}
		if ( ! empty( $settings['yoast'] ) ) {
			$meta_keys[] = '_yoast_wpseo_opengraph-image';
			$meta_keys[] = '_yoast_wpseo_twitter-image';
		}

		if ( ! empty( $meta_keys ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			$queries[] = $wpdb->prepare(
				"SELECT 'postmeta' AS source, post_id AS item_id, meta_key AS label, '-' AS subtype, 'Plugin Data' AS match_type
				 FROM {$wpdb->postmeta}
				 WHERE meta_key IN ($placeholders)
				 AND (
				    meta_value = %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				 )
				 AND post_id != %d",
				...array_merge( $meta_keys, [ (string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor, $id ] )
			);
		}

		// ACF Check (Explicit - PostMeta)
		if ( ! empty( $settings['acf'] ) ) {
			$queries[] = $wpdb->prepare(
				"SELECT 'acf' AS source, pm1.post_id AS item_id, pm1.meta_key AS label, '-' AS subtype, 'ACF Field' AS match_type
				 FROM {$wpdb->postmeta} pm1
				 JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = CONCAT('_', pm1.meta_key)
				 WHERE pm2.meta_value LIKE 'field_%%'
				 AND (
				    pm1.meta_value = %s
				    OR pm1.meta_value LIKE %s
				    OR pm1.meta_value LIKE %s
				    OR pm1.meta_value LIKE %s
				    OR pm1.meta_value LIKE %s
				    OR pm1.meta_value LIKE %s
				    OR pm1.meta_value LIKE %s
				 )
				 AND pm1.post_id != %d" . $this->get_ignored_keys_where('pm1.meta_key'),
				(string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor, $id
			);

			// ACF Options Pages
			$queries[] = $wpdb->prepare(
				"SELECT 'acf' AS source, o1.option_id AS item_id, o1.option_name AS label, 'Option Page' AS subtype, 'ACF Field' AS match_type
				 FROM {$wpdb->options} o1
				 JOIN {$wpdb->options} o2 ON o2.option_name = CONCAT('_', o1.option_name)
				 WHERE o2.option_value LIKE 'field_%%'
				 AND (
				    o1.option_value = %s
				    OR o1.option_value LIKE %s
				    OR o1.option_value LIKE %s
				    OR o1.option_value LIKE %s
				    OR o1.option_value LIKE %s
				    OR o1.option_value LIKE %s
				    OR o1.option_value LIKE %s
				 )" . $this->get_ignored_keys_where('o1.option_name'),
				(string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor
			);

			// ACF Term Meta
			$queries[] = $wpdb->prepare(
				"SELECT 'acf' AS source, tm1.term_id AS item_id, tm1.meta_key AS label, 'Term' AS subtype, 'ACF Field' AS match_type
				 FROM {$wpdb->termmeta} tm1
				 JOIN {$wpdb->termmeta} tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = CONCAT('_', tm1.meta_key)
				 WHERE tm2.meta_value LIKE 'field_%%'
				 AND (
				    tm1.meta_value = %s
				    OR tm1.meta_value LIKE %s
				    OR tm1.meta_value LIKE %s
				    OR tm1.meta_value LIKE %s
				    OR tm1.meta_value LIKE %s
				    OR tm1.meta_value LIKE %s
				    OR tm1.meta_value LIKE %s
				 )" . $this->get_ignored_keys_where('tm1.meta_key'),
				(string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor
			);
		}

		// Global Meta check if enabled
		if ( ! empty( $settings['global_meta'] ) ) {
			$exclude_keys = ! empty( $meta_keys ) ? $meta_keys : [ '' ];
			$placeholders_exclude = implode( ',', array_fill( 0, count( $exclude_keys ), '%s' ) );
			
			$queries[] = $wpdb->prepare(
				"SELECT 'postmeta' AS source, post_id AS item_id, meta_key AS label, '-' AS subtype, 'Global Metadata' AS match_type
				 FROM {$wpdb->postmeta}
				 WHERE meta_key NOT IN ($placeholders_exclude)
				 AND (
				    meta_value = %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				    OR meta_value LIKE %s
				 )
				 AND post_id != %d" . $this->get_ignored_keys_where('meta_key'),
				...array_merge( $exclude_keys, [ (string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor, $id ] )
			);
		}

		// 3. CHECK OPTIONS
		$queries[] = $wpdb->prepare(
			"SELECT 'options' AS source, option_id AS item_id, option_name AS label, '-' AS subtype, 'Global Theme Settings' AS match_type
			 FROM {$wpdb->options}
			 WHERE (
			    option_value = %s
			    OR option_value LIKE %s
			    OR option_value LIKE %s
			    OR option_value LIKE %s
			    OR option_value LIKE %s
			    OR option_value LIKE %s
			    OR option_value LIKE %s
			 )" . $this->get_ignored_keys_where('option_name'),
			(string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor
		);

		// 4. CHECK TERMMETA
		$queries[] = $wpdb->prepare(
			"SELECT 'termmeta' AS source, term_id AS item_id, meta_key AS label, '-' AS subtype, 'Category/Attribute/Brand' AS match_type
			 FROM {$wpdb->termmeta}
			 WHERE (
			    meta_value = %s 
			    OR meta_value LIKE %s
			    OR meta_value LIKE %s
			    OR meta_value LIKE %s
			    OR meta_value LIKE %s
			    OR meta_value LIKE %s
			    OR meta_value LIKE %s
			 )",
			(string)$id, $search_filename, $search_id_serialized_str, $search_id_serialized_int, $search_id_comma_left, $search_id_comma_right, $search_id_elementor
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
