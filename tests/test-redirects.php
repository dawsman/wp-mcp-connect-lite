<?php
/**
 * Redirects functionality tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Redirects_Test extends WP_UnitTestCase {

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
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_redirect_cpt_registered() {
        $this->assertTrue( post_type_exists( 'cwp_redirect' ) );
    }

    public function test_redirect_cpt_supports_rest() {
        $post_type = get_post_type_object( 'cwp_redirect' );
        $this->assertTrue( $post_type->show_in_rest );
        $this->assertEquals( 'redirects', $post_type->rest_base );
    }

    public function test_create_redirect_via_rest() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/redirects' );
        $request->set_body_params( array(
            'title'       => 'Test Redirect',
            'status'      => 'publish',
            'from_url'    => '/old-page',
            'to_url'      => '/new-page',
            'status_code' => '301',
            'enabled'     => true,
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 201, $response->get_status() );

        $data = $response->get_data();
        $this->assertEquals( '/old-page', $data['from_url'] );
        $this->assertEquals( '/new-page', $data['to_url'] );
    }

    public function test_from_url_normalized_with_leading_slash() {
        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
        ) );

        $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
        $post = get_post( $redirect_id );

        $redirects->update_meta_field( 'old-page', $post, 'from_url' );

        $stored = get_post_meta( $redirect_id, '_cwp_from_url', true );
        $this->assertStringStartsWith( '/', $stored );
    }

    public function test_to_url_sanitized() {
        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
        ) );

        $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
        $post = get_post( $redirect_id );

        $redirects->update_meta_field( '/page', $post, 'to_url' );

        $stored = get_post_meta( $redirect_id, '_cwp_to_url', true );
        $this->assertEquals( '/page', $stored );
    }

    public function test_invalid_status_code_defaults_to_301() {
        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
        ) );

        $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
        $post = get_post( $redirect_id );

        $redirects->update_meta_field( '999', $post, 'status_code' );

        $stored = get_post_meta( $redirect_id, '_cwp_status_code', true );
        $this->assertEquals( 301, intval( $stored ) );
    }

    public function test_valid_status_codes_accepted() {
        $valid_codes = array( 301, 302, 307, 308 );

        foreach ( $valid_codes as $code ) {
            $redirect_id = $this->factory->post->create( array(
                'post_type'   => 'cwp_redirect',
                'post_status' => 'publish',
            ) );

            $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
            $post = get_post( $redirect_id );

            $redirects->update_meta_field( strval( $code ), $post, 'status_code' );

            $stored = get_post_meta( $redirect_id, '_cwp_status_code', true );
            $this->assertEquals( $code, intval( $stored ), "Status code $code should be accepted" );
        }
    }

    public function test_redirect_cache_built_on_save() {
        delete_option( 'cwp_redirect_rules' );

        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
            'post_title'  => 'Test Redirect',
        ) );

        update_post_meta( $redirect_id, '_cwp_from_url', '/test-path' );
        update_post_meta( $redirect_id, '_cwp_to_url', 'https://example.com/destination' );
        update_post_meta( $redirect_id, '_cwp_status_code', '301' );

        $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
        $redirects->update_redirect_cache();

        $rules = get_option( 'cwp_redirect_rules' );
        $this->assertIsArray( $rules );
        $this->assertArrayHasKey( '/test-path', $rules );
        $this->assertEquals( 'https://example.com/destination', $rules['/test-path']['to'] );
        $this->assertEquals( 301, $rules['/test-path']['code'] );
    }

    public function test_cache_contains_only_published_redirects() {
        $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
            'meta_input'  => array(
                '_cwp_from_url' => '/published',
                '_cwp_to_url'   => 'https://example.com/pub',
            ),
        ) );

        $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'draft',
            'meta_input'  => array(
                '_cwp_from_url' => '/draft',
                '_cwp_to_url'   => 'https://example.com/draft',
            ),
        ) );

        $redirects = new WP_MCP_Connect_Redirects( 'wp-mcp-connect', '1.0.0' );
        $redirects->update_redirect_cache();

        $rules = get_option( 'cwp_redirect_rules' );
        $this->assertArrayHasKey( '/published', $rules );
        $this->assertArrayNotHasKey( '/draft', $rules );
    }

    public function test_list_redirects_via_rest() {
        $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
            'post_title'  => 'Redirect 1',
            'meta_input'  => array(
                '_cwp_from_url' => '/path1',
                '_cwp_to_url'   => 'https://example.com/dest1',
            ),
        ) );

        $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
            'post_title'  => 'Redirect 2',
            'meta_input'  => array(
                '_cwp_from_url' => '/path2',
                '_cwp_to_url'   => 'https://example.com/dest2',
            ),
        ) );

        $request = new WP_REST_Request( 'GET', '/wp/v2/redirects' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertGreaterThanOrEqual( 2, count( $data ) );
    }

    public function test_delete_redirect_via_rest() {
        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
        ) );

        $request = new WP_REST_Request( 'DELETE', '/wp/v2/redirects/' . $redirect_id );
        $request->set_param( 'force', true );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $post = get_post( $redirect_id );
        $this->assertNull( $post );
    }

    public function test_update_redirect_via_rest() {
        $redirect_id = $this->factory->post->create( array(
            'post_type'   => 'cwp_redirect',
            'post_status' => 'publish',
            'meta_input'  => array(
                '_cwp_from_url'    => '/original',
                '_cwp_to_url'      => 'https://example.com/original',
                '_cwp_status_code' => '301',
            ),
        ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/redirects/' . $redirect_id );
        $request->set_body_params( array(
            'from_url'    => '/updated-path',
            'to_url'      => '/updated',
            'status_code' => '302',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertEquals( '/updated-path', $data['from_url'] );
        $this->assertEquals( '/updated', $data['to_url'] );
    }
}
