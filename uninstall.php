<?php
/**
 * Uninstall handler.
 *
 * Default policy is to KEEP all data. Data is only removed when the site owner
 * explicitly opted in through Settings -> Uninstall behaviour.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$policy = get_option( 'tlp_uninstall_policy', 'keep' );

if ( 'keep' === $policy ) {
	return;
}

$tables = array(
	$wpdb->prefix . 'tlp_activity_log',
	$wpdb->prefix . 'tlp_course_rules',
);

if ( 'cache_and_logs' === $policy ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tlp_activity_log" );
	delete_option( 'tlp_rules_version' );
	delete_option( 'tlp_hidden_mode_courses' );
	return;
}

if ( 'everything' === $policy ) {
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_tlp_config'" );

	delete_option( 'tlp_db_version' );
	delete_option( 'tlp_rule_schema_version' );
	delete_option( 'tlp_rules_version' );
	delete_option( 'tlp_settings' );
	delete_option( 'tlp_uninstall_policy' );
	delete_option( 'tlp_hidden_mode_courses' );
}
