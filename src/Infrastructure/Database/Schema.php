<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and DDL.
 *
 * Rules live in a real table, not in serialised meta, because release 1.3 has
 * to answer "which courses depend on this one" and "which learners are stuck"
 * without unserialising the whole catalogue.
 */
final class Schema {

	/** Bump whenever the DDL below changes. */
	public const DB_VERSION = '1.0.0';

	public const OPTION_DB_VERSION = 'tlp_db_version';

	public static function rules_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tlp_course_rules';
	}

	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tlp_activity_log';
	}

	/**
	 * Create or update tables via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$rules   = self::rules_table();
		$log     = self::log_table();

		/*
		 * Index notes:
		 *  - target_enabled  drives "which rules protect this course", the hot read.
		 *  - source_type     drives dependency and cycle checks in the other direction.
		 */
		$sql = array();

		$sql[] = "CREATE TABLE {$rules} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			target_course_id BIGINT UNSIGNED NOT NULL,
			rule_type VARCHAR(64) NOT NULL,
			operator VARCHAR(32) NOT NULL DEFAULT '',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			value LONGTEXT NULL,
			rule_group SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY target_enabled (target_course_id, enabled),
			KEY source_type (source_id, rule_type),
			KEY rule_group (target_course_id, rule_group, position)
		) {$charset};";

		$sql[] = "CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(191) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_time (event, created_at),
			KEY user_course (user_id, course_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Whether the installed schema is behind the shipped one.
	 */
	public static function needs_upgrade(): bool {
		return version_compare( (string) get_option( self::OPTION_DB_VERSION, '0' ), self::DB_VERSION, '<' );
	}
}
