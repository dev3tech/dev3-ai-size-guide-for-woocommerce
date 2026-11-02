<?php
/**
 * Plugin tables: return records and storefront events.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + dbDelta SQL (hooked to dev3sg_schema_sql).
 */
class Tables {

	/**
	 * Returns table.
	 *
	 * @return string
	 */
	public static function returns() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sg_returns';
	}

	/**
	 * Events table.
	 *
	 * @return string
	 */
	public static function events() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sg_events';
	}

	/**
	 * Adds CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$returns = self::returns();
		$events  = self::events();
		$sql[]   = "CREATE TABLE {$returns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chart_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			size varchar(40) NOT NULL DEFAULT '',
			reason varchar(12) NOT NULL DEFAULT 'unknown',
			source varchar(12) NOT NULL DEFAULT 'manual',
			note text NOT NULL,
			ai_reason varchar(12) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY chart_size (chart_id,size),
			KEY product (product_id),
			KEY created (created_at),
			KEY source_item (source,order_id,item_id)
		) {$charset};";
		$sql[]   = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(12) NOT NULL DEFAULT '',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chart_id bigint(20) unsigned NOT NULL DEFAULT 0,
			size varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_created (type,created_at),
			KEY product (product_id)
		) {$charset};";
		return $sql;
	}
}
