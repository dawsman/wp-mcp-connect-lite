<?php
defined( 'ABSPATH' ) || exit;

/**
 * Extended media functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Media_Extended {

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
		register_rest_route( 'mcp/v1', '/media/oversized', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_oversized_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'threshold' => array(
					'type'              => 'integer',
					'default'           => 2097152,
					'sanitize_callback' => 'absint',
				),
				'page' => array(
					'type'    => 'integer',
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/media/duplicates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_duplicate_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'page' => array(
					'type'    => 'integer',
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/media/bulk-alt-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_update_alt_text' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'updates' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'alt_text' => array( 'type' => 'string' ),
						),
					),
				),
			),
		) );
	}

	/**
	 * Check if user has permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Get images larger than threshold.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_oversized_images( $request ) {
		$threshold = $request->get_param( 'threshold' );
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );

		$cache_key = 'cwp_oversized_' . md5( $threshold . '_' . $page . '_' . $per_page );
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$query = $wpdb->prepare(
			"SELECT p.ID, p.post_title, pm.meta_value as file_path
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%'
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d",
			$per_page * 5,
			0
		);

		$attachments = $wpdb->get_results( $query );
		$upload_dir = wp_upload_dir();
		$base_path = $upload_dir['basedir'];

		$oversized = array();

		foreach ( $attachments as $attachment ) {
			$file_path = trailingslashit( $base_path ) . $attachment->file_path;

			if ( file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );

				if ( $file_size >= $threshold ) {
					$oversized[] = array(
						'id'        => (int) $attachment->ID,
						'title'     => $attachment->post_title,
						'file_path' => $attachment->file_path,
						'file_size' => $file_size,
						'url'       => wp_get_attachment_url( $attachment->ID ),
					);
				}
			}
		}

		$total = count( $oversized );
		$oversized = array_slice( $oversized, $offset, $per_page );

		$result = array(
			'images'      => $oversized,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
			'threshold'   => $threshold,
		);

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Find duplicate images based on file hash.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_duplicate_images( $request ) {
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );

		$cache_key = 'cwp_duplicates_' . $page . '_' . $per_page;
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_value as file_path
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_mime_type LIKE %s
				ORDER BY p.ID ASC",
				'_wp_attached_file',
				'attachment',
				'image/%'
			)
		);
		$upload_dir = wp_upload_dir();
		$base_path = $upload_dir['basedir'];

		$hashes = array();
		$duplicates = array();

		foreach ( $attachments as $attachment ) {
			$file_path = trailingslashit( $base_path ) . $attachment->file_path;

			if ( file_exists( $file_path ) && filesize( $file_path ) < 10 * 1024 * 1024 ) {
				$hash = md5_file( $file_path );

				if ( ! isset( $hashes[ $hash ] ) ) {
					$hashes[ $hash ] = array();
				}

				$hashes[ $hash ][] = array(
					'id'        => (int) $attachment->ID,
					'title'     => $attachment->post_title,
					'file_path' => $attachment->file_path,
					'url'       => wp_get_attachment_url( $attachment->ID ),
				);
			}
		}

		foreach ( $hashes as $hash => $images ) {
			if ( count( $images ) > 1 ) {
				$duplicates[] = array(
					'hash'   => $hash,
					'count'  => count( $images ),
					'images' => $images,
				);
			}
		}

		$total = count( $duplicates );
		$offset = ( $page - 1 ) * $per_page;
		$duplicates = array_slice( $duplicates, $offset, $per_page );

		$result = array(
			'duplicate_groups' => $duplicates,
			'total'            => $total,
			'page'             => $page,
			'per_page'         => $per_page,
			'total_pages'      => ceil( $total / $per_page ),
		);

		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Bulk update alt text for multiple images.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function bulk_update_alt_text( $request ) {
		$updates = $request->get_param( 'updates' );

		if ( count( $updates ) > 50 ) {
			return new WP_Error(
				'too_many_updates',
				__( 'Maximum 50 updates per request.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $updates as $update ) {
			if ( empty( $update['id'] ) ) {
				$results['failed'][] = array(
					'id'     => null,
					'reason' => 'Missing ID',
				);
				continue;
			}

			$id = absint( $update['id'] );
			$attachment = get_post( $id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$results['failed'][] = array(
					'id'     => $id,
					'reason' => 'Attachment not found',
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				$results['failed'][] = array(
					'id'     => $id,
					'reason' => 'Permission denied',
				);
				continue;
			}

			$alt_text = isset( $update['alt_text'] ) ? sanitize_text_field( $update['alt_text'] ) : '';
			update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );

			$results['success'][] = array(
				'id'       => $id,
				'alt_text' => $alt_text,
			);
		}

		delete_transient( 'cwp_media_missing_alt_results' );
		delete_transient( 'cwp_media_missing_alt_total' );

		return array(
			'updated' => count( $results['success'] ),
			'failed'  => count( $results['failed'] ),
			'results' => $results,
		);
	}

	/**
	 * Invalidate cache on media changes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function invalidate_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_cwp_oversized_' ) . '%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_cwp_duplicates_' ) . '%'
			)
		);
	}
}
