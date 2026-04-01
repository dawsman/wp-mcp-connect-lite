<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles API request logging for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Logger {

	/**
	 * Database table name (without prefix).
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const TABLE_NAME = 'cwp_api_log';

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name    The name of the plugin.
	 * @param    string    $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @since    1.0.0
	 * @return   string    The full table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the logging database table.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id bigint(20) unsigned DEFAULT NULL,
			endpoint varchar(255) NOT NULL,
			method varchar(10) NOT NULL,
			status_code smallint(3) unsigned NOT NULL,
			description varchar(255) DEFAULT NULL,
			response_time_ms int(10) unsigned NOT NULL,
			ip_address varchar(45) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_timestamp (timestamp),
			KEY idx_user_id (user_id),
			KEY idx_status_code (status_code)
		) $charset_collate;";

		// Migrate existing tables to add description column
		self::maybe_add_description_column();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add description column to existing tables (migration).
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function maybe_add_description_column() {
		global $wpdb;

		$table_name = self::get_table_name();
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `$table_name` LIKE %s",
				'description'
			)
		);

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled by plugin code.
			$wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER status_code" );
		}
	}

	/**
	 * Drop the logging database table.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled by plugin code.
		$wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );
	}

	/**
	 * Log an API request.
	 *
	 * @since    1.0.0
	 * @param    string    $endpoint         The API endpoint.
	 * @param    string    $method           The HTTP method.
	 * @param    int       $status_code      The response status code.
	 * @param    float     $response_time    Response time in milliseconds.
	 * @param    string    $description      Optional description of the action taken.
	 * @return   bool|int                    Insert ID on success, false on failure.
	 */
	public function log_request( $endpoint, $method, $status_code, $response_time, $description = null ) {
		if ( ! $this->is_logging_enabled() ) {
			return false;
		}

		global $wpdb;

		$user_id = get_current_user_id();
		$ip_address = $this->get_client_ip();

		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'user_id'          => $user_id ? $user_id : null,
				'endpoint'         => sanitize_text_field( $endpoint ),
				'method'           => sanitize_text_field( $method ),
				'status_code'      => absint( $status_code ),
				'description'      => $description ? sanitize_text_field( $description ) : null,
				'response_time_ms' => absint( $response_time ),
				'ip_address'       => sanitize_text_field( $ip_address ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get recent log entries.
	 *
	 * @since    1.0.0
	 * @param    int       $limit     Number of entries to retrieve.
	 * @param    int       $offset    Offset for pagination.
	 * @param    string    $status    Filter by status: 'all', 'success', 'error'.
	 * @return   array                Array of log entries.
	 */
	public function get_logs( $limit = 25, $offset = 0, $status = 'all' ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$where = '';

		if ( 'success' === $status ) {
			$where = 'WHERE status_code >= 200 AND status_code < 300';
		} elseif ( 'error' === $status ) {
			$where = 'WHERE status_code >= 400';
		}

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, u.display_name as user_name 
				FROM $table_name l 
				LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
				$where
				ORDER BY l.timestamp DESC 
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $logs ? $logs : array();
	}

	/**
	 * Get total log count.
	 *
	 * @since    1.0.0
	 * @param    string    $status    Filter by status.
	 * @return   int                  Total count.
	 */
	public function get_log_count( $status = 'all' ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled by plugin code.
		if ( 'success' === $status ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `$table_name` WHERE status_code >= 200 AND status_code < 300"
			);
		}

		if ( 'error' === $status ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `$table_name` WHERE status_code >= 400"
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );
		// phpcs:enable
	}

	/**
	 * Get log statistics.
	 *
	 * @since    1.0.0
	 * @return   array    Statistics array.
	 */
	public function get_stats() {
		global $wpdb;

		$table_name = self::get_table_name();

		$stats = array(
			'total'          => 0,
			'success'        => 0,
			'error'          => 0,
			'avg_response'   => 0,
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled by plugin code.
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );
		$success = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` WHERE status_code >= 200 AND status_code < 300" );
		$error = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` WHERE status_code >= 400" );
		$avg = $wpdb->get_var( "SELECT AVG(response_time_ms) FROM `$table_name`" );
		// phpcs:enable

		$stats['total'] = (int) $total;
		$stats['success'] = (int) $success;
		$stats['error'] = (int) $error;
		$stats['avg_response'] = round( (float) $avg, 2 );

		return $stats;
	}

	/**
	 * Delete old log entries.
	 *
	 * @since    1.0.0
	 * @param    int    $days    Delete entries older than this many days.
	 * @return   int             Number of deleted rows.
	 */
	public function cleanup_old_logs( $days = 30 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE timestamp < %s",
				$date
			)
		);

		return $deleted ? $deleted : 0;
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @since    1.0.0
	 * @return   bool    True if enabled.
	 */
	public function is_logging_enabled() {
		return (bool) get_option( 'cwp_enable_logging', false );
	}

	/**
	 * Get client IP address using trusted-proxy-aware resolution.
	 *
	 * @since    1.0.0
	 * @return   string    Client IP address.
	 */
	private function get_client_ip() {
		if ( class_exists( 'WP_MCP_Connect_Auth' ) ) {
			return WP_MCP_Connect_Auth::get_client_ip();
		}
		// Fallback if auth class not loaded.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Register REST API routes for logs.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_logs_endpoint' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 25,
					'maximum' => 100,
				),
				'status'   => array(
					'type'    => 'string',
					'default' => 'all',
					'enum'    => array( 'all', 'success', 'error' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/logs/recent', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_recent_logs' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 10,
					'maximum' => 50,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/logs/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_logging_status' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/logs/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats_endpoint' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/logs/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_logs' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'status' => array(
					'type'    => 'string',
					'default' => 'all',
				),
			),
		) );
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST endpoint: Get logs.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_logs_endpoint( $request ) {
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$status = $request->get_param( 'status' );
		$offset = ( $page - 1 ) * $per_page;

		$logs = $this->get_logs( $per_page, $offset, $status );
		$total = $this->get_log_count( $status );

		return array(
			'logs'        => $logs,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/**
	 * Export logs as CSV.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function export_logs( $request ) {
		$status = $request->get_param( 'status' );
		$logs = $this->get_logs( 5000, 0, $status );

		$rows = array();
		$rows[] = array( 'timestamp', 'method', 'endpoint', 'status_code', 'response_time_ms', 'user_id', 'user_name', 'description' );

		foreach ( $logs as $log ) {
			$rows[] = array(
				$log['timestamp'],
				$log['method'],
				$log['endpoint'],
				$log['status_code'],
				$log['response_time_ms'],
				$log['user_id'],
				$log['user_name'],
				$log['description'],
			);
		}

		$csv = $this->array_to_csv( $rows );

		return array(
			'filename' => 'api-logs.csv',
			'csv'      => $csv,
			'total'    => count( $rows ) - 1,
		);
	}

	private function array_to_csv( $rows ) {
		$fh = fopen( 'php://temp', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/**
	 * REST endpoint: Get recent logs.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_recent_logs( $request ) {
		$limit = min( 50, max( 1, $request->get_param( 'limit' ) ) );
		return $this->get_logs( $limit, 0, 'all' );
	}

	/**
	 * REST endpoint: Get logging status.
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	public function get_logging_status() {
		return array(
			'enabled' => $this->is_logging_enabled(),
		);
	}

	/**
	 * REST endpoint: Get statistics.
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	public function get_stats_endpoint() {
		return $this->get_stats();
	}
}
