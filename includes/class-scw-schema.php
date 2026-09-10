<?php
/**
 * Esquema de base de datos del plugin.
 *
 * Tres tablas propias:
 *  - {prefix}scw_queue  : cola persistente de URLs con locks.
 *  - {prefix}scw_runs   : una fila por petición HTTP realizada (log de request).
 *  - {prefix}scw_events : todo lo que NO es una petición (scheduler, breaker, fatales).
 *
 * Versión 2 (F2.1): la tabla de cola se alinea con la especificación de la cola
 * persistente. Cambios respecto a la versión 1:
 *   object_type      -> type
 *   lease_owner      -> lock_token
 *   lease_expires_at -> locked_at
 *   + max_attempts
 *   + last_error
 * Las tablas runs y events NO cambian.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Schema {

	/** Versión del esquema. Incrementar al cambiar cualquier CREATE TABLE. */
	const DB_VERSION = '2';

	/** Opción donde se guarda la versión instalada del esquema. */
	const OPTION_DB_VERSION = 'scw_db_version';

	/** Códigos de evento propios de las migraciones. */
	const EVENT_MIGRATION_ABORTED     = 'SCHEMA_MIGRATION_ABORTED';
	const EVENT_MIGRATION_FAILED      = 'SCHEMA_MIGRATION_FAILED';
	const EVENT_MIGRATION_DONE        = 'SCHEMA_MIGRATION_DONE';
	const EVENT_MIGRATION_MIXED_STATE = 'SCHEMA_MIGRATION_MIXED_STATE';
	const EVENT_INSTALL_FAILED        = 'SCHEMA_INSTALL_FAILED';
	const EVENT_VERSION_MISSING       = 'SCHEMA_VERSION_MISSING';

	/** Columnas que definen la versión 2 de la tabla de cola. */
	const QUEUE_V2_COLUMNS = array( 'type', 'lock_token', 'locked_at', 'max_attempts', 'last_error' );

	/** Columnas de la versión 1 que no deben sobrevivir a la migración. */
	const QUEUE_V1_COLUMNS = array( 'object_type', 'lease_owner', 'lease_expires_at' );

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
	 * Punto de entrada único de instalación y actualización del esquema.
	 *
	 * Toda ruta que cree tablas pasa por aquí, incluida la activación, para que
	 * ninguna migración pueda saltarse el guard de seguridad. La decisión de
	 * migrar no se toma únicamente por la versión guardada en la opción, sino
	 * inspeccionando la tabla real siempre que esa versión no coincida con la
	 * esperada.
	 *
	 * @return bool True si el esquema queda en la versión esperada.
	 */
	public static function install() {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '' );
		$recreated = false;

		// Una versión ausente NO significa instalación limpia: la opción puede
		// haberse perdido (restauración parcial, borrado manual) con la tabla
		// todavía presente. Por eso, mientras la versión no sea exactamente la
		// esperada, se inspecciona la tabla antes de dejar que dbDelta la toque.
		// upgrade_to_2() resuelve los cuatro casos: sin tabla o ya en v2 sigue
		// adelante; con columnas de v1 aplica el guard completo; en estado mixto
		// aborta.
		if ( self::DB_VERSION !== $installed ) {
			if ( '' === $installed && self::table_exists( 'queue' ) ) {
				SCW_Logger::warning(
					self::EVENT_VERSION_MISSING,
					'No consta la versión de esquema pero la tabla de cola ya existe. Se inspecciona la tabla antes de decidir si hay que migrarla.',
					array(
						'table'   => self::table( 'queue' ),
						'columns' => self::get_columns( 'queue' ),
					)
				);
			}

			$migration = self::upgrade_to_2();

			if ( false === $migration ) {
				return false;
			}

			$recreated = ( 'recreated' === $migration );
		}

		self::create_tables();

		// La creación se verifica SIEMPRE antes de dar nada por bueno: dbDelta no
		// devuelve un resultado fiable y un fallo silencioso dejaría el esquema a
		// medias con la versión marcada como correcta.
		if ( ! self::queue_is_v2() ) {
			SCW_Logger::error(
				self::EVENT_INSTALL_FAILED,
				'La tabla de cola no ha quedado con el esquema de la versión 2 tras ejecutar dbDelta. La versión de esquema no se actualiza.',
				array(
					'table'     => self::table( 'queue' ),
					'exists'    => self::table_exists( 'queue' ),
					'recreated' => $recreated,
				)
			);
			return false;
		}

		if ( $recreated ) {
			SCW_Logger::info(
				self::EVENT_MIGRATION_DONE,
				'Migración completada: la tabla de cola se ha recreado y verificado con el esquema de la versión 2.',
				array( 'table' => self::table( 'queue' ) )
			);
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );

		return true;
	}

	/**
	 * Crea o actualiza las tablas mediante dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables() {
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
			type varchar(32) NOT NULL DEFAULT 'unknown',
			source varchar(191) NOT NULL DEFAULT '',
			priority smallint(5) unsigned NOT NULL DEFAULT 50,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint(3) unsigned NOT NULL DEFAULT 2,
			available_at datetime NOT NULL DEFAULT {$zero},
			locked_at datetime DEFAULT NULL,
			lock_token char(32) DEFAULT NULL,
			last_result varchar(32) DEFAULT NULL,
			last_error varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT {$zero},
			updated_at datetime NOT NULL DEFAULT {$zero},
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY claim (status,available_at,priority),
			KEY locked_at (locked_at),
			KEY type (type)
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
	}

	/**
	 * Migración de la versión 1 a la 2.
	 *
	 * dbDelta no sabe renombrar columnas: si se limitara a ejecutarse dejaría las
	 * tres columnas antiguas huérfanas junto a las nuevas. Como F1 nunca insertó
	 * ninguna fila en la cola, la migración recrea la tabla, pero sólo después de
	 * comprobarlo explícitamente.
	 *
	 * Garantías:
	 *  1. Comprueba que la tabla existe.
	 *  2. Considera la tabla migrada sólo si tiene TODAS las columnas de v2 y
	 *     NINGUNA de v1.
	 *  3. Un estado mixto (columnas de v1 y v2 a la vez) aborta sin tocar la
	 *     tabla ni borrar datos, y queda registrado de forma explícita.
	 *  4. Cuenta las filas antes de modificar nada.
	 *  5. Con 1 o más filas, aborta sin modificarla.
	 *  6. Vacía, la elimina para que dbDelta la recree con el esquema F2.1.
	 *  7. Si algo falla devuelve false y la versión de esquema no se actualiza.
	 *  8. Registra un evento de error al abortar o fallar.
	 *  9. El evento de migración completada lo emite install(), no este método,
	 *     y sólo tras verificar la tabla recreada.
	 * 10. No toca scw_runs ni scw_events.
	 *
	 * @return string|false 'skip' si no había nada que migrar, 'recreated' si la
	 *                      tabla se ha eliminado para recrearla, false si se ha
	 *                      abortado o ha fallado.
	 */
	private static function upgrade_to_2() {
		global $wpdb;

		// 1. Sin tabla no hay nada que migrar: dbDelta la creará ya en v2.
		if ( ! self::table_exists( 'queue' ) ) {
			return 'skip';
		}

		$columns = self::get_columns( 'queue' );
		$has_v2  = array_intersect( self::QUEUE_V2_COLUMNS, $columns );
		$has_v1  = array_intersect( self::QUEUE_V1_COLUMNS, $columns );

		// Ya está en v2: todas las columnas nuevas y ninguna de las antiguas.
		if ( count( $has_v2 ) === count( self::QUEUE_V2_COLUMNS ) && empty( $has_v1 ) ) {
			return 'skip';
		}

		$table = self::table( 'queue' );

		// Estado mixto: conviven columnas de v1 y de v2. Sólo puede venir de una
		// migración interrumpida o de una intervención manual. No se toca nada,
		// ni siquiera si la tabla estuviera vacía: es una anomalía que merece que
		// la mire una persona antes de destruir la evidencia.
		if ( ! empty( $has_v2 ) && ! empty( $has_v1 ) ) {
			SCW_Logger::error(
				self::EVENT_MIGRATION_MIXED_STATE,
				'La tabla de cola contiene a la vez columnas de la versión 1 y de la versión 2. Migración abortada sin modificar la tabla ni borrar datos. Requiere revisión manual.',
				array(
					'table'      => $table,
					'v1_columns' => array_values( $has_v1 ),
					'v2_columns' => array_values( $has_v2 ),
				)
			);
			return false;
		}

		// 2. Contar filas.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

		if ( null === $count ) {
			SCW_Logger::error(
				self::EVENT_MIGRATION_FAILED,
				'No se ha podido contar las filas de la cola. Migración a la versión 2 cancelada.',
				array(
					'table'    => $table,
					'db_error' => $wpdb->last_error,
				)
			);
			return false;
		}

		$count = (int) $count;

		// 3. Con datos no se toca. La migración quedaría en nuestras manos.
		if ( $count > 0 ) {
			SCW_Logger::error(
				self::EVENT_MIGRATION_ABORTED,
				sprintf(
					'La cola contiene %d filas. Migración a la versión 2 abortada sin modificar la tabla. El esquema permanece en la versión 1.',
					$count
				),
				array(
					'table' => $table,
					'rows'  => $count,
				)
			);
			return false;
		}

		// 4. Tabla vacía: se elimina para que dbDelta la recree limpia.
		$dropped = $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

		if ( false === $dropped || self::table_exists( 'queue' ) ) {
			SCW_Logger::error(
				self::EVENT_MIGRATION_FAILED,
				'No se ha podido eliminar la tabla de cola vacía. Migración a la versión 2 cancelada.',
				array(
					'table'    => $table,
					'db_error' => $wpdb->last_error,
				)
			);
			return false;
		}

		// El evento de migración completada NO se registra aquí: en este punto la
		// tabla sólo se ha eliminado. install() lo registrará después de crearla
		// y verificarla.
		return 'recreated';
	}

	/**
	 * Ejecuta install() sólo si la versión de esquema guardada no coincide.
	 *
	 * @return bool True si se ha ejecutado y completado una migración.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return false;
		}

		return self::install();
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
	 * Nombres de las columnas de una tabla del plugin.
	 *
	 * @param string $name Nombre lógico de tabla.
	 * @return string[] Vacío si la tabla no existe.
	 */
	public static function get_columns( $name ) {
		global $wpdb;

		if ( ! self::table_exists( $name ) ) {
			return array();
		}

		$table   = self::table( $name );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB

		return is_array( $columns ) ? $columns : array();
	}

	/**
	 * Comprueba si una columna existe en una tabla del plugin.
	 *
	 * @param string $name   Nombre lógico de tabla.
	 * @param string $column Nombre de columna.
	 * @return bool
	 */
	public static function column_exists( $name, $column ) {
		return in_array( $column, self::get_columns( $name ), true );
	}

	/**
	 * Comprueba que la tabla de cola tiene exactamente la forma de la versión 2.
	 *
	 * Exige las cinco columnas nuevas y, además, la ausencia de las tres
	 * antiguas: una tabla que las tuviera todas a la vez sería un estado mixto,
	 * no una migración completada.
	 *
	 * @return bool
	 */
	public static function queue_is_v2() {
		if ( ! self::table_exists( 'queue' ) ) {
			return false;
		}

		$columns = self::get_columns( 'queue' );

		foreach ( self::QUEUE_V2_COLUMNS as $column ) {
			if ( ! in_array( $column, $columns, true ) ) {
				return false;
			}
		}

		foreach ( self::QUEUE_V1_COLUMNS as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				return false;
			}
		}

		return true;
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
