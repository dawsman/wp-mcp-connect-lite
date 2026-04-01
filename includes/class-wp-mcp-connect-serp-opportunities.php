<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_SERP_Opportunities {

	/**
	 * Find pages where actual CTR is significantly below expected CTR
	 * for their position (indicating SERP features stealing clicks).
	 *
	 * @return array List of opportunities.
	 */
	public static function find_opportunities() {
		global $wpdb;
		$data_table = $wpdb->prefix . 'cwp_gsc_data';
		$ctr_table  = $wpdb->prefix . 'cwp_gsc_ctr_curve';

		// Check tables exist
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$data_table}'" ) !== $data_table ) {
			return array();
		}
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ctr_table}'" ) !== $ctr_table ) {
			return array();
		}

		// Get CTR benchmarks (most recent)
		$benchmarks = array();
		$curves = $wpdb->get_results(
			"SELECT position_band, avg_ctr FROM {$ctr_table} ORDER BY computed_date DESC LIMIT 7",
			ARRAY_A
		);
		foreach ( $curves as $row ) {
			$benchmarks[ $row['position_band'] ] = (float) $row['avg_ctr'];
		}

		if ( empty( $benchmarks ) ) {
			return array();
		}

		// Get pages ranking 1-10 with meaningful impressions
		$pages = $wpdb->get_results(
			"SELECT d.url, d.post_id, d.avg_position, d.impressions, d.clicks, d.ctr,
					d.top_query, p.post_title
			 FROM {$data_table} d
			 LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
			 WHERE d.avg_position > 0 AND d.avg_position <= 10
			 AND d.impressions >= 50
			 ORDER BY d.impressions DESC
			 LIMIT 100",
			ARRAY_A
		);

		$opportunities = array();

		foreach ( $pages as $page ) {
			$position = (float) $page['avg_position'];
			$actual_ctr = (float) $page['ctr'];

			// Determine position band
			$band = self::position_to_band( $position );
			$expected_ctr = $benchmarks[ $band ] ?? null;

			if ( null === $expected_ctr ) {
				continue;
			}

			$ctr_gap = $expected_ctr - $actual_ctr;

			// Only flag if gap is significant (> 30% below expected)
			if ( $ctr_gap <= $expected_ctr * 0.3 ) {
				continue;
			}

			$impressions = (int) $page['impressions'];
			$estimated_lost_clicks = round( $ctr_gap * $impressions );

			// Check current schema on the post
			$current_schema = array();
			if ( $page['post_id'] ) {
				$schema_json = get_post_meta( (int) $page['post_id'], '_cwp_schema_json', true );
				if ( $schema_json ) {
					$parsed = json_decode( $schema_json, true );
					if ( is_array( $parsed ) ) {
						if ( isset( $parsed['@type'] ) ) {
							$current_schema[] = $parsed['@type'];
						} else {
							foreach ( $parsed as $item ) {
								if ( isset( $item['@type'] ) ) {
									$current_schema[] = $item['@type'];
								}
							}
						}
					}
				}
			}

			// Suggest schema types
			$suggestions = self::suggest_schema( $page['top_query'] ?? '', $current_schema );

			$opportunities[] = array(
				'url'                  => $page['url'],
				'post_id'              => $page['post_id'] ? (int) $page['post_id'] : null,
				'post_title'           => $page['post_title'] ?? null,
				'position'             => round( $position, 1 ),
				'impressions'          => $impressions,
				'actual_ctr'           => round( $actual_ctr, 4 ),
				'expected_ctr'         => round( $expected_ctr, 4 ),
				'ctr_gap'              => round( $ctr_gap, 4 ),
				'estimated_lost_clicks' => $estimated_lost_clicks,
				'current_schema'       => $current_schema,
				'suggested_schema'     => $suggestions,
				'top_query'            => $page['top_query'] ?? '',
				'edit_url'             => $page['post_id'] ? get_edit_post_link( (int) $page['post_id'], 'raw' ) : null,
			);
		}

		// Sort by estimated lost clicks desc
		usort( $opportunities, function( $a, $b ) {
			return $b['estimated_lost_clicks'] - $a['estimated_lost_clicks'];
		} );

		return $opportunities;
	}

	/**
	 * Map position to CTR curve band.
	 */
	private static function position_to_band( $position ) {
		if ( $position <= 1.5 ) return '1';
		if ( $position <= 2.5 ) return '2';
		if ( $position <= 3.5 ) return '3';
		if ( $position <= 5.5 ) return '4-5';
		if ( $position <= 10.5 ) return '6-10';
		return '11-20';
	}

	/**
	 * Suggest schema types based on query patterns.
	 */
	private static function suggest_schema( $query, $existing ) {
		$suggestions = array();
		$query_lower = strtolower( $query );

		$patterns = array(
			'FAQPage'  => array( 'how', 'what', 'why', 'when', 'where', 'who', 'faq', 'question' ),
			'HowTo'    => array( 'how to', 'tutorial', 'guide', 'step', 'instructions' ),
			'Article'  => array(),  // Always suggest if missing
			'Review'   => array( 'review', 'best', 'top', 'vs', 'comparison' ),
			'Product'  => array( 'buy', 'price', 'cheap', 'deal', 'discount', 'shop' ),
		);

		foreach ( $patterns as $type => $keywords ) {
			if ( in_array( $type, $existing, true ) ) {
				continue;
			}
			if ( empty( $keywords ) && 'Article' === $type && ! in_array( 'Article', $existing, true ) ) {
				$suggestions[] = $type;
				continue;
			}
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $query_lower, $kw ) ) {
					$suggestions[] = $type;
					break;
				}
			}
		}

		return $suggestions;
	}
}
