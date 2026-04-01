<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-specific functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Admin {

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
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name    The name of this plugin.
	 * @param    string    $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register the admin menu.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'WP MCP Connect', 'wp-mcp-connect' ),
			__( 'MCP Connect', 'wp-mcp-connect' ),
			'manage_options',
			'wp-mcp-connect',
			array( $this, 'render_admin_page' ),
			'dashicons-rest-api',
			80
		);
	}

	/**
	 * Render the admin page container.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function render_admin_page() {
		echo '<div class="wrap"><div id="wp-mcp-connect-admin"></div></div>';
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since    1.0.0
	 * @param    string    $hook    The current admin page hook.
	 * @return   void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_wp-mcp-connect' !== $hook ) {
			return;
		}

		$asset_file = WP_MCP_CONNECT_PATH . 'admin/build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'wp-mcp-connect-admin',
			WP_MCP_CONNECT_URL . 'admin/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'wp-mcp-connect-admin',
			WP_MCP_CONNECT_URL . 'admin/build/index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_set_script_translations(
			'wp-mcp-connect-admin',
			'wp-mcp-connect',
			WP_MCP_CONNECT_PATH . 'languages'
		);
	}

	/**
	 * Add settings link to plugins page.
	 *
	 * @since    1.0.0
	 * @param    array    $links    Existing plugin action links.
	 * @return   array              Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=wp-mcp-connect' ),
			__( 'Settings', 'wp-mcp-connect' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}
