<?php
/**
 * Planificador.
 *
 * En F1 el scheduler está completamente cableado pero es INERTE:
 *
 *  - scw_tick    : registrado, nunca programado y, si alguien lo dispara a mano,
 *                  sale inmediatamente porque run_status es STOPPED. No hace
 *                  ninguna petición HTTP.
 *  - scw_watchdog: recurrente cada 5 minutos. Sólo escribe un latido en el
 *                  estado (para poder demostrar que WP-Cron nativo se dispara)
 *                  y ejecuta la limpieza por retención una vez al día. No
 *                  calienta nada.
 *
 * El filtro cron_schedules se registra al cargar el fichero del plugin, no en
 * plugins_loaded, porque en el momento del hook de activación plugins_loaded ya
 * ha ocurrido y wp_schedule_event no encontraría el intervalo personalizado.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Scheduler {

	const HOOK_TICK      = 'scw_tick';
	const HOOK_WATCHDOG  = 'scw_watchdog';
	const SCHEDULE_SLUG  = 'scw_five_minutes';
	const LOCK_TRANSIENT = 'scw_tick_lock';

	/**
	 * Registra los hooks. Se llama desde SCW_Plugin::run().
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK_TICK, array( $this, 'handle_tick' ) );
		add_action( self::HOOK_WATCHDOG, array( $this, 'handle_watchdog' ) );
	}

	/**
	 * Intervalo personalizado de 5 minutos para el watchdog.
	 *
	 * @param array $schedules Intervalos existentes.
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_SLUG ] ) ) {
			$schedules[ self::SCHEDULE_SLUG ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Cada 5 minutos (Servisello Cache Warmer)',
			);
		}

		return $schedules;
	}

	/**
	 * Programa el watchdog si no lo está ya. Se invoca en la activación.
	 *
	 * @return void
	 */
	public static function schedule_watchdog() {
		if ( ! wp_next_scheduled( self::HOOK_WATCHDOG ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE_SLUG, self::HOOK_WATCHDOG );
		}
	}

	/**
	 * Elimina todos los eventos programados por el plugin.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::HOOK_TICK );
		wp_clear_scheduled_hook( self::HOOK_WATCHDOG );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Timestamp del próximo tick, o 0.
	 *
	 * @return int
	 */
	public static function next_tick() {
		return (int) wp_next_scheduled( self::HOOK_TICK );
	}

	/**
	 * Timestamp del próximo watchdog, o 0.
	 *
	 * @return int
	 */
	public static function next_watchdog() {
		return (int) wp_next_scheduled( self::HOOK_WATCHDOG );
	}

	/**
	 * Tick del worker.
	 *
	 * F1: no existe worker todavía. Si el estado no es RUNNING se sale sin hacer
	 * nada. Si alguien fuerza el hook estando en RUNNING (imposible en F1, porque
	 * no hay forma de ponerlo en RUNNING desde la interfaz), se registra y se
	 * sale igualmente. Nunca se realiza una petición HTTP en esta fase.
	 *
	 * @return void
	 */
	public function handle_tick() {
		if ( ! SCW_State::is_running() ) {
			SCW_Logger::log(
				SCW_Logger::CODE_TICK_SKIPPED,
				'Tick descartado: el crawler no está en estado RUNNING.',
				array( 'run_status' => SCW_State::get( 'run_status' ) ),
				SCW_Logger::LEVEL_DEBUG
			);
			return;
		}

		SCW_Logger::warning(
			SCW_Logger::CODE_TICK_IDLE,
			'Tick recibido pero el worker todavía no está implementado (fase F1).'
		);

		SCW_State::set( array( 'last_tick_at' => time() ) );
	}

	/**
	 * Watchdog.
	 *
	 * F1: latido y limpieza por retención. La detección de stall del planificador
	 * y la recuperación de leases caducados se activan en F3/F5, cuando existan
	 * ticks reales que vigilar.
	 *
	 * @return void
	 */
	public function handle_watchdog() {
		$now    = time();
		$update = array( 'last_watchdog_at' => $now );

		$last_purge = (int) SCW_State::get( 'last_purge_at', 0 );

		if ( ( $now - $last_purge ) > DAY_IN_SECONDS ) {
			SCW_Logger::purge_older_than( (int) SCW_Settings::get( 'retention_events_days', 30 ) );
			$update['last_purge_at'] = $now;
		}

		SCW_State::set( $update );
	}
}
