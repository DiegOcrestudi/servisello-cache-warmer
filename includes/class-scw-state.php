<?php
/**
 * Estado de ejecución del crawler.
 *
 * Opción pequeña con autoload = no. Contiene únicamente estado volátil:
 * run_status, sesión, heartbeat del scheduler, delay vigente y contadores del
 * circuit breaker. Las estadísticas del panel NUNCA se leen de aquí: se
 * calculan por SQL sobre las tablas, para que un estado corrupto no falsee los
 * números.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_State {

	const OPTION = 'scw_runtime_state';

	const STATUS_STOPPED        = 'STOPPED';
	const STATUS_RUNNING        = 'RUNNING';
	const STATUS_PAUSED_MANUAL  = 'PAUSED_MANUAL';
	const STATUS_PAUSED_BREAKER = 'PAUSED_BREAKER';
	const STATUS_STALLED        = 'STALLED';

	const BREAKER_CLOSED    = 'CLOSED';
	const BREAKER_OPEN      = 'OPEN';
	const BREAKER_HALF_OPEN = 'HALF_OPEN';

	/**
	 * Estado inicial.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'run_status'           => self::STATUS_STOPPED,
			'status_reason'        => '',
			'session_id'           => '',
			'session_started_at'   => 0,
			'current_delay'        => 0,
			'ewma_duration_ms'     => 0,
			'last_tick_at'         => 0,
			'expected_next_tick_at' => 0,
			'last_watchdog_at'     => 0,
			'last_purge_at'        => 0,
			'stall_recoveries'     => 0,
			'consecutive_errors'   => 0,
			'consecutive_slow_6s'  => 0,
			'consecutive_slow_10s' => 0,
			'breaker_state'        => self::BREAKER_CLOSED,
			'breaker_until'        => 0,
			'breaker_openings'     => 0,
			'breaker_cooldown'     => 0,
		);
	}

	/**
	 * Estado completo.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Un valor concreto.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Actualiza parte del estado conservando autoload = no.
	 *
	 * @param array $values Pares clave/valor.
	 * @return bool
	 */
	public static function set( array $values ) {
		$merged = array_merge( self::all(), $values );

		return update_option( self::OPTION, $merged, false );
	}

	/**
	 * ¿Está el crawler en marcha?
	 *
	 * @return bool
	 */
	public static function is_running() {
		return self::STATUS_RUNNING === self::get( 'run_status' );
	}

	/**
	 * Crea la opción en la activación si no existe.
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Devuelve el estado a reposo. Se usa en la desactivación.
	 *
	 * @param string $reason Motivo legible.
	 * @return void
	 */
	public static function reset( $reason = '' ) {
		$defaults                  = self::defaults();
		$defaults['status_reason'] = $reason;

		update_option( self::OPTION, $defaults, false );
	}

	/**
	 * Elimina la opción. Sólo desde uninstall.php.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}
}
