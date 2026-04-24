<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted plugin updater using a remote JSON manifest.
 *
 * Hooks into WordPress's native update system so that new releases
 * published on GitHub appear in Dashboard > Updates and the Plugins list.
 *
 * @since      1.1.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Updater {

	/**
	 * Plugin basename (e.g. 'wp-mcp-connector/wp-mcp-connect.php').
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Currently installed plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Plugin slug used by plugins_api.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Remote URL of the JSON update manifest.
	 *
	 * @var string
	 */
	private $manifest_url;

	/**
	 * Transient key for cached remote data.
	 *
	 * @var string
	 */
	private $cache_key = 'wp_mcp_connect_updater_data';

	/**
	 * Cache lifetime in seconds (1 hour).
	 *
	 * Short enough to limit the window during which a briefly-poisoned manifest
	 * keeps offering a malicious update after the upstream is restored.
	 *
	 * @var int
	 */
	private $cache_expiry = 3600;

	/**
	 * Hosts allowed to serve the manifest (including after a single redirect).
	 *
	 * @var string[]
	 */
	private $allowed_manifest_hosts = array(
		'raw.githubusercontent.com',
		'objects.githubusercontent.com',
	);

	/**
	 * Hosts allowed to serve the plugin ZIP.
	 *
	 * @var string[]
	 */
	private $allowed_package_hosts = array(
		'github.com',
		'objects.githubusercontent.com',
		'codeload.github.com',
	);

	/**
	 * Constructor.
	 *
	 * @param string $plugin_basename Full plugin basename.
	 * @param string $current_version Installed version string.
	 * @param string $slug            Plugin slug.
	 * @param string $manifest_url    URL to the remote JSON manifest.
	 */
	public function __construct( $plugin_basename, $current_version, $slug, $manifest_url ) {
		$this->plugin_basename = $plugin_basename;
		$this->current_version = $current_version;
		$this->slug            = $slug;
		$this->manifest_url    = $manifest_url;

		$this->init_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package' ), 10, 3 );
	}

	/**
	 * Fetch remote manifest data, with transient caching.
	 *
	 * @return object|false Decoded manifest object on success, false on failure.
	 */
	private function get_remote_data() {
		if ( isset( $_GET['force-check'] ) && '1' === $_GET['force-check'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP core uses the same unnonced flag for updates UI.
			delete_transient( $this->cache_key );
		}

		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$manifest_host = wp_parse_url( $this->manifest_url, PHP_URL_HOST );
		if ( 'https' !== wp_parse_url( $this->manifest_url, PHP_URL_SCHEME ) ||
			! in_array( $manifest_host, $this->allowed_manifest_hosts, true ) ) {
			return false;
		}

		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'sslverify'   => true,
		);

		if ( defined( 'WP_MCP_CONNECT_GITHUB_TOKEN' ) && WP_MCP_CONNECT_GITHUB_TOKEN ) {
			$args['headers'] = array(
				'Authorization' => 'token ' . WP_MCP_CONNECT_GITHUB_TOKEN,
				'Accept'        => 'application/vnd.github.v3.raw',
			);
		}

		$response = wp_remote_get( $this->manifest_url, $args );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				return false;
			}
			$redirect_host   = wp_parse_url( $location, PHP_URL_HOST );
			$redirect_scheme = wp_parse_url( $location, PHP_URL_SCHEME );
			if ( 'https' !== $redirect_scheme ||
				! in_array( $redirect_host, $this->allowed_manifest_hosts, true ) ) {
				return false;
			}
			// Re-issue without the Authorization header: a token minted for the
			// original host must not leak to a redirect target, even an allowlisted one.
			$response = wp_remote_get(
				$location,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'sslverify'   => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$code = wp_remote_retrieve_response_code( $response );
		}

		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->version ) ) {
			return false;
		}

		if ( ! $this->is_valid_version( $data->version ) ) {
			return false;
		}

		set_transient( $this->cache_key, $data, $this->cache_expiry );

		return $data;
	}

	/**
	 * Validate a version string against a strict semver-lite pattern.
	 *
	 * Rejects anything containing HTML, control characters, or SQL fragments —
	 * a manifest-sourced value gets interpolated into admin UI strings and
	 * must not carry markup.
	 *
	 * @param string $version Candidate version string.
	 * @return bool True if the value looks like a version number.
	 */
	private function is_valid_version( $version ) {
		return (bool) preg_match( '/^\d+\.\d+(\.\d+)?(-[A-Za-z0-9.]+)?$/', (string) $version );
	}

	/**
	 * Check that a URL uses HTTPS and points at an allowlisted package host.
	 *
	 * @param string $url Candidate download URL.
	 * @return bool True if the URL is safe to hand to WP_Upgrader.
	 */
	private function is_allowed_package_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['host'] ), $this->allowed_package_hosts, true );
	}

	/**
	 * Inject update information into the update_plugins transient when a
	 * newer version is available remotely.
	 *
	 * @param object $transient The update_plugins site transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote_data();

		if ( false === $remote ) {
			return $transient;
		}

		if ( ! version_compare( $this->current_version, $remote->version, '<' ) ) {
			return $transient;
		}

		$download_url = isset( $remote->download_url ) ? (string) $remote->download_url : '';
		if ( ! $this->is_allowed_package_url( $download_url ) ) {
			return $transient;
		}

		if ( empty( $remote->zip_sha256 ) || ! preg_match( '/^[0-9a-f]{64}$/i', (string) $remote->zip_sha256 ) ) {
			// Refuse to advertise an update without an integrity hash.
			return $transient;
		}

		$update              = new stdClass();
		$update->slug        = $this->slug;
		$update->plugin      = $this->plugin_basename;
		$update->new_version = $remote->version;
		$update->url         = esc_url_raw( $remote->homepage ?? '' );
		$update->package     = $download_url;
		$update->icons       = isset( $remote->icons ) ? (array) $remote->icons : array();
		$update->banners     = isset( $remote->banners ) ? (array) $remote->banners : array();
		$update->tested      = sanitize_text_field( $remote->tested ?? '' );
		$update->requires    = sanitize_text_field( $remote->requires ?? '' );
		$update->requires_php = sanitize_text_field( $remote->requires_php ?? '' );

		$transient->response[ $this->plugin_basename ] = $update;

		return $transient;
	}

	/**
	 * Supply full plugin information for the "View details" modal.
	 *
	 * @param false|object $result Default false or existing result.
	 * @param string       $action The plugins_api action.
	 * @param object       $args   Request arguments including slug.
	 * @return false|object Plugin info object or passthrough.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$remote = $this->get_remote_data();

		if ( false === $remote ) {
			return $result;
		}

		$download_url = isset( $remote->download_url ) ? (string) $remote->download_url : '';
		$safe_package = $this->is_allowed_package_url( $download_url ) ? $download_url : '';

		$info                 = new stdClass();
		$info->name           = sanitize_text_field( $remote->name ?? '' );
		$info->slug           = $this->slug;
		$info->version        = $remote->version; // Already validated by is_valid_version() in get_remote_data().
		$info->author         = wp_kses(
			$remote->author ?? '',
			array(
				'a' => array( 'href' => array(), 'title' => array() ),
			)
		);
		$info->author_profile = esc_url_raw( $remote->author_profile ?? '' );
		$info->homepage       = esc_url_raw( $remote->homepage ?? '' );
		$info->requires       = sanitize_text_field( $remote->requires ?? '' );
		$info->tested         = sanitize_text_field( $remote->tested ?? '' );
		$info->requires_php   = sanitize_text_field( $remote->requires_php ?? '' );
		$info->download_link  = $safe_package;
		$info->trunk          = $safe_package;
		$info->last_updated   = sanitize_text_field( $remote->last_updated ?? '' );

		$info->sections = array(
			'description'  => isset( $remote->sections->description ) ? wp_kses_post( $remote->sections->description ) : '',
			'changelog'    => isset( $remote->sections->changelog ) ? wp_kses_post( $remote->sections->changelog ) : '',
			'installation' => isset( $remote->sections->installation ) ? wp_kses_post( $remote->sections->installation ) : '',
		);

		$info->banners = isset( $remote->banners ) ? (array) $remote->banners : array();
		$info->icons   = isset( $remote->icons ) ? (array) $remote->icons : array();

		return $info;
	}

	/**
	 * Delete cached manifest data after a successful plugin upgrade.
	 *
	 * @param \WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array        $options  Upgrade context details.
	 */
	public function clear_cache( $upgrader, $options ) {
		if (
			'update' === ( $options['action'] ?? '' ) &&
			'plugin' === ( $options['type'] ?? '' ) &&
			isset( $options['plugins'] ) &&
			is_array( $options['plugins'] )
		) {
			if ( in_array( $this->plugin_basename, $options['plugins'], true ) ) {
				delete_transient( $this->cache_key );
			}
		}
	}

	/**
	 * Rename the extracted ZIP directory to match the expected plugin folder.
	 *
	 * GitHub release ZIPs often contain a top-level directory like
	 * 'wp-mcp-connector-1.1.0/' but WordPress expects 'wp-mcp-connector/'.
	 *
	 * @param string       $source        Path to the extracted source directory.
	 * @param string       $remote_source Path to the remote source.
	 * @param \WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array        $hook_extra    Extra context about the upgrade.
	 * @return string|WP_Error Corrected source path or original.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		// Contain the source path inside the upgrader's temp directory.
		$real_source = realpath( $source );
		$real_base   = realpath( $remote_source );
		if ( false === $real_source || false === $real_base ||
			0 !== strpos( trailingslashit( $real_source ), trailingslashit( $real_base ) ) ) {
			return new WP_Error(
				'path_escape',
				__( 'Extracted update directory is outside the expected temp path.', 'wp-mcp-connect' )
			);
		}

		$expected_dir = trailingslashit( $remote_source ) . dirname( $this->plugin_basename ) . '/';

		if ( $source === $expected_dir ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem->move( $source, $expected_dir ) ) {
			return $expected_dir;
		}

		return new WP_Error(
			'rename_failed',
			__( 'Unable to rename the update source directory.', 'wp-mcp-connect' )
		);
	}

	/**
	 * Verify the SHA256 of the downloaded ZIP against the manifest before
	 * WordPress extracts and installs it.
	 *
	 * Returning a string path short-circuits `WP_Upgrader::download_package`,
	 * which is critical: if we let WP download a second time independently a
	 * remote TOCTOU (serve good bytes to us, bad bytes to WP) would defeat the
	 * hash check. By returning the already-verified temp file we guarantee the
	 * bytes being extracted are the bytes we hashed.
	 *
	 * Returns:
	 *   - WP_Error to abort the upgrade
	 *   - string: path to verified temp file (WP extracts this)
	 *   - passthrough of `$reply` for packages not in our scope
	 *
	 * @param false|WP_Error|string $reply    Short-circuit value.
	 * @param string                $package  Package URL WP is about to download.
	 * @param \WP_Upgrader          $upgrader WP_Upgrader instance.
	 * @return false|WP_Error|string
	 */
	public function verify_package( $reply, $package, $upgrader ) {
		if ( ! is_string( $package ) || '' === $package ) {
			return $reply;
		}

		// Only intercept packages that look like ours — match on the configured allowed hosts.
		$host = wp_parse_url( $package, PHP_URL_HOST );
		if ( empty( $host ) || ! in_array( strtolower( $host ), $this->allowed_package_hosts, true ) ) {
			return $reply;
		}

		// Refuse anything but HTTPS at this layer too.
		if ( 'https' !== strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) ) ) {
			return new WP_Error(
				'insecure_package',
				__( 'Update package must be served over HTTPS.', 'wp-mcp-connect' )
			);
		}

		$remote = $this->get_remote_data();
		if ( false === $remote || empty( $remote->zip_sha256 ) ||
			! preg_match( '/^[0-9a-f]{64}$/i', (string) $remote->zip_sha256 ) ) {
			return new WP_Error(
				'missing_package_hash',
				__( 'Update manifest is missing a valid zip_sha256 — aborting update.', 'wp-mcp-connect' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmpfile = download_url( $package );
		if ( is_wp_error( $tmpfile ) ) {
			return $tmpfile;
		}

		$actual = hash_file( 'sha256', $tmpfile );

		if ( ! is_string( $actual ) ||
			! hash_equals( strtolower( (string) $remote->zip_sha256 ), strtolower( $actual ) ) ) {
			wp_delete_file( $tmpfile );
			return new WP_Error(
				'hash_mismatch',
				__( 'Update package SHA256 does not match the manifest — aborting update.', 'wp-mcp-connect' )
			);
		}

		// Return the verified file path to short-circuit WP's own download.
		// WP_Upgrader will unzip this file directly and remove it afterwards
		// via its own cleanup path.
		return $tmpfile;
	}
}
