<?php
/**
 * Operations log tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Ops_Test extends WP_UnitTestCase {

	protected static $admin_id;
	protected static $subscriber_id;
	protected $server;
	protected $ops;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();

		$this->ops = new WP_MCP_Connect_Ops();
		WP_MCP_Connect_Ops::create_table();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;
		$table = $wpdb->prefix . WP_MCP_Connect_Ops::TABLE_NAME;
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$wp_rest_server = null;
		parent::tear_down();
	}

	// ========================================================================
	// Route registration
	// ========================================================================

	public function test_ops_list_route_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/ops', $routes );
	}

	public function test_ops_detail_route_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/ops/(?P<id>\\d+)', $routes );
	}

	public function test_ops_rollback_route_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/ops/rollback', $routes );
	}

	// ========================================================================
	// Permission checks
	// ========================================================================

	public function test_subscriber_cannot_list_ops() {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/mcp/v1/ops' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_admin_can_list_ops() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/mcp/v1/ops' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	// ========================================================================
	// log_operation + list_ops
	// ========================================================================

	public function test_log_operation_inserts_row() {
		wp_set_current_user( self::$admin_id );

		$id = WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array( 'test' => true ), array( 'old' => true ) );
		$this->assertGreaterThan( 0, $id );

		$request  = new WP_REST_Request( 'GET', '/mcp/v1/ops' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 1, $data['total'] );
		$this->assertEquals( 'seo_bulk', $data['ops'][0]['op_type'] );
	}

	public function test_list_ops_filter_by_type() {
		wp_set_current_user( self::$admin_id );

		WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array(), array() );
		WP_MCP_Connect_Ops::log_operation( 'custom_css', array(), array() );

		$request = new WP_REST_Request( 'GET', '/mcp/v1/ops' );
		$request->set_param( 'op_type', 'seo_bulk' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 1, $data['total'] );
		$this->assertEquals( 'seo_bulk', $data['ops'][0]['op_type'] );
	}

	public function test_list_ops_pagination() {
		wp_set_current_user( self::$admin_id );

		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array( 'i' => $i ), array() );
		}

		$request = new WP_REST_Request( 'GET', '/mcp/v1/ops' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 5, $data['total'] );
		$this->assertCount( 2, $data['ops'] );
		$this->assertEquals( 3, $data['total_pages'] );
	}

	// ========================================================================
	// get_op
	// ========================================================================

	public function test_get_op_returns_detail() {
		wp_set_current_user( self::$admin_id );

		$id = WP_MCP_Connect_Ops::log_operation( 'custom_css', array( 'css' => 'body{}' ), array( 'css' => 'old{}' ) );

		$request  = new WP_REST_Request( 'GET', "/mcp/v1/ops/{$id}" );
		$request->set_url_params( array( 'id' => (string) $id ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'payload', $data );
		$this->assertArrayHasKey( 'previous_state', $data );
		$this->assertEquals( 'body{}', $data['payload']['css'] );
	}

	public function test_get_op_not_found() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp/v1/ops/99999' );
		$request->set_url_params( array( 'id' => '99999' ) );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================================================
	// rollback_op
	// ========================================================================

	public function test_rollback_seo_bulk() {
		wp_set_current_user( self::$admin_id );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_cwp_seo_title', 'New Title' );

		$previous_state = array(
			array( 'post_id' => $post_id, 'seo_title' => 'Old Title' ),
		);

		$op_id = WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array(), $previous_state );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/ops/rollback' );
		$request->set_param( 'id', $op_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Old Title', get_post_meta( $post_id, '_cwp_seo_title', true ) );
	}

	public function test_rollback_custom_css() {
		wp_set_current_user( self::$admin_id );

		wp_update_custom_css_post( 'body { color: red; }' );

		$previous_state = array( 'css' => 'body { color: blue; }' );
		$op_id = WP_MCP_Connect_Ops::log_operation( 'custom_css', array(), $previous_state );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/ops/rollback' );
		$request->set_param( 'id', $op_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
	}

	public function test_rollback_invalid_type_fails() {
		wp_set_current_user( self::$admin_id );

		$op_id = WP_MCP_Connect_Ops::log_operation( 'unknown_type', array(), array() );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/ops/rollback' );
		$request->set_param( 'id', $op_id );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_rollback_missing_op_fails() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/ops/rollback' );
		$request->set_param( 'id', 99999 );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_rollback_seo_bulk_with_empty_previous_state_fails() {
		wp_set_current_user( self::$admin_id );

		$op_id = WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array(), null );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/ops/rollback' );
		$request->set_param( 'id', $op_id );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}
}
