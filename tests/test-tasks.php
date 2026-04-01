<?php
/**
 * Tasks endpoint tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Tasks_Test extends WP_UnitTestCase {

    protected static $editor_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        do_action( 'init' );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_tasks_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/tasks', $routes );
        $this->assertArrayHasKey( '/mcp/v1/tasks/refresh', $routes );
    }

    public function test_create_and_list_task() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'POST', '/mcp/v1/tasks' );
        $request->set_param( 'type', 'seo_missing' );
        $request->set_param( 'title', 'Test Task' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $list_request = new WP_REST_Request( 'GET', '/mcp/v1/tasks' );
        $list_response = $this->server->dispatch( $list_request );

        $this->assertEquals( 200, $list_response->get_status() );
        $data = $list_response->get_data();
        $this->assertArrayHasKey( 'tasks', $data );
    }
}
