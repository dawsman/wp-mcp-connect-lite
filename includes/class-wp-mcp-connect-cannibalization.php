<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Cannibalization {

	/**
	 * Find cannibalization conflicts from GSC query data.
	 *
	 * @param int $limit Max conflicts to return.
	 * @return array Conflicts with competing pages and recommendations.
	 */
	public static function find_conflicts( $limit = 20 ) {
		global $wpdb;
		$query_table = $wpdb->prefix . 'cwp_gsc_queries';
		$data_table  = $wpdb->prefix . 'cwp_gsc_data';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$query_table}'" ) !== $query_table ) {
			return array();
		}

		$conflicts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.query, COUNT(DISTINCT d.post_id) AS page_count,
						SUM(q.impressions) AS total_impressions
				 FROM {$query_table} q
				 INNER JOIN {$data_table} d ON d.id = q.gsc_data_id
				 WHERE d.post_id IS NOT NULL AND d.post_id > 0
				 AND q.impressions >= 10
				 GROUP BY q.query
				 HAVING page_count >= 2
				 ORDER BY total_impressions DESC
				 LIMIT %d",
				$limit * 2
			),
			ARRAY_A
		);

		if ( empty( $conflicts_raw ) ) {
			return array();
		}

		$conflicts = array();

		foreach ( $conflicts_raw as $row ) {
			$query = $row['query'];

			$pages = $wpdb->get_results( $wpdb->prepare(
				"SELECT d.post_id, d.url, q.impressions, q.clicks, q.ctr, q.position
				 FROM {$query_table} q
				 INNER JOIN {$data_table} d ON d.id = q.gsc_data_id
				 WHERE q.query = %s AND d.post_id IS NOT NULL AND d.post_id > 0
				 ORDER BY q.clicks DESC",
				$query
			), ARRAY_A );

			if ( count( $pages ) < 2 ) {
				continue;
			}

			$competing    = array();
			$winner       = null;
			$winner_clicks = -1;

			foreach ( $pages as $page ) {
				$pid  = (int) $page['post_id'];
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}

				$focus = get_post_meta( $pid, '_cwp_focus_keyword', true );
				$entry = array(
					'post_id'       => $pid,
					'title'         => $post->post_title,
					'type'          => $post->post_type,
					'url'           => $page['url'],
					'edit_url'      => get_edit_post_link( $pid, 'raw' ),
					'impressions'   => (int) $page['impressions'],
					'clicks'        => (int) $page['clicks'],
					'ctr'           => (float) $page['ctr'],
					'position'      => (float) $page['position'],
					'focus_keyword' => $focus ?: null,
					'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				);

				if ( (int) $page['clicks'] > $winner_clicks ) {
					$winner_clicks = (int) $page['clicks'];
					$winner        = $pid;
				}

				$competing[] = $entry;
			}

			if ( count( $competing ) < 2 ) {
				continue;
			}

			$recommendation = self::recommend_action( $competing, $winner );

			$conflicts[] = array(
				'query'             => $query,
				'total_impressions' => (int) $row['total_impressions'],
				'page_count'        => count( $competing ),
				'competing_pages'   => $competing,
				'winner_post_id'    => $winner,
				'recommendation'    => $recommendation,
			);

			if ( count( $conflicts ) >= $limit ) {
				break;
			}
		}

		return $conflicts;
	}

	/**
	 * Recommend an action for a cannibalization conflict.
	 */
	private static function recommend_action( $pages, $winner_id ) {
		$total_clicks = array_sum( array_column( $pages, 'clicks' ) );
		$winner_data  = null;
		$losers       = array();

		foreach ( $pages as $p ) {
			if ( $p['post_id'] === $winner_id ) {
				$winner_data = $p;
			} else {
				$losers[] = $p;
			}
		}

		if ( ! $winner_data ) {
			return array( 'action' => 'review', 'reason' => 'Unable to determine winner.' );
		}

		$winner_share = $total_clicks > 0 ? $winner_data['clicks'] / $total_clicks : 0;

		if ( $winner_share >= 0.8 ) {
			return array(
				'action'    => 'merge',
				'reason'    => sprintf(
					'Post "%s" captures %.0f%% of clicks. Consider redirecting weaker pages to it.',
					$winner_data['title'],
					$winner_share * 100
				),
				'target_id' => $winner_id,
				'merge_ids' => array_column( $losers, 'post_id' ),
			);
		}

		$word_counts = array_column( $pages, 'word_count' );
		if ( max( $word_counts ) > 3 * min( array_filter( $word_counts ) ?: array( 1 ) ) ) {
			return array(
				'action'    => 'merge',
				'reason'    => 'Significant content length difference. Consider merging the shorter post into the longer one.',
				'target_id' => $winner_id,
				'merge_ids' => array_column( $losers, 'post_id' ),
			);
		}

		return array(
			'action' => 'differentiate',
			'reason' => 'Traffic is split relatively evenly. Consider changing focus keywords to target different intents.',
			'pages'  => array_map( function( $p ) {
				return array( 'post_id' => $p['post_id'], 'title' => $p['title'] );
			}, $pages ),
		);
	}
}
