<?php
/**
 * SEO functionality tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_SEO_Test extends WP_UnitTestCase {

    protected static $editor_id;
    protected $server;
    protected $post_id;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::$editor_id );

        $this->post_id = $this->factory->post->create( array(
            'post_title'   => 'Test SEO Post',
            'post_content' => 'Test content',
            'post_status'  => 'publish',
        ) );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_seo_fields_registered_for_posts() {
        $request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id );
        $request->set_param( 'context', 'edit' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'cwp_seo_title', $data );
        $this->assertArrayHasKey( 'cwp_seo_description', $data );
        $this->assertArrayHasKey( 'cwp_og_title', $data );
        $this->assertArrayHasKey( 'cwp_og_description', $data );
        $this->assertArrayHasKey( 'cwp_og_image_id', $data );
        $this->assertArrayHasKey( 'cwp_schema_json', $data );
    }

    public function test_seo_fields_registered_for_pages() {
        $page_id = $this->factory->post->create( array(
            'post_type'   => 'page',
            'post_title'  => 'Test SEO Page',
            'post_status' => 'publish',
        ) );

        $request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id );
        $request->set_param( 'context', 'edit' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'cwp_seo_title', $data );
        $this->assertArrayHasKey( 'cwp_seo_description', $data );
    }

    public function test_update_seo_title() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_seo_title' => 'Custom SEO Title',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $stored = get_post_meta( $this->post_id, '_cwp_seo_title', true );
        $this->assertEquals( 'Custom SEO Title', $stored );
    }

    public function test_update_seo_description() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_seo_description' => 'A custom meta description for SEO.',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $stored = get_post_meta( $this->post_id, '_cwp_seo_description', true );
        $this->assertEquals( 'A custom meta description for SEO.', $stored );
    }

    public function test_update_og_fields() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_og_title'       => 'OG Title',
            'cwp_og_description' => 'OG Description',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $this->assertEquals( 'OG Title', get_post_meta( $this->post_id, '_cwp_og_title', true ) );
        $this->assertEquals( 'OG Description', get_post_meta( $this->post_id, '_cwp_og_description', true ) );
    }

    public function test_update_og_image_id() {
        $attachment_id = $this->factory->attachment->create_upload_object(
            dirname( __FILE__ ) . '/data/test-image.jpg',
            $this->post_id
        );

        if ( ! $attachment_id ) {
            $attachment_id = 123;
        }

        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_og_image_id' => $attachment_id,
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $stored = get_post_meta( $this->post_id, '_cwp_og_image_id', true );
        $this->assertEquals( $attachment_id, intval( $stored ) );
    }

    public function test_update_valid_schema_json() {
        $schema = json_encode( array(
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'name'     => 'Test Article',
        ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_schema_json' => $schema,
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $stored = get_post_meta( $this->post_id, '_cwp_schema_json', true );
        $decoded = json_decode( $stored, true );

        $this->assertEquals( 'Article', $decoded['@type'] );
        $this->assertEquals( 'Test Article', $decoded['name'] );
    }

    public function test_reject_invalid_schema_json() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_schema_json' => 'not valid json {{{',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 400, $response->get_status() );
    }

    public function test_clear_schema_json_with_empty_value() {
        update_post_meta( $this->post_id, '_cwp_schema_json', '{"@type":"Article"}' );

        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_schema_json' => '',
        ) );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $stored = get_post_meta( $this->post_id, '_cwp_schema_json', true );
        $this->assertEmpty( $stored );
    }

    public function test_sanitize_seo_title_xss() {
        $malicious = '<script>alert("xss")</script>Title';

        $request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
        $request->set_body_params( array(
            'cwp_seo_title' => $malicious,
        ) );
        $response = $this->server->dispatch( $request );

        $stored = get_post_meta( $this->post_id, '_cwp_seo_title', true );
        $this->assertStringNotContainsString( '<script>', $stored );
    }

    public function test_meta_output_on_singular() {
        update_post_meta( $this->post_id, '_cwp_seo_description', 'Test meta description' );
        update_post_meta( $this->post_id, '_cwp_og_title', 'Test OG Title' );

        $this->go_to( get_permalink( $this->post_id ) );

        ob_start();
        $seo = new WP_MCP_Connect_SEO( 'wp-mcp-connect', '1.0.0' );
        $seo->output_meta_tags();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'name="description"', $output );
        $this->assertStringContainsString( 'Test meta description', $output );
        $this->assertStringContainsString( 'property="og:title"', $output );
        $this->assertStringContainsString( 'Test OG Title', $output );
    }

    public function test_document_title_filter() {
        update_post_meta( $this->post_id, '_cwp_seo_title', 'Custom Document Title' );

        $this->go_to( get_permalink( $this->post_id ) );

        $seo = new WP_MCP_Connect_SEO( 'wp-mcp-connect', '1.0.0' );
        $filtered = $seo->filter_document_title( 'Original Title' );

        $this->assertEquals( 'Custom Document Title', $filtered );
    }

    public function test_document_title_unchanged_without_custom() {
        $this->go_to( get_permalink( $this->post_id ) );

        $seo = new WP_MCP_Connect_SEO( 'wp-mcp-connect', '1.0.0' );
        $filtered = $seo->filter_document_title( 'Original Title' );

        $this->assertEquals( 'Original Title', $filtered );
    }
}
