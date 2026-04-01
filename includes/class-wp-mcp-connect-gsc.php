<?php
defined( 'ABSPATH' ) || exit;

/**
 * Main Google Search Console handler for WP MCP Connect.
 *
 * Manages database tables, REST endpoints, and coordinates GSC functionality.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_GSC {

	/**
	 * Database table name for GSC page data.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const TABLE_DATA = 'cwp_gsc_data';

	/**
	 * Database table name for GSC query data.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const TABLE_QUERIES = 'cwp_gsc_queries';

	/**
	 * Database table name for sync logs.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const TABLE_SYNC_LOG = 'cwp_gsc_sync_log';

	/**
	 * Database table name for daily page-level metric snapshots.
	 *
	 * @since    1.1.0
	 * @var      string
	 */
	const TABLE_DATA_SNAPSHOTS = 'cwp_gsc_data_snapshots';

	/**
	 * Database table name for daily query-level metric snapshots.
	 *
	 * @since    1.1.0
	 * @var      string
	 */
	const TABLE_QUERY_SNAPSHOTS = 'cwp_gsc_query_snapshots';

	/**
	 * Database table name for site-average CTR by position band.
	 *
	 * @since    1.1.0
	 * @var      string
	 */
	const TABLE_CTR_CURVE = 'cwp_gsc_ctr_curve';

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $version;

	/**
	 * Auth handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_Auth
	 */
	private $auth;

	/**
	 * API handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_API
	 */
	private $api;

	/**
	 * Sync handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_Sync
	 */
	private $sync;

	/**
	 * Insights handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_Insights
	 */
	private $insights;

	/**
	 * Logger handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_Logger|null
	 */
	private $logger;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string                      $plugin_name    The name of the plugin.
	 * @param    string                      $version        The version of this plugin.
	 * @param    WP_MCP_Connect_Logger|null  $logger         Optional logger instance.
	 */
	public function __construct( $plugin_name, $version, $logger = null ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->logger = $logger;

		$this->load_dependencies();
		$this->instantiate_components();

		// Check and create tables on admin_init to ensure WordPress is fully loaded.
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );
	}

	/**
	 * Create tables if they don't exist.
	 *
	 * Called on admin_init to ensure dbDelta is available.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @return   void
	 */
	public function maybe_create_tables() {
		global $wpdb;

		$table_name = self::get_table_name( self::TABLE_DATA );
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$table_name
		) );

		if ( ! $table_exists ) {
			self::create_tables();
		}
	}

	/**
	 * Load required dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function load_dependencies() {
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-gsc-auth.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-gsc-api.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-gsc-sync.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-gsc-insights.php';
	}

	/**
	 * Instantiate component classes.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function instantiate_components() {
		$this->auth = new WP_MCP_Connect_GSC_Auth( $this->plugin_name, $this->version );
		$this->api = new WP_MCP_Connect_GSC_API( $this->auth );
		$this->sync = new WP_MCP_Connect_GSC_Sync( $this->api, $this->auth );
		$this->insights = new WP_MCP_Connect_GSC_Insights();
	}

	/**
	 * Get full table name with prefix.
	 *
	 * @since    1.0.0
	 * @param    string    $table    The table constant.
	 * @return   string              Full table name.
	 */
	public static function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Create all GSC database tables.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Main GSC data table.
		$table_data = self::get_table_name( self::TABLE_DATA );
		$sql_data = "CREATE TABLE $table_data (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			url_hash char(32) NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			is_indexed tinyint(1) DEFAULT NULL,
			index_status varchar(50) DEFAULT NULL,
			last_crawl_time datetime DEFAULT NULL,
			crawl_status varchar(50) DEFAULT NULL,
			robots_txt_state varchar(50) DEFAULT NULL,
			indexing_state varchar(50) DEFAULT NULL,
			impressions int(10) unsigned DEFAULT 0,
			clicks int(10) unsigned DEFAULT 0,
			ctr decimal(5,4) DEFAULT 0,
			avg_position decimal(5,2) DEFAULT 0,
			top_query varchar(500) DEFAULT NULL,
			top_query_impressions int(10) unsigned DEFAULT 0,
			top_query_clicks int(10) unsigned DEFAULT 0,
			top_query_position decimal(5,2) DEFAULT 0,
			prev_impressions int(10) unsigned DEFAULT 0,
			prev_clicks int(10) unsigned DEFAULT 0,
			prev_position decimal(5,2) DEFAULT 0,
			data_date date DEFAULT NULL,
			last_synced datetime DEFAULT NULL,
			last_inspected datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_url_hash (url_hash),
			KEY idx_post_id (post_id),
			KEY idx_is_indexed (is_indexed),
			KEY idx_impressions (impressions),
			KEY idx_last_synced (last_synced),
			KEY idx_data_date (data_date)
		) $charset_collate;";

		dbDelta( $sql_data );

		// Queries table.
		$table_queries = self::get_table_name( self::TABLE_QUERIES );
		$sql_queries = "CREATE TABLE $table_queries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gsc_data_id bigint(20) unsigned NOT NULL,
			query varchar(500) NOT NULL,
			impressions int(10) unsigned DEFAULT 0,
			clicks int(10) unsigned DEFAULT 0,
			ctr decimal(5,4) DEFAULT 0,
			position decimal(5,2) DEFAULT 0,
			data_date date DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_gsc_data_id (gsc_data_id),
			KEY idx_query (query(191)),
			KEY idx_impressions (impressions)
		) $charset_collate;";

		dbDelta( $sql_queries );

		// Sync log table.
		$table_sync = self::get_table_name( self::TABLE_SYNC_LOG );
		$sql_sync = "CREATE TABLE $table_sync (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sync_type varchar(50) NOT NULL,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			pages_processed int(10) unsigned DEFAULT 0,
			errors_count int(10) unsigned DEFAULT 0,
			error_message text DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_started_at (started_at)
		) $charset_collate;";

		dbDelta( $sql_sync );

		// Daily page-level metric snapshots.
		$table_data_snapshots = self::get_table_name( self::TABLE_DATA_SNAPSHOTS );
		$sql_data_snapshots = "CREATE TABLE $table_data_snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			snapshot_date date NOT NULL,
			impressions int(10) unsigned DEFAULT 0,
			clicks int(10) unsigned DEFAULT 0,
			ctr decimal(5,4) DEFAULT 0,
			avg_position decimal(5,2) DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_url_date (url_hash, snapshot_date),
			KEY idx_snapshot_date (snapshot_date)
		) $charset_collate;";

		dbDelta( $sql_data_snapshots );

		// Daily query-level metric snapshots.
		$table_query_snapshots = self::get_table_name( self::TABLE_QUERY_SNAPSHOTS );
		$sql_query_snapshots = "CREATE TABLE $table_query_snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			query varchar(500) NOT NULL,
			query_hash char(32) NOT NULL,
			snapshot_date date NOT NULL,
			impressions int(10) unsigned DEFAULT 0,
			clicks int(10) unsigned DEFAULT 0,
			ctr decimal(5,4) DEFAULT 0,
			position decimal(5,2) DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_url_query_date (url_hash, query_hash, snapshot_date),
			KEY idx_snapshot_date (snapshot_date),
			KEY idx_query_hash (query_hash)
		) $charset_collate;";

		dbDelta( $sql_query_snapshots );

		// Site-average CTR by position band.
		$table_ctr_curve = self::get_table_name( self::TABLE_CTR_CURVE );
		$sql_ctr_curve = "CREATE TABLE $table_ctr_curve (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			position_band varchar(20) NOT NULL,
			avg_ctr decimal(7,6) DEFAULT 0,
			sample_size int(10) unsigned DEFAULT 0,
			computed_date date NOT NULL,
			PRIMARY KEY (id),
			KEY idx_computed_date (computed_date)
		) $charset_collate;";

		dbDelta( $sql_ctr_curve );
	}

	/**
	 * Drop all GSC database tables.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array( self::TABLE_DATA, self::TABLE_QUERIES, self::TABLE_SYNC_LOG, self::TABLE_DATA_SNAPSHOTS, self::TABLE_QUERY_SNAPSHOTS, self::TABLE_CTR_CURVE );

		foreach ( $tables as $table ) {
			$table_name = self::get_table_name( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
		}

		// Clean up options.
		$options = array(
			'cwp_gsc_client_id',
			'cwp_gsc_client_secret',
			'cwp_gsc_access_token',
			'cwp_gsc_refresh_token',
			'cwp_gsc_token_expiry',
			'cwp_gsc_site_url',
			'cwp_gsc_sync_enabled',
			'cwp_gsc_sync_frequency',
			'cwp_gsc_last_sync',
			'cwp_gsc_data_retention_days',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Register all REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		// Auth routes.
		$this->auth->register_routes();

		// Sync routes.
		$this->sync->register_routes();

		// Main GSC data routes.
		register_rest_route( 'mcp/v1', '/gsc/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_overview' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/pages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_pages' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'page'      => array(
					'type'    => 'integer',
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'default' => 25,
					'maximum' => 100,
				),
				'orderby'   => array(
					'type'    => 'string',
					'default' => 'impressions',
					'enum'    => array( 'impressions', 'clicks', 'ctr', 'position', 'last_crawl_time' ),
				),
				'order'     => array(
					'type'    => 'string',
					'default' => 'desc',
					'enum'    => array( 'asc', 'desc' ),
				),
				'indexed'   => array(
					'type'    => 'string',
					'default' => 'all',
					'enum'    => array( 'all', 'yes', 'no' ),
				),
				'search'    => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/pages/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_details' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/insights', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_insights' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'type' => array(
					'type'    => 'string',
					'default' => 'all',
					'enum'    => array( 'all', 'ctr_opportunity', 'keyword_mismatch', 'not_indexed', 'stale_crawl', 'position_decline', 'top_performer', 'cannibalization', 'content_gap', 'ctr_underperformer' ),
				),
				'limit' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_csv' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'indexed' => array(
					'type'    => 'string',
					'default' => 'all',
					'enum'    => array( 'all', 'yes', 'no' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/refresh-planner', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_refresh_planner' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
				'min_impressions' => array(
					'type'    => 'integer',
					'default' => 5,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/keywords/recommend', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'recommend_keyword' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'gsc_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'strategy' => array(
					'type'    => 'string',
					'default' => 'clicks',
				),
				'show_candidates' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );

		// Historical data & analysis routes.
		register_rest_route( 'mcp/v1', '/gsc/pages/(?P<id>\d+)/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_history' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'days' => array(
					'type'    => 'integer',
					'default' => 90,
					'minimum' => 7,
					'maximum' => 365,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/cannibalization', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_cannibalization' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
				'min_impressions' => array(
					'type'    => 'integer',
					'default' => 100,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/content-gaps', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_content_gaps' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
				'min_impressions' => array(
					'type'    => 'integer',
					'default' => 100,
				),
				'max_position' => array(
					'type'    => 'number',
					'default' => 20.0,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/ctr-curve', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_ctr_curve' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'gsc_id' => array(
					'type' => 'integer',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/trends', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_trends' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'days' => array(
					'type'    => 'integer',
					'default' => 28,
					'minimum' => 7,
					'maximum' => 365,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/keywords/recommend-bulk', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'recommend_keywords_bulk' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
				'min_impressions' => array(
					'type'    => 'integer',
					'default' => 10,
				),
				'strategy' => array(
					'type'    => 'string',
					'default' => 'clicks',
				),
				'show_candidates' => array(
					'type'    => 'boolean',
					'default' => false,
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
	 * Get GSC overview statistics.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_overview( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$table_data = self::get_table_name( self::TABLE_DATA );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
		$total_pages = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_data ) );
		$indexed_pages = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_indexed = 1', $table_data ) );
		$not_indexed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_indexed = 0', $table_data ) );
		$total_impressions = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(impressions) FROM %i', $table_data ) );
		$total_clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(clicks) FROM %i', $table_data ) );
		$avg_ctr = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(ctr) FROM %i WHERE impressions > 0', $table_data ) );
		$avg_position = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(avg_position) FROM %i WHERE avg_position > 0', $table_data ) );
		// phpcs:enable

		$last_sync = get_option( 'cwp_gsc_last_sync', 0 );
		$site_url = get_option( 'cwp_gsc_site_url', '' );

		// Stale crawls (not crawled in 30+ days).
		$stale_threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
		$stale_crawls = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `$table_data` WHERE last_crawl_time < %s OR last_crawl_time IS NULL",
			$stale_threshold
		) );

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/overview', 'GET', 200, $response_time, 'Fetched Search Console overview' );

		return rest_ensure_response( array(
			'connected'         => $this->auth->is_connected(),
			'site_url'          => $site_url,
			'last_sync'         => $last_sync ? gmdate( 'c', $last_sync ) : null,
			'total_pages'       => $total_pages,
			'indexed_pages'     => $indexed_pages,
			'not_indexed'       => $not_indexed,
			'stale_crawls'      => $stale_crawls,
			'total_impressions' => $total_impressions,
			'total_clicks'      => $total_clicks,
			'avg_ctr'           => round( $avg_ctr * 100, 2 ),
			'avg_position'      => round( $avg_position, 1 ),
		) );
	}

	/**
	 * Refresh planner based on GSC deltas and post age.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function get_refresh_planner( $request ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_DATA );

		$limit = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$min_impressions = max( 5, (int) $request->get_param( 'min_impressions' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.url, d.post_id, d.impressions, d.prev_impressions, d.avg_position, d.prev_position, d.ctr, d.clicks, d.data_date, p.post_title, p.post_modified_gmt
				FROM {$table} d
				LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
				WHERE d.impressions >= %d
				ORDER BY d.impressions DESC
				LIMIT %d",
				$min_impressions,
				$limit * 2
			),
			ARRAY_A
		);

		$results = array();
		$now = current_time( 'timestamp', 1 );

		foreach ( $rows as $row ) {
			$post_modified = ! empty( $row['post_modified_gmt'] ) ? strtotime( $row['post_modified_gmt'] ) : 0;
			$age_days      = $post_modified ? (int) floor( ( $now - $post_modified ) / DAY_IN_SECONDS ) : 0;
			// NOTE: Do NOT skip here globally — stale_content needs recently unmodified pages.

			$reasons          = array();
			$prev_impressions = (int) $row['prev_impressions'];
			$impressions      = (int) $row['impressions'];
			$prev_position    = (float) $row['prev_position'];
			$avg_position     = (float) $row['avg_position'];
			$ctr              = (float) $row['ctr'];
			$clicks           = (int) $row['clicks'];

			// Only apply recent-edit exclusion for performance-based reasons
			$recently_edited = ( $age_days > 0 && $age_days < 30 );

			if ( ! $recently_edited ) {
				if ( $prev_impressions >= 20 && $impressions <= (int) floor( $prev_impressions * 0.8 ) ) {
					$reasons[] = 'impressions_drop';
				}
				if ( $prev_position > 0 && ( $avg_position - $prev_position ) >= 3 ) {
					$reasons[] = 'position_decline';
				}
				if ( $impressions >= 50 && $ctr < 0.02 ) {
					$reasons[] = 'low_ctr';
				}
			}

			// No clicks — impressions with zero clicks, regardless of edit recency
			if ( $impressions >= 10 && $clicks === 0 ) {
				$reasons[] = 'no_clicks';
			}

			// Stale content — has impressions, not recently updated
			if ( $impressions >= 5 && $age_days >= 90 ) {
				$reasons[] = 'stale_content';
			}

			if ( empty( $reasons ) ) {
				continue;
			}

			$results[] = array(
				'id'               => (int) $row['id'],
				'url'              => $row['url'],
				'post_id'          => (int) $row['post_id'],
				'post_title'       => $row['post_title'],
				'impressions'      => $impressions,
				'prev_impressions' => $prev_impressions,
				'avg_position'     => $avg_position,
				'prev_position'    => $prev_position,
				'ctr'              => $ctr,
				'clicks'           => $clicks,
				'age_days'         => $age_days,
				'reasons'          => $reasons,
			);
		}

		return array(
			'results' => array_slice( $results, 0, $limit ),
			'total'   => count( $results ),
		);
	}

	/**
	 * Recommend keyword for a single GSC page.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function recommend_keyword( $request ) {
		$gsc_id = (int) $request->get_param( 'gsc_id' );
		$strategy = sanitize_text_field( (string) $request->get_param( 'strategy' ) );
		$show_candidates = (bool) $request->get_param( 'show_candidates' );

		if ( $gsc_id <= 0 ) {
			return new WP_Error( 'invalid_gsc_id', __( 'Invalid GSC ID.', 'wp-mcp-connect' ), array( 'status' => 400 ) );
		}

		$data = $this->get_gsc_data_by_id( $gsc_id );
		if ( ! $data ) {
			return new WP_Error( 'not_found', __( 'GSC page not found.', 'wp-mcp-connect' ), array( 'status' => 404 ) );
		}

		$candidates = $this->get_keyword_candidates( $gsc_id, $strategy );
		$top = ! empty( $candidates ) ? $candidates[0] : null;
		$current = $data['post_id'] ? WP_MCP_Connect_SEO_Plugins::get_seo_value( $data['post_id'], 'focus_keyword' ) : '';

		return array(
			'gsc_id'            => $gsc_id,
			'post_id'           => (int) $data['post_id'],
			'post_title'        => $data['post_title'],
			'url'               => $data['url'],
			'current_keyword'   => $current,
			'recommended_keyword' => $top ? $top['query'] : null,
			'recommended_score' => $top ? $top['score'] : null,
			'candidates'        => $show_candidates ? array_slice( $candidates, 0, 5 ) : array(),
		);
	}

	/**
	 * Recommend keywords in bulk.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function recommend_keywords_bulk( $request ) {
		$limit = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$min_impressions = max( 0, (int) $request->get_param( 'min_impressions' ) );
		$strategy = sanitize_text_field( (string) $request->get_param( 'strategy' ) );
		$show_candidates = (bool) $request->get_param( 'show_candidates' );

		global $wpdb;
		$table = self::get_table_name( self::TABLE_DATA );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE impressions >= %d ORDER BY impressions DESC LIMIT %d",
				$min_impressions,
				$limit
			),
			ARRAY_A
		);

		$results = array();
		foreach ( $rows as $row ) {
			$request->set_param( 'gsc_id', (int) $row['id'] );
			$request->set_param( 'strategy', $strategy );
			$request->set_param( 'show_candidates', $show_candidates );
			$results[] = $this->recommend_keyword( $request );
		}

		return array(
			'results' => $results,
			'total'   => count( $results ),
		);
	}

	private function get_gsc_data_by_id( $id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_DATA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.*, p.post_title FROM {$table} d LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE d.id = %d", $id ), ARRAY_A );
		return $row;
	}

	private function get_keyword_candidates( $gsc_id, $strategy ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_QUERIES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT query, impressions, clicks, ctr, position FROM {$table} WHERE gsc_data_id = %d ORDER BY impressions DESC LIMIT 50", $gsc_id ), ARRAY_A );
		$brand_terms = $this->get_brand_terms();
		$candidates = array();
		foreach ( $rows as $row ) {
			// Filter out branded queries from recommendations.
			if ( $this->is_branded_query( $row['query'], $brand_terms ) ) {
				continue;
			}

			$candidates[] = array(
				'query'       => $row['query'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'ctr'         => (float) $row['ctr'],
				'position'    => (float) $row['position'],
				'score'       => $this->score_query( $row, $strategy ),
				'intent'      => $this->classify_intent( $row['query'] ),
			);
		}

		usort( $candidates, function( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return $candidates;
	}

	private function score_query( $row, $strategy ) {
		$impressions = (float) $row['impressions'];
		$clicks = (float) $row['clicks'];
		$ctr = (float) $row['ctr'];
		$position = (float) $row['position'];

		switch ( $strategy ) {
			case 'impressions':
				return $impressions + $clicks * 10;
			case 'position':
				if ( $position >= 4 && $position <= 20 ) {
					return $impressions * ( 21 - $position );
				}
				return $impressions * 0.1;
			case 'balanced':
				$position_score = $position > 0 ? max( 0, 21 - $position ) : 0;
				// Striking distance bonus for positions 4-15.
				$striking_bonus = ( $position >= 4 && $position <= 15 ) ? $impressions * 0.3 : 0;
				return $clicks * 50 + $impressions * 0.5 + $ctr * 1000 + $position_score * 10 + $striking_bonus;
			case 'clicks':
			default:
				return $clicks * 100 + $impressions * 0.1;
		}
	}

	/**
	 * Get brand terms for filtering.
	 *
	 * @since    1.1.0
	 * @return   array    Array of lowercase brand terms.
	 */
	private function get_brand_terms() {
		$site_name = strtolower( get_bloginfo( 'name' ) );
		$terms = array_filter( array( $site_name ) );

		/**
		 * Filter the list of brand terms excluded from keyword recommendations.
		 *
		 * @since 1.1.0
		 * @param array $terms Default brand terms (site name).
		 */
		return apply_filters( 'cwp_gsc_brand_terms', $terms );
	}

	/**
	 * Check if a query contains a brand term.
	 *
	 * @since    1.1.0
	 * @param    string    $query        The search query.
	 * @param    array     $brand_terms  Brand terms to check against.
	 * @return   bool
	 */
	private function is_branded_query( $query, $brand_terms ) {
		$query_lower = strtolower( $query );
		foreach ( $brand_terms as $term ) {
			if ( ! empty( $term ) && false !== strpos( $query_lower, $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classify search intent of a query.
	 *
	 * @since    1.1.0
	 * @param    string    $query    The search query.
	 * @return   string              One of: informational, transactional, navigational, mixed.
	 */
	private function classify_intent( $query ) {
		$query_lower = strtolower( $query );

		$transactional = array( 'buy', 'price', 'cost', 'cheap', 'deal', 'discount', 'coupon', 'order', 'purchase', 'shop', 'store', 'hire', 'booking', 'subscribe' );
		$navigational = array( 'login', 'sign in', 'account', 'dashboard', 'contact', 'support', 'official', 'website' );
		$informational = array( 'how to', 'what is', 'why', 'guide', 'tutorial', 'tips', 'best', 'vs', 'review', 'example', 'list of', 'difference between', 'ways to' );

		$is_transactional = false;
		$is_informational = false;
		$is_navigational = false;

		foreach ( $transactional as $word ) {
			if ( false !== strpos( $query_lower, $word ) ) {
				$is_transactional = true;
				break;
			}
		}

		foreach ( $navigational as $word ) {
			if ( false !== strpos( $query_lower, $word ) ) {
				$is_navigational = true;
				break;
			}
		}

		foreach ( $informational as $word ) {
			if ( false !== strpos( $query_lower, $word ) ) {
				$is_informational = true;
				break;
			}
		}

		if ( $is_transactional && $is_informational ) {
			return 'mixed';
		}
		if ( $is_transactional ) {
			return 'transactional';
		}
		if ( $is_navigational ) {
			return 'navigational';
		}
		if ( $is_informational ) {
			return 'informational';
		}
		return 'mixed';
	}

	/**
	 * Get paginated GSC page data.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_pages( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$table_data = self::get_table_name( self::TABLE_DATA );

		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$orderby = $request->get_param( 'orderby' );
		$order = strtoupper( $request->get_param( 'order' ) );
		$indexed = $request->get_param( 'indexed' );
		$search = $request->get_param( 'search' );
		$offset = ( $page - 1 ) * $per_page;

		// Validate orderby against allowed columns.
		$allowed_orderby = array( 'impressions', 'clicks', 'ctr', 'avg_position', 'last_crawl_time' );
		if ( 'position' === $orderby ) {
			$orderby = 'avg_position';
		}
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'impressions';
		}

		// Validate order direction.
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$where_clauses = array();
		$where_values = array();

		if ( 'yes' === $indexed ) {
			$where_clauses[] = 'is_indexed = 1';
		} elseif ( 'no' === $indexed ) {
			$where_clauses[] = 'is_indexed = 0';
		}

		if ( ! empty( $search ) ) {
			$where_clauses[] = 'url LIKE %s';
			$where_values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name, orderby, and order are internally controlled and validated.
		$count_sql = "SELECT COUNT(*) FROM `$table_data` $where_sql";
		if ( ! empty( $where_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $where_values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$query_sql = "SELECT * FROM `$table_data` $where_sql ORDER BY `$orderby` $order LIMIT %d OFFSET %d";
		$query_values = array_merge( $where_values, array( $per_page, $offset ) );
		$results = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_values ), ARRAY_A );
		// phpcs:enable

		// Enrich with WordPress post data.
		$pages = array();
		foreach ( $results as $row ) {
			$post_data = null;
			if ( ! empty( $row['post_id'] ) ) {
				$post = get_post( $row['post_id'] );
				if ( $post ) {
					$focus_keyword = WP_MCP_Connect_SEO_Plugins::get_seo_value( $post->ID, 'focus_keyword' );
					$post_data = array(
						'id'            => $post->ID,
						'title'         => $post->post_title,
						'type'          => $post->post_type,
						'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
						'focus_keyword' => $focus_keyword,
					);
				}
			}

			// Calculate keyword match.
			$keyword_match = null;
			if ( $post_data && ! empty( $post_data['focus_keyword'] ) && ! empty( $row['top_query'] ) ) {
				similar_text(
					strtolower( $row['top_query'] ),
					strtolower( $post_data['focus_keyword'] ),
					$keyword_match
				);
				$keyword_match = round( $keyword_match );
			}

			// Calculate trends.
			$impressions_trend = null;
			$clicks_trend = null;
			$position_trend = null;

			if ( $row['prev_impressions'] > 0 ) {
				$impressions_trend = $row['impressions'] - $row['prev_impressions'];
			}
			if ( $row['prev_clicks'] > 0 ) {
				$clicks_trend = $row['clicks'] - $row['prev_clicks'];
			}
			if ( $row['prev_position'] > 0 && $row['avg_position'] > 0 ) {
				$position_trend = $row['prev_position'] - $row['avg_position']; // Positive = improved.
			}

			$pages[] = array(
				'id'                => (int) $row['id'],
				'url'               => $row['url'],
				'post'              => $post_data,
				'is_indexed'        => $row['is_indexed'] !== null ? (bool) $row['is_indexed'] : null,
				'index_status'      => $row['index_status'],
				'last_crawl_time'   => $row['last_crawl_time'],
				'crawl_status'      => $row['crawl_status'],
				'impressions'       => (int) $row['impressions'],
				'impressions_trend' => $impressions_trend,
				'clicks'            => (int) $row['clicks'],
				'clicks_trend'      => $clicks_trend,
				'ctr'               => round( (float) $row['ctr'] * 100, 2 ),
				'avg_position'      => round( (float) $row['avg_position'], 1 ),
				'position_trend'    => $position_trend !== null ? round( $position_trend, 1 ) : null,
				'top_query'         => $row['top_query'],
				'keyword_match'     => $keyword_match,
				'last_synced'       => $row['last_synced'],
			);
		}

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/pages', 'GET', 200, $response_time, sprintf( 'Listed %d Search Console pages', count( $pages ) ) );

		return rest_ensure_response( array(
			'pages'       => $pages,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );
	}

	/**
	 * Get detailed data for a single page.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_page_details( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$id = $request->get_param( 'id' );
		$table_data = self::get_table_name( self::TABLE_DATA );
		$table_queries = self::get_table_name( self::TABLE_QUERIES );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
		$page = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `$table_data` WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $page ) {
			$response_time = ( microtime( true ) - $start_time ) * 1000;
			$this->log_request( '/mcp/v1/gsc/pages/' . $id, 'GET', 404, $response_time, 'Page not found' );
			return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
		}

		// Get all queries for this page.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
		$queries = $wpdb->get_results( $wpdb->prepare(
			"SELECT query, impressions, clicks, ctr, position FROM `$table_queries` WHERE gsc_data_id = %d ORDER BY impressions DESC LIMIT 20",
			$id
		), ARRAY_A );

		// Enrich query data.
		$formatted_queries = array();
		foreach ( $queries as $q ) {
			$formatted_queries[] = array(
				'query'       => $q['query'],
				'impressions' => (int) $q['impressions'],
				'clicks'      => (int) $q['clicks'],
				'ctr'         => round( (float) $q['ctr'] * 100, 2 ),
				'position'    => round( (float) $q['position'], 1 ),
			);
		}

		// Get WordPress post data.
		$post_data = null;
		if ( ! empty( $page['post_id'] ) ) {
			$post = get_post( $page['post_id'] );
			if ( $post ) {
				$seo_title = WP_MCP_Connect_SEO_Plugins::get_resolved_seo_value( $post->ID, 'seo_title' );
				$seo_desc = WP_MCP_Connect_SEO_Plugins::get_resolved_seo_value( $post->ID, 'seo_description' );
				$focus_keyword = WP_MCP_Connect_SEO_Plugins::get_seo_value( $post->ID, 'focus_keyword' );

				$post_data = array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'type'            => $post->post_type,
					'status'          => $post->post_status,
					'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
					'view_url'        => get_permalink( $post->ID ),
					'seo_title'       => $seo_title['value'] ?? '',
					'seo_description' => $seo_desc['value'] ?? '',
					'focus_keyword'   => $focus_keyword,
				);
			}
		}

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/pages/' . $id, 'GET', 200, $response_time, 'Fetched page details' );

		return rest_ensure_response( array(
			'id'                     => (int) $page['id'],
			'url'                    => $page['url'],
			'post'                   => $post_data,
			'is_indexed'             => $page['is_indexed'] !== null ? (bool) $page['is_indexed'] : null,
			'index_status'           => $page['index_status'],
			'last_crawl_time'        => $page['last_crawl_time'],
			'crawl_status'           => $page['crawl_status'],
			'robots_txt_state'       => $page['robots_txt_state'],
			'indexing_state'         => $page['indexing_state'],
			'impressions'            => (int) $page['impressions'],
			'clicks'                 => (int) $page['clicks'],
			'ctr'                    => round( (float) $page['ctr'] * 100, 2 ),
			'avg_position'           => round( (float) $page['avg_position'], 1 ),
			'prev_impressions'       => (int) $page['prev_impressions'],
			'prev_clicks'            => (int) $page['prev_clicks'],
			'prev_position'          => round( (float) $page['prev_position'], 1 ),
			'top_query'              => $page['top_query'],
			'top_query_impressions'  => (int) $page['top_query_impressions'],
			'top_query_clicks'       => (int) $page['top_query_clicks'],
			'top_query_position'     => round( (float) $page['top_query_position'], 1 ),
			'queries'                => $formatted_queries,
			'last_synced'            => $page['last_synced'],
			'last_inspected'         => $page['last_inspected'],
		) );
	}

	/**
	 * Get actionable insights.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_insights( $request ) {
		$start_time = microtime( true );

		$type = $request->get_param( 'type' );
		$limit = $request->get_param( 'limit' );

		$insights = $this->insights->generate_insights( $type, $limit );

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$insight_desc = 'all' === $type ? 'Generated all GSC insights' : sprintf( 'Generated %s insights', str_replace( '_', ' ', $type ) );
		$this->log_request( '/mcp/v1/gsc/insights', 'GET', 200, $response_time, $insight_desc );

		return rest_ensure_response( $insights );
	}

	/**
	 * Get historical time-series for a single page.
	 *
	 * @since    1.1.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_page_history( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		$days = min( 365, max( 7, (int) $request->get_param( 'days' ) ) );

		$table_data = self::get_table_name( self::TABLE_DATA );
		$table_snapshots = self::get_table_name( self::TABLE_DATA_SNAPSHOTS );

		// Get url_hash for this page.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$url_hash = $wpdb->get_var( $wpdb->prepare(
			"SELECT url_hash FROM $table_data WHERE id = %d",
			$id
		) );

		if ( ! $url_hash ) {
			return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
		}

		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$snapshots = $wpdb->get_results( $wpdb->prepare(
			"SELECT snapshot_date, impressions, clicks, ctr, avg_position
			FROM $table_snapshots
			WHERE url_hash = %s AND snapshot_date >= %s
			ORDER BY snapshot_date ASC",
			$url_hash,
			$since
		), ARRAY_A );

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/pages/' . $id . '/history', 'GET', 200, $response_time, 'Page history' );

		return rest_ensure_response( array(
			'page_id'   => $id,
			'days'      => $days,
			'snapshots' => array_map( function( $s ) {
				return array(
					'date'        => $s['snapshot_date'],
					'impressions' => (int) $s['impressions'],
					'clicks'      => (int) $s['clicks'],
					'ctr'         => (float) $s['ctr'],
					'position'    => round( (float) $s['avg_position'], 1 ),
				);
			}, $snapshots ),
		) );
	}

	/**
	 * Get keyword cannibalization — queries ranking on 2+ pages.
	 *
	 * @since    1.1.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_cannibalization( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$limit = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$min_impressions = max( 0, (int) $request->get_param( 'min_impressions' ) );

		$table_qs = self::get_table_name( self::TABLE_QUERY_SNAPSHOTS );
		$table_data = self::get_table_name( self::TABLE_DATA );

		// Get the most recent snapshot date.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(snapshot_date) FROM $table_qs" );

		if ( ! $latest_date ) {
			return rest_ensure_response( array( 'queries' => array(), 'total' => 0 ) );
		}

		// Find queries appearing on 2+ distinct URLs on the latest date.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cannibalized = $wpdb->get_results( $wpdb->prepare(
			"SELECT query_hash, query, SUM(impressions) AS total_impressions, COUNT(DISTINCT url_hash) AS page_count
			FROM $table_qs
			WHERE snapshot_date = %s AND impressions >= %d
			GROUP BY query_hash, query
			HAVING page_count >= 2
			ORDER BY total_impressions DESC
			LIMIT %d",
			$latest_date,
			$min_impressions,
			$limit
		), ARRAY_A );

		$results = array();
		foreach ( $cannibalized as $row ) {
			// Get competing pages for this query.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pages = $wpdb->get_results( $wpdb->prepare(
				"SELECT qs.url_hash, qs.impressions, qs.clicks, qs.ctr, qs.position,
					d.url, d.post_id, p.post_title
				FROM $table_qs qs
				LEFT JOIN $table_data d ON d.url_hash = qs.url_hash
				LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
				WHERE qs.query_hash = %s AND qs.snapshot_date = %s
				ORDER BY qs.impressions DESC",
				$row['query_hash'],
				$latest_date
			), ARRAY_A );

			$results[] = array(
				'query'             => $row['query'],
				'total_impressions' => (int) $row['total_impressions'],
				'page_count'        => (int) $row['page_count'],
				'pages'             => array_map( function( $pg ) {
					return array(
						'url'         => $pg['url'],
						'post_id'     => $pg['post_id'] ? (int) $pg['post_id'] : null,
						'post_title'  => $pg['post_title'],
						'impressions' => (int) $pg['impressions'],
						'clicks'      => (int) $pg['clicks'],
						'ctr'         => (float) $pg['ctr'],
						'position'    => round( (float) $pg['position'], 1 ),
					);
				}, $pages ),
			);
		}

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/cannibalization', 'GET', 200, $response_time, 'Cannibalization report' );

		return rest_ensure_response( array(
			'queries'       => $results,
			'total'         => count( $results ),
			'snapshot_date' => $latest_date,
		) );
	}

	/**
	 * Get content gaps — high-impression queries without dedicated content.
	 *
	 * @since    1.1.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_content_gaps( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$limit = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$min_impressions = max( 0, (int) $request->get_param( 'min_impressions' ) );
		$max_position = (float) $request->get_param( 'max_position' );

		$table_qs = self::get_table_name( self::TABLE_QUERY_SNAPSHOTS );
		$table_data = self::get_table_name( self::TABLE_DATA );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(snapshot_date) FROM $table_qs" );

		if ( ! $latest_date ) {
			return rest_ensure_response( array( 'gaps' => array(), 'total' => 0 ) );
		}

		// Queries with high impressions where best-ranking page has no post_id or poor position.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gaps = $wpdb->get_results( $wpdb->prepare(
			"SELECT qs.query, qs.query_hash, qs.impressions, qs.clicks, qs.ctr, qs.position,
				qs.url_hash, d.url, d.post_id, p.post_title
			FROM $table_qs qs
			LEFT JOIN $table_data d ON d.url_hash = qs.url_hash
			LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
			WHERE qs.snapshot_date = %s
				AND qs.impressions >= %d
				AND (d.post_id IS NULL OR qs.position > %f)
			ORDER BY qs.impressions DESC
			LIMIT %d",
			$latest_date,
			$min_impressions,
			$max_position,
			$limit
		), ARRAY_A );

		$results = array_map( function( $row ) use ( $max_position ) {
			$reason = empty( $row['post_id'] )
				? 'no_dedicated_content'
				: 'poor_position';

			return array(
				'query'       => $row['query'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'ctr'         => (float) $row['ctr'],
				'position'    => round( (float) $row['position'], 1 ),
				'url'         => $row['url'],
				'post_id'     => $row['post_id'] ? (int) $row['post_id'] : null,
				'post_title'  => $row['post_title'],
				'reason'      => $reason,
			);
		}, $gaps );

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/content-gaps', 'GET', 200, $response_time, 'Content gaps report' );

		return rest_ensure_response( array(
			'gaps'          => $results,
			'total'         => count( $results ),
			'snapshot_date' => $latest_date,
		) );
	}

	/**
	 * Get site-average CTR by position band, with optional page comparison.
	 *
	 * @since    1.1.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_ctr_curve( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$table_curve = self::get_table_name( self::TABLE_CTR_CURVE );

		// Get the most recent CTR curve.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(computed_date) FROM $table_curve" );

		$curve = array();
		if ( $latest_date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT position_band, avg_ctr, sample_size FROM $table_curve WHERE computed_date = %s ORDER BY position_band ASC",
				$latest_date
			), ARRAY_A );

			foreach ( $rows as $row ) {
				$curve[] = array(
					'position_band' => $row['position_band'],
					'avg_ctr'       => (float) $row['avg_ctr'],
					'sample_size'   => (int) $row['sample_size'],
				);
			}
		}

		$page_comparison = null;
		$gsc_id = $request->get_param( 'gsc_id' );
		if ( $gsc_id ) {
			$table_data = self::get_table_name( self::TABLE_DATA );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page = $wpdb->get_row( $wpdb->prepare(
				"SELECT avg_position, ctr FROM $table_data WHERE id = %d",
				(int) $gsc_id
			), ARRAY_A );

			if ( $page ) {
				$pos = (float) $page['avg_position'];
				$band = $this->position_to_band( $pos );
				$site_avg = 0;
				foreach ( $curve as $c ) {
					if ( $c['position_band'] === $band ) {
						$site_avg = $c['avg_ctr'];
						break;
					}
				}

				$page_comparison = array(
					'position'      => round( $pos, 1 ),
					'position_band' => $band,
					'page_ctr'      => (float) $page['ctr'],
					'site_avg_ctr'  => $site_avg,
					'delta'         => round( (float) $page['ctr'] - $site_avg, 6 ),
				);
			}
		}

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/ctr-curve', 'GET', 200, $response_time, 'CTR curve' );

		return rest_ensure_response( array(
			'curve'          => $curve,
			'computed_date'  => $latest_date,
			'page_comparison' => $page_comparison,
		) );
	}

	/**
	 * Get aggregate site-wide time-series for dashboard charts.
	 *
	 * @since    1.1.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_trends( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$days = min( 365, max( 7, (int) $request->get_param( 'days' ) ) );
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$table_snapshots = self::get_table_name( self::TABLE_DATA_SNAPSHOTS );

		// Aggregate all pages by date.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT snapshot_date,
				SUM(impressions) AS impressions,
				SUM(clicks) AS clicks,
				CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,
				AVG(avg_position) AS position
			FROM $table_snapshots
			WHERE snapshot_date >= %s
			GROUP BY snapshot_date
			ORDER BY snapshot_date ASC",
			$since
		), ARRAY_A );

		$series = array_map( function( $row ) {
			return array(
				'date'        => $row['snapshot_date'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'ctr'         => round( (float) $row['ctr'], 4 ),
				'position'    => round( (float) $row['position'], 1 ),
			);
		}, $rows );

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/trends', 'GET', 200, $response_time, 'Trends data' );

		return rest_ensure_response( array(
			'days'   => $days,
			'series' => $series,
		) );
	}

	/**
	 * Convert a position value to a band label.
	 *
	 * @since    1.1.0
	 * @param    float    $position    Average position.
	 * @return   string                Band label (e.g. '1', '2', '3', '4-5', '6-10', '11-20', '21+').
	 */
	private function position_to_band( $position ) {
		$pos = round( $position );
		if ( $pos <= 3 ) {
			return (string) $pos;
		}
		if ( $pos <= 5 ) {
			return '4-5';
		}
		if ( $pos <= 10 ) {
			return '6-10';
		}
		if ( $pos <= 20 ) {
			return '11-20';
		}
		return '21+';
	}

	/**
	 * Export GSC data as CSV.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function export_csv( $request ) {
		$start_time = microtime( true );
		global $wpdb;

		$table_data = self::get_table_name( self::TABLE_DATA );
		$indexed = $request->get_param( 'indexed' );

		$where_clause = '';
		$where_value = null;
		if ( 'yes' === $indexed ) {
			$where_clause = 'WHERE is_indexed = %d';
			$where_value = 1;
		} elseif ( 'no' === $indexed ) {
			$where_clause = 'WHERE is_indexed = %d';
			$where_value = 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally controlled via get_table_name().
		$query = "SELECT * FROM `$table_data` $where_clause ORDER BY impressions DESC";
		if ( null !== $where_value ) {
			$results = $wpdb->get_results( $wpdb->prepare( $query, $where_value ), ARRAY_A );
		} else {
			$results = $wpdb->get_results( $query, ARRAY_A );
		}

		$csv_lines = array();
		$csv_lines[] = array(
			'URL',
			'Post ID',
			'Post Title',
			'Indexed',
			'Index Status',
			'Last Crawled',
			'Top Query',
			'Focus Keyword',
			'Keyword Match %',
			'Impressions',
			'Clicks',
			'CTR %',
			'Position',
			'Position Change',
			'Last Synced',
		);

		foreach ( $results as $row ) {
			$post_title = '';
			$focus_keyword = '';
			$keyword_match = '';

			if ( ! empty( $row['post_id'] ) ) {
				$post = get_post( $row['post_id'] );
				if ( $post ) {
					$post_title = $post->post_title;
					$focus_keyword = WP_MCP_Connect_SEO_Plugins::get_seo_value( $post->ID, 'focus_keyword' );

					if ( ! empty( $focus_keyword ) && ! empty( $row['top_query'] ) ) {
						similar_text(
							strtolower( $row['top_query'] ),
							strtolower( $focus_keyword ),
							$keyword_match
						);
						$keyword_match = round( $keyword_match );
					}
				}
			}

			$position_change = '';
			if ( $row['prev_position'] > 0 && $row['avg_position'] > 0 ) {
				$position_change = round( $row['prev_position'] - $row['avg_position'], 1 );
			}

			$csv_lines[] = array(
				$row['url'],
				$row['post_id'] ?? '',
				$post_title,
				$row['is_indexed'] === '1' ? 'Yes' : ( $row['is_indexed'] === '0' ? 'No' : 'Unknown' ),
				$row['index_status'] ?? '',
				$row['last_crawl_time'] ?? '',
				$row['top_query'] ?? '',
				$focus_keyword,
				$keyword_match,
				$row['impressions'],
				$row['clicks'],
				round( (float) $row['ctr'] * 100, 2 ),
				round( (float) $row['avg_position'], 1 ),
				$position_change,
				$row['last_synced'] ?? '',
			);
		}

		// Convert to CSV string.
		$csv_output = '';
		foreach ( $csv_lines as $line ) {
			$csv_output .= implode( ',', array_map( function( $field ) {
				$field = $this->sanitize_csv_field( $field );
				$field = str_replace( '"', '""', $field );
				return '"' . $field . '"';
			}, $line ) ) . "\n";
		}

		$response_time = ( microtime( true ) - $start_time ) * 1000;
		$this->log_request( '/mcp/v1/gsc/export', 'GET', 200, $response_time, sprintf( 'Exported %d pages to CSV', count( $results ) ) );

		return rest_ensure_response( array(
			'csv'      => $csv_output,
			'filename' => 'gsc-export-' . gmdate( 'Y-m-d' ) . '.csv',
			'count'    => count( $results ),
		) );
	}

	/**
	 * Sanitize CSV fields to reduce formula injection risk.
	 *
	 * @since    1.0.0
	 * @param    mixed    $value    Field value.
	 * @return   string             Sanitized field value.
	 */
	private function sanitize_csv_field( $value ) {
		$string = (string) $value;
		if ( '' === $string ) {
			return $string;
		}

		$trimmed = ltrim( $string );
		if ( '' !== $trimmed && preg_match( '/^[=+\-@]/', $trimmed ) ) {
			return "'" . $string;
		}

		return $string;
	}

	/**
	 * Match a URL to a WordPress post.
	 *
	 * @since    1.0.0
	 * @param    string    $url    The URL to match.
	 * @return   int|null          Post ID or null if not found.
	 */
	public static function match_url_to_post( $url ) {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			return $post_id;
		}

		// Try without trailing slash.
		$url_no_slash = rtrim( $url, '/' );
		$post_id = url_to_postid( $url_no_slash );

		if ( $post_id ) {
			return $post_id;
		}

		// Try with trailing slash.
		$url_with_slash = trailingslashit( $url );
		$post_id = url_to_postid( $url_with_slash );

		return $post_id ?: null;
	}

	/**
	 * Schedule WP-Cron events.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function schedule_cron() {
		$this->sync->schedule_sync();
	}

	/**
	 * Unschedule WP-Cron events.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function unschedule_cron() {
		$this->sync->unschedule_sync();
	}

	/**
	 * Delegate scheduled sync to the sync handler.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function run_scheduled_sync() {
		$this->sync->run_scheduled_sync();
	}

	/**
	 * Delegate manual sync trigger to the sync handler.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function run_manual_sync() {
		$this->sync->run_scheduled_sync();
	}

	/**
	 * Log an API request if logger is available.
	 *
	 * @since    1.0.0
	 * @param    string    $endpoint       The API endpoint.
	 * @param    string    $method         HTTP method.
	 * @param    int       $status_code    HTTP status code.
	 * @param    float     $response_time  Response time in milliseconds.
	 * @param    string    $description    Optional description of the action.
	 * @return   void
	 */
	private function log_request( $endpoint, $method, $status_code, $response_time, $description = null ) {
		if ( $this->logger ) {
			$this->logger->log_request( $endpoint, $method, $status_code, $response_time, $description );
		}
	}
}
