<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiSizeGuide
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3sg_settings = get_option( 'dev3sg_settings', array() );
if ( empty( $dev3sg_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3sg_usage', 'dev3sg_core_cache', 'dev3sg_core_jobs', 'dev3sg_core_embeddings', 'dev3sg_returns', 'dev3sg_events' ) as $dev3sg_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3sg_table}" );
}
foreach ( array( 'dev3sg_settings', 'dev3sg_license', 'dev3sg_db_version', 'dev3sg_dismissed' ) as $dev3sg_option ) {
	delete_option( $dev3sg_option );
}
delete_transient( 'dev3sg_models' );
delete_site_transient( 'dev3sg_update_info' );
wp_unschedule_hook( 'dev3sg_run_job' );
delete_transient( 'dev3sg_rules_index' );
delete_transient( 'dev3sg_insights' );
delete_transient( 'dev3sg_sold_units' );
foreach ( get_posts(
	array(
		'post_type'   => 'dev3sg_chart',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
) as $dev3sg_chart ) {
	wp_delete_post( $dev3sg_chart, true );
}
delete_post_meta_by_key( '_dev3sg_chart' );
