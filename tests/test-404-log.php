<?php
/**
 * 404 log endpoint tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_404_Log_Test extends WP_UnitTestCase {

    protected static $admin_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
    }

    public function set_up() {
        parent::set_up();

        if ( class_exists( 'WP_MCP_Connect_404_Log' ) ) {
            WP_MCP_Connect_404_Log::create_table();
        }

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_404_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/404', $routes );
    }

    public function test_404_list_requires_admin() {
        wp_set_current_user( self::$admin_id );
        $request = new WP_REST_Request( 'GET', '/mcp/v1/404' );
        $response = $this->server->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );
    }
}
