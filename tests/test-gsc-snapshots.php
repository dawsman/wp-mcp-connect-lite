<?php
/**
 * GSC snapshot and analysis tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_GSC_Snapshots_Test extends WP_UnitTestCase {

	protected static $admin_id;
	protected $server;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::$admin_id );

		// Ensure tables exist.
		WP_MCP_Connect_GSC::create_tables();
	}

	public function tear_down() {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		// Clean up snapshot tables.
		$tables = array(
			WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS,
			WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS,
			WP_MCP_Connect_GSC::TABLE_CTR_CURVE,
			WP_MCP_Connect_GSC::TABLE_DATA,
			WP_MCP_Connect_GSC::TABLE_QUERIES,
		);
		foreach ( $tables as $table ) {
			$full_name = WP_MCP_Connect_GSC::get_table_name( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE $full_name" );
		}

		parent::tear_down();
	}

	/**
	 * Test that new table constants are defined.
	 */
	public function test_snapshot_table_constants_exist() {
		$this->assertEquals( 'cwp_gsc_data_snapshots', WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS );
		$this->assertEquals( 'cwp_gsc_query_snapshots', WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );
		$this->assertEquals( 'cwp_gsc_ctr_curve', WP_MCP_Connect_GSC::TABLE_CTR_CURVE );
	}

	/**
	 * Test that snapshot tables are created by create_tables().
	 */
	public function test_snapshot_tables_created() {
		global $wpdb;

		$tables = array(
			WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS,
			WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS,
			WP_MCP_Connect_GSC::TABLE_CTR_CURVE,
		);

		foreach ( $tables as $table ) {
			$full_name = WP_MCP_Connect_GSC::get_table_name( $table );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) );
			$this->assertNotNull( $exists, "Table $full_name should exist" );
		}
	}

	/**
	 * Test snapshot idempotency — REPLACE INTO should not create duplicates.
	 */
	public function test_snapshot_idempotency() {
		global $wpdb;

		$table = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA_SNAPSHOTS );
		$url_hash = md5( 'https://example.com/test-page' );
		$date = '2024-01-15';

		// Insert first snapshot.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"REPLACE INTO $table (url_hash, snapshot_date, impressions, clicks, ctr, avg_position)
			VALUES (%s, %s, %d, %d, %f, %f)",
			$url_hash, $date, 100, 10, 0.1, 5.5
		) );

		$count1 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE url_hash = %s AND snapshot_date = %s",
			$url_hash, $date
		) );
		$this->assertEquals( 1, $count1 );

		// Replace with updated data — should NOT create a second row.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"REPLACE INTO $table (url_hash, snapshot_date, impressions, clicks, ctr, avg_position)
			VALUES (%s, %s, %d, %d, %f, %f)",
			$url_hash, $date, 200, 20, 0.1, 4.5
		) );

		$count2 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE url_hash = %s AND snapshot_date = %s",
			$url_hash, $date
		) );
		$this->assertEquals( 1, $count2, 'REPLACE should update, not insert duplicate' );

		// Verify updated values.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT impressions, clicks FROM $table WHERE url_hash = %s AND snapshot_date = %s",
			$url_hash, $date
		), ARRAY_A );

		$this->assertEquals( 200, (int) $row['impressions'] );
		$this->assertEquals( 20, (int) $row['clicks'] );
	}

	/**
	 * Test query snapshot idempotency.
	 */
	public function test_query_snapshot_idempotency() {
		global $wpdb;

		$table = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );
		$url_hash = md5( 'https://example.com/test-page' );
		$query = 'test keyword';
		$query_hash = md5( $query );
		$date = '2024-01-15';

		// Insert first.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"REPLACE INTO $table (url_hash, query, query_hash, snapshot_date, impressions, clicks, ctr, position)
			VALUES (%s, %s, %s, %s, %d, %d, %f, %f)",
			$url_hash, $query, $query_hash, $date, 50, 5, 0.1, 8.0
		) );

		// Replace with updated data.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"REPLACE INTO $table (url_hash, query, query_hash, snapshot_date, impressions, clicks, ctr, position)
			VALUES (%s, %s, %s, %s, %d, %d, %f, %f)",
			$url_hash, $query, $query_hash, $date, 100, 15, 0.15, 6.0
		) );

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE url_hash = %s AND query_hash = %s AND snapshot_date = %s",
			$url_hash, $query_hash, $date
		) );
		$this->assertEquals( 1, $count, 'Query snapshot REPLACE should be idempotent' );
	}

	/**
	 * Test CTR curve computation logic (position bands).
	 */
	public function test_position_to_band_logic() {
		// Test the band classification logic directly.
		$bands = array(
			1.0  => '1',
			2.0  => '2',
			3.0  => '3',
			4.0  => '4-5',
			5.0  => '4-5',
			6.0  => '6-10',
			10.0 => '6-10',
			11.0 => '11-20',
			20.0 => '11-20',
			21.0 => '21+',
			50.0 => '21+',
		);

		foreach ( $bands as $position => $expected_band ) {
			$pos = round( $position );
			if ( $pos <= 3 ) {
				$band = (string) $pos;
			} elseif ( $pos <= 5 ) {
				$band = '4-5';
			} elseif ( $pos <= 10 ) {
				$band = '6-10';
			} elseif ( $pos <= 20 ) {
				$band = '11-20';
			} else {
				$band = '21+';
			}

			$this->assertEquals( $expected_band, $band, "Position $position should map to band $expected_band" );
		}
	}

	/**
	 * Test trends endpoint registration.
	 */
	public function test_trends_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/gsc/trends', $routes );
	}

	/**
	 * Test cannibalization endpoint registration.
	 */
	public function test_cannibalization_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/gsc/cannibalization', $routes );
	}

	/**
	 * Test content-gaps endpoint registration.
	 */
	public function test_content_gaps_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/gsc/content-gaps', $routes );
	}

	/**
	 * Test ctr-curve endpoint registration.
	 */
	public function test_ctr_curve_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/gsc/ctr-curve', $routes );
	}

	/**
	 * Test page history endpoint registration.
	 */
	public function test_page_history_endpoint_registered() {
		$routes = $this->server->get_routes();
		// Check that a route matching /gsc/pages/{id}/history exists.
		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( preg_match( '#/mcp/v1/gsc/pages/.*history#', $route ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Page history endpoint should be registered' );
	}

	/**
	 * Test insights enum includes new types.
	 */
	public function test_insights_enum_includes_new_types() {
		$routes = $this->server->get_routes();
		$insights_route = $routes['/mcp/v1/gsc/insights'] ?? null;
		$this->assertNotNull( $insights_route );

		// Check the enum of the type argument.
		$args = $insights_route[0]['args'] ?? array();
		$type_enum = $args['type']['enum'] ?? array();

		$this->assertContains( 'cannibalization', $type_enum );
		$this->assertContains( 'content_gap', $type_enum );
		$this->assertContains( 'ctr_underperformer', $type_enum );
	}

	/**
	 * Test trends endpoint returns expected structure with empty data.
	 */
	public function test_trends_endpoint_empty_response() {
		$request = new WP_REST_Request( 'GET', '/mcp/v1/gsc/trends' );
		$request->set_param( 'days', 28 );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'days', $data );
		$this->assertArrayHasKey( 'series', $data );
		$this->assertEquals( 28, $data['days'] );
		$this->assertIsArray( $data['series'] );
	}

	/**
	 * Test CTR curve endpoint returns expected structure.
	 */
	public function test_ctr_curve_endpoint_structure() {
		$request = new WP_REST_Request( 'GET', '/mcp/v1/gsc/ctr-curve' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'curve', $data );
		$this->assertArrayHasKey( 'computed_date', $data );
		$this->assertArrayHasKey( 'page_comparison', $data );
		$this->assertIsArray( $data['curve'] );
	}

	/**
	 * Test cannibalization endpoint returns expected structure with empty data.
	 */
	public function test_cannibalization_endpoint_empty_response() {
		$request = new WP_REST_Request( 'GET', '/mcp/v1/gsc/cannibalization' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'queries', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertIsArray( $data['queries'] );
		$this->assertEquals( 0, $data['total'] );
	}

	/**
	 * Test content gaps endpoint returns expected structure with empty data.
	 */
	public function test_content_gaps_endpoint_empty_response() {
		$request = new WP_REST_Request( 'GET', '/mcp/v1/gsc/content-gaps' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'gaps', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertIsArray( $data['gaps'] );
		$this->assertEquals( 0, $data['total'] );
	}
}
