<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Rules {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Get all rules.
	 */
	public static function get_rules() {
		return get_option( 'cwp_automation_rules', array() );
	}

	/**
	 * Save a rule.
	 */
	public static function save_rule( $rule ) {
		$rules            = self::get_rules();
		$rule['id']       = uniqid( 'rule_' );
		$rule['created']  = gmdate( 'c' );
		$rule['enabled']  = true;
		$rule['last_run'] = null;
		$rule['run_count'] = 0;
		$rules[]          = $rule;
		update_option( 'cwp_automation_rules', $rules );
		return $rule;
	}

	/**
	 * Delete a rule by ID.
	 */
	public static function delete_rule( $rule_id ) {
		$rules = self::get_rules();
		$rules = array_values( array_filter( $rules, function ( $r ) use ( $rule_id ) {
			return $r['id'] !== $rule_id;
		} ) );
		update_option( 'cwp_automation_rules', $rules );
		return true;
	}

	/**
	 * Toggle a rule on/off.
	 */
	public static function toggle_rule( $rule_id ) {
		$rules = self::get_rules();
		foreach ( $rules as &$rule ) {
			if ( $rule['id'] === $rule_id ) {
				$rule['enabled'] = ! $rule['enabled'];
				break;
			}
		}
		update_option( 'cwp_automation_rules', $rules );
		return $rules;
	}

	/**
	 * Evaluate all enabled rules. Called via wp-cron.
	 */
	public function evaluate_rules() {
		$rules    = self::get_rules();
		$executed = 0;

		foreach ( $rules as &$rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			$triggered = $this->evaluate_condition( $rule );
			if ( $triggered ) {
				$this->execute_action( $rule );
				$rule['last_run']  = gmdate( 'c' );
				$rule['run_count'] = ( $rule['run_count'] ?? 0 ) + 1;
				$executed++;
			}
		}

		if ( $executed > 0 ) {
			update_option( 'cwp_automation_rules', $rules );
		}

		return $executed;
	}

	/**
	 * Evaluate a rule's condition.
	 */
	private function evaluate_condition( $rule ) {
		$condition = $rule['condition'] ?? '';
		$threshold = $rule['threshold'] ?? 0;

		switch ( $condition ) {
			case 'health_score_below':
				global $wpdb;
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta}
					 WHERE meta_key = '_cwp_health_score' AND CAST(meta_value AS UNSIGNED) < %d",
					(int) $threshold
				) );
				return $count > 0;

			case 'missing_meta_description':
				global $wpdb;
				$post_types   = get_post_types( array( 'public' => true ), 'names' );
				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				$count        = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 AND p.ID NOT IN (
						 SELECT post_id FROM {$wpdb->postmeta}
						 WHERE meta_key = '_cwp_seo_description' AND meta_value != ''
					 )",
					...array_values( $post_types )
				) );
				return $count > 0;

			case 'orphan_pages_exist':
				global $wpdb;
				$table = $wpdb->prefix . 'cwp_internal_links';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
					return false;
				}
				$post_types   = get_post_types( array( 'public' => true ), 'names' );
				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				$count        = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 AND p.ID NOT IN (SELECT DISTINCT target_post_id FROM {$table})",
					...array_values( $post_types )
				) );
				return $count > 0;

			default:
				return false;
		}
	}

	/**
	 * Execute a rule's action.
	 */
	private function execute_action( $rule ) {
		$action = $rule['action'] ?? '';

		switch ( $action ) {
			case 'create_tasks':
				if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
					WP_MCP_Connect_Audit_Log::log( 'rule_executed', sprintf(
						'Automation rule "%s" triggered: %s',
						$rule['name'] ?? $rule['id'],
						$rule['condition'] ?? 'unknown'
					) );
				}
				break;

			case 'send_notification':
				$to      = get_option( 'admin_email' );
				$subject = sprintf( '[%s] SEO Automation Alert', get_bloginfo( 'name' ) );
				$message = sprintf(
					"Automation rule \"%s\" was triggered.\n\nCondition: %s\nThreshold: %s\n\nPlease review in your WordPress admin panel.",
					$rule['name'] ?? $rule['id'],
					$rule['condition'] ?? 'unknown',
					$rule['threshold'] ?? 'N/A'
				);
				wp_mail( $to, $subject, $message );
				break;
		}
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/rules', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_list_rules' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_create_rule' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'name'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'condition' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'threshold' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'action'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/rules/(?P<id>[a-z0-9_]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_delete_rule' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( 'mcp/v1', '/rules/(?P<id>[a-z0-9_]+)/toggle', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_toggle_rule' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( 'mcp/v1', '/rules/evaluate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_evaluate_rules' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	public function rest_list_rules() {
		return rest_ensure_response( array(
			'rules'                => self::get_rules(),
			'available_conditions' => array(
				'health_score_below'       => 'Health score below threshold',
				'missing_meta_description' => 'Posts missing meta description',
				'orphan_pages_exist'       => 'Orphan pages exist (no inlinks)',
			),
			'available_actions'    => array(
				'create_tasks'      => 'Create tasks in the task queue',
				'send_notification' => 'Send email notification to admin',
			),
		) );
	}

	public function rest_create_rule( $request ) {
		$rule = self::save_rule( array(
			'name'      => $request->get_param( 'name' ),
			'condition' => $request->get_param( 'condition' ),
			'threshold' => $request->get_param( 'threshold' ),
			'action'    => $request->get_param( 'action' ),
		) );
		return rest_ensure_response( array( 'success' => true, 'rule' => $rule ) );
	}

	public function rest_delete_rule( $request ) {
		self::delete_rule( $request->get_param( 'id' ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function rest_toggle_rule( $request ) {
		$rules = self::toggle_rule( $request->get_param( 'id' ) );
		return rest_ensure_response( array( 'success' => true, 'rules' => $rules ) );
	}

	public function rest_evaluate_rules() {
		$executed = $this->evaluate_rules();
		return rest_ensure_response( array( 'executed' => $executed ) );
	}
}
