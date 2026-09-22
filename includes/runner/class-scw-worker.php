<?php
/**
 * Worker de calentamiento.
 *
 * Orquesta un único ciclo: recupera leases caducados, reclama como máximo UNA
 * URL de la cola, la valida, ejecuta como máximo UNA petición HTTP, registra
 * el resultado en scw_runs y completa la fila de la cola usando su
 * lock_token. No implementa circuit breaker (F5.4).
 *
 * F5.2: entre el registro del run y el cierre de la cola se consulta a
 * SCW_Retry_Policy. Las REGLAS de reintento tampoco viven aquí: el Worker
 * pregunta y ejecuta, aplazando con SCW_Queue::defer() o cerrando con
 * SCW_Queue::complete(). Una petición HTTP real sigue generando exactamente
 * una fila en scw_runs, también cuando es un reintento.
 *
 * F5.3: inmediatamente después de SCW_HTTP_Client::fetch(), y sólo si la
 * petición ha llegado al servidor, se consulta a SCW_Pacer y se persisten
 * current_delay, ewma_duration_ms y last_request_at en UNA escritura de
 * SCW_State. Las reglas de ritmo no viven aquí, y el Worker tampoco decide
 * cuándo se ejecuta el siguiente tick: eso lo hace SCW_Tick_Planner leyendo
 * esos dos hechos. Las rutas empty y skipped no tocan el estado de pacing.
 *
 * Reutiliza siempre las abstracciones existentes: SCW_Queue para el ciclo de
 * vida de la cola, SCW_URL_Normalizer y SCW_URL_Exclusions para decidir si
 * una URL debe pedirse, SCW_HTTP_Client para la petición, SCW_Content_Validator
 * para el veredicto de integridad y SCW_Runs para el registro. Esta clase no
 * vuelve a implementar nada de eso.
 *
 * F4.4: la validación de contenido se inserta entre la petición y el registro.
 * Las REGLAS de esa decisión no viven aquí: el Worker sólo pasa la respuesta a
 * SCW_Content_Validator y traduce su veredicto al estado terminal de la cola.
 * Si hay que cambiar qué se considera dudoso, se cambia allí, no aquí.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Worker {

	const RESULT_EMPTY      = 'empty';
	const RESULT_SKIPPED    = 'skipped';
	const RESULT_SUCCESS    = 'success';
	const RESULT_SUSPICIOUS = 'suspicious';
	const RESULT_FAILED     = 'failed';

	/**
	 * La petición ha fallado con un error recuperable y quedaban intentos: la
	 * fila vuelve a pending con un available_at futuro en lugar de cerrarse.
	 */
	const RESULT_RETRY = 'retry';

	/**
	 * Ejecuta un único ciclo de calentamiento.
	 *
	 * Como máximo una URL reclamada y como máximo una petición HTTP. Si la
	 * cola está vacía, o si la URL reclamada no debe pedirse (inválida o
	 * excluida), no se realiza ninguna petición HTTP y no se crea ninguna
	 * fila en scw_runs: sólo se completa la cola.
	 *
	 * @return array {
	 *     @type string      $result            empty|skipped|success|suspicious|failed|retry.
	 *     @type int|null    $queue_id
	 *     @type string|null $queue_status      Estado terminal aplicado a la cola.
	 *     @type int|null    $http_status
	 *     @type string|null $error_type
	 *     @type string|null $validation_result Veredicto de contenido, o null si no lo hubo.
	 *     @type int         $recovered_leases  Filas recuperadas de leases caducados en este ciclo.
	 *     @type int|null     $attempts          Intentos consumidos por la URL tras este ciclo.
	 *     @type int|null     $max_attempts      Techo congelado de la URL.
	 *     @type array|null   $retry             Decisión de SCW_Retry_Policy, o null si no hubo petición.
	 *     @type array|null   $pace              Resultado de SCW_Pacer::compute() ({delay, ewma, band}),
	 *                                           o null si no hubo petición real.
	 * }
	 */
	public static function run_once() {
		// Estado compartido con el manejador de shutdown: por referencia, para
		// que refleje hasta dónde llegó la ejecución si hay un fatal de PHP.
		$state = array(
			'claimed'   => null,
			'completed' => false,
		);

		register_shutdown_function(
			static function () use ( &$state ) {
				self::maybe_log_fatal( $state );
			}
		);

		$outcome = array(
			'result'            => self::RESULT_EMPTY,
			'queue_id'          => null,
			'queue_status'      => null,
			'http_status'       => null,
			'error_type'        => null,
			'validation_result' => null,
			'recovered_leases'  => 0,
			'attempts'          => null,
			'max_attempts'      => null,
			'retry'             => null,
			'pace'              => null,
		);

		$outcome['recovered_leases'] = self::recover_expired_leases();

		$claimed = SCW_Queue::claim();

		if ( ! $claimed ) {
			$state['completed'] = true;
			return $outcome;
		}

		$state['claimed']    = $claimed;
		$outcome['queue_id'] = (int) $claimed['id'];

		// 1. Validación previa: reutiliza el normalizador y las exclusiones ya
		// existentes. Si la URL no debe pedirse, no se hace ninguna petición
		// HTTP y no se crea fila en scw_runs (no ha habido ningún intento real
		// de red).
		$validation = self::validate_url( $claimed['url'] );

		if ( null !== $validation ) {
			SCW_Queue::complete(
				$claimed['id'],
				SCW_Queue::STATUS_SKIPPED,
				array(
					'lock_token'  => $claimed['lock_token'],
					'last_result' => $validation['reason'],
					'last_error'  => $validation['message'],
				)
			);

			$outcome['result']       = self::RESULT_SKIPPED;
			$outcome['queue_status'] = SCW_Queue::STATUS_SKIPPED;
			$outcome['error_type']   = $validation['reason'];

			$state['completed'] = true;
			return $outcome;
		}

		// 2. Petición HTTP real. Se pide exactamente la URL que hay en la cola
		// (ya normalizada al encolarla), no una nueva normalización recién
		// calculada, para no divergir del hash con el que se reclamó la fila.
		$session_id = (string) SCW_State::get( 'session_id', '' );

		$http = SCW_HTTP_Client::fetch( $claimed['url'] );

		// Fin de la petición (F5.3, OD-2): se toma aquí, inmediatamente después
		// de fetch(), y es el único reloj que usa el pacing.
		$request_finished_at = time();

		// 2b. Pacing (F5.3). Antes de la validación de contenido: el hecho "el
		// servidor ha recibido una petición" no depende del veredicto, y
		// persistirlo cuanto antes reduce la ventana en la que un fatal
		// posterior dejaría el sistema sin ritmo. Usa exactamente el
		// duration_ms de fetch(), el mismo que se registra en scw_runs.
		$outcome['pace'] = self::apply_pacing( $http, $request_finished_at );

		// 3. Validación de contenido (F4.4). Es el único punto donde el body
		// está disponible; se consume aquí y se suelta al terminar el ciclo.
		// Nunca se persiste.
		$verdict = SCW_Content_Validator::validate( $http, $claimed['url'] );

		unset( $http['body'] );

		// 4. Registro del run. Toda petición HTTP real (2xx-5xx, timeout o
		// error de transporte) genera exactamente una fila.
		SCW_Runs::insert(
			array(
				'queue_id'                  => (int) $claimed['id'],
				'url_hash'                  => $claimed['url_hash'],
				'url'                       => $claimed['url'],
				'session_id'                => $session_id,
				'attempt'                   => (int) $claimed['attempts'],
				'http_status'               => $http['http_status'],
				'duration_ms'               => $http['duration_ms'],
				'bytes'                     => $http['bytes'],
				'x_litespeed_cache'         => $http['x_litespeed_cache'],
				'x_litespeed_cache_control' => $http['x_litespeed_cache_control'],
				'vary'                      => $http['vary'],
				'content_type'              => $http['content_type'],
				'user_agent'                => $http['user_agent'],
				'error_type'                => $http['error_type'],
				'error_message'             => $http['error_message'],
				'validation_result'         => $verdict['result'],
				'yith_presets'              => $verdict['yith_presets'],
				'diagnostics'               => self::build_diagnostics( $http, $verdict ),
			)
		);

		// 5. Decisión de reintento (F5.2). Las REGLAS no viven aquí: el Worker
		// pregunta a SCW_Retry_Policy y ejecuta. La política no toca la cola.
		$retry = SCW_Retry_Policy::decide(
			SCW_Retry_Policy::context( $claimed, $http, $verdict )
		);

		$outcome['attempts']     = (int) $claimed['attempts'];
		$outcome['max_attempts'] = (int) $claimed['max_attempts'];
		$outcome['retry']        = $retry;

		// 6. Cierre de la cola. Un error HTTP o un HTML dudoso son resultados
		// de la petición, no fallos del sistema: nunca generan un scw_event,
		// sólo el estado de la cola y la fila de scw_runs ya creada.
		if ( SCW_Retry_Policy::DECISION_RETRY === $retry['decision'] ) {
			// Aplazar NO es cerrar. Se reutiliza SCW_Queue::defer() de F5.0,
			// que valida processing, exige el lock_token, libera el lock y
			// conserva attempts y max_attempts. Aquí no se duplica nada de eso.
			// La fila queda pending con available_at futuro, y el
			// SCW_Tick_Planner de F5.1 ya sabe planificar sobre ese dato.
			SCW_Queue::defer(
				$claimed['id'],
				$retry['available_at'],
				array(
					'lock_token'  => $claimed['lock_token'],
					'last_result' => $verdict['last_result'],
					'last_error'  => $http['error_message'],
				)
			);

			$outcome['result']            = self::RESULT_RETRY;
			$outcome['queue_status']      = SCW_Queue::STATUS_PENDING;
			$outcome['http_status']       = $http['http_status'];
			$outcome['error_type']        = $http['error_type'];
			$outcome['validation_result'] = $verdict['result'];

			$state['completed'] = true;

			return $outcome;
		}

		$queue_status = $verdict['queue_status'];

		// Un fallo recuperable que ya ha agotado los intentos se cierra como
		// failed dejando constancia del motivo, en lugar del último código
		// HTTP: es el dato que explica por qué no habrá otro intento.
		$last_result = ( SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED === $retry['reason'] )
			? SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED
			: $verdict['last_result'];

		SCW_Queue::complete(
			$claimed['id'],
			$queue_status,
			array(
				'lock_token'  => $claimed['lock_token'],
				'last_result' => $last_result,
				'last_error'  => $http['error_message'],
			)
		);

		$outcome['result']            = self::result_for_queue_status( $queue_status );
		$outcome['queue_status']      = $queue_status;
		$outcome['http_status']       = $http['http_status'];
		$outcome['error_type']        = $http['error_type'];
		$outcome['validation_result'] = $verdict['result'];

		$state['completed'] = true;

		return $outcome;
	}

	/**
	 * Calcula y persiste el pacing de una petición.
	 *
	 * Las reglas son de SCW_Pacer. Aquí sólo se decide SI procede (petición
	 * real) y se hace la ÚNICA escritura de pacing del sistema: current_delay,
	 * ewma_duration_ms y last_request_at en una sola llamada a SCW_State::set().
	 *
	 * Los rechazos previos a la red de SCW_HTTP_Client (invalid_url,
	 * host_not_allowed) no han cargado el servidor y no actualizan nada.
	 *
	 * @param array $http        Resultado de SCW_HTTP_Client::fetch(), sin tocar.
	 * @param int   $finished_at time() inmediatamente posterior a fetch().
	 * @return array|null Resultado del pacer, o null si no hubo petición real.
	 */
	private static function apply_pacing( $http, $finished_at ) {
		if ( ! SCW_Pacer::is_real_request( $http ) ) {
			return null;
		}

		$pace = SCW_Pacer::compute(
			$http,
			(int) SCW_State::get( 'ewma_duration_ms', 0 ),
			SCW_Pacer::config()
		);

		SCW_State::set(
			array(
				'current_delay'    => $pace['delay'],
				'ewma_duration_ms' => $pace['ewma'],
				'last_request_at'  => (int) $finished_at,
			)
		);

		return $pace;
	}

	/**
	 * Recupera leases caducados usando la implementación ya existente de
	 * SCW_Queue, y deja constancia en scw_events si ha recuperado algo.
	 *
	 * No se implementa ningún sistema de leases nuevo: esto es sólo el punto
	 * de llamada y el registro del evento.
	 *
	 * @return int Filas recuperadas.
	 */
	private static function recover_expired_leases() {
		$recovered = SCW_Queue::release_expired_locks();

		if ( $recovered > 0 ) {
			SCW_Logger::warning(
				SCW_Logger::CODE_LEASE_EXPIRED,
				sprintf( 'Se han recuperado %d elemento(s) de la cola cuyo lease había caducado.', $recovered ),
				array( 'recovered' => $recovered )
			);
		}

		return $recovered;
	}

	/**
	 * Decide si una URL debe pedirse.
	 *
	 * Reutiliza SCW_URL_Normalizer y SCW_URL_Exclusions tal cual existen; no
	 * reimplementa ninguna de sus reglas.
	 *
	 * @param string $url URL de la fila reclamada.
	 * @return array|null Null si debe pedirse, o { reason, message } si debe omitirse.
	 */
	private static function validate_url( $url ) {
		$normalized = SCW_URL_Normalizer::normalize( $url );

		if ( ! $normalized['valid'] ) {
			return array(
				'reason'  => $normalized['error'],
				'message' => $normalized['error_message'],
			);
		}

		$exclusion = SCW_URL_Exclusions::check( $normalized['url'] );

		if ( ! $exclusion['processable'] ) {
			return array(
				'reason'  => $exclusion['error'],
				'message' => $exclusion['error_message'],
			);
		}

		if ( $exclusion['excluded'] ) {
			return array(
				'reason'  => $exclusion['reason'],
				'message' => 'URL excluida por regla ' . $exclusion['type'] . ': ' . $exclusion['pattern'] . '.',
			);
		}

		return null;
	}

	/**
	 * Traduce el estado terminal de la cola al vocabulario del Worker.
	 *
	 * La decisión de CUÁL es ese estado la toma SCW_Content_Validator; aquí
	 * sólo se traduce para el valor de retorno.
	 *
	 * @param string $queue_status Estado terminal aplicado a la cola.
	 * @return string
	 */
	private static function result_for_queue_status( $queue_status ) {
		if ( SCW_Queue::STATUS_SUCCESS === $queue_status ) {
			return self::RESULT_SUCCESS;
		}

		if ( SCW_Queue::STATUS_SUSPICIOUS === $queue_status ) {
			return self::RESULT_SUSPICIOUS;
		}

		return self::RESULT_FAILED;
	}

	/**
	 * Combina los diagnósticos del cliente HTTP y de la validación en la forma
	 * que espera SCW_Runs.
	 *
	 * Los datos de transporte (redirect_location, possibly_truncated,
	 * content_length_header) se mantienen en la raíz, como en F3; la evidencia
	 * de contenido se añade en sus propios bloques. El body nunca entra aquí.
	 *
	 * @param array $http    Resultado de SCW_HTTP_Client::fetch().
	 * @param array $verdict Resultado de SCW_Content_Validator::validate().
	 * @return array|null
	 */
	private static function build_diagnostics( $http, $verdict = array() ) {
		$diagnostics = array();

		if ( ! empty( $http['redirect_location'] ) ) {
			$diagnostics['redirect_location'] = $http['redirect_location'];
		}

		if ( ! empty( $http['diagnostics'] ) && is_array( $http['diagnostics'] ) ) {
			$diagnostics = array_merge( $diagnostics, $http['diagnostics'] );
		}

		if ( ! empty( $verdict['diagnostics'] ) && is_array( $verdict['diagnostics'] ) ) {
			$diagnostics = array_merge( $diagnostics, $verdict['diagnostics'] );
		}

		return empty( $diagnostics ) ? null : $diagnostics;
	}

	/**
	 * Manejador de shutdown: deja constancia en scw_events si el proceso ha
	 * muerto de forma fatal antes de que este ciclo terminara limpiamente.
	 *
	 * No intenta reparar ni completar la fila de la cola desde aquí: eso
	 * seguirá dependiendo de SCW_Queue::release_expired_locks() en un tick
	 * posterior, una vez caduque el lease. Esto sólo hace visible el fatal.
	 *
	 * Limitación conocida: error_get_last() refleja el último error de TODA
	 * la petición PHP, no sólo de este worker. En el contexto de una petición
	 * de WP-Cron dedicada a scw_tick esto es una aproximación razonable, pero
	 * no una atribución perfecta.
	 *
	 * @param array $state Estado por referencia de run_once().
	 * @return void
	 */
	private static function maybe_log_fatal( $state ) {
		if ( ! empty( $state['completed'] ) ) {
			return;
		}

		$error = error_get_last();

		if ( ! $error ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR );

		if ( ! in_array( $error['type'], $fatal_types, true ) ) {
			return;
		}

		$context = array( 'php_error' => $error );

		if ( ! empty( $state['claimed'] ) && is_array( $state['claimed'] ) ) {
			$context['queue_id'] = (int) $state['claimed']['id'];
			$context['url']      = $state['claimed']['url'];
		}

		SCW_Logger::error(
			SCW_Logger::CODE_WORKER_FATAL,
			'El worker no ha terminado su ciclo: se ha detectado un error fatal de PHP.',
			$context
		);
	}
}
