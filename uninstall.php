<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'cwp_redirect_rules' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cwp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cwp_' ) . '%'
	)
);

$redirect_posts = get_posts( array(
	'post_type'      => 'cwp_redirect',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

foreach ( $redirect_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_cwp_' ) . '%'
	)
);

// Remove task posts.
$task_posts = get_posts( array(
	'post_type'      => 'cwp_task',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

foreach ( $task_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cwp_404_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cwp_ops_log" );
