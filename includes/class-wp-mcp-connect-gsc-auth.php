<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console OAuth authentication handler.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_GSC_Auth {

	/**
	 * Google OAuth authorization URL.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Google OAuth token URL.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Google OAuth revoke URL.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const OAUTH_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Google Search Console API scopes.
	 *
	 * @since    1.0.0
	 * @var      array
	 */
	const SCOPES = array( 'https://www.googleapis.com/auth/webmasters.readonly' );

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $version;

	/**
	 * Initialize the class.
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
		register_rest_route( 'mcp/v1', '/gsc/auth/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/auth/url', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_auth_url' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/auth/callback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_callback' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'code' => array(
					'required' => true,
					'type'     => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'state' => array(
					'required' => true,
					'type'     => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/auth/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'disconnect' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/auth/credentials', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_credentials' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'client_id'     => array(
					'required' => true,
					'type'     => 'string',
				),
				'client_secret' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/sites', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_sites' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/sites', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_site' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'site_url' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/gsc/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_settings' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/gsc/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_settings' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'sync_enabled'         => array( 'type' => 'boolean' ),
				'sync_frequency'       => array( 'type' => 'string' ),
				'data_retention_days'  => array( 'type' => 'integer' ),
			),
		) );
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get OAuth Client ID from wp-config.php constant.
	 *
	 * @since    1.0.0
	 * @return   string|false    Client ID or false if not configured.
	 */
	private function get_client_id() {
		if ( defined( 'CWP_GSC_CLIENT_ID' ) && ! empty( CWP_GSC_CLIENT_ID ) ) {
			return CWP_GSC_CLIENT_ID;
		}
		return false;
	}

	/**
	 * Get OAuth Client Secret from wp-config.php constant.
	 *
	 * @since    1.0.0
	 * @return   string|false    Client Secret or false if not configured.
	 */
	private function get_client_secret() {
		if ( defined( 'CWP_GSC_CLIENT_SECRET' ) && ! empty( CWP_GSC_CLIENT_SECRET ) ) {
			return CWP_GSC_CLIENT_SECRET;
		}
		return false;
	}

	/**
	 * Get connection status.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response
	 */
	public function get_status() {
		$has_credentials = $this->has_credentials();
		$is_connected = $this->is_connected();
		$has_site = ! empty( get_option( 'cwp_gsc_site_url', '' ) );

		$status = 'disconnected';
		$message = null;

		if ( ! $has_credentials ) {
			$status = 'needs_credentials';
			$message = 'OAuth credentials not configured. Add CWP_GSC_CLIENT_ID and CWP_GSC_CLIENT_SECRET constants to wp-config.php.';
		} elseif ( $has_credentials && $is_connected && $has_site ) {
			$status = 'connected';
		} elseif ( $has_credentials && $is_connected ) {
			$status = 'needs_site';
		} elseif ( $has_credentials ) {
			$status = 'needs_auth';
		}

		$response = array(
			'status'          => $status,
			'has_credentials' => $has_credentials,
			'is_connected'    => $is_connected,
			'has_site'        => $has_site,
			'site_url'        => get_option( 'cwp_gsc_site_url', '' ),
		);

		if ( $message ) {
			$response['message'] = $message;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Check if OAuth credentials are configured in wp-config.php.
	 *
	 * Credentials must be defined as constants:
	 * - CWP_GSC_CLIENT_ID
	 * - CWP_GSC_CLIENT_SECRET
	 *
	 * @since    1.0.0
	 * @return   bool    True if both credentials are configured.
	 */
	public function has_credentials() {
		return $this->get_client_id() !== false && $this->get_client_secret() !== false;
	}

	/**
	 * Check if connected to Google (has valid tokens).
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function is_connected() {
		$refresh_token = $this->decrypt_token( get_option( 'cwp_gsc_refresh_token', '' ) );
		return ! empty( $refresh_token );
	}

	/**
	 * Save OAuth credentials.
	 * Credentials must be configured in wp-config.php for security.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response
	 */
	public function save_credentials( $request ) {
		return rest_ensure_response( array(
			'success' => false,
			'message' => 'OAuth credentials must be configured in wp-config.php. Add CWP_GSC_CLIENT_ID and CWP_GSC_CLIENT_SECRET constants.',
		) );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_auth_url() {
		$client_id = $this->get_client_id();

		if ( ! $client_id ) {
			return new WP_Error(
				'credentials_not_configured',
				'Google OAuth credentials not configured. Add CWP_GSC_CLIENT_ID and CWP_GSC_CLIENT_SECRET to wp-config.php.',
				array( 'status' => 500 )
			);
		}

		$redirect_uri = $this->get_redirect_uri();
		$state = wp_create_nonce( 'cwp_gsc_oauth' ) . '|' . get_current_user_id();

		// Store state for verification (user-scoped to prevent cross-user attacks).
		set_transient( 'cwp_gsc_oauth_state_' . get_current_user_id(), $state, 600 );

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => implode( ' ', self::SCOPES ),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		$auth_url = self::OAUTH_AUTH_URL . '?' . http_build_query( $params );

		return rest_ensure_response( array(
			'url'   => $auth_url,
			'state' => $state,
		) );
	}

	/**
	 * Get OAuth redirect URI.
	 *
	 * @since    1.0.0
	 * @return   string
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php?page=wp-mcp-connect&gsc_callback=1' );
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function handle_callback( $request ) {
		$code = sanitize_text_field( $request->get_param( 'code' ) );
		$state = sanitize_text_field( $request->get_param( 'state' ) );

		// Extract user_id from the state parameter.
		$state_parts = explode( '|', $state );
		$state_user_id = isset( $state_parts[1] ) ? absint( $state_parts[1] ) : 0;
		if ( ! $state_user_id ) {
			return new WP_Error(
				'invalid_state',
				'Invalid OAuth state format. Please try again.',
				array( 'status' => 400 )
			);
		}

		// Verify state using user-scoped transient.
		$stored_state = get_transient( 'cwp_gsc_oauth_state_' . $state_user_id );
		if ( ! $stored_state || $state !== $stored_state ) {
			return new WP_Error(
				'invalid_state',
				'Invalid OAuth state. Please try again.',
				array( 'status' => 400 )
			);
		}

		delete_transient( 'cwp_gsc_oauth_state_' . $state_user_id );

		// Exchange code for tokens.
		$tokens = $this->exchange_code_for_tokens( $code );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		// Store tokens.
		update_option( 'cwp_gsc_access_token', $this->encrypt_token( $tokens['access_token'] ) );
		update_option( 'cwp_gsc_token_expiry', time() + $tokens['expires_in'] );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			update_option( 'cwp_gsc_refresh_token', $this->encrypt_token( $tokens['refresh_token'] ) );
		}

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'gsc_connected', 'Google Search Console connected' );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Successfully connected to Google Search Console.',
		) );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @since    1.0.0
	 * @param    string    $code    Authorization code.
	 * @return   array|WP_Error     Tokens array or error.
	 */
	private function exchange_code_for_tokens( $code ) {
		$client_id = $this->get_client_id();
		$client_secret = $this->get_client_secret();

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error(
				'credentials_not_configured',
				'Google OAuth credentials not configured. Add CWP_GSC_CLIENT_ID and CWP_GSC_CLIENT_SECRET to wp-config.php.',
				array( 'status' => 500 )
			);
		}

		$redirect_uri = $this->get_redirect_uri();

		$response = wp_remote_post( self::OAUTH_TOKEN_URL, array(
			'body' => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'token_exchange_failed',
				'Failed to exchange authorization code: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error(
				'token_error',
				'Token error: ' . ( $body['error_description'] ?? $body['error'] ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $body['access_token'] ) ) {
			return new WP_Error(
				'no_access_token',
				'No access token received.',
				array( 'status' => 500 )
			);
		}

		return $body;
	}

	/**
	 * Disconnect from Google.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response
	 */
	public function disconnect() {
		// Try to revoke the token.
		$access_token = $this->get_access_token();
		if ( $access_token ) {
			wp_remote_post( self::OAUTH_REVOKE_URL, array(
				'body' => array( 'token' => $access_token ),
			) );
		}

		// Clear stored tokens and site.
		delete_option( 'cwp_gsc_access_token' );
		delete_option( 'cwp_gsc_refresh_token' );
		delete_option( 'cwp_gsc_token_expiry' );
		delete_option( 'cwp_gsc_site_url' );

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'gsc_disconnected', 'Google Search Console disconnected' );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Disconnected from Google Search Console.',
		) );
	}

	/**
	 * Get a valid access token, refreshing if needed.
	 *
	 * @since    1.0.0
	 * @return   string|false    Access token or false on failure.
	 */
	public function get_access_token() {
		$access_token = $this->decrypt_token( get_option( 'cwp_gsc_access_token', '' ) );
		$expiry = (int) get_option( 'cwp_gsc_token_expiry', 0 );

		// Check if token is expired (with 5-minute buffer).
		if ( time() >= ( $expiry - 300 ) ) {
			$refreshed = $this->refresh_access_token();
			if ( ! $refreshed ) {
				return false;
			}
			$access_token = $this->decrypt_token( get_option( 'cwp_gsc_access_token', '' ) );
		}

		return $access_token ?: false;
	}

	/**
	 * Refresh the access token using refresh token.
	 *
	 * @since    1.0.0
	 * @return   bool    True on success, false on failure.
	 */
	private function refresh_access_token() {
		$refresh_token = $this->decrypt_token( get_option( 'cwp_gsc_refresh_token', '' ) );

		if ( empty( $refresh_token ) ) {
			return false;
		}

		$client_id = $this->get_client_id();
		$client_secret = $this->get_client_secret();

		if ( ! $client_id || ! $client_secret ) {
			return false;
		}

		$response = wp_remote_post( self::OAUTH_TOKEN_URL, array(
			'body' => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return false;
		}

		update_option( 'cwp_gsc_access_token', $this->encrypt_token( $body['access_token'] ) );
		update_option( 'cwp_gsc_token_expiry', time() + $body['expires_in'] );

		// Google sometimes returns a new refresh token.
		if ( ! empty( $body['refresh_token'] ) ) {
			update_option( 'cwp_gsc_refresh_token', $this->encrypt_token( $body['refresh_token'] ) );
		}

		return true;
	}

	/**
	 * Get available Search Console sites.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response|WP_Error
	 */
	public function get_sites() {
		$access_token = $this->get_access_token();

		if ( ! $access_token ) {
			return new WP_Error(
				'not_connected',
				'Not connected to Google Search Console.',
				array( 'status' => 401 )
			);
		}

		$response = wp_remote_get( 'https://www.googleapis.com/webmasters/v3/sites', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_error',
				'Failed to fetch sites: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error(
				'api_error',
				'API error: ' . ( $body['error']['message'] ?? 'Unknown error' ),
				array( 'status' => $body['error']['code'] ?? 500 )
			);
		}

		$sites = array();
		$site_url = home_url();

		if ( ! empty( $body['siteEntry'] ) ) {
			foreach ( $body['siteEntry'] as $site ) {
				$sites[] = array(
					'url'             => $site['siteUrl'],
					'permission'      => $site['permissionLevel'],
					'is_current_site' => strpos( $site['siteUrl'], parse_url( $site_url, PHP_URL_HOST ) ) !== false,
				);
			}
		}

		return rest_ensure_response( array(
			'sites'        => $sites,
			'current_site' => get_option( 'cwp_gsc_site_url', '' ),
		) );
	}

	/**
	 * Set the active Search Console site.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response|WP_Error
	 */
	public function set_site( $request ) {
		$site_url = sanitize_text_field( $request->get_param( 'site_url' ) );

		update_option( 'cwp_gsc_site_url', $site_url );

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Site selected successfully.',
			'site_url' => $site_url,
		) );
	}

	/**
	 * Get GSC settings.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( array(
			'sync_enabled'        => (bool) get_option( 'cwp_gsc_sync_enabled', false ),
			'sync_frequency'      => get_option( 'cwp_gsc_sync_frequency', 'daily' ),
			'data_retention_days' => (int) get_option( 'cwp_gsc_data_retention_days', 90 ),
			'last_sync'           => get_option( 'cwp_gsc_last_sync', 0 ),
		) );
	}

	/**
	 * Save GSC settings.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response
	 */
	public function save_settings( $request ) {
		$sync_enabled = $request->get_param( 'sync_enabled' );
		$sync_frequency = $request->get_param( 'sync_frequency' );
		$data_retention_days = $request->get_param( 'data_retention_days' );

		if ( $sync_enabled !== null ) {
			update_option( 'cwp_gsc_sync_enabled', (bool) $sync_enabled );
		}

		if ( $sync_frequency !== null ) {
			$allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
			if ( in_array( $sync_frequency, $allowed, true ) ) {
				update_option( 'cwp_gsc_sync_frequency', $sync_frequency );
			}
		}

		if ( $data_retention_days !== null ) {
			$days = max( 7, min( 365, (int) $data_retention_days ) );
			update_option( 'cwp_gsc_data_retention_days', $days );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Settings saved successfully.',
		) );
	}

	/**
	 * Encrypt a token for storage.
	 *
	 * @since    1.0.0
	 * @param    string    $token    The token to encrypt.
	 * @return   string              Encrypted token.
	 */
	private function encrypt_token( $token ) {
		if ( empty( $token ) ) {
			return '';
		}

		$key = $this->get_encryption_key();
		if ( is_wp_error( $key ) ) {
			return '';
		}

		$iv = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a stored token.
	 *
	 * @since    1.0.0
	 * @param    string    $encrypted    The encrypted token.
	 * @return   string                  Decrypted token.
	 */
	private function decrypt_token( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$key = $this->get_encryption_key();
		if ( is_wp_error( $key ) ) {
			return '';
		}

		$data = base64_decode( $encrypted );

		if ( strlen( $data ) < 16 ) {
			return '';
		}

		$iv = substr( $data, 0, 16 );
		$encrypted_data = substr( $data, 16 );

		$decrypted = openssl_decrypt( $encrypted_data, 'AES-256-CBC', $key, 0, $iv );

		return $decrypted ?: '';
	}

	/**
	 * Get encryption key from WordPress salts using proper key derivation.
	 *
	 * @since    1.0.0
	 * @return   string    Binary encryption key.
	 */
	private function get_encryption_key() {
		$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';

		if ( empty( $auth_key ) && empty( $secure_auth_key ) ) {
			return new WP_Error(
				'encryption_keys_missing',
				'WordPress security keys (AUTH_KEY, SECURE_AUTH_KEY) must be configured in wp-config.php.'
			);
		}

		if ( $auth_key === 'put your unique phrase here' || $secure_auth_key === 'put your unique phrase here' ) {
			return new WP_Error(
				'encryption_keys_default',
				'WordPress security keys are using default values. Please configure unique keys in wp-config.php.'
			);
		}

		// Use HKDF (Hash-based Key Derivation Function) for proper key derivation.
		// This provides domain separation via the context string.
		$ikm = $auth_key . $secure_auth_key;
		$context = 'cwp_gsc_token_encryption_v1';

		// PHP 7.1.2+ has hash_hkdf, fall back to manual HKDF for older versions.
		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $ikm, 32, $context );
		}

		// Manual HKDF implementation for PHP < 7.1.2.
		$prk = hash_hmac( 'sha256', $ikm, '', true );
		return substr( hash_hmac( 'sha256', $context . chr( 1 ), $prk, true ), 0, 32 );
	}
}
