<?php
/**
 * Planificador temporal.
 *
 * ÚNICO componente que decide cuándo debe ejecutarse el siguiente scw_tick.
 *
 * La arquitectura de F5 separa dos cosas que hasta ahora estaban mezcladas:
 *
 *   - Los demás componentes PRODUCEN HECHOS y los persisten:
 *       Retry    (F5.2) -> scw_queue.available_at
 *       Pacer    (F5.3) -> current_delay, last_request_at
 *       Breaker  (F5.4) -> breaker_state, breaker_until
 *       Watchdog (F5.5) -> reparación de emergencia de la cadena
 *
 *   - Esta clase LEE esos hechos y devuelve UNA decisión temporal.
 *
 * SCW_Scheduler es quien aplica esa decisión mediante arm_tick(). Aquí no se
 * programa ningún evento de WordPress, no se escribe estado y no se registra
 * ningún evento: plan() es una función pura sobre su contexto.
 *
 * La regla fundamental es que el trabajo disponible y el ritmo del servidor son
 * ejes ORTOGONALES, no acumulativos:
 *
 *     next_tick = max( earliest_work, pace_ready )
 *
 * Nunca la suma. Un backoff de 30 s con un pacing de 60 s espera 60, no 90.
 *
 * F5.1 no implementa ni retry, ni pacing, ni breaker, ni watchdog: sólo el
 * punto único de decisión que los cuatro usarán. Con el estado actual del
 * sistema (current_delay = 0, breaker CLOSED, sin last_request_at) el resultado
 * es equivalente al encadenado fijo de F3, salvo por la cola agotada.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Tick_Planner {

	/** No debe programarse ningún tick. */
	const ACTION_NONE = 'none';

	/** Debe detenerse el run: no queda trabajo de ninguna clase. */
	const ACTION_STOP = 'stop';

	/** Debe armarse un tick en el timestamp indicado. */
	const ACTION_SCHEDULE = 'schedule';

	/** El crawler no está en RUNNING: nadie reprograma nada. */
	const REASON_NOT_RUNNING = 'not_running';

	/** No quedan filas pending NI processing. */
	const REASON_QUEUE_DRAINED = 'queue_drained';

	/** El circuito está abierto: se espera a breaker_until. */
	const REASON_BREAKER_OPEN = 'breaker_open';

	/** Hay trabajo reclamable ahora mismo. */
	const REASON_WORK_READY = 'work_ready';

	/** Hay trabajo pending, pero aplazado a un available_at futuro. */
	const REASON_WORK_DEFERRED = 'work_deferred';

	/** No hay pending, pero sí processing: se espera a la caducidad del lease. */
	const REASON_LEASE_RECOVERY = 'lease_recovery';

	/** El ritmo del servidor es la restricción dominante. */
	const REASON_PACED = 'paced';

	/**
	 * Techo de reserva para pace_max_delay, en segundos.
	 *
	 * NO es un ajuste nuevo: replica el valor por defecto que pace_max_delay ya
	 * tiene en SCW_Settings desde F1. Sólo se usa si el ajuste falta o no es
	 * válido, para que el planificador nunca devuelva un horizonte ilimitado.
	 */
	const DEFAULT_PACE_MAX_DELAY = 300;

	/**
	 * Construye el contexto leyendo el estado real del sistema.
	 *
	 * Es la ÚNICA parte impura de esta clase, y está deliberadamente separada
	 * de plan() para que la decisión pueda probarse sin base de datos.
	 *
	 * @param int|null $now Timestamp de referencia. Null usa el momento actual.
	 * @return array Contexto listo para plan().
	 */
	public static function context( $now = null ) {
		$now = ( null === $now ) ? time() : (int) $now;

		$metrics = SCW_Queue::metrics( $now );

		return array(
			'now'               => $now,
			'run_status'        => SCW_State::get( 'run_status' ),
			'breaker_state'     => SCW_State::get( 'breaker_state' ),
			'breaker_until'     => (int) SCW_State::get( 'breaker_until', 0 ),
			'current_delay'     => (int) SCW_State::get( 'current_delay', 0 ),
			'last_request_at'   => (int) SCW_State::get( 'last_request_at', 0 ),
			'pending'           => (int) $metrics[ SCW_Queue::STATUS_PENDING ],
			'processing'        => (int) $metrics[ SCW_Queue::STATUS_PROCESSING ],
			'claimable_now'     => (int) $metrics['claimable_now'],
			'next_available_at' => $metrics['next_available_at'],
			'open_work'         => (int) $metrics['open_work'],
			'lease_seconds'     => SCW_Queue::lease_seconds(),
			'pace_max_delay'    => self::pace_max_delay(),
		);
	}

	/**
	 * Decide cuándo debe ejecutarse el siguiente tick.
	 *
	 * Función PURA: mismo contexto, mismo resultado. No lee el reloj, no toca
	 * la base de datos y no programa nada.
	 *
	 * Precedencias, y por qué son ésas:
	 *
	 *   1. run_status. Si el operador ha parado, nada reprograma. Es la única
	 *      forma de que "Detener" sea absoluto.
	 *   2. Cola agotada antes que breaker. Si no queda trabajo, parar es
	 *      correcto aunque el circuito esté abierto: no hay nada que proteger.
	 *   3. Breaker antes que trabajo y ritmo. Es un cortocircuito, no un
	 *      sumando: con el circuito abierto, ni el pacing ni el available_at de
	 *      ninguna URL tienen voz.
	 *
	 * @param array $context Contexto, normalmente de context().
	 * @return array {
	 *     @type string   $action Una de ACTION_*.
	 *     @type int|null $at     Timestamp del próximo tick, o null.
	 *     @type int|null $delay  Segundos desde now hasta $at, o null.
	 *     @type string   $reason Una de REASON_*.
	 *     @type bool     $capped True si pace_max_delay ha recortado $at.
	 *     @type int      $now    Timestamp de referencia utilizado.
	 * }
	 */
	public static function plan( $context ) {
		$ctx = self::normalize( $context );

		// 1. Intención del operador.
		if ( SCW_State::STATUS_RUNNING !== $ctx['run_status'] ) {
			return self::decision( self::ACTION_NONE, null, self::REASON_NOT_RUNNING, $ctx );
		}

		// 2. Cola realmente agotada. "Realmente" excluye el caso processing > 0:
		// esa fila puede recuperarse al caducar su lease y no está perdida.
		if ( self::is_drained( $ctx ) ) {
			return self::decision( self::ACTION_STOP, null, self::REASON_QUEUE_DRAINED, $ctx );
		}

		// 3. Circuito abierto. NO se toca run_status aquí: un breaker OPEN
		// normal no equivale a PAUSED_BREAKER. Sólo se espera a breaker_until.
		if ( SCW_State::BREAKER_OPEN === $ctx['breaker_state'] ) {
			return self::decision(
				self::ACTION_SCHEDULE,
				max( $ctx['now'], $ctx['breaker_until'] ),
				self::REASON_BREAKER_OPEN,
				$ctx
			);
		}

		// 4. Cuándo hay trabajo y 5. cuándo lo permite el ritmo.
		$work = self::earliest_work( $ctx );
		$pace = self::pace_ready( $ctx );

		// 6. max(), NUNCA la suma: son dos restricciones simultáneas.
		$at     = max( $work['at'], $pace, $ctx['now'] );
		$reason = ( $pace > $work['at'] ) ? self::REASON_PACED : $work['reason'];

		return self::decision( self::ACTION_SCHEDULE, $at, $reason, $ctx );
	}

	/**
	 * ¿Está la cola realmente agotada?
	 *
	 * Sólo si no queda NADA sin terminar. Una fila en processing NO cuenta como
	 * cola vacía: su lease puede caducar y devolverla al trabajo.
	 *
	 * @param array $ctx Contexto normalizado.
	 * @return bool
	 */
	private static function is_drained( $ctx ) {
		return 0 === $ctx['pending'] && 0 === $ctx['processing'] && $ctx['open_work'] <= 0;
	}

	/**
	 * Momento más temprano en que puede haber trabajo que hacer.
	 *
	 * Tres casos, en este orden:
	 *
	 *   a) Hay filas reclamables ahora -> ahora.
	 *   b) Hay pending, pero todas aplazadas -> su available_at más temprano.
	 *   c) No hay pending pero sí processing -> la caducidad del lease.
	 *
	 * Sobre el caso (c): NO se introduce aquí ninguna mecánica nueva de leases.
	 * Se usa exclusivamente la duración de lease que SCW_Queue ya expone
	 * (lease_seconds(), la misma que aplica release_expired_locks()), y se toma
	 * como COTA SUPERIOR: cualquier lease vivo en este instante caduca, como
	 * muy tarde, dentro de lease_seconds segundos. Programar ahí garantiza que
	 * el tick correspondiente encuentre la fila ya recuperable, sin necesidad
	 * de conocer su locked_at ni de añadir un segundo contador de lease.
	 *
	 * @param array $ctx Contexto normalizado.
	 * @return array { @type int $at; @type string $reason }
	 */
	private static function earliest_work( $ctx ) {
		if ( $ctx['claimable_now'] > 0 ) {
			return array(
				'at'     => $ctx['now'],
				'reason' => self::REASON_WORK_READY,
			);
		}

		if ( $ctx['pending'] > 0 && null !== $ctx['next_available_at'] ) {
			return array(
				'at'     => max( $ctx['now'], (int) $ctx['next_available_at'] ),
				'reason' => self::REASON_WORK_DEFERRED,
			);
		}

		if ( $ctx['processing'] > 0 ) {
			return array(
				'at'     => $ctx['now'] + $ctx['lease_seconds'],
				'reason' => self::REASON_LEASE_RECOVERY,
			);
		}

		// Defensivo: is_drained() ya ha descartado este caso.
		return array(
			'at'     => $ctx['now'],
			'reason' => self::REASON_WORK_READY,
		);
	}

	/**
	 * Momento a partir del cual el ritmo permite otra petición.
	 *
	 * Sin marca de la última petición o sin un delay válido, el ritmo no impone
	 * ninguna restricción: el pacing real llega en F5.3.
	 *
	 * @param array $ctx Contexto normalizado.
	 * @return int
	 */
	private static function pace_ready( $ctx ) {
		if ( $ctx['last_request_at'] <= 0 || $ctx['current_delay'] <= 0 ) {
			return $ctx['now'];
		}

		return $ctx['last_request_at'] + $ctx['current_delay'];
	}

	/**
	 * Construye la decisión, aplicando el techo de pace_max_delay.
	 *
	 * El techo NO se aplica a la espera del breaker: cuando el circuito está
	 * abierto, recortar la espera sólo produciría ticks que despiertan, no
	 * hacen nada y vuelven a dormir, que es justo la carga que el breaker
	 * intenta evitar.
	 *
	 * @param string   $action Acción.
	 * @param int|null $at     Timestamp propuesto.
	 * @param string   $reason Motivo.
	 * @param array    $ctx    Contexto normalizado.
	 * @return array
	 */
	private static function decision( $action, $at, $reason, $ctx ) {
		$capped = false;

		if ( self::ACTION_SCHEDULE === $action ) {
			$at = max( $ctx['now'], (int) $at );

			if ( self::REASON_BREAKER_OPEN !== $reason ) {
				$cap = $ctx['now'] + $ctx['pace_max_delay'];

				if ( $at > $cap ) {
					$at     = $cap;
					$capped = true;
				}
			}
		} else {
			$at = null;
		}

		return array(
			'action' => $action,
			'at'     => $at,
			'delay'  => ( null === $at ) ? null : $at - $ctx['now'],
			'reason' => $reason,
			'capped' => $capped,
			'now'    => $ctx['now'],
		);
	}

	/**
	 * Normaliza el contexto para que plan() no dependa de claves ausentes.
	 *
	 * @param array $context Contexto parcial o completo.
	 * @return array
	 */
	private static function normalize( $context ) {
		$context = is_array( $context ) ? $context : array();

		$now = isset( $context['now'] ) ? (int) $context['now'] : time();

		$next_available_at = null;

		if ( isset( $context['next_available_at'] ) && null !== $context['next_available_at'] ) {
			$next_available_at = (int) $context['next_available_at'];
		}

		$lease = isset( $context['lease_seconds'] ) ? (int) $context['lease_seconds'] : SCW_Queue::DEFAULT_LEASE_SECONDS;
		$cap   = isset( $context['pace_max_delay'] ) ? (int) $context['pace_max_delay'] : self::DEFAULT_PACE_MAX_DELAY;

		return array(
			'now'               => $now,
			'run_status'        => isset( $context['run_status'] ) ? (string) $context['run_status'] : SCW_State::STATUS_STOPPED,
			'breaker_state'     => isset( $context['breaker_state'] ) ? (string) $context['breaker_state'] : SCW_State::BREAKER_CLOSED,
			'breaker_until'     => isset( $context['breaker_until'] ) ? (int) $context['breaker_until'] : 0,
			'current_delay'     => isset( $context['current_delay'] ) ? (int) $context['current_delay'] : 0,
			'last_request_at'   => isset( $context['last_request_at'] ) ? (int) $context['last_request_at'] : 0,
			'pending'           => isset( $context['pending'] ) ? max( 0, (int) $context['pending'] ) : 0,
			'processing'        => isset( $context['processing'] ) ? max( 0, (int) $context['processing'] ) : 0,
			'claimable_now'     => isset( $context['claimable_now'] ) ? max( 0, (int) $context['claimable_now'] ) : 0,
			'next_available_at' => $next_available_at,
			'open_work'         => isset( $context['open_work'] ) ? (int) $context['open_work'] : 0,
			'lease_seconds'     => $lease > 0 ? $lease : SCW_Queue::DEFAULT_LEASE_SECONDS,
			'pace_max_delay'    => $cap > 0 ? $cap : self::DEFAULT_PACE_MAX_DELAY,
		);
	}

	/**
	 * Techo de planificación en segundos, desde el ajuste ya existente.
	 *
	 * @return int
	 */
	private static function pace_max_delay() {
		$cap = (int) SCW_Settings::get( 'pace_max_delay', self::DEFAULT_PACE_MAX_DELAY );

		return $cap > 0 ? $cap : self::DEFAULT_PACE_MAX_DELAY;
	}
}
