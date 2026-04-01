<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content relationship tools for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Links {

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
		register_rest_route( 'mcp/v1', '/content/broken-links', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_broken_links' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'any',
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

		register_rest_route( 'mcp/v1', '/content/orphaned', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_orphaned_content' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
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

		register_rest_route( 'mcp/v1', '/content/related', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_related_posts' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'limit' => array(
					'type'    => 'integer',
					'default' => 5,
					'maximum' => 20,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/links/suggestions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_link_suggestions' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_id' => array(
					'type' => 'integer',
				),
				'limit' => array(
					'type'    => 'integer',
					'default' => 5,
					'maximum' => 20,
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
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Scan for broken internal links.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_broken_links( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );

		$args = array(
			'post_type'      => 'any' === $post_type ? array( 'post', 'page' ) : $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'paged'          => 1,
		);

		$site_url = home_url();
		$results = array();
		$total_scanned = 0;

		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$content = get_the_content();
			$total_scanned++;

			$broken = $this->find_broken_links_in_content( $content, $site_url );

			if ( ! empty( $broken ) ) {
				$results[] = array(
					'post_id'      => $post_id,
					'post_title'   => get_the_title(),
					'post_type'    => get_post_type(),
					'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
					'broken_links' => $broken,
				);
			}
		}

		wp_reset_postdata();

		$total = count( $results );
		$offset = ( $page - 1 ) * $per_page;
		$results = array_slice( $results, $offset, $per_page );

		return array(
			'posts'         => $results,
			'total'         => $total,
			'total_scanned' => $total_scanned,
			'page'          => $page,
			'per_page'      => $per_page,
			'total_pages'   => ceil( $total / $per_page ),
		);
	}

	/**
	 * Find broken links in content.
	 *
	 * @since    1.0.0
	 * @param    string    $content     Post content.
	 * @param    string    $site_url    Site URL.
	 * @return   array                  Broken links.
	 */
	private function find_broken_links_in_content( $content, $site_url ) {
		$broken = array();

		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return $broken;
		}

		foreach ( $matches[1] as $url ) {
			if ( empty( $url ) || '#' === $url[0] || 'javascript:' === substr( $url, 0, 11 ) ) {
				continue;
			}

			if ( strpos( $url, $site_url ) !== 0 && strpos( $url, '/' ) !== 0 ) {
				continue;
			}

			if ( strpos( $url, '/' ) === 0 ) {
				$url = $site_url . $url;
			}

			$post_id = url_to_postid( $url );

			if ( 0 === $post_id ) {
				$parsed = wp_parse_url( $url );
				$path = isset( $parsed['path'] ) ? $parsed['path'] : '';

				if ( ! empty( $path ) && '/' !== $path ) {
					$page_path = trim( $path, '/' );

					$page = get_page_by_path( $page_path );

					if ( ! $page ) {
						$broken[] = array(
							'url'    => $url,
							'reason' => 'not_found',
						);
					}
				}
			} else {
				$post = get_post( $post_id );
				if ( ! $post || 'publish' !== $post->post_status ) {
					$broken[] = array(
						'url'    => $url,
						'reason' => $post ? 'not_published' : 'deleted',
					);
				}
			}
		}

		return $broken;
	}

	/**
	 * Get link suggestions for a post or for orphaned content.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function get_link_suggestions( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$limit = min( 20, max( 1, (int) $request->get_param( 'limit' ) ) );

		if ( $post_id ) {
			$suggestions = $this->build_related_suggestions( $post_id, $limit );
			return array(
				'post_id'     => $post_id,
				'suggestions' => $suggestions,
			);
		}

		// For orphaned content, suggest top related posts for each orphan.
		$orphaned = $this->get_orphaned_content( $request );
		$results = array();
		if ( is_array( $orphaned ) && ! empty( $orphaned['posts'] ) ) {
			foreach ( $orphaned['posts'] as $post ) {
				$suggestions = $this->build_related_suggestions( (int) $post['post_id'], $limit );
				$results[] = array(
					'post_id'     => (int) $post['post_id'],
					'post_title'  => $post['post_title'],
					'post_url'    => $post['post_url'],
					'suggestions' => $suggestions,
				);
			}
		}

		return array(
			'orphaned'    => true,
			'results'     => $results,
		);
	}

	private function build_related_suggestions( $post_id, $limit ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$categories = wp_get_post_categories( $post_id );
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

		$tax_query = array( 'relation' => 'OR' );
		if ( ! empty( $categories ) ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $categories,
			);
		}
		if ( ! empty( $tags ) ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tags,
			);
		}

		$args = array(
			'post_type'      => $post->post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'post__not_in'   => array( $post_id ),
		);

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query;
		}

		$query = new WP_Query( $args );
		$suggestions = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$suggestions[] = array(
					'id'          => get_the_ID(),
					'title'       => get_the_title(),
					'url'         => get_permalink(),
					'anchor_text' => get_the_title(),
				);
			}
			wp_reset_postdata();
		}

		return $suggestions;
	}

	/**
	 * Get posts without categories or tags.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_orphaned_content( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );

		global $wpdb;

		$tax_query_sql = '';
		$taxonomies = get_object_taxonomies( $post_type, 'names' );

		if ( in_array( 'category', $taxonomies, true ) || in_array( 'post_tag', $taxonomies, true ) ) {
			$tax_list = array();
			if ( in_array( 'category', $taxonomies, true ) ) {
				$tax_list[] = "'category'";
			}
			if ( in_array( 'post_tag', $taxonomies, true ) ) {
				$tax_list[] = "'post_tag'";
			}
			$tax_in = implode( ',', $tax_list );

			$tax_query_sql = "AND p.ID NOT IN (
				SELECT tr.object_id 
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy IN ({$tax_in})
			)";
		}

		$count_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p 
			WHERE p.post_type = %s 
			AND p.post_status = 'publish'
			{$tax_query_sql}",
			$post_type
		);

		$total = (int) $wpdb->get_var( $count_query );
		$offset = ( $page - 1 ) * $per_page;

		$posts_query = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date 
			FROM {$wpdb->posts} p 
			WHERE p.post_type = %s 
			AND p.post_status = 'publish'
			{$tax_query_sql}
			ORDER BY p.post_date DESC
			LIMIT %d OFFSET %d",
			$post_type,
			$per_page,
			$offset
		);

		$posts = $wpdb->get_results( $posts_query );
		$results = array();

		foreach ( $posts as $post ) {
			$results[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'date'      => $post->post_date,
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
				'permalink' => get_permalink( $post->ID ),
			);
		}

		return array(
			'posts'       => $results,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/**
	 * Get related posts by taxonomy overlap.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function get_related_posts( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$limit = min( 20, max( 1, $request->get_param( 'limit' ) ) );

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$post_type = $post->post_type;
		$categories = wp_get_post_categories( $post_id );
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

		if ( empty( $categories ) && empty( $tags ) ) {
			return array(
				'post_id' => $post_id,
				'related' => array(),
				'message' => 'Post has no categories or tags',
			);
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'post__not_in'   => array( $post_id ),
			'orderby'        => 'relevance',
		);

		if ( ! empty( $categories ) && ! empty( $tags ) ) {
			$args['tax_query'] = array(
				'relation' => 'OR',
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $categories,
				),
				array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $tags,
				),
			);
		} elseif ( ! empty( $categories ) ) {
			$args['category__in'] = $categories;
		} elseif ( ! empty( $tags ) ) {
			$args['tag__in'] = $tags;
		}

		$query = new WP_Query( $args );
		$related = array();

		while ( $query->have_posts() ) {
			$query->the_post();
			$related_id = get_the_ID();

			$shared_cats = array_intersect( $categories, wp_get_post_categories( $related_id ) );
			$shared_tags = array_intersect( $tags, wp_get_post_tags( $related_id, array( 'fields' => 'ids' ) ) );

			$related[] = array(
				'id'            => $related_id,
				'title'         => get_the_title(),
				'permalink'     => get_permalink(),
				'shared_terms'  => count( $shared_cats ) + count( $shared_tags ),
			);
		}

		wp_reset_postdata();

		usort( $related, function( $a, $b ) {
			return $b['shared_terms'] - $a['shared_terms'];
		} );

		return array(
			'post_id' => $post_id,
			'related' => array_slice( $related, 0, $limit ),
		);
	}
}
