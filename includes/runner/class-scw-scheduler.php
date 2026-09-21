<?php
/**
 * Planificador.
 *
 * Desde F3, scw_tick deja de ser inerte:
 *
 *  - scw_tick    : si el crawler está RUNNING, adquiere el lock global
 *                  (LOCK_TRANSIENT) para impedir ejecuciones simultáneas,
 *                  ejecuta un único SCW_Worker::run_once() (como máximo una
 *                  URL, como máximo una petición HTTP) y, si sigue RUNNING,
 *                  programa el siguiente scw_tick con wp_schedule_single_event()
 *                  encadenado. No hay cron recurrente para el warmer.
 *  - scw_watchdog: sin cambios respecto a F1/F2. Recurrente cada 5 minutos,
 *                  sólo escribe un latido y ejecuta la limpieza por retención
 *                  una vez al día. La detección de stall del planificador
 *                  sigue siendo una preocupación de F5.
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
	 * Duración del lock global del tick, en segundos.
	 *
	 * No es un ajuste de pacing (eso es F5): es sólo el margen de seguridad
	 * del lock de exclusión mutua entre ejecuciones de scw_tick. Se fija a un
	 * valor generoso pero acotado, muy por encima del http_timeout por
	 * defecto (30 s) más margen para la escritura en BD, de modo que un
	 * proceso colgado o un fatal no dejen el scheduler bloqueado para
	 * siempre: el lock expira solo.
	 */
	const TICK_LOCK_SECONDS = 90;

	/**
	 * Retardo por defecto entre un tick y el siguiente cuando no hay ningún
	 * ajuste de pacing más específico que usar.
	 *
	 * F3 reutiliza el valor ya existente de pace_base_delay como intervalo
	 * FIJO entre ticks, sin ninguna lógica adaptativa: eso es F5. Si el
	 * ajuste no existe o es inválido, se usa este valor de reserva.
	 */
	const DEFAULT_TICK_DELAY_SECONDS = 5;

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
	 * Si el estado no es RUNNING, sale sin hacer nada (igual que en F1/F2). Si
	 * está RUNNING, intenta adquirir el lock global del tick; si ya hay otra
	 * ejecución en curso, sale sin procesar. Con el lock adquirido, ejecuta un
	 * único SCW_Worker::run_once() y, si el crawler sigue RUNNING, programa el
	 * siguiente scw_tick. El lock se libera siempre, incluso si el worker
	 * lanza una excepción.
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

		if ( ! self::acquire_tick_lock() ) {
			SCW_Logger::log(
				SCW_Logger::CODE_TICK_SKIPPED,
				'Tick descartado: ya hay otra ejecución de scw_tick en curso.',
				array( 'reason' => 'tick_lock_busy' ),
				SCW_Logger::LEVEL_DEBUG
			);
			return;
		}

		try {
			$result = SCW_Worker::run_once();

			SCW_State::set( array( 'last_tick_at' => time() ) );

			if ( SCW_Worker::RESULT_EMPTY === $result['result'] ) {
				SCW_Logger::info( SCW_Logger::CODE_QUEUE_EMPTY, 'La cola no tiene URLs pendientes en este tick.' );
			}

			// Un tick productivo NO escribe ningún evento de éxito: el latido
			// son last_tick_at y expected_next_tick_at. scw_events queda
			// reservada a scheduler, leases, breaker y fallos internos.

			if ( SCW_State::is_running() ) {
				// ÚNICA decisión temporal del sistema. El Scheduler no calcula
				// ningún retardo por su cuenta: sólo aplica lo que decide
				// SCW_Tick_Planner.
				self::apply_plan( SCW_Tick_Planner::plan( SCW_Tick_Planner::context() ) );
			}
		} finally {
			self::release_tick_lock();
		}
	}

	/**
	 * Intenta adquirir el lock global del tick.
	 *
	 * Reutiliza el transient ya reservado por F1 (LOCK_TRANSIENT), sin crear
	 * ningún mecanismo paralelo. Es una exclusión "best effort" al estilo de
	 * la que usa el propio wp-cron.php de WordPress core para evitar
	 * disparos solapados (comprobar y luego fijar el transient), no un lock
	 * atómico garantizado a nivel de fila como el de SCW_Queue::claim(). Ante
	 * una carrera muy estrecha entre dos peticiones casi simultáneas, el peor
	 * caso posible es que dos ticks reclamen y procesen dos filas DISTINTAS
	 * de la cola en paralelo; nunca la misma fila dos veces, porque para eso
	 * sigue existiendo la protección atómica de SCW_Queue.
	 *
	 * @return bool True si se ha adquirido el lock.
	 */
	private static function acquire_tick_lock() {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, time(), self::TICK_LOCK_SECONDS );

		return true;
	}

	/**
	 * Libera el lock global del tick.
	 *
	 * @return void
	 */
	private static function release_tick_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Aplica una decisión de SCW_Tick_Planner.
	 *
	 * El Scheduler NO inventa ninguna decisión temporal propia: se limita a
	 * ejecutar la que recibe.
	 *
	 * @param array $plan Decisión devuelta por SCW_Tick_Planner::plan().
	 * @return bool True si la decisión se ha aplicado con éxito.
	 */
	public static function apply_plan( $plan ) {
		if ( ! is_array( $plan ) || ! isset( $plan['action'] ) ) {
			return false;
		}

		if ( SCW_Tick_Planner::ACTION_SCHEDULE === $plan['action'] ) {
			return self::arm_tick( $plan['at'] );
		}

		if ( SCW_Tick_Planner::ACTION_STOP === $plan['action'] ) {
			return self::stop_run( isset( $plan['reason'] ) ? (string) $plan['reason'] : '' );
		}

		// ACTION_NONE: el crawler no está RUNNING. No se programa nada y no se
		// toca el estado.
		return false;
	}

	/**
	 * Arma el siguiente scw_tick en un timestamp concreto.
	 *
	 * Sustituye a la limitación de schedule_next_tick(), que era idempotente
	 * por presencia: si ya existía un tick programado devolvía true sin mirar
	 * el retardo solicitado, de modo que cualquier intento de cambiar el
	 * momento del siguiente tick era un no-op silencioso. Con pacing adaptativo
	 * y circuit breaker eso haría inaplicable la decisión del planificador.
	 *
	 * Para evitar duplicados, un tick ya programado en otro instante se elimina
	 * ANTES de crear el nuevo. Si ya está armado exactamente en el timestamp
	 * pedido, no se toca el cron: la operación es idempotente.
	 *
	 * expected_next_tick_at se actualiza únicamente cuando queda un tick
	 * realmente armado. No se registra ningún evento de éxito.
	 *
	 * @param int $timestamp Momento en que debe ejecutarse el tick.
	 * @return bool True si queda un tick armado en ese timestamp.
	 */
	public static function arm_tick( $timestamp ) {
		$timestamp = max( time(), (int) $timestamp );
		$existing  = (int) wp_next_scheduled( self::HOOK_TICK );

		if ( $existing === $timestamp ) {
			SCW_State::set( array( 'expected_next_tick_at' => $timestamp ) );

			return true;
		}

		if ( $existing ) {
			wp_clear_scheduled_hook( self::HOOK_TICK );
		}

		$scheduled = wp_schedule_single_event( $timestamp, self::HOOK_TICK, array(), true );

		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			SCW_Logger::error(
				SCW_Logger::CODE_CRON_NOT_SCHEDULED,
				'No se ha podido armar el siguiente scw_tick.',
				array(
					'timestamp' => $timestamp,
					'delay'     => $timestamp - time(),
					'error'     => is_wp_error( $scheduled ) ? $scheduled->get_error_message() : null,
				)
			);

			return false;
		}

		SCW_State::set( array( 'expected_next_tick_at' => $timestamp ) );

		return true;
	}

	/**
	 * Elimina el tick programado, si lo hay, y limpia la expectativa.
	 *
	 * No toca el watchdog, a diferencia de unschedule_all().
	 *
	 * @return void
	 */
	public static function clear_tick() {
		wp_clear_scheduled_hook( self::HOOK_TICK );
		SCW_State::set( array( 'expected_next_tick_at' => 0 ) );
	}

	/**
	 * Detiene el run porque no queda trabajo de ninguna clase.
	 *
	 * Sustituye al encadenado indefinido de F3, en el que una cola vacía seguía
	 * generando un tick cada pace_base_delay segundos, y con él un evento
	 * QUEUE_EMPTY, indefinidamente.
	 *
	 * @param string $reason Motivo devuelto por el planificador.
	 * @return bool
	 */
	private static function stop_run( $reason ) {
		self::clear_tick();

		SCW_State::set(
			array(
				'run_status'    => SCW_State::STATUS_STOPPED,
				'status_reason' => 'Cola agotada: no quedan URLs pendientes ni en proceso.',
			)
		);

		SCW_Logger::info(
			SCW_Logger::CODE_RUN_STOPPED,
			'Crawler detenido: la cola está agotada.',
			array( 'reason' => $reason )
		);

		return true;
	}

	/**
	 * Programa el siguiente scw_tick si no hay ya uno pendiente.
	 *
	 * Se conserva con su contrato de F3 intacto (idempotente por presencia)
	 * porque el panel de administración lo utiliza para arrancar la cadena. La
	 * programación real la hace arm_tick(), de modo que sigue existiendo un
	 * único punto que habla con WP-Cron.
	 *
	 * Para CAMBIAR el momento de un tick ya programado hay que usar arm_tick().
	 *
	 * @param int|null $delay_seconds Retardo explícito en segundos. Null usa
	 *                                el ajuste pace_base_delay.
	 * @return bool True si queda un tick programado (ya existente o recién creado).
	 */
	public static function schedule_next_tick( $delay_seconds = null ) {
		if ( wp_next_scheduled( self::HOOK_TICK ) ) {
			return true;
		}

		$delay = null === $delay_seconds ? self::next_tick_delay() : max( 0, (int) $delay_seconds );

		return self::arm_tick( time() + $delay );
	}

	/**
	 * Retardo fijo entre ticks, en segundos.
	 *
	 * @return int
	 */
	private static function next_tick_delay() {
		$delay = (int) SCW_Settings::get( 'pace_base_delay', self::DEFAULT_TICK_DELAY_SECONDS );

		return $delay < 1 ? self::DEFAULT_TICK_DELAY_SECONDS : $delay;
	}

	/**
	 * Watchdog.
	 *
	 * Sin cambios funcionales respecto a F1/F2: late y limpia por retención.
	 * Desde F3, SCW_Worker recupera los leases caducados en cada tick (vía
	 * SCW_Queue::release_expired_locks()), así que ese caso ya no depende del
	 * watchdog. La detección de stall del planificador (ticks que deberían
	 * estar llegando y han dejado de hacerlo) sigue siendo una preocupación
	 * de F5.
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
