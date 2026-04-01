<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Decay {

	/**
	 * Analyze decay status for all pages with GSC data.
	 *
	 * @param int $limit Max results.
	 * @return array Pages with decay analysis.
	 */
	public static function analyze_all( $limit = 50 ) {
		global $wpdb;
		$snapshot_table = $wpdb->prefix . 'cwp_gsc_data_snapshots';
		$data_table = $wpdb->prefix . 'cwp_gsc_data';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$snapshot_table}'" ) !== $snapshot_table ) {
			return array();
		}

		// Get URLs with enough snapshot data (at least 4 weeks)
		$urls = $wpdb->get_results(
			"SELECT url_hash, COUNT(*) AS data_points, MIN(snapshot_date) AS first_date
			 FROM {$snapshot_table}
			 WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
			 GROUP BY url_hash
			 HAVING data_points >= 4
			 ORDER BY data_points DESC
			 LIMIT 200"
		);

		if ( empty( $urls ) ) {
			return array();
		}

		$results = array();

		foreach ( $urls as $url_row ) {
			$hash = $url_row->url_hash;

			// Get snapshots for this URL
			$snapshots = $wpdb->get_results( $wpdb->prepare(
				"SELECT snapshot_date, clicks, impressions
				 FROM {$snapshot_table}
				 WHERE url_hash = %s AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
				 ORDER BY snapshot_date ASC",
				$hash
			), ARRAY_A );

			if ( count( $snapshots ) < 4 ) {
				continue;
			}

			// Run linear regression on clicks
			$click_trend = self::linear_regression(
				array_map( function( $s ) { return (int) $s['clicks']; }, $snapshots )
			);

			// Run linear regression on impressions
			$impression_trend = self::linear_regression(
				array_map( function( $s ) { return (int) $s['impressions']; }, $snapshots )
			);

			// Classify decay status
			$status = self::classify( $click_trend, $impression_trend );

			// Get post info
			$page_data = $wpdb->get_row( $wpdb->prepare(
				"SELECT url, post_id, impressions, clicks, avg_position FROM {$data_table} WHERE url_hash = %s LIMIT 1",
				$hash
			) );

			if ( ! $page_data ) {
				continue;
			}

			$post_title = null;
			$edit_url = null;
			if ( $page_data->post_id ) {
				$post = get_post( (int) $page_data->post_id );
				if ( $post ) {
					$post_title = $post->post_title;
					$edit_url = get_edit_post_link( (int) $page_data->post_id, 'raw' );
				}
			}

			// Calculate estimated weekly loss
			$weekly_loss = 0;
			if ( $click_trend['slope'] < 0 ) {
				$weekly_loss = abs( round( $click_trend['slope'] * 7 ) );
			}

			$results[] = array(
				'url'              => $page_data->url,
				'post_id'          => $page_data->post_id ? (int) $page_data->post_id : null,
				'post_title'       => $post_title,
				'edit_url'         => $edit_url,
				'status'           => $status,
				'impressions'      => (int) $page_data->impressions,
				'clicks'           => (int) $page_data->clicks,
				'position'         => (float) $page_data->avg_position,
				'click_slope'      => round( $click_trend['slope'], 4 ),
				'impression_slope' => round( $impression_trend['slope'], 4 ),
				'data_points'      => count( $snapshots ),
				'weekly_loss'      => $weekly_loss,
				'last_modified'    => $page_data->post_id ? get_post_modified_time( 'Y-m-d', false, (int) $page_data->post_id ) : null,
			);
		}

		// Sort: declining first, then by weekly loss
		usort( $results, function( $a, $b ) {
			$order = array( 'accelerating_decline' => 0, 'early_decline' => 1, 'stable' => 2, 'growing' => 3 );
			$ao = $order[ $a['status'] ] ?? 2;
			$bo = $order[ $b['status'] ] ?? 2;
			if ( $ao !== $bo ) {
				return $ao - $bo;
			}
			return $b['weekly_loss'] - $a['weekly_loss'];
		} );

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Simple linear regression. Returns slope and r-squared.
	 *
	 * @param array $values Array of numeric values (equally spaced time series).
	 * @return array ['slope' => float, 'r_squared' => float]
	 */
	private static function linear_regression( $values ) {
		$n = count( $values );
		if ( $n < 2 ) {
			return array( 'slope' => 0, 'r_squared' => 0 );
		}

		$sum_x = 0;
		$sum_y = 0;
		$sum_xy = 0;
		$sum_x2 = 0;
		$sum_y2 = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			$x = $i;
			$y = $values[ $i ];
			$sum_x += $x;
			$sum_y += $y;
			$sum_xy += $x * $y;
			$sum_x2 += $x * $x;
			$sum_y2 += $y * $y;
		}

		$denom = ( $n * $sum_x2 - $sum_x * $sum_x );
		if ( abs( $denom ) < 0.0001 ) {
			return array( 'slope' => 0, 'r_squared' => 0 );
		}

		$slope = ( $n * $sum_xy - $sum_x * $sum_y ) / $denom;

		// R-squared
		$ss_tot = $sum_y2 - ( $sum_y * $sum_y ) / $n;
		$ss_res = 0;
		$intercept = ( $sum_y - $slope * $sum_x ) / $n;
		for ( $i = 0; $i < $n; $i++ ) {
			$predicted = $intercept + $slope * $i;
			$ss_res += ( $values[ $i ] - $predicted ) * ( $values[ $i ] - $predicted );
		}
		$r_squared = ( abs( $ss_tot ) > 0.0001 ) ? 1 - ( $ss_res / $ss_tot ) : 0;

		return array( 'slope' => $slope, 'r_squared' => max( 0, $r_squared ) );
	}

	/**
	 * Classify decay status based on trends.
	 *
	 * @param array $click_trend      Click trend with slope and r_squared.
	 * @param array $impression_trend Impression trend with slope and r_squared.
	 * @return string Status: growing, stable, early_decline, accelerating_decline.
	 */
	private static function classify( $click_trend, $impression_trend ) {
		$cs = $click_trend['slope'];
		$is = $impression_trend['slope'];

		if ( $cs > 0.1 && $is > 0 ) {
			return 'growing';
		}
		if ( $cs < -0.5 && $is < -1 ) {
			return 'accelerating_decline';
		}
		if ( $cs < -0.1 || $is < -0.5 ) {
			return 'early_decline';
		}
		return 'stable';
	}

	/**
	 * Get summary counts by status.
	 *
	 * @return array Counts keyed by status.
	 */
	public static function get_summary() {
		$all = self::analyze_all( 200 );
		$counts = array( 'growing' => 0, 'stable' => 0, 'early_decline' => 0, 'accelerating_decline' => 0 );
		foreach ( $all as $item ) {
			if ( isset( $counts[ $item['status'] ] ) ) {
				$counts[ $item['status'] ]++;
			}
		}
		return $counts;
	}
}
