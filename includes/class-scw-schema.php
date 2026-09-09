<?php
/**
 * Esquema de base de datos del plugin.
 *
 * Tres tablas propias:
 *  - {prefix}scw_queue  : cola persistente de URLs con leases.
 *  - {prefix}scw_runs   : una fila por petición HTTP realizada (log de request).
 *  - {prefix}scw_events : todo lo que NO es una petición (scheduler, breaker, fatales).
 *
 * La separación entre runs y events es la que permite distinguir un fallo HTTP
 * de un fallo del planificador, tal y como se acordó en la arquitectura.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Schema {

	/** Versión del esquema. Incrementar al cambiar cualquier CREATE TABLE. */
	const DB_VERSION = '1';

	/** Opción donde se guarda la versión instalada del esquema. */
	const OPTION_DB_VERSION = 'scw_db_version';

	/**
	 * Devuelve el nombre completo de una tabla usando siempre el prefijo dinámico.
	 *
	 * @param string $name queue|runs|events.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'scw_' . $name;
	}

	/**
	 * Lista de nombres lógicos de tabla.
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array( 'queue', 'runs', 'events' );
	}

	/**
	 * Crea o actualiza las tablas mediante dbDelta.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$zero            = "'1970-01-01 00:00:00'";

		$queue  = self::table( 'queue' );
		$runs   = self::table( 'runs' );
		$events = self::table( 'events' );

		$sql_queue = "CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(40) NOT NULL,
			url varchar(2048) NOT NULL,
			object_type varchar(32) NOT NULL DEFAULT 'unknown',
			source varchar(191) NOT NULL DEFAULT '',
			priority smallint(5) unsigned NOT NULL DEFAULT 50,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL DEFAULT {$zero},
			lease_owner char(32) DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			last_result varchar(32) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT {$zero},
			updated_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY claim (status,available_at,priority),
			KEY lease_expires_at (lease_expires_at),
			KEY object_type (object_type)
		) {$charset_collate};";

		$sql_runs = "CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url_hash char(40) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL DEFAULT '',
			session_id char(32) NOT NULL DEFAULT '',
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			requested_at datetime NOT NULL DEFAULT {$zero},
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			bytes int(10) unsigned NOT NULL DEFAULT 0,
			x_litespeed_cache varchar(32) DEFAULT NULL,
			x_litespeed_cache_control varchar(191) DEFAULT NULL,
			vary varchar(191) DEFAULT NULL,
			content_type varchar(191) DEFAULT NULL,
			user_agent varchar(255) DEFAULT NULL,
			error_type varchar(32) DEFAULT NULL,
			error_message text,
			validation_result varchar(32) DEFAULT NULL,
			yith_presets varchar(191) DEFAULT NULL,
			diagnostics longtext,
			PRIMARY KEY  (id),
			KEY url_hash (url_hash),
			KEY session_id (session_id),
			KEY requested_at (requested_at),
			KEY validation_result (validation_result),
			KEY error_type (error_type)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT {$zero},
			level varchar(16) NOT NULL DEFAULT 'info',
			code varchar(48) NOT NULL DEFAULT '',
			message text,
			context longtext,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY code (code),
			KEY level (level)
		) {$charset_collate};";

		dbDelta( $sql_queue );
		dbDelta( $sql_runs );
		dbDelta( $sql_events );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Ejecuta install() sólo si la versión de esquema guardada no coincide.
	 *
	 * @return bool True si se ha ejecutado una migración.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return false;
		}

		self::install();
		return true;
	}

	/**
	 * Comprueba si una tabla existe realmente en la base de datos.
	 *
	 * @param string $name Nombre lógico.
	 * @return bool
	 */
	public static function table_exists( $name ) {
		global $wpdb;

		$table = self::table( $name );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $found === $table;
	}

	/**
	 * Número de filas de una tabla. Devuelve null si la tabla no existe.
	 *
	 * @param string $name Nombre lógico.
	 * @return int|null
	 */
	public static function row_count( $name ) {
		global $wpdb;

		if ( ! self::table_exists( $name ) ) {
			return null;
		}

		$table = self::table( $name );

		// El nombre de tabla no puede parametrizarse; se construye internamente.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Elimina todas las tablas del plugin. Sólo se invoca desde uninstall.php
	 * y únicamente si el ajuste delete_data_on_uninstall está activo.
	 *
	 * @return void
	 */
	public static function drop_all() {
		global $wpdb;

		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
