<?php
/**
 * Media functionality tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Media_Test extends WP_UnitTestCase {

    protected static $editor_id;
    protected static $subscriber_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
        self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::$editor_id );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    protected function create_test_attachment( $with_alt = false ) {
        $attachment_id = $this->factory->attachment->create( array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Test Image',
            'post_status'    => 'inherit',
        ) );

        if ( $with_alt ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Test alt text' );
        }

        return $attachment_id;
    }

    public function test_missing_alt_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/media/missing-alt', $routes );
    }

    public function test_missing_alt_requires_editor() {
        wp_set_current_user( self::$subscriber_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_missing_alt_returns_images_without_alt() {
        $without_alt = $this->create_test_attachment( false );
        $with_alt = $this->create_test_attachment( true );

        delete_transient( 'cwp_missing_alt_images_1_20' );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'results', $data );
        $this->assertArrayHasKey( 'total', $data );
        $this->assertArrayHasKey( 'total_pages', $data );

        $ids = array_column( $data['results'], 'id' );
        $this->assertContains( $without_alt, $ids );
        $this->assertNotContains( $with_alt, $ids );
    }

    public function test_missing_alt_pagination() {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->create_test_attachment( false );
        }

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 2 );
        $request->set_param( 'page', 1 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertLessThanOrEqual( 2, count( $data['results'] ) );
        $this->assertEquals( 1, $data['page'] );
        $this->assertEquals( 2, $data['per_page'] );
    }

    public function test_per_page_max_limit() {
        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 500 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertLessThanOrEqual( 100, $data['per_page'] );
    }

    public function test_per_page_min_limit() {
        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 0 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertGreaterThanOrEqual( 1, $data['per_page'] );
    }

    public function test_results_include_required_fields() {
        $attachment_id = $this->create_test_attachment( false );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();

        if ( count( $data['results'] ) > 0 ) {
            $result = $data['results'][0];
            $this->assertArrayHasKey( 'id', $result );
            $this->assertArrayHasKey( 'title', $result );
            $this->assertArrayHasKey( 'url', $result );
            $this->assertArrayHasKey( 'filename', $result );
            $this->assertArrayHasKey( 'uploaded_at', $result );
            $this->assertArrayHasKey( 'resolution', $result );
        }
    }

    public function test_refresh_parameter_bypasses_cache() {
        $this->create_test_attachment( false );

        $cache_key = 'cwp_missing_alt_images_1_20';
        set_transient( $cache_key, array( 'results' => array(), 'total' => 0 ), 300 );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'refresh', false );
        $response = $this->server->dispatch( $request );
        $cached_data = $response->get_data();

        $this->assertEquals( 0, $cached_data['total'] );

        $request_refresh = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request_refresh->set_param( 'refresh', true );
        $response_refresh = $this->server->dispatch( $request_refresh );
        $refreshed_data = $response_refresh->get_data();

        $this->assertGreaterThan( 0, $refreshed_data['total'] );
    }

    public function test_cache_invalidation() {
        $attachment_id = $this->create_test_attachment( false );

        $cache_key = 'cwp_missing_alt_images_1_20';
        set_transient( $cache_key, array( 'results' => array( array( 'id' => 999 ) ), 'total' => 1 ), 300 );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache( $attachment_id );

        $cached = get_transient( $cache_key );
        $this->assertFalse( $cached );
    }

    public function test_empty_alt_treated_as_missing() {
        $attachment_id = $this->factory->attachment->create( array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Empty Alt Image',
            'post_status'    => 'inherit',
        ) );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $ids = array_column( $data['results'], 'id' );
        $this->assertContains( $attachment_id, $ids );
    }

    public function test_only_images_returned() {
        $pdf_attachment = $this->factory->attachment->create( array(
            'post_mime_type' => 'application/pdf',
            'post_title'     => 'PDF File',
            'post_status'    => 'inherit',
        ) );

        $image_attachment = $this->create_test_attachment( false );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $ids = array_column( $data['results'], 'id' );

        $this->assertNotContains( $pdf_attachment, $ids );
        $this->assertContains( $image_attachment, $ids );
    }
}
