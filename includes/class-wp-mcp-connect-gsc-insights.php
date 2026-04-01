<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console insights and recommendations engine.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_GSC_Insights {

	/**
	 * Minimum impressions to consider for CTR opportunity.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const HIGH_IMPRESSIONS_THRESHOLD = 1000;

	/**
	 * CTR below this percentage is considered low.
	 *
	 * @since    1.0.0
	 * @var      float
	 */
	const LOW_CTR_THRESHOLD = 0.02;

	/**
	 * Position decline threshold (spots).
	 *
	 * @since    1.0.0
	 * @var      float
	 */
	const POSITION_DECLINE_THRESHOLD = 3.0;

	/**
	 * Days after which crawl is considered stale.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const STALE_CRAWL_DAYS = 30;

	/**
	 * Minimum keyword match percentage to consider a match.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const KEYWORD_MATCH_THRESHOLD = 50;

	/**
	 * Generate all insights.
	 *
	 * @since    1.0.0
	 * @param    string    $type     Type filter ('all' or specific type).
	 * @param    int       $limit    Maximum insights to return.
	 * @return   array               Array of insights.
	 */
	public function generate_insights( $type = 'all', $limit = 20 ) {
		$insights = array();

		if ( 'all' === $type || 'ctr_opportunity' === $type ) {
			$insights = array_merge( $insights, $this->find_ctr_opportunities() );
		}

		if ( 'all' === $type || 'keyword_mismatch' === $type ) {
			$insights = array_merge( $insights, $this->find_keyword_mismatches() );
		}

		if ( 'all' === $type || 'not_indexed' === $type ) {
			$insights = array_merge( $insights, $this->find_indexing_issues() );
		}

		if ( 'all' === $type || 'stale_crawl' === $type ) {
			$insights = array_merge( $insights, $this->find_stale_crawls() );
		}

		if ( 'all' === $type || 'position_decline' === $type ) {
			$insights = array_merge( $insights, $this->find_declining_pages() );
		}

		if ( 'all' === $type || 'top_performer' === $type ) {
			$insights = array_merge( $insights, $this->find_top_performers() );
		}

		if ( 'all' === $type || 'cannibalization' === $type ) {
			$insights = array_merge( $insights, $this->find_cannibalization() );
		}

		if ( 'all' === $type || 'content_gap' === $type ) {
			$insights = array_merge( $insights, $this->find_content_gaps() );
		}

		if ( 'all' === $type || 'ctr_underperformer' === $type ) {
			$insights = array_merge( $insights, $this->find_ctr_underperformers() );
		}

		// Sort by priority score.
		usort( $insights, function( $a, $b ) {
			return $b['priority_score'] - $a['priority_score'];
		} );

		// Apply limit.
		$insights = array_slice( $insights, 0, $limit );

		// Group by type for summary.
		$summary = array(
			'ctr_opportunity'    => 0,
			'keyword_mismatch'   => 0,
			'not_indexed'        => 0,
			'stale_crawl'        => 0,
			'position_decline'   => 0,
			'top_performer'      => 0,
			'cannibalization'    => 0,
			'content_gap'        => 0,
			'ctr_underperformer' => 0,
		);

		foreach ( $insights as $insight ) {
			if ( isset( $summary[ $insight['type'] ] ) ) {
				$summary[ $insight['type'] ]++;
			}
		}

		return array(
			'insights' => $insights,
			'summary'  => $summary,
			'total'    => count( $insights ),
		);
	}

	/**
	 * Find pages with high impressions but low CTR.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_ctr_opportunities() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_data
			WHERE impressions >= %d
			AND ctr < %f
			ORDER BY impressions DESC
			LIMIT 20",
			self::HIGH_IMPRESSIONS_THRESHOLD,
			self::LOW_CTR_THRESHOLD
		), ARRAY_A );

		$insights = array();

		foreach ( $results as $row ) {
			$post_data = $this->get_post_data( $row['post_id'] );
			$ctr_percent = round( (float) $row['ctr'] * 100, 2 );

			$insights[] = array(
				'type'           => 'ctr_opportunity',
				'priority'       => 'high',
				'priority_score' => $this->calculate_priority_score( 'ctr_opportunity', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'This page has %s impressions but only %s%% CTR. Improving the title and meta description could increase clicks significantly.',
					number_format( $row['impressions'] ),
					$ctr_percent
				),
				'metrics'        => array(
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],
					'ctr'         => $ctr_percent,
					'position'    => round( (float) $row['avg_position'], 1 ),
				),
				'action'         => 'edit_seo',
				'action_label'   => 'Edit SEO',
				'action_url'     => $post_data ? $post_data['edit_url'] : null,
			);
		}

		return $insights;
	}

	/**
	 * Find pages where top query doesn't match focus keyword.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_keyword_mismatches() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$results = $wpdb->get_results(
			"SELECT * FROM $table_data
			WHERE post_id IS NOT NULL
			AND top_query IS NOT NULL
			AND impressions >= 100
			ORDER BY impressions DESC
			LIMIT 50",
			ARRAY_A
		);

		$insights = array();

		foreach ( $results as $row ) {
			$focus_keyword = WP_MCP_Connect_SEO_Plugins::get_seo_value( $row['post_id'], 'focus_keyword' );

			if ( empty( $focus_keyword ) ) {
				continue;
			}

			// Calculate similarity.
			similar_text(
				strtolower( $row['top_query'] ),
				strtolower( $focus_keyword ),
				$match_percent
			);

			if ( $match_percent >= self::KEYWORD_MATCH_THRESHOLD ) {
				continue;
			}

			$post_data = $this->get_post_data( $row['post_id'] );

			$insights[] = array(
				'type'           => 'keyword_mismatch',
				'priority'       => 'medium',
				'priority_score' => $this->calculate_priority_score( 'keyword_mismatch', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'Top performing query "%s" (%s impressions) differs from focus keyword "%s". Consider updating the focus keyword or creating dedicated content.',
					$row['top_query'],
					number_format( $row['top_query_impressions'] ),
					$focus_keyword
				),
				'metrics'        => array(
					'top_query'     => $row['top_query'],
					'focus_keyword' => $focus_keyword,
					'match_percent' => round( $match_percent ),
					'impressions'   => (int) $row['top_query_impressions'],
				),
				'action'         => 'edit_seo',
				'action_label'   => 'Update Focus Keyword',
				'action_url'     => $post_data ? $post_data['edit_url'] : null,
			);
		}

		return array_slice( $insights, 0, 20 );
	}

	/**
	 * Find pages with indexing issues.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_indexing_issues() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$results = $wpdb->get_results(
			"SELECT * FROM $table_data
			WHERE is_indexed = 0
			ORDER BY impressions DESC, last_synced DESC
			LIMIT 20",
			ARRAY_A
		);

		$insights = array();

		foreach ( $results as $row ) {
			$post_data = $this->get_post_data( $row['post_id'] );

			$reason = $this->get_indexing_reason( $row );

			$insights[] = array(
				'type'           => 'not_indexed',
				'priority'       => 'high',
				'priority_score' => $this->calculate_priority_score( 'not_indexed', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'This page is not indexed by Google. %s',
					$reason
				),
				'metrics'        => array(
					'index_status'    => $row['index_status'],
					'indexing_state'  => $row['indexing_state'],
					'robots_txt_state' => $row['robots_txt_state'],
					'last_crawl'      => $row['last_crawl_time'],
				),
				'action'         => 'inspect_url',
				'action_label'   => 'Request Indexing',
				'action_url'     => 'https://search.google.com/search-console/inspect?resource_id=' . get_option( 'cwp_gsc_site_url', '' ) . '&id=' . rawurlencode( $row['url'] ),
			);
		}

		return $insights;
	}

	/**
	 * Find pages with stale crawl data.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_stale_crawls() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::STALE_CRAWL_DAYS . ' days' ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_data
			WHERE (last_crawl_time < %s OR last_crawl_time IS NULL)
			AND impressions > 0
			ORDER BY impressions DESC
			LIMIT 20",
			$threshold
		), ARRAY_A );

		$insights = array();

		foreach ( $results as $row ) {
			$post_data = $this->get_post_data( $row['post_id'] );

			$days_ago = 'never';
			if ( ! empty( $row['last_crawl_time'] ) ) {
				$diff = time() - strtotime( $row['last_crawl_time'] );
				$days_ago = round( $diff / DAY_IN_SECONDS ) . ' days ago';
			}

			$insights[] = array(
				'type'           => 'stale_crawl',
				'priority'       => 'low',
				'priority_score' => $this->calculate_priority_score( 'stale_crawl', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'This page was last crawled %s. Request a fresh crawl to ensure Google has the latest content.',
					$days_ago
				),
				'metrics'        => array(
					'last_crawl'  => $row['last_crawl_time'],
					'impressions' => (int) $row['impressions'],
				),
				'action'         => 'inspect_url',
				'action_label'   => 'Request Crawl',
				'action_url'     => null,
			);
		}

		return $insights;
	}

	/**
	 * Find pages with declining positions.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_declining_pages() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_data
			WHERE prev_position > 0
			AND avg_position > 0
			AND (avg_position - prev_position) >= %f
			AND impressions >= 100
			ORDER BY (avg_position - prev_position) DESC
			LIMIT 20",
			self::POSITION_DECLINE_THRESHOLD
		), ARRAY_A );

		$insights = array();

		foreach ( $results as $row ) {
			$post_data = $this->get_post_data( $row['post_id'] );
			$decline = round( (float) $row['avg_position'] - (float) $row['prev_position'], 1 );

			$insights[] = array(
				'type'           => 'position_decline',
				'priority'       => $decline >= 5 ? 'high' : 'medium',
				'priority_score' => $this->calculate_priority_score( 'position_decline', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'Position dropped from %.1f to %.1f (-%s spots). Review content freshness and check competitor updates.',
					$row['prev_position'],
					$row['avg_position'],
					abs( $decline )
				),
				'metrics'        => array(
					'current_position'  => round( (float) $row['avg_position'], 1 ),
					'previous_position' => round( (float) $row['prev_position'], 1 ),
					'decline'           => $decline,
					'impressions'       => (int) $row['impressions'],
					'top_query'         => $row['top_query'],
				),
				'action'         => 'edit_post',
				'action_label'   => 'Review Content',
				'action_url'     => $post_data ? $post_data['edit_url'] : null,
			);
		}

		return $insights;
	}

	/**
	 * Find top performing pages.
	 *
	 * @since    1.0.0
	 * @return   array    Array of insights.
	 */
	private function find_top_performers() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		$results = $wpdb->get_results(
			"SELECT * FROM $table_data
			WHERE clicks > 0
			ORDER BY clicks DESC
			LIMIT 10",
			ARRAY_A
		);

		$insights = array();

		foreach ( $results as $row ) {
			$post_data = $this->get_post_data( $row['post_id'] );

			$trend = '';
			if ( $row['prev_clicks'] > 0 ) {
				$change = $row['clicks'] - $row['prev_clicks'];
				if ( $change > 0 ) {
					$trend = sprintf( ' (+%s vs previous period)', number_format( $change ) );
				} elseif ( $change < 0 ) {
					$trend = sprintf( ' (%s vs previous period)', number_format( $change ) );
				}
			}

			$insights[] = array(
				'type'           => 'top_performer',
				'priority'       => 'info',
				'priority_score' => $this->calculate_priority_score( 'top_performer', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'Top performing page with %s clicks and %s impressions%s. Consider expanding this content or creating related articles.',
					number_format( $row['clicks'] ),
					number_format( $row['impressions'] ),
					$trend
				),
				'metrics'        => array(
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'ctr'         => round( (float) $row['ctr'] * 100, 2 ),
					'position'    => round( (float) $row['avg_position'], 1 ),
					'top_query'   => $row['top_query'],
				),
				'action'         => 'view_post',
				'action_label'   => 'View Page',
				'action_url'     => $post_data ? $post_data['view_url'] : $row['url'],
			);
		}

		return $insights;
	}

	/**
	 * Find keyword cannibalization — queries splitting traffic across pages.
	 *
	 * @since    1.1.0
	 * @return   array    Array of insights.
	 */
	private function find_cannibalization() {
		global $wpdb;

		$table_qs = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );
		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(snapshot_date) FROM $table_qs" );

		if ( ! $latest_date ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cannibalized = $wpdb->get_results( $wpdb->prepare(
			"SELECT query_hash, query, SUM(impressions) AS total_impressions, COUNT(DISTINCT url_hash) AS page_count
			FROM $table_qs
			WHERE snapshot_date = %s AND impressions >= 50
			GROUP BY query_hash, query
			HAVING page_count >= 2
			ORDER BY total_impressions DESC
			LIMIT 10",
			$latest_date
		), ARRAY_A );

		$insights = array();
		foreach ( $cannibalized as $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pages = $wpdb->get_results( $wpdb->prepare(
				"SELECT qs.url_hash, d.url, d.post_id, d.id AS page_id, qs.impressions, qs.clicks, qs.position
				FROM $table_qs qs
				LEFT JOIN $table_data d ON d.url_hash = qs.url_hash
				WHERE qs.query_hash = %s AND qs.snapshot_date = %s
				ORDER BY qs.impressions DESC LIMIT 5",
				$row['query_hash'],
				$latest_date
			), ARRAY_A );

			$urls = array_map( function( $p ) {
				return $p['url'] ?? '(unknown)';
			}, $pages );

			$first_page = $pages[0] ?? null;

			$insights[] = array(
				'type'           => 'cannibalization',
				'priority'       => 'high',
				'priority_score' => $this->calculate_priority_score( 'cannibalization', array( 'impressions' => $row['total_impressions'] ) ),
				'page_id'        => $first_page ? (int) $first_page['page_id'] : null,
				'url'            => $first_page ? $first_page['url'] : null,
				'post'           => $first_page ? $this->get_post_data( $first_page['post_id'] ) : null,
				'message'        => sprintf(
					'Query "%s" (%s impressions) is splitting traffic across %d pages: %s. Consolidate into one authoritative page.',
					$row['query'],
					number_format( $row['total_impressions'] ),
					$row['page_count'],
					implode( ', ', array_slice( $urls, 0, 3 ) )
				),
				'metrics'        => array(
					'query'             => $row['query'],
					'total_impressions' => (int) $row['total_impressions'],
					'page_count'        => (int) $row['page_count'],
					'competing_urls'    => $urls,
				),
				'action'         => 'view_cannibalization',
				'action_label'   => 'View Details',
				'action_url'     => null,
			);
		}

		return $insights;
	}

	/**
	 * Find content gaps — high-impression queries without dedicated content.
	 *
	 * @since    1.1.0
	 * @return   array    Array of insights.
	 */
	private function find_content_gaps() {
		global $wpdb;

		$table_qs = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_QUERY_SNAPSHOTS );
		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(snapshot_date) FROM $table_qs" );

		if ( ! $latest_date ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gaps = $wpdb->get_results( $wpdb->prepare(
			"SELECT qs.query, qs.impressions, qs.clicks, qs.position,
				d.url, d.post_id, d.id AS page_id
			FROM $table_qs qs
			LEFT JOIN $table_data d ON d.url_hash = qs.url_hash
			WHERE qs.snapshot_date = %s
				AND qs.impressions >= 200
				AND (d.post_id IS NULL OR qs.position > 15)
			ORDER BY qs.impressions DESC
			LIMIT 10",
			$latest_date
		), ARRAY_A );

		$insights = array();
		foreach ( $gaps as $row ) {
			$reason = empty( $row['post_id'] )
				? 'No dedicated WordPress content exists for this query.'
				: sprintf( 'Content exists but ranks poorly at position %.1f.', $row['position'] );

			$insights[] = array(
				'type'           => 'content_gap',
				'priority'       => 'medium',
				'priority_score' => $this->calculate_priority_score( 'content_gap', array( 'impressions' => $row['impressions'] ) ),
				'page_id'        => $row['page_id'] ? (int) $row['page_id'] : null,
				'url'            => $row['url'],
				'post'           => $this->get_post_data( $row['post_id'] ),
				'message'        => sprintf(
					'High-potential query "%s" has %s impressions but %s. Consider creating dedicated content.',
					$row['query'],
					number_format( $row['impressions'] ),
					$reason
				),
				'metrics'        => array(
					'query'       => $row['query'],
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],
					'position'    => round( (float) $row['position'], 1 ),
				),
				'action'         => 'create_content',
				'action_label'   => 'Create Content',
				'action_url'     => admin_url( 'post-new.php' ),
			);
		}

		return $insights;
	}

	/**
	 * Find pages with CTR significantly below site average for their position band.
	 *
	 * @since    1.1.0
	 * @return   array    Array of insights.
	 */
	private function find_ctr_underperformers() {
		global $wpdb;

		$table_data = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_DATA );
		$table_curve = WP_MCP_Connect_GSC::get_table_name( WP_MCP_Connect_GSC::TABLE_CTR_CURVE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_date = $wpdb->get_var( "SELECT MAX(computed_date) FROM $table_curve" );

		if ( ! $latest_date ) {
			return array();
		}

		// Get CTR curve data.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$curve_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT position_band, avg_ctr FROM $table_curve WHERE computed_date = %s",
			$latest_date
		), ARRAY_A );

		$curve = array();
		foreach ( $curve_rows as $cr ) {
			$curve[ $cr['position_band'] ] = (float) $cr['avg_ctr'];
		}

		if ( empty( $curve ) ) {
			return array();
		}

		// Get pages with significant traffic.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pages = $wpdb->get_results(
			"SELECT * FROM $table_data WHERE impressions >= 500 AND avg_position BETWEEN 1 AND 20 ORDER BY impressions DESC LIMIT 50",
			ARRAY_A
		);

		$insights = array();
		foreach ( $pages as $row ) {
			$band = $this->position_to_band( (float) $row['avg_position'] );
			$site_avg = $curve[ $band ] ?? null;

			if ( null === $site_avg || $site_avg <= 0 ) {
				continue;
			}

			$page_ctr = (float) $row['ctr'];
			$threshold = $site_avg * 0.7; // 30% below average.

			if ( $page_ctr >= $threshold ) {
				continue;
			}

			$post_data = $this->get_post_data( $row['post_id'] );
			$deficit = round( ( 1 - ( $page_ctr / $site_avg ) ) * 100, 0 );

			$insights[] = array(
				'type'           => 'ctr_underperformer',
				'priority'       => $deficit >= 50 ? 'high' : 'medium',
				'priority_score' => $this->calculate_priority_score( 'ctr_underperformer', $row ),
				'page_id'        => (int) $row['id'],
				'url'            => $row['url'],
				'post'           => $post_data,
				'message'        => sprintf(
					'CTR is %d%% below site average for position %s (%.2f%% vs %.2f%% avg). Improve title and meta description.',
					$deficit,
					$band,
					$page_ctr * 100,
					$site_avg * 100
				),
				'metrics'        => array(
					'impressions'    => (int) $row['impressions'],
					'clicks'         => (int) $row['clicks'],
					'page_ctr'       => round( $page_ctr * 100, 2 ),
					'site_avg_ctr'   => round( $site_avg * 100, 2 ),
					'position'       => round( (float) $row['avg_position'], 1 ),
					'position_band'  => $band,
					'deficit_percent' => $deficit,
				),
				'action'         => 'edit_seo',
				'action_label'   => 'Edit SEO',
				'action_url'     => $post_data ? $post_data['edit_url'] : null,
			);
		}

		return array_slice( $insights, 0, 15 );
	}

	/**
	 * Convert a position value to a band label.
	 *
	 * @since    1.1.0
	 * @param    float    $position    Average position.
	 * @return   string
	 */
	private function position_to_band( $position ) {
		$pos = round( $position );
		if ( $pos <= 3 ) {
			return (string) $pos;
		}
		if ( $pos <= 5 ) {
			return '4-5';
		}
		if ( $pos <= 10 ) {
			return '6-10';
		}
		if ( $pos <= 20 ) {
			return '11-20';
		}
		return '21+';
	}

	/**
	 * Calculate priority score for sorting.
	 *
	 * @since    1.0.0
	 * @param    string    $type    Insight type.
	 * @param    array     $data    Page data.
	 * @return   int                Priority score.
	 */
	private function calculate_priority_score( $type, $data ) {
		$base_scores = array(
			'not_indexed'        => 100,
			'cannibalization'    => 85,
			'ctr_opportunity'    => 80,
			'ctr_underperformer' => 75,
			'position_decline'   => 70,
			'content_gap'        => 60,
			'keyword_mismatch'   => 50,
			'stale_crawl'        => 30,
			'top_performer'      => 20,
		);

		$score = $base_scores[ $type ] ?? 0;

		// Adjust by traffic impact.
		$impressions = (int) ( $data['impressions'] ?? 0 );
		if ( $impressions > 10000 ) {
			$score += 20;
		} elseif ( $impressions > 1000 ) {
			$score += 10;
		} elseif ( $impressions > 100 ) {
			$score += 5;
		}

		// Adjust by linked WordPress post.
		if ( ! empty( $data['post_id'] ) ) {
			$score += 5;
		}

		return $score;
	}

	/**
	 * Get reason for indexing issue.
	 *
	 * @since    1.0.0
	 * @param    array    $row    Page data.
	 * @return   string           Human-readable reason.
	 */
	private function get_indexing_reason( $row ) {
		$state = $row['indexing_state'] ?? '';
		$robots = $row['robots_txt_state'] ?? '';

		if ( 'BLOCKED_BY_ROBOTS_TXT' === $robots ) {
			return 'The page is blocked by robots.txt.';
		}

		switch ( $state ) {
			case 'NOINDEX':
				return 'The page has a noindex directive.';
			case 'BLOCKED_BY_META_TAG':
				return 'The page is blocked by a meta robots tag.';
			case 'BLOCKED_BY_HTTP_HEADER':
				return 'The page is blocked by X-Robots-Tag HTTP header.';
			case 'BLOCKED_BY_ROBOTS_TXT':
				return 'The page is blocked by robots.txt.';
			case 'REDIRECT':
				return 'The page redirects to another URL.';
			case 'NOT_FOUND':
				return 'The page returns a 404 error.';
			case 'SERVER_ERROR':
				return 'The page returns a server error.';
			case 'CRAWLED_NOT_INDEXED':
				return 'Google crawled the page but chose not to index it. The content may need improvement.';
			case 'DISCOVERED_NOT_INDEXED':
				return 'Google discovered the page but hasn\'t crawled it yet.';
			default:
				return 'Check the URL inspection tool for details.';
		}
	}

	/**
	 * Get WordPress post data.
	 *
	 * @since    1.0.0
	 * @param    int|null    $post_id    Post ID.
	 * @return   array|null              Post data or null.
	 */
	private function get_post_data( $post_id ) {
		if ( empty( $post_id ) ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'type'     => $post->post_type,
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			'view_url' => get_permalink( $post->ID ),
		);
	}
}
