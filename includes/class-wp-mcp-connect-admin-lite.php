<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Admin_Lite {

	public function maybe_handle_gsc_callback() {
		if ( ! isset( $_GET['page'], $_GET['gsc_callback'] ) )                          return;
		if ( 'wp-mcp-connect' !== sanitize_key( wp_unslash( $_GET['page'] ) ) )         return;
		if ( ! current_user_can( 'manage_options' ) )                                   return;
		if ( ! class_exists( 'WP_MCP_Connect_GSC_Auth' ) )                              return;

		$auth    = new WP_MCP_Connect_GSC_Auth( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
		$request = new WP_REST_Request( 'GET', '/mcp/v1/gsc/auth/callback' );
		$request->set_param( 'code',  isset( $_GET['code'] )  ? sanitize_text_field( wp_unslash( $_GET['code']  ) ) : '' );
		$request->set_param( 'state', isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '' );

		$result = $auth->handle_callback( $request );
		$status = is_wp_error( $result ) ? 'error' : 'connected';
		$msg    = is_wp_error( $result ) ? $result->get_error_message() : '';

		set_transient( 'cwp_gsc_admin_notice_' . get_current_user_id(),
			array( 'status' => $status, 'message' => $msg ), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-connect&gsc=' . $status ) );
		exit;
	}

	public function handle_gsc_connect() {
		check_admin_referer( 'cwp_gsc_connect' );
		if ( ! current_user_can( 'manage_options' ) )      wp_die( 'Forbidden' );
		if ( ! class_exists( 'WP_MCP_Connect_GSC_Auth' ) ) wp_die( 'GSC class missing' );

		$auth   = new WP_MCP_Connect_GSC_Auth( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
		$result = $auth->get_auth_url();
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ) );

		$data = $result->get_data();
		wp_redirect( $data['url'] );
		exit;
	}

	public function handle_gsc_disconnect() {
		check_admin_referer( 'cwp_gsc_disconnect' );
		if ( ! current_user_can( 'manage_options' ) )      wp_die( 'Forbidden' );
		if ( ! class_exists( 'WP_MCP_Connect_GSC_Auth' ) ) wp_die( 'GSC class missing' );

		$auth = new WP_MCP_Connect_GSC_Auth( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
		$auth->disconnect();
		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-connect&gsc=disconnected' ) );
		exit;
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'MCP Connect Lite', 'wp-mcp-connect' ),
			__( 'MCP Connect Lite', 'wp-mcp-connect' ),
			'manage_options',
			'wp-mcp-connect',
			array( $this, 'render_page' ),
			'dashicons-rest-api',
			80
		);
	}

	public function render_page() {
		$last_access = get_option( 'cwp_api_last_access', array() );
		$connected   = ! empty( $last_access['timestamp'] );
		$recent      = $connected && ( time() - $last_access['timestamp'] ) < 86400;

		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$ops = $this->get_ops_log( $per_page, ( $page - 1 ) * $per_page );
		$total = $this->get_ops_count();
		$total_pages = (int) ceil( $total / $per_page );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Connect Lite', 'wp-mcp-connect' ); ?></h1>

			<?php
			$gsc_connected = false;
			$gsc_has_creds = false;
			$gsc_site      = '';
			if ( class_exists( 'WP_MCP_Connect_GSC_Auth' ) ) {
				$gsc_auth     = new WP_MCP_Connect_GSC_Auth( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
				$gsc_response = $gsc_auth->get_status();
				$gsc_data     = ( $gsc_response instanceof WP_REST_Response ) ? $gsc_response->get_data() : array();
				if ( ! is_array( $gsc_data ) ) {
					$gsc_data = array();
				}
				$gsc_connected = ! empty( $gsc_data['is_connected'] );
				$gsc_has_creds = ! empty( $gsc_data['has_credentials'] );
				$gsc_site      = $gsc_data['site_url'] ?? '';
			}
			$gsc_notice_key = 'cwp_gsc_admin_notice_' . get_current_user_id();
			$gsc_notice     = get_transient( $gsc_notice_key );
			if ( $gsc_notice ) delete_transient( $gsc_notice_key );
			?>
			<div class="cwp-status-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $gsc_connected ? '#00a32a' : '#dba617'; ?>;padding:16px 20px;margin:20px 0;max-width:700px;">
				<h2 style="margin:0 0 8px;font-size:15px;">
					<?php esc_html_e( 'Google Search Console', 'wp-mcp-connect' ); ?> &mdash;
					<?php
					if ( $gsc_connected ) {
						esc_html_e( 'Connected', 'wp-mcp-connect' );
					} elseif ( $gsc_has_creds ) {
						esc_html_e( 'Not connected', 'wp-mcp-connect' );
					} else {
						esc_html_e( 'Credentials missing', 'wp-mcp-connect' );
					}
					?>
				</h2>

				<?php if ( $gsc_notice && 'error' === $gsc_notice['status'] ) : ?>
					<div class="notice notice-error inline" style="margin:8px 0;"><p><?php echo esc_html( $gsc_notice['message'] ); ?></p></div>
				<?php elseif ( $gsc_notice && 'connected' === $gsc_notice['status'] ) : ?>
					<div class="notice notice-success inline" style="margin:8px 0;"><p><?php esc_html_e( 'Successfully connected.', 'wp-mcp-connect' ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! $gsc_has_creds ) : ?>
					<p class="description" style="margin:0;">
						<?php
						printf(
							/* translators: 1: CWP_GSC_CLIENT_ID constant, 2: CWP_GSC_CLIENT_SECRET constant, 3: wp-config.php filename */
							esc_html__( 'Add %1$s and %2$s to %3$s.', 'wp-mcp-connect' ),
							'<code>CWP_GSC_CLIENT_ID</code>',
							'<code>CWP_GSC_CLIENT_SECRET</code>',
							'<code>wp-config.php</code>'
						);
						?>
					</p>
				<?php elseif ( $gsc_connected ) : ?>
					<?php if ( $gsc_site ) : ?>
						<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Site:', 'wp-mcp-connect' ); ?> <code><?php echo esc_html( $gsc_site ); ?></code></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="cwp_gsc_disconnect">
						<?php wp_nonce_field( 'cwp_gsc_disconnect' ); ?>
						<button class="button"><?php esc_html_e( 'Disconnect', 'wp-mcp-connect' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="cwp_gsc_connect">
						<?php wp_nonce_field( 'cwp_gsc_connect' ); ?>
						<button class="button button-primary"><?php esc_html_e( 'Connect to Google Search Console', 'wp-mcp-connect' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="cwp-status-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $recent ? '#00a32a' : ( $connected ? '#dba617' : '#d63638' ); ?>;padding:16px 20px;margin:20px 0;max-width:700px;">
				<h2 style="margin:0 0 8px;font-size:15px;">
					<?php if ( $recent ) : ?>
						&#9679; <?php esc_html_e( 'Connected', 'wp-mcp-connect' ); ?>
					<?php elseif ( $connected ) : ?>
						&#9679; <?php esc_html_e( 'Idle', 'wp-mcp-connect' ); ?>
					<?php else : ?>
						&#9679; <?php esc_html_e( 'Not connected', 'wp-mcp-connect' ); ?>
					<?php endif; ?>
				</h2>
				<?php if ( $connected ) : ?>
					<table class="form-table" style="margin:0;">
						<tr><th style="padding:4px 10px 4px 0;width:120px;"><?php esc_html_e( 'Last seen', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><?php echo esc_html( $this->time_ago( $last_access['timestamp'] ) ); ?> <span class="description">(<?php echo esc_html( wp_date( 'M j, Y g:i a', $last_access['timestamp'] ) ); ?>)</span></td></tr>
						<?php if ( ! empty( $last_access['user_login'] ) ) : ?>
						<tr><th style="padding:4px 10px 4px 0;"><?php esc_html_e( 'User', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><?php echo esc_html( $last_access['user_login'] ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $last_access['ip'] ) ) : ?>
						<tr><th style="padding:4px 10px 4px 0;"><?php esc_html_e( 'IP address', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><code><?php echo esc_html( $last_access['ip'] ); ?></code></td></tr>
						<?php endif; ?>
					</table>
				<?php else : ?>
					<p class="description" style="margin:0;"><?php esc_html_e( 'No MCP client has connected yet. Configure your MCP server with this site\'s URL and an application password.', 'wp-mcp-connect' ); ?></p>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Change Log', 'wp-mcp-connect' ); ?>
				<?php if ( $total > 0 ) : ?>
					<span class="description" style="font-size:13px;font-weight:normal;"> &mdash; <?php echo esc_html( number_format_i18n( $total ) ); ?> <?php esc_html_e( 'operations', 'wp-mcp-connect' ); ?></span>
				<?php endif; ?>
			</h2>

			<?php if ( empty( $ops ) ) : ?>
				<p class="description"><?php esc_html_e( 'No operations recorded yet. Changes made via MCP will appear here.', 'wp-mcp-connect' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:150px;"><?php esc_html_e( 'Date', 'wp-mcp-connect' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Operation', 'wp-mcp-connect' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wp-mcp-connect' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Status', 'wp-mcp-connect' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ops as $op ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $op->created_at ) ) ); ?></td>
								<td><code><?php echo esc_html( $op->op_type ); ?></code></td>
								<td><?php echo esc_html( $this->summarize_payload( $op->op_type, $op->payload ) ); ?></td>
								<td>
									<?php if ( 'rolled_back' === $op->status ) : ?>
										<span style="color:#d63638;">&#8634; <?php echo esc_html( $op->status ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $op->status ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom" style="max-width:900px;">
						<div class="tablenav-pages">
							<?php
							echo paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_ops_log( $limit, $offset ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_ops_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT op_type, payload, status, created_at FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
	}

	private function get_ops_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_ops_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + hardcoded constant, existence already checked above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	private function time_ago( $timestamp ) {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'just now', 'wp-mcp-connect' );
		}
		if ( $diff < 3600 ) {
			$mins = (int) floor( $diff / 60 );
			return sprintf( _n( '%d minute ago', '%d minutes ago', $mins, 'wp-mcp-connect' ), $mins );
		}
		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'wp-mcp-connect' ), $hours );
		}
		$days = (int) floor( $diff / 86400 );
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'wp-mcp-connect' ), $days );
	}

	private function summarize_payload( $op_type, $payload_json ) {
		if ( empty( $payload_json ) ) {
			return '';
		}

		$data = json_decode( $payload_json, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$title = $data['title'] ?? $data['post_title'] ?? $data['name'] ?? '';
		$id    = $data['id'] ?? $data['post_id'] ?? '';

		$parts = array();
		if ( $id ) {
			$parts[] = '#' . $id;
		}
		if ( $title ) {
			$parts[] = '"' . mb_strimwidth( $title, 0, 60, '...' ) . '"';
		}

		if ( empty( $parts ) ) {
			$keys = array_keys( $data );
			$keys = array_filter( $keys, function ( $k ) {
				return ! in_array( $k, array( 'previous_state', 'nonce' ), true );
			} );
			return implode( ', ', array_slice( $keys, 0, 4 ) );
		}

		return implode( ' ', $parts );
	}
}
