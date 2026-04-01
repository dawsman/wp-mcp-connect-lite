<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Clusters {

	/**
	 * Build topic clusters from taxonomy + GSC query overlap.
	 *
	 * @return array Clusters with members, metrics, and gaps.
	 */
	public static function build_clusters() {
		global $wpdb;

		// Step 1: Group posts by shared categories
		$cat_groups = self::group_by_taxonomy( 'category' );

		// Merge: posts sharing categories form initial clusters
		$clusters = array();
		foreach ( $cat_groups as $term_id => $posts ) {
			$term = get_term( $term_id );
			if ( ! $term || is_wp_error( $term ) || count( $posts ) < 2 ) {
				continue;
			}
			$clusters[] = array(
				'name'     => $term->name,
				'source'   => 'category',
				'term_id'  => $term_id,
				'post_ids' => $posts,
			);
		}

		// Enrich with GSC data
		$gsc_table   = $wpdb->prefix . 'cwp_gsc_data';
		$query_table = $wpdb->prefix . 'cwp_gsc_queries';
		$has_gsc     = $wpdb->get_var( "SHOW TABLES LIKE '{$gsc_table}'" ) === $gsc_table; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = array();

		foreach ( $clusters as $cluster ) {
			$members          = array();
			$total_impressions = 0;
			$total_clicks     = 0;
			$pillar_id        = null;
			$pillar_clicks    = 0;

			foreach ( $cluster['post_ids'] as $pid ) {
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}

				$member = array(
					'post_id'     => $pid,
					'title'       => $post->post_title,
					'type'        => $post->post_type,
					'edit_url'    => get_edit_post_link( $pid, 'raw' ),
					'permalink'   => get_permalink( $pid ),
					'impressions' => 0,
					'clicks'      => 0,
					'position'    => null,
				);

				if ( $has_gsc ) {
					$gsc = $wpdb->get_row( $wpdb->prepare(
						"SELECT impressions, clicks, avg_position FROM {$gsc_table} WHERE post_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$pid
					) );
					if ( $gsc ) {
						$member['impressions'] = (int) $gsc->impressions;
						$member['clicks']      = (int) $gsc->clicks;
						$member['position']    = (float) $gsc->avg_position;
						$total_impressions    += (int) $gsc->impressions;
						$total_clicks         += (int) $gsc->clicks;

						if ( (int) $gsc->clicks > $pillar_clicks ) {
							$pillar_clicks = (int) $gsc->clicks;
							$pillar_id     = $pid;
						}
					}
				}

				$members[] = $member;
			}

			// Find topic gaps: queries from cluster members that have no dedicated post
			$gaps = array();
			if ( $has_gsc && $wpdb->get_var( "SHOW TABLES LIKE '{$query_table}'" ) === $query_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// Get all queries for cluster posts
				$pid_list        = implode( ',', array_map( 'intval', $cluster['post_ids'] ) );
				$cluster_queries = $wpdb->get_results(
					"SELECT DISTINCT q.query, SUM(q.impressions) as total_imp
					 FROM {$query_table} q
					 INNER JOIN {$gsc_table} d ON d.id = q.gsc_data_id
					 WHERE d.post_id IN ({$pid_list})
					 GROUP BY q.query
					 HAVING total_imp >= 50
					 ORDER BY total_imp DESC
					 LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);

				// Check which queries lack a dedicated page ranking well
				foreach ( $cluster_queries as $q ) {
					$query         = $q['query'];
					$has_dedicated = false;
					foreach ( $cluster['post_ids'] as $pid ) {
						$focus = get_post_meta( $pid, '_cwp_focus_keyword', true );
						if ( $focus && stripos( $query, strtolower( $focus ) ) !== false ) {
							$has_dedicated = true;
							break;
						}
					}
					if ( ! $has_dedicated ) {
						$gaps[] = array(
							'query'       => $query,
							'impressions' => (int) $q['total_imp'],
						);
					}
				}
			}

			// Check internal linking within cluster
			$internal_links = 0;
			$links_table    = $wpdb->prefix . 'cwp_internal_links';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$links_table}'" ) === $links_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pid_list       = implode( ',', array_map( 'intval', $cluster['post_ids'] ) );
				$internal_links = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$links_table}
					 WHERE source_post_id IN ({$pid_list}) AND target_post_id IN ({$pid_list})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}

			$results[] = array(
				'name'              => $cluster['name'],
				'source'            => $cluster['source'],
				'member_count'      => count( $members ),
				'members'           => $members,
				'total_impressions' => $total_impressions,
				'total_clicks'      => $total_clicks,
				'pillar_post_id'    => $pillar_id,
				'internal_links'    => $internal_links,
				'gaps'              => array_slice( $gaps, 0, 5 ),
				'gap_count'         => count( $gaps ),
			);
		}

		// Sort by total impressions desc
		usort( $results, function ( $a, $b ) {
			return $b['total_impressions'] - $a['total_impressions'];
		} );

		return $results;
	}

	/**
	 * Group posts by taxonomy terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array Associative array of term_id => post IDs.
	 */
	private static function group_by_taxonomy( $taxonomy ) {
		$groups = array();

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		) );

		if ( is_wp_error( $terms ) ) {
			return $groups;
		}

		foreach ( $terms as $term ) {
			$posts = get_posts( array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
				'posts_per_page' => 100,
				'fields'         => 'ids',
			) );
			if ( count( $posts ) >= 2 ) {
				$groups[ $term->term_id ] = $posts;
			}
		}

		return $groups;
	}
}
