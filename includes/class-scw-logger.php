<?php
/**
 * Registro de eventos.
 *
 * Esta tabla guarda TODO lo que no es una petición HTTP: activaciones,
 * arranques y paradas, fallos del planificador, leases caducados, fatales del
 * worker y aperturas del circuit breaker. Los fallos HTTP van a scw_runs y no
 * pasan por aquí. Esa separación es la que permite que el panel no diga nunca
 * "el calentamiento ha fallado" cuando lo que falló fue el cron.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Logger {

	const LEVEL_DEBUG    = 'debug';
	const LEVEL_INFO     = 'info';
	const LEVEL_WARNING  = 'warning';
	const LEVEL_ERROR    = 'error';
	const LEVEL_CRITICAL = 'critical';

	// Ciclo de vida del plugin.
	const CODE_PLUGIN_ACTIVATED   = 'PLUGIN_ACTIVATED';
	const CODE_PLUGIN_DEACTIVATED = 'PLUGIN_DEACTIVATED';
	const CODE_SCHEMA_INSTALLED   = 'SCHEMA_INSTALLED';
	const CODE_SCHEMA_UPGRADED    = 'SCHEMA_UPGRADED';

	// Ejecución.
	const CODE_RUN_STARTED   = 'RUN_STARTED';
	const CODE_RUN_STOPPED   = 'RUN_STOPPED';
	const CODE_TICK_OK       = 'TICK_OK';
	const CODE_TICK_IDLE     = 'TICK_IDLE';
	const CODE_TICK_SKIPPED  = 'TICK_SKIPPED';
	const CODE_QUEUE_EMPTY   = 'QUEUE_EMPTY';

	// Fallos de planificación, no de red.
	const CODE_SCHEDULER_STALL   = 'SCHEDULER_STALL';
	const CODE_LEASE_EXPIRED     = 'LEASE_EXPIRED';
	const CODE_WORKER_FATAL      = 'WORKER_FATAL';
	const CODE_CRON_NOT_SCHEDULED = 'CRON_NOT_SCHEDULED';
	const CODE_LOOPBACK_BLOCKED  = 'LOOPBACK_BLOCKED';
	const CODE_LOOPBACK_OK       = 'LOOPBACK_OK';
	const CODE_ENV_CHECK         = 'ENV_CHECK';

	// Circuit breaker.
	const CODE_BREAKER_OPEN     = 'BREAKER_OPEN';
	const CODE_BREAKER_PROBE_OK = 'BREAKER_PROBE_OK';
	const CODE_BREAKER_GIVE_UP  = 'BREAKER_GIVE_UP';

	/**
	 * Escribe un evento.
	 *
	 * @param string $code    Código normalizado (constantes CODE_*).
	 * @param string $message Mensaje legible.
	 * @param array  $context Datos adicionales, se guardan como JSON.
	 * @param string $level   Nivel.
	 * @return int|false ID insertado o false.
	 */
	public static function log( $code, $message = '', $context = array(), $level = self::LEVEL_INFO ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'events' ) ) {
			return false;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			SCW_Schema::table( 'events' ),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => substr( (string) $level, 0, 16 ),
				'code'       => substr( (string) $code, 0, 48 ),
				'message'    => (string) $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Atajo para nivel info.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 * @return int|false
	 */
	public static function info( $code, $message = '', $context = array() ) {
		return self::log( $code, $message, $context, self::LEVEL_INFO );
	}

	/**
	 * Atajo para nivel warning.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 * @return int|false
	 */
	public static function warning( $code, $message = '', $context = array() ) {
		return self::log( $code, $message, $context, self::LEVEL_WARNING );
	}

	/**
	 * Atajo para nivel error.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 * @return int|false
	 */
	public static function error( $code, $message = '', $context = array() ) {
		return self::log( $code, $message, $context, self::LEVEL_ERROR );
	}

	/**
	 * Últimos eventos, más recientes primero.
	 *
	 * @param int $limit Número de filas.
	 * @return array
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'events' ) ) {
			return array();
		}

		$table = SCW_Schema::table( 'events' );
		$limit = max( 1, min( 200, (int) $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT id, created_at, level, code, message, context FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Borra eventos más antiguos que N días.
	 *
	 * @param int $days Días de retención.
	 * @return int Filas borradas.
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'events' ) ) {
			return 0;
		}

		$days   = max( 1, (int) $days );
		$table  = SCW_Schema::table( 'events' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);
	}
}
