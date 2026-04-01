<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles detection and integration with third-party SEO plugins.
 *
 * Supports Rank Math, Yoast SEO, and All in One SEO with automatic
 * detection and meta field mapping.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_SEO_Plugins {

	/**
	 * Supported SEO plugins with their meta key mappings.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array
	 */
	private static $plugins = array(
		'rank_math' => array(
			'file'      => 'seo-by-rank-math/rank-math.php',
			'name'      => 'Rank Math',
			'meta_keys' => array(
				'seo_title'       => 'rank_math_title',
				'seo_description' => 'rank_math_description',
				'og_title'        => 'rank_math_facebook_title',
				'og_description'  => 'rank_math_facebook_description',
				'og_image_id'     => 'rank_math_facebook_image_id',
				'schema_json'          => 'rank_math_schema',
				'focus_keyword'        => 'rank_math_focus_keyword',
				'cornerstone_content'  => 'rank_math_pillar_content',
			),
		),
		'yoast' => array(
			'file'      => 'wordpress-seo/wp-seo.php',
			'name'      => 'Yoast SEO',
			'meta_keys' => array(
				'seo_title'       => '_yoast_wpseo_title',
				'seo_description' => '_yoast_wpseo_metadesc',
				'og_title'        => '_yoast_wpseo_opengraph-title',
				'og_description'  => '_yoast_wpseo_opengraph-description',
				'og_image_id'     => '_yoast_wpseo_opengraph-image-id',
				'schema_json'          => '_yoast_wpseo_schema_page_type',
				'focus_keyword'        => '_yoast_wpseo_focuskw',
				'cornerstone_content'  => '_yoast_wpseo_is_cornerstone',
			),
		),
		'aioseo' => array(
			'file'      => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'name'      => 'All in One SEO',
			'meta_keys' => array(
				'seo_title'       => '_aioseo_title',
				'seo_description' => '_aioseo_description',
				'og_title'        => '_aioseo_og_title',
				'og_description'  => '_aioseo_og_description',
				'og_image_id'     => '_aioseo_og_image_custom_url',
				'schema_json'          => '_aioseo_schema',
				'focus_keyword'        => '_aioseo_focus_keyword',
				'cornerstone_content'  => '',
			),
		),
		'cwp' => array(
			'file'      => null,
			'name'      => 'WP MCP Connect (Built-in)',
			'meta_keys' => array(
				'seo_title'       => '_cwp_seo_title',
				'seo_description' => '_cwp_seo_description',
				'og_title'        => '_cwp_og_title',
				'og_description'  => '_cwp_og_description',
				'og_image_id'     => '_cwp_og_image_id',
				'schema_json'          => '_cwp_schema_json',
				'focus_keyword'        => '_cwp_focus_keyword',
				'cornerstone_content'  => '_cwp_cornerstone_content',
			),
		),
	);

	/**
	 * Cached active plugin detection result.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array|null
	 */
	private static $active_plugin = null;

	/**
	 * Detect which SEO plugin is active.
	 *
	 * Returns the first detected plugin from the priority list:
	 * Rank Math > Yoast > AIOSEO > cwp (fallback)
	 *
	 * @since    1.0.0
	 * @return   array    Plugin data with slug, name, file, and meta_keys.
	 */
	public static function detect_active_plugin() {
		if ( self::$active_plugin !== null ) {
			return self::$active_plugin;
		}

		// Check if function exists (it won't on frontend early)
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check plugins in priority order
		foreach ( self::$plugins as $slug => $plugin ) {
			if ( $plugin['file'] === null ) {
				continue; // Skip cwp fallback for now
			}

			if ( is_plugin_active( $plugin['file'] ) ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin['file'] );
				self::$active_plugin = array(
					'slug'      => $slug,
					'name'      => $plugin['name'],
					'version'   => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
					'file'      => $plugin['file'],
					'meta_keys' => $plugin['meta_keys'],
				);
				return self::$active_plugin;
			}
		}

		// Fallback to built-in cwp fields
		self::$active_plugin = array(
			'slug'      => 'cwp',
			'name'      => self::$plugins['cwp']['name'],
			'version'   => '',
			'file'      => null,
			'meta_keys' => self::$plugins['cwp']['meta_keys'],
		);

		return self::$active_plugin;
	}

	/**
	 * Get the meta key for a specific SEO field based on active plugin.
	 *
	 * @since    1.0.0
	 * @param    string    $field    The SEO field name (seo_title, seo_description, etc.)
	 * @return   string              The meta key to use.
	 */
	public static function get_meta_key( $field ) {
		$plugin = self::detect_active_plugin();

		if ( isset( $plugin['meta_keys'][ $field ] ) ) {
			return $plugin['meta_keys'][ $field ];
		}

		// Fallback to cwp key
		return self::$plugins['cwp']['meta_keys'][ $field ] ?? '_cwp_' . $field;
	}

	/**
	 * Get an SEO value from the active plugin's meta field.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @return   mixed                 The meta value.
	 */
	public static function get_seo_value( $post_id, $field ) {
		$meta_key = self::get_meta_key( $field );
		$value = get_post_meta( $post_id, $meta_key, true );

		// Rank Math stores schema as a PHP array in proprietary wrapper format - unwrap and convert to JSON.
		$plugin = self::detect_active_plugin();
		if ( 'schema_json' === $field && is_array( $value ) && 'rank_math' === $plugin['slug'] ) {
			$value = self::unwrap_schema_from_rank_math( $value );
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		// Other plugins: convert array schema to JSON as-is.
		if ( 'schema_json' === $field && is_array( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return $value;
	}

	/**
	 * Get the resolved/rendered SEO value, including defaults from templates.
	 *
	 * When SEO plugins use templates like %title% %sep% %sitename%, the post
	 * meta may be empty but there's still SEO content being generated.
	 * This method returns the actual rendered value that appears on the frontend.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name (seo_title, seo_description).
	 * @return   array                 Array with 'value', 'is_custom', and 'template' keys.
	 */
	public static function get_resolved_seo_value( $post_id, $field ) {
		$plugin = self::detect_active_plugin();
		$custom_value = self::get_seo_value( $post_id, $field );

		$result = array(
			'value'     => $custom_value,
			'is_custom' => ! empty( $custom_value ),
			'template'  => '',
		);

		// If there's a custom value, return it
		if ( ! empty( $custom_value ) ) {
			return $result;
		}

		// Try to get the resolved default value based on active plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_resolved_value( $post_id, $field, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_resolved_value( $post_id, $field, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_resolved_value( $post_id, $field, $result );
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from Rank Math.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_resolved_value( $post_id, $field, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		if ( 'seo_title' === $field ) {
			// Get the title template from Rank Math settings
			$template = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_title" );
			if ( empty( $template ) ) {
				$template = '%title% %sep% %sitename%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $post );
		} elseif ( 'seo_description' === $field ) {
			// Get the description template from Rank Math settings
			$template = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_description" );
			if ( empty( $template ) ) {
				$template = '%excerpt%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $post );
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from Yoast.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_resolved_value( $post_id, $field, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		if ( 'seo_title' === $field ) {
			$template = \WPSEO_Options::get( "title-{$post_type}" );
			if ( ! empty( $template ) ) {
				$result['template'] = $template;
				if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
					$replace_vars = new \WPSEO_Replace_Vars();
					$result['value'] = $replace_vars->replace( $template, $post );
				}
			}
		} elseif ( 'seo_description' === $field ) {
			$template = \WPSEO_Options::get( "metadesc-{$post_type}" );
			if ( ! empty( $template ) ) {
				$result['template'] = $template;
				if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
					$replace_vars = new \WPSEO_Replace_Vars();
					$result['value'] = $replace_vars->replace( $template, $post );
				}
			}
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from All in One SEO.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_resolved_value( $post_id, $field, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		// AIOSEO uses its own helper functions
		if ( 'seo_title' === $field ) {
			$title = aioseo()->meta->title->getPostTitle( $post );
			if ( ! empty( $title ) ) {
				$result['value'] = $title;
				$result['template'] = '(AIOSEO default)';
			}
		} elseif ( 'seo_description' === $field ) {
			$desc = aioseo()->meta->description->getPostDescription( $post );
			if ( ! empty( $desc ) ) {
				$result['value'] = $desc;
				$result['template'] = '(AIOSEO default)';
			}
		}

		return $result;
	}

	/**
	 * Get the resolved schema value, including defaults from plugin settings.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @return   array                 Array with 'value', 'is_custom', 'schema_type', and 'template' keys.
	 */
	public static function get_resolved_schema_value( $post_id ) {
		$plugin = self::detect_active_plugin();
		$custom_value = self::get_seo_value( $post_id, 'schema_json' );

		$result = array(
			'value'       => $custom_value,
			'is_custom'   => ! empty( $custom_value ),
			'schema_type' => '',
			'template'    => '',
		);

		// If there's a custom value, try to extract the schema type.
		if ( ! empty( $custom_value ) ) {
			$decoded = json_decode( $custom_value, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['@type'] ) ) {
					// Single schema object - @type may be string or array.
					$type = $decoded['@type'];
					$result['schema_type'] = is_array( $type ) ? implode( ', ', $type ) : $type;
				} elseif ( isset( $decoded[0] ) ) {
					// Array of schema objects - collect all types.
					$types = array();
					foreach ( $decoded as $schema_obj ) {
						if ( isset( $schema_obj['@type'] ) ) {
							$t = $schema_obj['@type'];
							$types[] = is_array( $t ) ? implode( ', ', $t ) : $t;
						}
					}
					$result['schema_type'] = implode( '; ', $types );
				}
			}
			return $result;
		}

		// Get default schema based on active plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_default_schema( $post_id, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_default_schema( $post_id, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_default_schema( $post_id, $result );
		}

		return $result;
	}

	/**
	 * Get Rank Math default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_default_schema( $post_id, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		// Get default schema type from Rank Math settings
		$schema_type = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_default_rich_snippet" );
		if ( empty( $schema_type ) || 'off' === $schema_type ) {
			$schema_type = 'post' === $post_type ? 'Article' : 'WebPage';
		}

		$result['schema_type'] = ucfirst( $schema_type );
		$result['template'] = "(Rank Math default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get Yoast default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_default_schema( $post_id, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		// Yoast uses schema-page-type and schema-article-type settings
		$page_type = \WPSEO_Options::get( "schema-page-type-{$post_type}" );
		$article_type = \WPSEO_Options::get( "schema-article-type-{$post_type}" );

		if ( ! empty( $article_type ) && 'None' !== $article_type ) {
			$result['schema_type'] = $article_type;
		} elseif ( ! empty( $page_type ) ) {
			$result['schema_type'] = $page_type;
		} else {
			$result['schema_type'] = 'post' === $post_type ? 'Article' : 'WebPage';
		}

		$result['template'] = "(Yoast default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get AIOSEO default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_default_schema( $post_id, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		// AIOSEO has default schema types
		$result['schema_type'] = 'post' === $post->post_type ? 'Article' : 'WebPage';
		$result['template'] = "(AIOSEO default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get resolved SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name (seo_title, seo_description).
	 * @return   array                 Array with 'value', 'is_custom', and 'template' keys.
	 */
	public static function get_resolved_term_seo_value( $term_id, $taxonomy, $field ) {
		$plugin = self::detect_active_plugin();

		$result = array(
			'value'     => '',
			'is_custom' => false,
			'template'  => '',
		);

		// Get custom term meta based on plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_term_seo( $term_id, $taxonomy, $field, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_term_seo( $term_id, $taxonomy, $field, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_term_seo( $term_id, $taxonomy, $field, $result );
		}

		return $result;
	}

	/**
	 * Get Rank Math SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		// Check for custom term meta first
		if ( 'seo_title' === $field ) {
			$custom = get_term_meta( $term_id, 'rank_math_title', true );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			// Get template from settings
			$template = \RankMath\Helper::get_settings( "titles.tax_{$taxonomy}_title" );
			if ( empty( $template ) ) {
				$template = '%term% %sep% %sitename%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $term );
		} elseif ( 'seo_description' === $field ) {
			$custom = get_term_meta( $term_id, 'rank_math_description', true );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			// Get template from settings
			$template = \RankMath\Helper::get_settings( "titles.tax_{$taxonomy}_description" );
			if ( empty( $template ) ) {
				$template = '%term_description%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $term );
		}

		return $result;
	}

	/**
	 * Get Yoast SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) || ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		if ( 'seo_title' === $field ) {
			$custom = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, 'title' );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			$template = \WPSEO_Options::get( "title-tax-{$taxonomy}" );
			if ( ! empty( $template ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
				$result['template'] = $template;
				$replace_vars = new \WPSEO_Replace_Vars();
				$result['value'] = $replace_vars->replace( $template, $term );
			}
		} elseif ( 'seo_description' === $field ) {
			$custom = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, 'desc' );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			$template = \WPSEO_Options::get( "metadesc-tax-{$taxonomy}" );
			if ( ! empty( $template ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
				$result['template'] = $template;
				$replace_vars = new \WPSEO_Replace_Vars();
				$result['value'] = $replace_vars->replace( $template, $term );
			}
		}

		return $result;
	}

	/**
	 * Get AIOSEO SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		if ( 'seo_title' === $field ) {
			$title = aioseo()->meta->title->getTermTitle( $term );
			if ( ! empty( $title ) ) {
				$result['value'] = $title;
				$result['template'] = '(AIOSEO default)';
			}
		} elseif ( 'seo_description' === $field ) {
			$desc = aioseo()->meta->description->getTermDescription( $term );
			if ( ! empty( $desc ) ) {
				$result['value'] = $desc;
				$result['template'] = '(AIOSEO default)';
			}
		}

		return $result;
	}

	/**
	 * Set an SEO value to the active plugin's meta field.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    mixed     $value      The value to set.
	 * @return   bool                  True on success, false on failure.
	 */
	public static function set_seo_value( $post_id, $field, $value ) {
		$meta_key = self::get_meta_key( $field );

		if ( empty( $value ) ) {
			return delete_post_meta( $post_id, $meta_key );
		}

		// For RankMath: Don't write to rank_math_schema as it breaks RankMath's UI.
		// Store schema in our own _cwp_schema_json meta key instead.
		// RankMath's internal format is fragile and can cause JS errors in the admin.
		$plugin = self::detect_active_plugin();
		if ( 'schema_json' === $field && 'rank_math' === $plugin['slug'] ) {
			// Write to our own meta key instead of RankMath's internal meta.
			return update_post_meta( $post_id, '_cwp_schema_json', $value );
		}

		// Rank Math handling - use direct update_post_meta for all fields.
		// RankMath's Helper::update_post_meta() has proven unreliable across versions.
		// Direct meta updates work consistently for all RankMath fields.
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = update_post_meta( $post_id, $meta_key, $value );
			self::debug_log_seo_save( $post_id, $field, $meta_key, $value, 'direct_meta' );
			return $result;
		}

		// Use Yoast's WPSEO_Meta class for proper handling and validation
		if ( 'yoast' === $plugin['slug'] && class_exists( 'WPSEO_Meta' ) ) {
			// WPSEO_Meta::set_value() expects the key without the '_yoast_wpseo_' prefix
			$yoast_key = str_replace( '_yoast_wpseo_', '', $meta_key );
			return WPSEO_Meta::set_value( $yoast_key, $value, $post_id );
		}

		return update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Log SEO save operations for debugging when WP_DEBUG is enabled.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    string    $meta_key   The actual meta key used.
	 * @param    mixed     $value      The value that was saved.
	 * @param    string    $method     The save method used ('rank_math_helper' or 'direct_meta').
	 * @return   void
	 */
	private static function debug_log_seo_save( $post_id, $field, $meta_key, $value, $method ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Verify the save by reading back the value.
		$saved = get_post_meta( $post_id, $meta_key, true );

		// Compare saved value - handle arrays and strings.
		$save_ok = false;
		if ( is_array( $value ) && is_array( $saved ) ) {
			$save_ok = ( $saved === $value );
		} elseif ( is_string( $value ) && is_string( $saved ) ) {
			$save_ok = ( $saved === $value );
		} elseif ( ! empty( $saved ) ) {
			// At least something was saved.
			$save_ok = true;
		}

		$status = $save_ok ? 'OK' : 'FAILED';
		$value_preview = is_array( $value ) ? 'array(' . count( $value ) . ')' : substr( (string) $value, 0, 50 );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[WP MCP Connect] SEO save: post=%d field=%s key=%s method=%s value="%s" status=%s',
				$post_id,
				$field,
				$meta_key,
				$method,
				$value_preview,
				$status
			)
		);
	}

	/**
	 * Get all supported SEO field names.
	 *
	 * @since    1.0.0
	 * @return   array    Array of field names.
	 */
	public static function get_field_names() {
		return array(
			'seo_title',
			'seo_description',
			'og_title',
			'og_description',
			'og_image_id',
			'schema_json',
			'focus_keyword',
			'cornerstone_content',
		);
	}

	/**
	 * Get info about the active SEO plugin for system info endpoint.
	 *
	 * @since    1.0.0
	 * @return   array    Plugin info with slug, name, and version.
	 */
	public static function get_plugin_info() {
		$plugin = self::detect_active_plugin();

		return array(
			'slug'    => $plugin['slug'],
			'name'    => $plugin['name'],
			'version' => $plugin['version'],
		);
	}

	/**
	 * Wrap standard JSON-LD schema into Rank Math's proprietary format.
	 *
	 * Rank Math expects schema stored as:
	 *   array( 'SchemaType-cwp1' => array( '@type' => '...', ..., 'metadata' => array(...) ) )
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    array     $schema     Decoded JSON-LD schema (single object, array of objects, or @graph container).
	 * @param    int       $post_id    The post ID.
	 * @param    string    $mode       'replace' to overwrite all schemas, 'merge' to preserve non-cwp schemas.
	 * @return   array                 Schema in Rank Math wrapper format.
	 */
	private static function wrap_schema_for_rank_math( $schema, $post_id, $mode = 'replace' ) {
		// Normalize input to a flat array of schema objects.
		$schemas = array();
		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			$schemas = $schema['@graph'];
		} elseif ( isset( $schema['@type'] ) ) {
			// Single schema object.
			$schemas = array( $schema );
		} elseif ( is_array( $schema ) && ! empty( $schema ) ) {
			// Check if it's a sequential array of schema objects.
			if ( isset( $schema[0] ) ) {
				$schemas = $schema;
			} else {
				// Associative array without @type - treat as single schema.
				$schemas = array( $schema );
			}
		}

		// Start with existing non-cwp schemas when merging.
		$wrapped = array();
		if ( 'merge' === $mode ) {
			$existing = get_post_meta( $post_id, 'rank_math_schema', true );
			if ( is_array( $existing ) ) {
				foreach ( $existing as $key => $entry ) {
					if ( ! self::is_cwp_schema_key( $key ) ) {
						$wrapped[ $key ] = $entry;
					}
				}
			}
		}

		// Wrap each schema object.
		$is_first = empty( $wrapped );
		foreach ( $schemas as $index => $single ) {
			$type = 'Thing';
			if ( isset( $single['@type'] ) ) {
				$type = is_array( $single['@type'] ) ? $single['@type'][0] : $single['@type'];
			}

			$key = $type . '-cwp' . ( $index + 1 );

			$single['metadata'] = array(
				'title'     => $type,
				'type'      => 'custom',
				'shortcode' => 'rank_math_schema',
				'isPrimary' => $is_first,
			);

			$wrapped[ $key ] = $single;
			$is_first = false;
		}

		return $wrapped;
	}

	/**
	 * Unwrap Rank Math's proprietary schema format into clean JSON-LD.
	 *
	 * Detects whether the stored value is in Rank Math wrapper format
	 * (string keys with metadata sub-arrays) and strips the wrapper,
	 * returning standard JSON-LD. Passes through already-clean data unchanged.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    array     $rank_math_schema    The raw rank_math_schema meta value.
	 * @return   array                          Clean JSON-LD schema (single object or array of objects).
	 */
	private static function unwrap_schema_from_rank_math( $rank_math_schema ) {
		if ( ! is_array( $rank_math_schema ) || empty( $rank_math_schema ) ) {
			return $rank_math_schema;
		}

		// Detect Rank Math wrapper format: string keys and at least one entry has 'metadata'.
		$is_wrapped = false;
		foreach ( $rank_math_schema as $key => $entry ) {
			if ( is_string( $key ) && is_array( $entry ) && isset( $entry['metadata'] ) ) {
				$is_wrapped = true;
				break;
			}
		}

		if ( ! $is_wrapped ) {
			return $rank_math_schema;
		}

		// Strip wrapper: remove metadata from each entry.
		$schemas = array();
		foreach ( $rank_math_schema as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			unset( $entry['metadata'] );
			$schemas[] = $entry;
		}

		if ( count( $schemas ) === 1 ) {
			return $schemas[0];
		}

		return $schemas;
	}

	/**
	 * Check if a Rank Math schema key was created by this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string    $key    The schema array key.
	 * @return   bool              True if the key matches the cwp pattern.
	 */
	private static function is_cwp_schema_key( $key ) {
		return (bool) preg_match( '/-cwp\d+$/', $key );
	}

	/**
	 * Repair posts with corrupted RankMath schema.
	 *
	 * Deletes the rank_math_schema meta to restore RankMath UI functionality.
	 * When schema is written in incompatible formats, it can break RankMath's
	 * JavaScript in the post editor, causing the metabox to fail.
	 *
	 * @since    1.0.0
	 * @param    int|null    $post_id    Optional post ID to repair. If null, repairs all affected posts.
	 * @return   int|bool                Number of affected rows when repairing all, or true/false for single post.
	 */
	public static function repair_rank_math_schema( $post_id = null ) {
		global $wpdb;

		if ( $post_id ) {
			// Repair single post - delete the rank_math_schema meta.
			$post_id = absint( $post_id );
			return delete_post_meta( $post_id, 'rank_math_schema' );
		}

		// Repair all posts with cwp-formatted schema.
		// The cwp suffix identifies schemas written by this plugin.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				'rank_math_schema',
				'%-cwp%'
			)
		);

		// Clear object cache for affected posts.
		wp_cache_flush();

		return $affected;
	}

	/**
	 * Get list of posts with potentially corrupted RankMath schema.
	 *
	 * @since    1.0.0
	 * @return   array    Array of post IDs with cwp-formatted schema.
	 */
	public static function get_posts_with_corrupted_schema() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				'rank_math_schema',
				'%-cwp%'
			)
		);

		return array_map( 'absint', $post_ids );
	}

	/**
	 * Clear the cached plugin detection (useful for testing).
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function clear_cache() {
		self::$active_plugin = null;
	}
}
