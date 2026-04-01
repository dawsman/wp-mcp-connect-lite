<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook event system for WP MCP Connect.
 *
 * Fires HTTP webhooks on plugin events and manages webhook subscriptions
 * via REST API endpoints.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Webhooks {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Fire a webhook event.
	 *
	 * @since 1.0.0
	 * @param string $event Event name (e.g., 'task.created', 'sync.completed').
	 * @param array  $data  Event payload.
	 */
	public static function fire( $event, $data = array() ) {
		$webhooks = get_option( 'cwp_webhooks', array() );
		if ( empty( $webhooks ) ) {
			return;
		}

		$payload = array(
			'event'     => $event,
			'timestamp' => gmdate( 'c' ),
			'site_url'  => home_url(),
			'data'      => $data,
		);

		$secret = '';
		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) && AUTH_KEY !== 'put your unique phrase here' ) {
			$secret = AUTH_KEY;
		} else {
			$secret = get_option( 'cwp_webhook_secret', '' );
			if ( empty( $secret ) ) {
				$secret = wp_generate_password( 32, true, true );
				update_option( 'cwp_webhook_secret', $secret, false );
			}
		}
		$body      = wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $secret );

		foreach ( $webhooks as $webhook ) {
			if ( ! empty( $webhook['events'] ) && ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			wp_remote_post( $webhook['url'], array(
				'body'    => $body,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-CWP-Signature' => $signature,
					'X-CWP-Event'     => $event,
				),
				'timeout'  => 5,
				'blocking' => false,
			) );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/webhooks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_webhooks' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_webhook' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'url'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'events' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/webhooks/(?P<index>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_webhook' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function list_webhooks() {
		return rest_ensure_response( array(
			'webhooks'         => get_option( 'cwp_webhooks', array() ),
			'available_events' => array(
				'task.created',
				'task.resolved',
				'sync.completed',
				'sync.failed',
				'redirect.created',
				'redirect.updated',
				'settings.changed',
				'audit.completed',
			),
		) );
	}

	/**
	 * Add a new webhook subscription.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function add_webhook( $request ) {
		$webhooks   = get_option( 'cwp_webhooks', array() );
		$webhooks[] = array(
			'url'     => esc_url_raw( $request->get_param( 'url' ) ),
			'events'  => $request->get_param( 'events' ),
			'created' => gmdate( 'c' ),
		);
		update_option( 'cwp_webhooks', $webhooks );

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'webhook_added', 'Webhook added: ' . $request->get_param( 'url' ) );
		}

		return rest_ensure_response( array( 'success' => true, 'webhooks' => $webhooks ) );
	}

	/**
	 * Delete a webhook by index.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_webhook( $request ) {
		$index    = (int) $request->get_param( 'index' );
		$webhooks = get_option( 'cwp_webhooks', array() );

		if ( ! isset( $webhooks[ $index ] ) ) {
			return new WP_Error( 'not_found', 'Webhook not found.', array( 'status' => 404 ) );
		}

		$removed = $webhooks[ $index ];
		array_splice( $webhooks, $index, 1 );
		update_option( 'cwp_webhooks', $webhooks );

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'webhook_removed', 'Webhook removed: ' . $removed['url'] );
		}

		return rest_ensure_response( array( 'success' => true, 'webhooks' => $webhooks ) );
	}
}
