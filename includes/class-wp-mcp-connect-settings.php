<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin settings for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Settings {

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Default settings.
	 *
	 * @since    1.0.0
	 * @var      array
	 */
	private $defaults = array(
		'rate_limit'         => 60,
		'rate_limit_window'  => 60,
		'enable_logging'     => false,
		'log_retention_days' => 30,
		'ip_whitelist'       => '',
		'ip_blacklist'       => '',
		'trusted_proxies'    => '',
		'reports_enabled'    => false,
		'reports_recipients' => '',
		'reports_frequency'  => 'weekly',
		'task_refresh_enabled'   => false,
		'task_refresh_frequency' => 'daily',
	);

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name    The name of the plugin.
	 * @param    string    $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'rate_limit' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'rate_limit_window' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'enable_logging' => array(
						'type' => 'boolean',
					),
					'log_retention_days' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'ip_whitelist' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'ip_blacklist' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'trusted_proxies' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'reports_enabled' => array(
						'type' => 'boolean',
					),
					'reports_recipients' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'reports_frequency' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'task_refresh_enabled' => array(
						'type' => 'boolean',
					),
					'task_refresh_frequency' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		) );
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error
	 */
	public function check_admin_permission() {
		return WP_MCP_Connect_Auth::check_admin_permission();
	}

	/**
	 * Get all settings.
	 *
	 * @since    1.0.0
	 * @return   array    Settings array.
	 */
	public function get_settings() {
		$settings = array();

		foreach ( $this->defaults as $key => $default ) {
			$option_key = 'cwp_' . $key;
			$settings[ $key ] = get_option( $option_key, $default );
		}

		return $settings;
	}

	/**
	 * Update settings.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error                 Updated settings or error.
	 */
	public function update_settings( $request ) {
		$updated = array();

		foreach ( $this->defaults as $key => $default ) {
			if ( $request->has_param( $key ) ) {
				$value = $request->get_param( $key );
				$option_key = 'cwp_' . $key;

				if ( is_bool( $this->defaults[ $key ] ) ) {
					$value = (bool) $value;
				} elseif ( is_int( $this->defaults[ $key ] ) ) {
					$value = absint( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_option( $option_key, $value );
				$updated[ $key ] = $value;
			}
		}

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'settings_changed', 'Settings updated via REST API' );
		}

		if ( class_exists( 'WP_MCP_Connect_Reports' ) ) {
			WP_MCP_Connect_Reports::maybe_schedule_report();
		}
		if ( class_exists( 'WP_MCP_Connect_Tasks' ) ) {
			WP_MCP_Connect_Tasks::maybe_schedule_refresh();
		}

		return array(
			'success'  => true,
			'settings' => $this->get_settings(),
		);
	}

	/**
	 * Get a specific setting.
	 *
	 * @since    1.0.0
	 * @param    string    $key        Setting key.
	 * @param    mixed     $default    Default value.
	 * @return   mixed                 Setting value.
	 */
	public function get( $key, $default = null ) {
		if ( null === $default && isset( $this->defaults[ $key ] ) ) {
			$default = $this->defaults[ $key ];
		}

		return get_option( 'cwp_' . $key, $default );
	}

	/**
	 * Set a specific setting.
	 *
	 * @since    1.0.0
	 * @param    string    $key      Setting key.
	 * @param    mixed     $value    Setting value.
	 * @return   bool                True on success.
	 */
	public function set( $key, $value ) {
		return update_option( 'cwp_' . $key, $value );
	}

	/**
	 * Get rate limit setting.
	 *
	 * @since    1.0.0
	 * @return   int    Requests per window.
	 */
	public function get_rate_limit() {
		return (int) $this->get( 'rate_limit', 60 );
	}

	/**
	 * Get rate limit window setting.
	 *
	 * @since    1.0.0
	 * @return   int    Window in seconds.
	 */
	public function get_rate_limit_window() {
		return (int) $this->get( 'rate_limit_window', 60 );
	}

	/**
	 * Check if IP is whitelisted.
	 *
	 * @since    1.0.0
	 * @param    string    $ip    IP address to check.
	 * @return   bool             True if whitelisted.
	 */
	public function is_ip_whitelisted( $ip ) {
		$whitelist = $this->get( 'ip_whitelist', '' );
		return $this->ip_in_list( $ip, $whitelist );
	}

	/**
	 * Check if IP is blacklisted.
	 *
	 * @since    1.0.0
	 * @param    string    $ip    IP address to check.
	 * @return   bool             True if blacklisted.
	 */
	public function is_ip_blacklisted( $ip ) {
		$blacklist = $this->get( 'ip_blacklist', '' );
		return $this->ip_in_list( $ip, $blacklist );
	}

	/**
	 * Check if IP is in a comma-separated list.
	 *
	 * @since    1.0.0
	 * @param    string    $ip      IP address.
	 * @param    string    $list    Comma-separated list.
	 * @return   bool               True if in list.
	 */
	private function ip_in_list( $ip, $list ) {
		return WP_MCP_Connect_Auth::ip_in_list( $ip, $list );
	}
}
