<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console data synchronization handler.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_GSC_Sync {

	/**
	 * WP-Cron hook name.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const CRON_HOOK = 'cwp_gsc_scheduled_sync';

	/**
	 * Daily URL inspection limit.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const INSPECTION_DAILY_LIMIT = 2000;

	/**
	 * API handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_API
	 */
	private $api;

	/**
	 * Auth handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_Auth
	 */
	private $auth;

	/**
	 * Initialize the class.
	 *
	 * @since    1.0.0
	 * @param    WP_MCP_Connect_GSC_API     $api     API handler instance.
	 * @param    WP_MCP_Connect_GSC_Auth    $auth    Auth handler instance.
	 */
	public function __construct( WP_MCP_Connect_GSC_API $api, WP_MCP_Connect_GSC_Auth $auth ) {
		$this->api = $api;
		$this->auth = $auth;
	}

	/**
	 * Ensure database tables exist before operations.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function ensure_tables_exist() {
		global $wpdb;

		$table_name = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$table_name
		) );

		if ( ! $table_exists ) {
			WP_MCP_Connect_GSC::create_tables();
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/gsc/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'trigger_sync' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'type' => array(
					'type'    => 'string',
					'default' => 'full',
					'enum'    => array( 'full', 'analytics', 'inspection' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/sync/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_sync_status' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/sync/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_sync_history' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 10,
					'maximum' => 50,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/sync/inspect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'inspect_single_url' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'url' => array(
					'required' => true,
					'type'     => 'string',
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
	 * Trigger a manual sync.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function trigger_sync( $request ) {
		// Ensure database tables exist before syncing.
		$this->ensure_tables_exist();

		if ( ! $this->auth->is_connected() ) {
			return new WP_Error(
				'not_connected',
				'Not connected to Google Search Console.',
				array( 'status' => 400 )
			);
		}

		$site_url = get_option( 'cwp_gsc_site_url', '' );
		if ( empty( $site_url ) ) {
			return new WP_Error(
				'no_site',
				'No Search Console site selected.',
				array( 'status' => 400 )
			);
		}

		// Instead of running sync inline, schedule it.
		if ( ! wp_next_scheduled( 'cwp_gsc_manual_sync' ) ) {
			wp_schedule_single_event( time(), 'cwp_gsc_manual_sync' );
		}

		return rest_ensure_response( array(
			'status'  => 'scheduled',
			'message' => 'Sync has been scheduled and will run in the background.',
		) );
	}

	/**
	 * Sync search analytics data.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url    The Search Console site URL.
	 * @return   array|WP_Error         Result or error.
	 */
	public function sync_search_analytics( $site_url ) {
		global $wpdb;

		// Calculate date ranges.
		// Current period: last 28 days (GSC data has ~3 day delay).
		$end_date = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-31 days' ) );

		// Previous period for trend comparison.
		$prev_end_date = gmdate( 'Y-m-d', strtotime( '-32 days' ) );
		$prev_start_date = gmdate( 'Y-m-d', strtotime( '-60 days' ) );

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$table_queries = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERIES );

		$pages_processed = 0;
		$start_row = 0;
		$page_data = array();

		// Fetch current period data.
		do {
			$response = $this->api->query_search_analytics( $site_url, $start_date, $end_date, array( 'page' ), 5000, $start_row );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['rows'] ) ) {
				break;
			}

			foreach ( $response['rows'] as $row ) {
				$url = $row['keys'][0];
				$page_data[ $url ] = array(
					'impressions' => $row['impressions'],
					'clicks'      => $row['clicks'],
					'ctr'         => $row['ctr'],
					'position'    => $row['position'],
				);
			}

			$start_row += 5000;

		} while ( count( $response['rows'] ) === 5000 );

		// Fetch previous period data for trends.
		$prev_data = array();
		$start_row = 0;

		do {
			$response = $this->api->query_search_analytics( $site_url, $prev_start_date, $prev_end_date, array( 'page' ), 5000, $start_row );

			if ( is_wp_error( $response ) ) {
				break; // Don't fail entire sync for trend data.
			}

			if ( empty( $response['rows'] ) ) {
				break;
			}

			foreach ( $response['rows'] as $row ) {
				$url = $row['keys'][0];
				$prev_data[ $url ] = array(
					'impressions' => $row['impressions'],
					'clicks'      => $row['clicks'],
					'position'    => $row['position'],
				);
			}

			$start_row += 5000;

		} while ( count( $response['rows'] ) === 5000 );

		// Fetch page + query data for top queries.
		$query_data = array();
		$start_row = 0;

		do {
			$response = $this->api->get_page_query_analytics( $site_url, $start_date, $end_date, 10000, $start_row );

			if ( is_wp_error( $response ) ) {
				break;
			}

			if ( empty( $response['rows'] ) ) {
				break;
			}

			foreach ( $response['rows'] as $row ) {
				$url = $row['keys'][0];
				$query = $row['keys'][1];

				if ( ! isset( $query_data[ $url ] ) ) {
					$query_data[ $url ] = array();
				}

				$query_data[ $url ][] = array(
					'query'       => $query,
					'impressions' => $row['impressions'],
					'clicks'      => $row['clicks'],
					'ctr'         => $row['ctr'],
					'position'    => $row['position'],
				);
			}

			$start_row += 10000;

		} while ( count( $response['rows'] ) === 10000 );

		// Save to database.
		$now = current_time( 'mysql', true );

		foreach ( $page_data as $url => $data ) {
			$url_hash = md5( $url );
			$post_id = WP_MCP_Connect_GSC::match_url_to_post( $url );

			// Get top query for this page.
			$top_query = null;
			$top_query_impressions = 0;
			$top_query_clicks = 0;
			$top_query_position = 0;

			if ( ! empty( $query_data[ $url ] ) ) {
				// Sort by impressions.
				usort( $query_data[ $url ], function( $a, $b ) {
					return $b['impressions'] - $a['impressions'];
				} );

				$top = $query_data[ $url ][0];
				$top_query = $top['query'];
				$top_query_impressions = $top['impressions'];
				$top_query_clicks = $top['clicks'];
				$top_query_position = $top['position'];
			}

			// Get previous period data.
			$prev_impressions = $prev_data[ $url ]['impressions'] ?? 0;
			$prev_clicks = $prev_data[ $url ]['clicks'] ?? 0;
			$prev_position = $prev_data[ $url ]['position'] ?? 0;

			// Check if record exists.
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_data WHERE url_hash = %s",
				$url_hash
			) );

			if ( $existing_id ) {
				// Update existing record.
				$wpdb->update(
					$table_data,
					array(
						'post_id'               => $post_id,
						'impressions'           => $data['impressions'],
						'clicks'                => $data['clicks'],
						'ctr'                   => $data['ctr'],
						'avg_position'          => $data['position'],
						'top_query'             => $top_query,
						'top_query_impressions' => $top_query_impressions,
						'top_query_clicks'      => $top_query_clicks,
						'top_query_position'    => $top_query_position,
						'prev_impressions'      => $prev_impressions,
						'prev_clicks'           => $prev_clicks,
						'prev_position'         => $prev_position,
						'data_date'             => $end_date,
						'last_synced'           => $now,
					),
					array( 'id' => $existing_id ),
					array( '%d', '%d', '%d', '%f', '%f', '%s', '%d', '%d', '%f', '%d', '%d', '%f', '%s', '%s' ),
					array( '%d' )
				);

				$gsc_data_id = $existing_id;
			} else {
				// Insert new record.
				$wpdb->insert(
					$table_data,
					array(
						'url'                   => $url,
						'url_hash'              => $url_hash,
						'post_id'               => $post_id,
						'impressions'           => $data['impressions'],
						'clicks'                => $data['clicks'],
						'ctr'                   => $data['ctr'],
						'avg_position'          => $data['position'],
						'top_query'             => $top_query,
						'top_query_impressions' => $top_query_impressions,
						'top_query_clicks'      => $top_query_clicks,
						'top_query_position'    => $top_query_position,
						'prev_impressions'      => $prev_impressions,
						'prev_clicks'           => $prev_clicks,
						'prev_position'         => $prev_position,
						'data_date'             => $end_date,
						'last_synced'           => $now,
					),
					array( '%s', '%s', '%d', '%d', '%d', '%f', '%f', '%s', '%d', '%d', '%f', '%d', '%d', '%f', '%s', '%s' )
				);

				$gsc_data_id = $wpdb->insert_id;
			}

			// Save queries for this page.
			if ( $gsc_data_id && ! empty( $query_data[ $url ] ) ) {
				// Delete old queries.
				$wpdb->delete( $table_queries, array( 'gsc_data_id' => $gsc_data_id ), array( '%d' ) );

				// Insert top 50 queries.
				$queries_to_save = array_slice( $query_data[ $url ], 0, 50 );
				foreach ( $queries_to_save as $q ) {
					$wpdb->insert(
						$table_queries,
						array(
							'gsc_data_id' => $gsc_data_id,
							'query'       => $q['query'],
							'impressions' => $q['impressions'],
							'clicks'      => $q['clicks'],
							'ctr'         => $q['ctr'],
							'position'    => $q['position'],
							'data_date'   => $end_date,
						),
						array( '%d', '%s', '%d', '%d', '%f', '%f', '%s' )
					);
				}
			}

			// Save page-level snapshot (idempotent via REPLACE).
			$this->save_data_snapshot( $url_hash, $end_date, $data );

			// Save query-level snapshots.
			if ( ! empty( $query_data[ $url ] ) ) {
				$this->save_query_snapshots( $url_hash, $end_date, $query_data[ $url ] );
			}

			$pages_processed++;
		}

		// Compute site-wide CTR curve from current data.
		$this->compute_ctr_curve();

		// Run one-time migration of existing data into snapshot tables.
		$this->maybe_migrate_existing_to_snapshots();

		return array(
			'pages_processed' => $pages_processed,
		);
	}

	/**
	 * Sync URL inspection data with traffic-based prioritization.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url    The Search Console site URL.
	 * @return   array|WP_Error         Result or error.
	 */
	public function sync_url_inspections( $site_url ) {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		// Get count of inspections done today.
		$today_count = (int) get_transient( 'cwp_gsc_inspection_count_' . gmdate( 'Y-m-d' ) );

		if ( $today_count >= self::INSPECTION_DAILY_LIMIT ) {
			return array(
				'inspected' => 0,
				'message'   => 'Daily inspection limit reached.',
			);
		}

		$remaining = self::INSPECTION_DAILY_LIMIT - $today_count;
		$stale_threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$inspected = 0;

		// Priority 1: High-traffic pages (top 20% by impressions) not inspected recently.
		$high_traffic = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, url FROM $table_data
			WHERE impressions > 0
			AND (last_inspected IS NULL OR last_inspected < %s)
			ORDER BY impressions DESC
			LIMIT %d",
			$stale_threshold,
			min( $remaining, 100 )
		), ARRAY_A );

		foreach ( $high_traffic as $page ) {
			if ( $inspected >= $remaining ) {
				break;
			}

			$result = $this->inspect_and_save( $site_url, $page['url'], $page['id'] );
			if ( ! is_wp_error( $result ) ) {
				$inspected++;
			}
		}

		// Priority 2: New content (recently synced but never inspected).
		if ( $inspected < $remaining ) {
			$new_content = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, url FROM $table_data
				WHERE last_inspected IS NULL
				ORDER BY last_synced DESC
				LIMIT %d",
				min( $remaining - $inspected, 50 )
			), ARRAY_A );

			foreach ( $new_content as $page ) {
				if ( $inspected >= $remaining ) {
					break;
				}

				$result = $this->inspect_and_save( $site_url, $page['url'], $page['id'] );
				if ( ! is_wp_error( $result ) ) {
					$inspected++;
				}
			}
		}

		// Priority 3: Pages with indexing issues.
		if ( $inspected < $remaining ) {
			$issues = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, url FROM $table_data
				WHERE is_indexed = 0
				AND (last_inspected IS NULL OR last_inspected < %s)
				ORDER BY impressions DESC
				LIMIT %d",
				$stale_threshold,
				min( $remaining - $inspected, 50 )
			), ARRAY_A );

			foreach ( $issues as $page ) {
				if ( $inspected >= $remaining ) {
					break;
				}

				$result = $this->inspect_and_save( $site_url, $page['url'], $page['id'] );
				if ( ! is_wp_error( $result ) ) {
					$inspected++;
				}
			}
		}

		// Update daily count.
		set_transient( 'cwp_gsc_inspection_count_' . gmdate( 'Y-m-d' ), $today_count + $inspected, DAY_IN_SECONDS );

		return array(
			'inspected' => $inspected,
			'remaining' => $remaining - $inspected,
		);
	}

	/**
	 * Inspect a URL and save the result.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url    The Search Console site URL.
	 * @param    string    $page_url    The URL to inspect.
	 * @param    int       $record_id   The database record ID.
	 * @return   bool|WP_Error          True on success, error on failure.
	 */
	private function inspect_and_save( $site_url, $page_url, $record_id ) {
		global $wpdb;

		$response = $this->api->inspect_url( $site_url, $page_url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->api->parse_inspection_result( $response );
		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$wpdb->update(
			$table_data,
			array(
				'is_indexed'       => $parsed['is_indexed'] ? 1 : 0,
				'index_status'     => $parsed['index_status'],
				'last_crawl_time'  => $parsed['last_crawl_time'],
				'crawl_status'     => $parsed['crawl_status'],
				'robots_txt_state' => $parsed['robots_txt_state'],
				'indexing_state'   => $parsed['indexing_state'],
				'last_inspected'   => current_time( 'mysql', true ),
			),
			array( 'id' => $record_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Inspect a single URL on demand.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function inspect_single_url( $request ) {
		global $wpdb;

		$url = esc_url_raw( $request->get_param( 'url' ) );
		$site_url = get_option( 'cwp_gsc_site_url', '' );

		if ( empty( $site_url ) ) {
			return new WP_Error(
				'no_site',
				'No Search Console site selected.',
				array( 'status' => 400 )
			);
		}

		$response = $this->api->inspect_url( $site_url, $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->api->parse_inspection_result( $response );

		// Update database if record exists.
		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$url_hash = md5( $url );

		$record_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_data WHERE url_hash = %s",
			$url_hash
		) );

		if ( $record_id ) {
			$wpdb->update(
				$table_data,
				array(
					'is_indexed'       => $parsed['is_indexed'] ? 1 : 0,
					'index_status'     => $parsed['index_status'],
					'last_crawl_time'  => $parsed['last_crawl_time'],
					'crawl_status'     => $parsed['crawl_status'],
					'robots_txt_state' => $parsed['robots_txt_state'],
					'indexing_state'   => $parsed['indexing_state'],
					'last_inspected'   => current_time( 'mysql', true ),
				),
				array( 'id' => $record_id ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		return rest_ensure_response( array(
			'url'     => $url,
			'result'  => $parsed,
			'updated' => (bool) $record_id,
		) );
	}

	/**
	 * Get sync status.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response
	 */
	public function get_sync_status() {
		global $wpdb;

		$table_sync = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_SYNC_LOG );

		$running = $wpdb->get_row(
			"SELECT * FROM $table_sync WHERE status = 'running' ORDER BY started_at DESC LIMIT 1",
			ARRAY_A
		);

		$last_completed = $wpdb->get_row(
			"SELECT * FROM $table_sync WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1",
			ARRAY_A
		);

		$today_inspections = (int) get_transient( 'cwp_gsc_inspection_count_' . gmdate( 'Y-m-d' ) );

		return rest_ensure_response( array(
			'is_running'           => ! empty( $running ),
			'running_sync'         => $running,
			'last_completed'       => $last_completed,
			'last_sync_timestamp'  => get_option( 'cwp_gsc_last_sync', 0 ),
			'inspections_today'    => $today_inspections,
			'inspections_remaining' => self::INSPECTION_DAILY_LIMIT - $today_inspections,
		) );
	}

	/**
	 * Get sync history.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response
	 */
	public function get_sync_history( $request ) {
		global $wpdb;

		$limit = $request->get_param( 'limit' );
		$table_sync = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_SYNC_LOG );

		$history = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_sync ORDER BY started_at DESC LIMIT %d",
			$limit
		), ARRAY_A );

		return rest_ensure_response( array(
			'history' => $history,
		) );
	}

	/**
	 * Start a sync log entry.
	 *
	 * @since    1.0.0
	 * @param    string    $type    Sync type.
	 * @return   int                Log ID.
	 */
	private function start_sync_log( $type ) {
		global $wpdb;

		$table_sync = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_SYNC_LOG );

		$wpdb->insert(
			$table_sync,
			array(
				'sync_type'  => $type,
				'started_at' => current_time( 'mysql', true ),
				'status'     => 'running',
			),
			array( '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Complete a sync log entry.
	 *
	 * @since    1.0.0
	 * @param    int       $log_id            Log ID.
	 * @param    int       $pages_processed   Number of pages processed.
	 * @param    int       $errors_count      Number of errors.
	 * @param    string    $error_message     Error message(s).
	 * @return   void
	 */
	private function complete_sync_log( $log_id, $pages_processed, $errors_count, $error_message = '' ) {
		global $wpdb;

		$table_sync = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_SYNC_LOG );

		$wpdb->update(
			$table_sync,
			array(
				'completed_at'    => current_time( 'mysql', true ),
				'status'          => $errors_count > 0 ? 'completed_with_errors' : 'completed',
				'pages_processed' => $pages_processed,
				'errors_count'    => $errors_count,
				'error_message'   => $error_message,
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Schedule WP-Cron sync.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function schedule_sync() {
		$enabled = get_option( 'cwp_gsc_sync_enabled', false );
		$frequency = get_option( 'cwp_gsc_sync_frequency', 'daily' );

		// Clear existing schedule.
		$this->unschedule_sync();

		if ( $enabled && $this->auth->is_connected() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), $frequency, self::CRON_HOOK );
			}
		}
	}

	/**
	 * Unschedule WP-Cron sync.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function unschedule_sync() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run scheduled sync.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function run_scheduled_sync() {
		if ( ! $this->auth->is_connected() ) {
			return;
		}

		$site_url = get_option( 'cwp_gsc_site_url', '' );
		if ( empty( $site_url ) ) {
			return;
		}

		$log_id = $this->start_sync_log( 'scheduled' );

		try {
			$analytics_result = $this->sync_search_analytics( $site_url );
			$pages = is_wp_error( $analytics_result ) ? 0 : $analytics_result['pages_processed'];

			$inspection_result = $this->sync_url_inspections( $site_url );
			$inspected = is_wp_error( $inspection_result ) ? 0 : $inspection_result['inspected'];

			$errors = array();
			if ( is_wp_error( $analytics_result ) ) {
				$errors[] = $analytics_result->get_error_message();
			}
			if ( is_wp_error( $inspection_result ) ) {
				$errors[] = $inspection_result->get_error_message();
			}

			$this->complete_sync_log( $log_id, $pages + $inspected, count( $errors ), implode( '; ', $errors ) );
			update_option( 'cwp_gsc_last_sync', time() );

			// Cleanup old data.
			$retention_days = get_option( 'cwp_gsc_data_retention_days', 90 );
			$this->cleanup_old_data( $retention_days );

		} catch ( Exception $e ) {
			$this->complete_sync_log( $log_id, 0, 1, $e->getMessage() );
		}
	}

	/**
	 * Save a page-level metric snapshot (idempotent via REPLACE).
	 *
	 * @since    1.1.0
	 * @param    string    $url_hash       MD5 hash of the URL.
	 * @param    string    $snapshot_date  The date for this snapshot.
	 * @param    array     $data           Metric data (impressions, clicks, ctr, position).
	 */
	private function save_data_snapshot( $url_hash, $snapshot_date, $data ) {
		global $wpdb;

		$table = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$wpdb->query( $wpdb->prepare(
			"REPLACE INTO $table (url_hash, snapshot_date, impressions, clicks, ctr, avg_position)
			VALUES (%s, %s, %d, %d, %f, %f)",
			$url_hash,
			$snapshot_date,
			$data['impressions'],
			$data['clicks'],
			$data['ctr'],
			$data['position']
		) );
	}

	/**
	 * Save query-level metric snapshots for a page (idempotent via REPLACE).
	 *
	 * @since    1.1.0
	 * @param    string    $url_hash       MD5 hash of the URL.
	 * @param    string    $snapshot_date  The date for this snapshot.
	 * @param    array     $queries        Array of query data.
	 */
	private function save_query_snapshots( $url_hash, $snapshot_date, $queries ) {
		global $wpdb;

		$table = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );

		foreach ( $queries as $q ) {
			$query_hash = md5( $q['query'] );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
			$wpdb->query( $wpdb->prepare(
				"REPLACE INTO $table (url_hash, query, query_hash, snapshot_date, impressions, clicks, ctr, position)
				VALUES (%s, %s, %s, %s, %d, %d, %f, %f)",
				$url_hash,
				$q['query'],
				$query_hash,
				$snapshot_date,
				$q['impressions'],
				$q['clicks'],
				$q['ctr'],
				$q['position']
			) );
		}
	}

	/**
	 * Compute site-average CTR by position band from current data.
	 *
	 * @since    1.1.0
	 */
	private function compute_ctr_curve() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$table_curve = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_CTR_CURVE );
		$today = gmdate( 'Y-m-d' );

		$bands = array(
			'1'     => array( 0.5, 1.5 ),
			'2'     => array( 1.5, 2.5 ),
			'3'     => array( 2.5, 3.5 ),
			'4-5'   => array( 3.5, 5.5 ),
			'6-10'  => array( 5.5, 10.5 ),
			'11-20' => array( 10.5, 20.5 ),
			'21+'   => array( 20.5, 999 ),
		);

		foreach ( $bands as $label => $range ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT AVG(ctr) AS avg_ctr, COUNT(*) AS sample_size
				FROM $table_data
				WHERE avg_position >= %f AND avg_position < %f AND impressions > 0",
				$range[0],
				$range[1]
			), ARRAY_A );

			if ( $row && (int) $row['sample_size'] > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO $table_curve (position_band, avg_ctr, sample_size, computed_date)
					VALUES (%s, %f, %d, %s)
					ON DUPLICATE KEY UPDATE avg_ctr = VALUES(avg_ctr), sample_size = VALUES(sample_size)",
					$label,
					(float) $row['avg_ctr'],
					(int) $row['sample_size'],
					$today
				) );
			}
		}
	}

	/**
	 * One-time migration: seed snapshot tables from existing data.
	 *
	 * @since    1.1.0
	 */
	private function maybe_migrate_existing_to_snapshots() {
		if ( get_option( 'cwp_gsc_snapshot_migration_done' ) ) {
			return;
		}

		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$table_queries = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERIES );
		$table_ds = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS );
		$table_qs = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );

		// Seed page-level snapshots from existing data.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names internally controlled.
		$wpdb->query(
			"INSERT IGNORE INTO $table_ds (url_hash, snapshot_date, impressions, clicks, ctr, avg_position)
			SELECT url_hash, COALESCE(data_date, DATE(last_synced)), impressions, clicks, ctr, avg_position
			FROM $table_data
			WHERE impressions > 0 AND (data_date IS NOT NULL OR last_synced IS NOT NULL)"
		);

		// Seed query-level snapshots from existing queries.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names internally controlled.
		$wpdb->query(
			"INSERT IGNORE INTO $table_qs (url_hash, query, query_hash, snapshot_date, impressions, clicks, ctr, position)
			SELECT d.url_hash, q.query, MD5(q.query), COALESCE(q.data_date, DATE(d.last_synced)),
				q.impressions, q.clicks, q.ctr, q.position
			FROM $table_queries q
			INNER JOIN $table_data d ON d.id = q.gsc_data_id
			WHERE q.impressions > 0 AND (q.data_date IS NOT NULL OR d.last_synced IS NOT NULL)"
		);

		update_option( 'cwp_gsc_snapshot_migration_done', true );
	}

	/**
	 * Clean up old sync logs and snapshot data.
	 *
	 * @since    1.0.0
	 * @param    int    $days    Days to retain.
	 * @return   int             Number of deleted rows.
	 */
	public function cleanup_old_data( $days ) {
		global $wpdb;

		$table_sync = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_SYNC_LOG );
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_sync WHERE started_at < %s",
			$threshold
		) );

		// Prune snapshot tables using the same retention period.
		$date_threshold = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$table_ds = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_ds WHERE snapshot_date < %s",
			$date_threshold
		) );

		$table_qs = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_qs WHERE snapshot_date < %s",
			$date_threshold
		) );

		$table_curve = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_CTR_CURVE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internally controlled.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_curve WHERE computed_date < %s",
			$date_threshold
		) );

		return $deleted;
	}
}
