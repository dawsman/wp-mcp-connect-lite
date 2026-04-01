<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console API client.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_GSC_API {

	/**
	 * Search Console API base URL.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const API_BASE = 'https://searchconsole.googleapis.com/v1';

	/**
	 * Webmasters API base URL.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const WEBMASTERS_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * Auth handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_GSC_Auth
	 */
	private $auth;

	/**
	 * Last API request timestamp for rate limiting.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      float
	 */
	private $last_request_time = 0;

	/**
	 * Minimum delay between requests in seconds.
	 *
	 * @since    1.0.0
	 * @var      float
	 */
	const REQUEST_DELAY = 0.2;

	/**
	 * Initialize the class.
	 *
	 * @since    1.0.0
	 * @param    WP_MCP_Connect_GSC_Auth    $auth    Auth handler instance.
	 */
	public function __construct( WP_MCP_Connect_GSC_Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Make an authenticated API request.
	 *
	 * @since    1.0.0
	 * @param    string    $url       The API URL.
	 * @param    string    $method    HTTP method.
	 * @param    array     $body      Request body for POST requests.
	 * @return   array|WP_Error       Response data or error.
	 */
	private function request( $url, $method = 'GET', $body = null ) {
		$this->throttle_request();

		$access_token = $this->auth->get_access_token();

		if ( ! $access_token ) {
			return new WP_Error(
				'not_connected',
				'Not connected to Google Search Console.',
				array( 'status' => 401 )
			);
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $body && 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Handle rate limiting.
		if ( 429 === $status_code ) {
			return new WP_Error(
				'rate_limited',
				'API rate limit exceeded. Please try again later.',
				array( 'status' => 429 )
			);
		}

		if ( $status_code >= 400 ) {
			$error_message = 'API error';
			if ( ! empty( $body['error']['message'] ) ) {
				$error_message = $body['error']['message'];
			}
			return new WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		return $body;
	}

	/**
	 * Throttle requests to avoid rate limiting.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function throttle_request() {
		$now = microtime( true );
		$elapsed = $now - $this->last_request_time;

		if ( $elapsed < self::REQUEST_DELAY ) {
			usleep( (int) ( ( self::REQUEST_DELAY - $elapsed ) * 1000000 ) );
		}

		$this->last_request_time = microtime( true );
	}

	/**
	 * Get search analytics data.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url      The Search Console site URL.
	 * @param    string    $start_date    Start date (YYYY-MM-DD).
	 * @param    string    $end_date      End date (YYYY-MM-DD).
	 * @param    array     $dimensions    Dimensions to group by.
	 * @param    int       $row_limit     Maximum rows to return.
	 * @param    int       $start_row     Starting row for pagination.
	 * @return   array|WP_Error           Response data or error.
	 */
	public function query_search_analytics( $site_url, $start_date, $end_date, $dimensions = array( 'page' ), $row_limit = 1000, $start_row = 0 ) {
		$encoded_site = rawurlencode( $site_url );
		$url = self::WEBMASTERS_BASE . "/sites/{$encoded_site}/searchAnalytics/query";

		$body = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => $dimensions,
			'rowLimit'   => $row_limit,
			'startRow'   => $start_row,
		);

		return $this->request( $url, 'POST', $body );
	}

	/**
	 * Get search analytics data for a specific page.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url      The Search Console site URL.
	 * @param    string    $page_url      The page URL to filter by.
	 * @param    string    $start_date    Start date (YYYY-MM-DD).
	 * @param    string    $end_date      End date (YYYY-MM-DD).
	 * @return   array|WP_Error           Response data or error.
	 */
	public function get_page_analytics( $site_url, $page_url, $start_date, $end_date ) {
		$encoded_site = rawurlencode( $site_url );
		$url = self::WEBMASTERS_BASE . "/sites/{$encoded_site}/searchAnalytics/query";

		$body = array(
			'startDate'        => $start_date,
			'endDate'          => $end_date,
			'dimensions'       => array( 'query' ),
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page_url,
						),
					),
				),
			),
			'rowLimit' => 100,
		);

		return $this->request( $url, 'POST', $body );
	}

	/**
	 * Get search analytics grouped by page and query.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url      The Search Console site URL.
	 * @param    string    $start_date    Start date (YYYY-MM-DD).
	 * @param    string    $end_date      End date (YYYY-MM-DD).
	 * @param    int       $row_limit     Maximum rows to return.
	 * @param    int       $start_row     Starting row for pagination.
	 * @return   array|WP_Error           Response data or error.
	 */
	public function get_page_query_analytics( $site_url, $start_date, $end_date, $row_limit = 5000, $start_row = 0 ) {
		$encoded_site = rawurlencode( $site_url );
		$url = self::WEBMASTERS_BASE . "/sites/{$encoded_site}/searchAnalytics/query";

		$body = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page', 'query' ),
			'rowLimit'   => $row_limit,
			'startRow'   => $start_row,
		);

		return $this->request( $url, 'POST', $body );
	}

	/**
	 * Inspect a URL for index status.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url    The Search Console site URL.
	 * @param    string    $page_url    The URL to inspect.
	 * @return   array|WP_Error         Inspection result or error.
	 */
	public function inspect_url( $site_url, $page_url ) {
		$url = self::API_BASE . '/urlInspection/index:inspect';

		$body = array(
			'inspectionUrl' => $page_url,
			'siteUrl'       => $site_url,
		);

		return $this->request( $url, 'POST', $body );
	}

	/**
	 * Parse URL inspection response into structured data.
	 *
	 * @since    1.0.0
	 * @param    array    $response    Raw API response.
	 * @return   array                 Parsed inspection data.
	 */
	public function parse_inspection_result( $response ) {
		$result = array(
			'is_indexed'      => null,
			'index_status'    => null,
			'last_crawl_time' => null,
			'crawl_status'    => null,
			'robots_txt_state' => null,
			'indexing_state'  => null,
		);

		if ( empty( $response['inspectionResult'] ) ) {
			return $result;
		}

		$inspection = $response['inspectionResult'];

		// Index status.
		if ( ! empty( $inspection['indexStatusResult'] ) ) {
			$index = $inspection['indexStatusResult'];

			$result['index_status'] = $index['verdict'] ?? null;
			$result['is_indexed'] = 'PASS' === $result['index_status'];
			$result['indexing_state'] = $index['indexingState'] ?? null;
			$result['robots_txt_state'] = $index['robotsTxtState'] ?? null;

			if ( ! empty( $index['lastCrawlTime'] ) ) {
				$result['last_crawl_time'] = gmdate( 'Y-m-d H:i:s', strtotime( $index['lastCrawlTime'] ) );
			}

			$result['crawl_status'] = $index['crawledAs'] ?? null;
		}

		return $result;
	}

	/**
	 * Get sitemaps for a site.
	 *
	 * @since    1.0.0
	 * @param    string    $site_url    The Search Console site URL.
	 * @return   array|WP_Error         Sitemaps data or error.
	 */
	public function get_sitemaps( $site_url ) {
		$encoded_site = rawurlencode( $site_url );
		$url = self::WEBMASTERS_BASE . "/sites/{$encoded_site}/sitemaps";

		return $this->request( $url, 'GET' );
	}

	/**
	 * Check if the API is accessible.
	 *
	 * @since    1.0.0
	 * @return   bool    True if accessible.
	 */
	public function is_api_accessible() {
		$access_token = $this->auth->get_access_token();
		return ! empty( $access_token );
	}
}
