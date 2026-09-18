<?php
/**
 * Política de reintentos por URL.
 *
 * Responde a UNA pregunta y sólo a una:
 *
 *   "Esta petición HTTP ha fallado. ¿Merece la pena volver a intentarlo, y
 *    cuándo, o esta URL ha fallado definitivamente?"
 *
 * NO ejecuta el reintento. Decide. Quien actúa es SCW_Worker, usando
 * SCW_Queue::defer() o SCW_Queue::complete(), que ya existen desde F5.0. Esta
 * clase no toca la base de datos, no hace peticiones, no escribe eventos y no
 * programa nada.
 *
 * Dos ejes que NO deben mezclarse:
 *
 *   - Retry backoff (aquí): cuándo volver a intentar ESTA URL. Se materializa
 *     como available_at en su fila de cola.
 *   - Pacing (F5.3, todavía inexistente): a qué ritmo global se hacen
 *     peticiones, sea cual sea la URL.
 *
 * El backoff NO depende del pacer y el pacer no consultará el backoff: el
 * SCW_Tick_Planner de F5.1 ya los combina con max(), nunca sumándolos.
 *
 * Sobre la clasificación: es una LISTA BLANCA explícita. No se asume que
 * "todo 5xx" sea recuperable. Un 501 o un 505 describen una incapacidad
 * estable del servidor, no una indisponibilidad pasajera, y reintentarlos sólo
 * añade carga sin ninguna probabilidad de éxito.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Retry_Policy {

	/** Hay que devolver la URL a pending con un available_at futuro. */
	const DECISION_RETRY = 'retry';

	/** Hay que cerrar la URL con un estado terminal. */
	const DECISION_TERMINAL = 'terminal';

	/** Se reintenta: quedaban intentos y el fallo es de clase recuperable. */
	const REASON_RETRY_SCHEDULED = 'retry_scheduled';

	/** El fallo era recuperable, pero la URL ya ha agotado sus intentos. */
	const REASON_RETRIES_EXHAUSTED = 'retries_exhausted';

	/** El fallo no pertenece a ninguna clase recuperable. */
	const REASON_NOT_RETRYABLE = 'not_retryable';

	/** No hubo fallo de petición: success o suspicious. El retry no aplica. */
	const REASON_NOT_ERROR = 'not_error';

	/**
	 * Códigos HTTP recuperables. Lista blanca cerrada.
	 *
	 *   408 Request Timeout      -> el servidor cerró la espera, puede ir mejor.
	 *   429 Too Many Requests    -> exceso de ritmo, por definición pasajero.
	 *   500 Internal Server Error-> fallo genérico, con frecuencia transitorio
	 *                               bajo carga (PHP-FPM saturado, etc.).
	 *   502 Bad Gateway          -> backend caído o reiniciando.
	 *   503 Service Unavailable  -> indisponibilidad declarada como temporal.
	 *   504 Gateway Timeout      -> el upstream no respondió a tiempo.
	 *
	 * Cualquier otro código, incluidos 501 y 505, es TERMINAL.
	 */
	const RETRYABLE_STATUSES = array( 408, 429, 500, 502, 503, 504 );

	/**
	 * Tipos de error de transporte recuperables.
	 *
	 * Son exactamente los dos que produce SCW_HTTP_Client::classify_wp_error()
	 * a partir de un WP_Error. No se inventa ningún valor nuevo: un WP_Error
	 * llega siempre al sistema como uno de estos dos.
	 *
	 * invalid_url y host_not_allowed quedan FUERA a propósito: describen un
	 * problema estable de la propia URL o de la configuración, y reintentarlos
	 * no puede cambiar el resultado.
	 */
	const RETRYABLE_ERROR_TYPES = array(
		SCW_HTTP_Client::ERROR_TIMEOUT,
		SCW_HTTP_Client::ERROR_TRANSPORT,
	);

	/**
	 * Escalera de backoff de reserva, en segundos.
	 *
	 * NO es una segunda fuente de verdad: replica el valor por defecto que
	 * http_retry_delays ya tiene en SCW_Settings desde F1. Sólo se usa si el
	 * ajuste falta o queda vacío tras sanearlo.
	 */
	const DEFAULT_RETRY_DELAYS = array( 30, 90 );

	/**
	 * Construye el contexto de decisión a partir de un ciclo del worker.
	 *
	 * Es la ÚNICA parte impura de esta clase (lee SCW_Settings y, si no se le
	 * inyecta, el reloj), y está separada de decide() para que la decisión sea
	 * probable sin base de datos y sin depender de la hora.
	 *
	 * @param array    $claimed Fila reclamada de la cola.
	 * @param array    $http    Resultado de SCW_HTTP_Client::fetch().
	 * @param array    $verdict Resultado de SCW_Content_Validator::validate().
	 * @param int|null $now     Timestamp de referencia. Null usa el actual.
	 * @return array
	 */
	public static function context( $claimed, $http, $verdict, $now = null ) {
		return array(
			'now'               => ( null === $now ) ? time() : (int) $now,
			'ok'                => ! empty( $http['ok'] ),
			'http_status'       => isset( $http['http_status'] ) ? $http['http_status'] : null,
			'error_type'        => isset( $http['error_type'] ) ? $http['error_type'] : null,
			'validation_result' => isset( $verdict['result'] ) ? $verdict['result'] : null,
			'attempts'          => isset( $claimed['attempts'] ) ? (int) $claimed['attempts'] : 0,
			'max_attempts'      => isset( $claimed['max_attempts'] ) ? (int) $claimed['max_attempts'] : 0,
			'retry_delays'      => self::configured_delays(),
		);
	}

	/**
	 * Decide qué hacer con una URL cuya petición acaba de terminar.
	 *
	 * Función PURA: mismo contexto, misma decisión. No lee el reloj ni los
	 * ajustes si el contexto los aporta, no consulta la base de datos y no
	 * produce ningún efecto.
	 *
	 * @param array $context Contexto, normalmente de context().
	 * @return array {
	 *     @type string   $decision      retry|terminal.
	 *     @type bool     $retryable     Si la CLASE de fallo es recuperable.
	 *     @type int      $attempts      Intentos ya consumidos por la URL.
	 *     @type int      $max_attempts  Techo congelado de la URL.
	 *     @type int      $attempts_left Intentos que quedaban al decidir.
	 *     @type int|null $retry_index   Número de este reintento (1 = el primero).
	 *     @type int|null $delay         Backoff en segundos, o null.
	 *     @type int|null $available_at  now + delay, o null.
	 *     @type string   $reason        Uno de REASON_*.
	 * }
	 */
	public static function decide( $context ) {
		$ctx = self::normalize( $context );

		$retryable     = self::is_retryable( $ctx['ok'], $ctx['http_status'], $ctx['error_type'] );
		$attempts_left = max( 0, $ctx['max_attempts'] - $ctx['attempts'] );

		// 1. Sólo un veredicto de ERROR puede dar lugar a un reintento. Un 2xx
		// con HTML dudoso sigue siendo suspicious y es TERMINAL: reintentarlo
		// podría devolver la página correcta y borrar la evidencia del fallo de
		// caché que este plugin existe para detectar.
		if ( SCW_Content_Validator::RESULT_ERROR !== $ctx['validation_result'] ) {
			return self::terminal( $ctx, false, $attempts_left, self::REASON_NOT_ERROR );
		}

		// 2. Clase de fallo no recuperable: terminal aunque sobren intentos.
		if ( ! $retryable ) {
			return self::terminal( $ctx, false, $attempts_left, self::REASON_NOT_RETRYABLE );
		}

		// 3. Recuperable, pero sin intentos disponibles. La URL nunca puede
		// superar su max_attempts.
		if ( $attempts_left < 1 ) {
			return self::terminal( $ctx, true, $attempts_left, self::REASON_RETRIES_EXHAUSTED );
		}

		// 4. Reintento. El índice es el número de intentos ya consumidos: tras
		// el primer intento (attempts = 1) toca el primer escalón del backoff.
		$retry_index = $ctx['attempts'];
		$delay       = self::backoff_delay( $retry_index, $ctx['retry_delays'] );

		return array(
			'decision'      => self::DECISION_RETRY,
			'retryable'     => true,
			'attempts'      => $ctx['attempts'],
			'max_attempts'  => $ctx['max_attempts'],
			'attempts_left' => $attempts_left,
			'retry_index'   => $retry_index,
			'delay'         => $delay,
			'available_at'  => $ctx['now'] + $delay,
			'reason'        => self::REASON_RETRY_SCHEDULED,
		);
	}

	/**
	 * ¿Es recuperable esta clase de fallo?
	 *
	 * Función pura sobre el resultado del transporte. No sabe nada de intentos
	 * ni de contenido: responde sólo si tiene sentido volver a pedir.
	 *
	 * @param bool        $ok          Si hubo respuesta HTTP real.
	 * @param int|null    $http_status Código HTTP, si lo hubo.
	 * @param string|null $error_type  Tipo de error de transporte, si lo hubo.
	 * @return bool
	 */
	public static function is_retryable( $ok, $http_status, $error_type = null ) {
		if ( ! $ok ) {
			// Sin respuesta: manda el tipo de error de transporte. Un WP_Error
			// llega siempre como timeout o transport_error; un error_type
			// ausente se trata como transport_error, igual que hace
			// SCW_Content_Validator al componer last_result.
			if ( null === $error_type || '' === $error_type ) {
				return true;
			}

			return in_array( (string) $error_type, self::RETRYABLE_ERROR_TYPES, true );
		}

		return in_array( (int) $http_status, self::RETRYABLE_STATUSES, true );
	}

	/**
	 * Backoff determinista para un reintento concreto.
	 *
	 * Escalera explícita, sin aleatoriedad y sin leer el reloj: el escalón N
	 * usa el elemento N-1 de la tabla, y a partir del final de la tabla se
	 * repite el último valor, que actúa como tope del backoff.
	 *
	 * Con la configuración por defecto (http_retry_delays = [30, 90]):
	 *
	 *   reintento 1 -> 30 s
	 *   reintento 2 -> 90 s
	 *   reintento 3+ -> 90 s  (tope)
	 *
	 * La escalera es configuración, no código: alargarla o suavizarla es
	 * editar http_retry_delays, sin tocar esta clase.
	 *
	 * @param int        $retry_index Número de reintento, empezando en 1.
	 * @param array|null $delays      Escalera. Null usa la configurada.
	 * @return int Segundos de espera.
	 */
	public static function backoff_delay( $retry_index, $delays = null ) {
		$delays = self::sanitize_delays( $delays );
		$index  = max( 1, (int) $retry_index );
		$pos    = min( $index, count( $delays ) ) - 1;

		return (int) $delays[ $pos ];
	}

	/**
	 * Escalera de backoff configurada, ya saneada.
	 *
	 * @return array
	 */
	public static function configured_delays() {
		return self::sanitize_delays( SCW_Settings::get( 'http_retry_delays', self::DEFAULT_RETRY_DELAYS ) );
	}

	/**
	 * Decisión terminal.
	 *
	 * @param array  $ctx           Contexto normalizado.
	 * @param bool   $retryable     Si la clase de fallo era recuperable.
	 * @param int    $attempts_left Intentos restantes.
	 * @param string $reason        Motivo.
	 * @return array
	 */
	private static function terminal( $ctx, $retryable, $attempts_left, $reason ) {
		return array(
			'decision'      => self::DECISION_TERMINAL,
			'retryable'     => (bool) $retryable,
			'attempts'      => $ctx['attempts'],
			'max_attempts'  => $ctx['max_attempts'],
			'attempts_left' => $attempts_left,
			'retry_index'   => null,
			'delay'         => null,
			'available_at'  => null,
			'reason'        => $reason,
		);
	}

	/**
	 * Sanea una escalera de backoff.
	 *
	 * Descarta valores no numéricos o negativos y reindexa. Si no queda nada
	 * utilizable, cae en la escalera de reserva en lugar de devolver una tabla
	 * vacía con la que backoff_delay() no podría operar.
	 *
	 * @param mixed $delays Escalera candidata.
	 * @return array Lista no vacía de enteros >= 0.
	 */
	private static function sanitize_delays( $delays ) {
		if ( ! is_array( $delays ) ) {
			return self::DEFAULT_RETRY_DELAYS;
		}

		$clean = array();

		foreach ( $delays as $delay ) {
			if ( ! is_numeric( $delay ) ) {
				continue;
			}

			$delay = (int) $delay;

			if ( $delay < 0 ) {
				continue;
			}

			$clean[] = $delay;
		}

		return empty( $clean ) ? self::DEFAULT_RETRY_DELAYS : $clean;
	}

	/**
	 * Normaliza el contexto para que decide() no dependa de claves ausentes.
	 *
	 * @param array $context Contexto parcial o completo.
	 * @return array
	 */
	private static function normalize( $context ) {
		$context = is_array( $context ) ? $context : array();

		$delays = array_key_exists( 'retry_delays', $context ) ? $context['retry_delays'] : null;

		return array(
			'now'               => isset( $context['now'] ) ? (int) $context['now'] : time(),
			'ok'                => ! empty( $context['ok'] ),
			'http_status'       => isset( $context['http_status'] ) ? $context['http_status'] : null,
			'error_type'        => isset( $context['error_type'] ) ? $context['error_type'] : null,
			'validation_result' => isset( $context['validation_result'] ) ? (string) $context['validation_result'] : null,
			'attempts'          => isset( $context['attempts'] ) ? max( 0, (int) $context['attempts'] ) : 0,
			'max_attempts'      => isset( $context['max_attempts'] ) ? max( 0, (int) $context['max_attempts'] ) : 0,
			'retry_delays'      => ( null === $delays ) ? self::configured_delays() : self::sanitize_delays( $delays ),
		);
	}
}
