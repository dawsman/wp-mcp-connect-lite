<?php
defined( 'ABSPATH' ) || exit;

/**
 * Auto-generated SEO meta title and description suggestions.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Meta_Suggest {

	/**
	 * Suggest an SEO title for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @return   string                Suggested SEO title.
	 */
	public static function suggest_title( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$focus = get_post_meta( $post_id, '_cwp_focus_keyword', true );
		$title = $post->post_title;
		$site  = get_bloginfo( 'name' );

		if ( $focus && stripos( $title, $focus ) === false ) {
			return sanitize_text_field( "{$title} - {$focus} | {$site}" );
		}
		return sanitize_text_field( "{$title} | {$site}" );
	}

	/**
	 * Suggest an SEO description for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @return   string                Suggested SEO description.
	 */
	public static function suggest_description( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );

		if ( strlen( $content ) > 155 ) {
			$content = substr( $content, 0, 155 );
			$last_space = strrpos( $content, ' ' );
			if ( $last_space !== false ) {
				$content = substr( $content, 0, $last_space );
			}
			$content .= '...';
		}

		$focus = get_post_meta( $post_id, '_cwp_focus_keyword', true );
		if ( $focus && stripos( $content, $focus ) === false ) {
			$content = ucfirst( $focus ) . ': ' . lcfirst( $content );
			if ( strlen( $content ) > 160 ) {
				$content = substr( $content, 0, 157 ) . '...';
			}
		}

		return sanitize_text_field( $content );
	}

	/**
	 * Suggest SEO meta for a single post.
	 *
	 * @since    1.0.0
	 * @param    int      $post_id    The post ID.
	 * @return   array                Associative array with post_id, seo_title, seo_description.
	 */
	public static function suggest_for_post( $post_id ) {
		return array(
			'post_id'         => (int) $post_id,
			'seo_title'       => self::suggest_title( $post_id ),
			'seo_description' => self::suggest_description( $post_id ),
		);
	}

	/**
	 * Suggest SEO meta for multiple posts.
	 *
	 * @since    1.0.0
	 * @param    array    $post_ids    Array of post IDs.
	 * @return   array                 Array of suggestion arrays.
	 */
	public static function suggest_bulk( $post_ids ) {
		return array_map( array( __CLASS__, 'suggest_for_post' ), $post_ids );
	}
}
