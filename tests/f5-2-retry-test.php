<?php
/**
 * Prueba controlada de la política de reintentos (F5.2).
 *
 * Cuatro bloques:
 *
 *   A. Clasificación  -> SCW_Retry_Policy::is_retryable(), función pura.
 *   B. max_attempts   -> SCW_Retry_Policy::decide(), función pura con reloj
 *                        sintético: ninguna aserción depende de la hora real.
 *   C. Queue          -> el aplazamiento usa SCW_Queue::defer() de F5.0.
 *   D. Worker         -> ciclo completo con pre_http_request simulado.
 *
 * NO hace ninguna petición HTTP real. NO hay pacer, breaker ni watchdog.
 *
 * Ejecución recomendada, desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f5-2-retry-test.php
 *
 * Todas las filas llevan source = 'f5-2-test' y se eliminan al final. El
 * estado del plugin se restaura siempre. El test NO modifica ningún ajuste:
 * sólo comprueba al final que http_retry_delays sigue intacto.
 *
 * Si la cola contiene trabajo abierto ajeno (filas pending o processing con
 * otro source), la suite se ABORTA antes de escribir nada y sale con código 1.
 * Ver la guarda de aislamiento más abajo.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Retry_Policy' ) ) {
	exit( "SCW_Retry_Policy no está cargada. ¿Está instalada la versión de F5.2?\n" );
}

$scw_source = 'f5-2-test';
$scw_pass   = 0;
$scw_fail   = 0;

/**
 * Comprueba una condición e imprime el resultado.
 *
 * @param string $label  Descripción.
 * @param bool   $ok     Resultado.
 * @param string $detail Detalle.
 * @return void
 */
$scw_check = function ( $label, $ok, $detail = '' ) use ( &$scw_pass, &$scw_fail ) {
	if ( $ok ) {
		$scw_pass++;
		echo "  [ OK ]   {$label}";
	} else {
		$scw_fail++;
		echo "  [FALLO]  {$label}";
	}

	if ( '' !== $detail ) {
		echo "  ->  {$detail}";
	}

	echo "\n";
};

// Reloj sintético. Los bloques A y B no tocan el reloj real en ningún momento.
$scw_now    = 1700000000;
$scw_delays = array( 30, 90 );

/**
 * Contexto de decisión sobre el reloj sintético.
 *
 * @param array $overrides Claves a sobrescribir.
 * @return array
 */
$scw_ctx = function ( $overrides = array() ) use ( $scw_now, $scw_delays ) {
	return array_merge(
		array(
			'now'               => $scw_now,
			'ok'                => true,
			'http_status'       => 500,
			'error_type'        => null,
			'validation_result' => SCW_Content_Validator::RESULT_ERROR,
			'attempts'          => 1,
			'max_attempts'      => 2,
			'retry_delays'      => $scw_delays,
		),
		$overrides
	);
};

$scw_host = (string) SCW_Settings::get( 'allowed_host', 'www.servisello.es' );

/**
 * Instala un mock de pre_http_request que devuelve siempre la misma respuesta.
 *
 * @param mixed $canned Respuesta o WP_Error.
 * @return array { remove, calls }
 */
$scw_install_mock = function ( $canned ) {
	$calls = 0;

	$filter = function () use ( $canned, &$calls ) {
		$calls++;
		return $canned;
	};

	add_filter( 'pre_http_request', $filter, 10, 3 );

	return array(
		'remove' => function () use ( $filter ) {
			remove_filter( 'pre_http_request', $filter, 10 );
		},
		'calls'  => function () use ( &$calls ) {
			return $calls;
		},
	);
};

/**
 * Respuesta HTTP simulada.
 *
 * @param int    $code    Código.
 * @param array  $headers Cabeceras.
 * @param string $body    Cuerpo.
 * @return array
 */
$scw_response = function ( $code, $headers = array(), $body = null ) {
	if ( null === $body ) {
		$body = '<!DOCTYPE html><html><head><title>t</title></head><body>' . str_repeat( 'x', 30000 ) . '</body></html>';
	}

	return array(
		'headers'  => array_merge( array( 'content-type' => 'text/html; charset=UTF-8' ), $headers ),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => 'simulada',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

/**
 * Encola una URL de prueba.
 *
 * @param string $slug Sufijo.
 * @param array  $args Argumentos de insert().
 * @return array|null Fila completa.
 */
$scw_enqueue = function ( $slug, $args = array() ) use ( $scw_source, $scw_host ) {
	$url = 'https://' . $scw_host . '/prueba-f5-2-' . $slug . '/';
	$res = SCW_Queue::insert( $url, array_merge( array( 'source' => $scw_source ), $args ) );

	return $res['id'] ? SCW_Queue::get( $res['id'] ) : null;
};

echo "\n=== F5.2 · Política de reintentos ===\n";
echo 'Reloj sintético: ' . $scw_now . "\n";
echo 'http_retry_delays configurado: ' . wp_json_encode( SCW_Retry_Policy::configured_delays() ) . "\n";
echo 'http_max_retries: ' . var_export( SCW_Settings::get( 'http_max_retries' ), true ) . "\n\n";

$scw_original_state  = SCW_State::all();
$scw_original_delays = SCW_Settings::get( 'http_retry_delays' ); // Sólo para comprobar al final que no ha cambiado.
$scw_initial_stats   = SCW_Queue::stats();

// ---------------------------------------------------------------------------
// Guarda de aislamiento
// ---------------------------------------------------------------------------
// SCW_Queue::claim() y SCW_Worker::run_once() trabajan sobre la fila pending de
// mayor prioridad de TODA la tabla, y run_once() recupera además cualquier
// lease caducado, sea de quien sea. Si la cola tiene trabajo abierto que no es
// de este test, los bloques C y D lo reclamarían y lo modificarían contra los
// mocks HTTP: se ha comprobado que una fila ajena de prioridad alta acaba
// aplazada con el reloj sintético y cerrada como failed.
//
// Por eso, si existe CUALQUIER fila pending o processing con un source distinto
// del de este test, la suite se aborta aquí: antes de la primera escritura del
// test, y por tanto antes de modificar ninguna fila. Esta comprobación es de
// sólo lectura.
global $wpdb;

$scw_foreign_sql = 'FROM ' . SCW_Queue::table() . ' WHERE status IN (%s, %s) AND ( source IS NULL OR source <> %s )';

$scw_foreign_total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
	$wpdb->prepare( 'SELECT COUNT(*) ' . $scw_foreign_sql, SCW_Queue::STATUS_PENDING, SCW_Queue::STATUS_PROCESSING, $scw_source )
);

if ( $scw_foreign_total > 0 ) {
	$scw_foreign_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare( 'SELECT id, status, source, priority, url ' . $scw_foreign_sql . ' ORDER BY priority DESC, id ASC LIMIT 5', SCW_Queue::STATUS_PENDING, SCW_Queue::STATUS_PROCESSING, $scw_source ),
		ARRAY_A
	);

	echo "  [ABORTADO]  La cola contiene {$scw_foreign_total} fila(s) pending/processing ajenas a este test.\n";
	echo "              El test NO se ejecuta, para no reclamar ni modificar datos reales.\n";
	echo "              No se ha escrito nada: ni filas de cola, ni runs, ni estado del plugin.\n\n";
	echo "              Primeras filas ajenas encontradas:\n";

	foreach ( (array) $scw_foreign_rows as $scw_foreign_row ) {
		echo '                id=' . $scw_foreign_row['id']
			. ' status=' . $scw_foreign_row['status']
			. ' source=' . var_export( $scw_foreign_row['source'], true )
			. ' priority=' . $scw_foreign_row['priority']
			. ' url=' . $scw_foreign_row['url'] . "\n";
	}

	echo "\n              Vacía o termina ese trabajo y vuelve a ejecutar la suite.\n";
	echo "\n=== SUITE ABORTADA: trabajo ajeno en la cola. 0 comprobaciones ejecutadas. ===\n\n";

	exit( 1 );
}

SCW_State::set( array( 'session_id' => $scw_source ) );

// ---------------------------------------------------------------------------
// A. Clasificación
// ---------------------------------------------------------------------------
echo "A. Clasificación de errores\n";

// A.1 Transporte.
$scw_check( 'timeout -> retryable', true === SCW_Retry_Policy::is_retryable( false, null, SCW_HTTP_Client::ERROR_TIMEOUT ) );
$scw_check( 'transport_error -> retryable', true === SCW_Retry_Policy::is_retryable( false, null, SCW_HTTP_Client::ERROR_TRANSPORT ) );
$scw_check( 'error de transporte sin tipo -> retryable', true === SCW_Retry_Policy::is_retryable( false, null, null ) );
$scw_check( 'error de transporte con tipo vacío -> retryable', true === SCW_Retry_Policy::is_retryable( false, null, '' ) );
$scw_check( 'invalid_url -> TERMINAL (reintentar no puede arreglar la URL)', false === SCW_Retry_Policy::is_retryable( false, null, SCW_HTTP_Client::ERROR_INVALID_URL ) );
$scw_check( 'host_not_allowed -> TERMINAL', false === SCW_Retry_Policy::is_retryable( false, null, SCW_HTTP_Client::ERROR_HOST ) );

// A.2 Códigos HTTP retryable.
foreach ( array( 408, 429, 500, 502, 503, 504 ) as $scw_code ) {
	$scw_check( "HTTP {$scw_code} -> retryable", true === SCW_Retry_Policy::is_retryable( true, $scw_code, null ) );
}

$scw_check( 'HTTP 504 está explícitamente en la lista blanca', in_array( 504, SCW_Retry_Policy::RETRYABLE_STATUSES, true ) );

// A.3 Códigos HTTP terminales.
foreach ( array( 200, 204, 301, 302, 304, 307, 308, 400, 401, 403, 404, 405, 410, 418, 422, 451, 501, 505, 507, 511 ) as $scw_code ) {
	$scw_check( "HTTP {$scw_code} -> terminal", false === SCW_Retry_Policy::is_retryable( true, $scw_code, null ) );
}

$scw_check( 'NO se asume que todo 5xx sea retryable', false === SCW_Retry_Policy::is_retryable( true, 501, null ) && false === SCW_Retry_Policy::is_retryable( true, 505, null ) );
$scw_check( 'NO se asume que todo 4xx sea terminal sin excepciones (408 y 429 sí lo son)', true === SCW_Retry_Policy::is_retryable( true, 408, null ) && true === SCW_Retry_Policy::is_retryable( true, 429, null ) );
$scw_check( 'La lista blanca tiene exactamente 6 códigos', 6 === count( SCW_Retry_Policy::RETRYABLE_STATUSES ) );

// A.4 Un veredicto que no sea error nunca se reintenta.
foreach ( array( SCW_Content_Validator::RESULT_OK, SCW_Content_Validator::RESULT_SUSPICIOUS ) as $scw_verdict ) {
	$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'validation_result' => $scw_verdict, 'http_status' => 200, 'attempts' => 1, 'max_attempts' => 5 ) ) );

	$scw_check(
		"validation_result = {$scw_verdict} -> terminal aunque sobren intentos",
		SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] && SCW_Retry_Policy::REASON_NOT_ERROR === $scw_d['reason'],
		'decision=' . $scw_d['decision'] . ' reason=' . $scw_d['reason']
	);
}

$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'validation_result' => SCW_Content_Validator::RESULT_SUSPICIOUS, 'http_status' => 503, 'max_attempts' => 9 ) ) );
$scw_check( 'Un HTML dudoso NO se convierte en retryable ni con un código recuperable', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] );

// A.5 Un error no retryable es terminal aunque queden intentos.
$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'http_status' => 404, 'attempts' => 1, 'max_attempts' => 9 ) ) );
$scw_check( '404 con 8 intentos libres -> terminal', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] && SCW_Retry_Policy::REASON_NOT_RETRYABLE === $scw_d['reason'], 'reason=' . $scw_d['reason'] );
$scw_check( 'La decisión informa de que la clase no era recuperable', false === $scw_d['retryable'] );

// ---------------------------------------------------------------------------
// B. max_attempts
// ---------------------------------------------------------------------------
echo "\nB. Límite de intentos\n";

$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'attempts' => 1, 'max_attempts' => 2 ) ) );
$scw_check( 'attempts 1/2 con HTTP 500 -> retry', SCW_Retry_Policy::DECISION_RETRY === $scw_d['decision'], 'decision=' . $scw_d['decision'] );
$scw_check( 'attempts_left = 1', 1 === $scw_d['attempts_left'] );
$scw_check( 'El motivo es retry_scheduled', SCW_Retry_Policy::REASON_RETRY_SCHEDULED === $scw_d['reason'] );

$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'attempts' => 2, 'max_attempts' => 2 ) ) );
$scw_check( 'attempts 2/2 -> terminal', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] );
$scw_check( 'El motivo es retries_exhausted', SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED === $scw_d['reason'] );
$scw_check( 'Informa de que la clase SÍ era recuperable', true === $scw_d['retryable'] );
$scw_check( 'No propone ningún available_at', null === $scw_d['available_at'] && null === $scw_d['delay'] );

$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'attempts' => 1, 'max_attempts' => 1 ) ) );
$scw_check( 'max_attempts = 1 -> ningún reintento nunca', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] && SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED === $scw_d['reason'] );

$scw_d = SCW_Retry_Policy::decide( $scw_ctx( array( 'attempts' => 7, 'max_attempts' => 2 ) ) );
$scw_check( 'attempts por encima del techo -> terminal, sin attempts_left negativo', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] && 0 === $scw_d['attempts_left'], 'attempts_left=' . $scw_d['attempts_left'] );

// Secuencia completa con max_attempts = 3: retry, retry, FAILED. Nunca un 4.º.
$scw_seq = array();

for ( $scw_i = 1; $scw_i <= 4; $scw_i++ ) {
	$scw_d     = SCW_Retry_Policy::decide( $scw_ctx( array( 'attempts' => $scw_i, 'max_attempts' => 3 ) ) );
	$scw_seq[] = $scw_d['decision'];
}

$scw_check(
	'max_attempts = 3 -> retry, retry, terminal, terminal',
	array( 'retry', 'retry', 'terminal', 'terminal' ) === $scw_seq,
	implode( ' / ', $scw_seq )
);

// ---------------------------------------------------------------------------
// B.bis Backoff
// ---------------------------------------------------------------------------
echo "\nB.bis  Backoff determinista\n";

$scw_check( 'Reintento 1 -> 30 s', 30 === SCW_Retry_Policy::backoff_delay( 1, $scw_delays ) );
$scw_check( 'Reintento 2 -> 90 s', 90 === SCW_Retry_Policy::backoff_delay( 2, $scw_delays ) );
$scw_check( 'Reintento 3 -> tope 90 s (se repite el último escalón)', 90 === SCW_Retry_Policy::backoff_delay( 3, $scw_delays ) );
$scw_check( 'Reintento 50 -> sigue en el tope', 90 === SCW_Retry_Policy::backoff_delay( 50, $scw_delays ) );
$scw_check( 'Índice 0 se trata como el primer reintento', 30 === SCW_Retry_Policy::backoff_delay( 0, $scw_delays ) );
$scw_check( 'Índice negativo se trata como el primer reintento', 30 === SCW_Retry_Policy::backoff_delay( -5, $scw_delays ) );

$scw_ladder = array( 5, 15, 30, 60 );
$scw_check( 'Una escalera de 4 escalones se respeta íntegra', array( 5, 15, 30, 60, 60 ) === array( SCW_Retry_Policy::backoff_delay( 1, $scw_ladder ), SCW_Retry_Policy::backoff_delay( 2, $scw_ladder ), SCW_Retry_Policy::backoff_delay( 3, $scw_ladder ), SCW_Retry_Policy::backoff_delay( 4, $scw_ladder ), SCW_Retry_Policy::backoff_delay( 5, $scw_ladder ) ) );

$scw_check( 'Escalera vacía -> escalera de reserva', 30 === SCW_Retry_Policy::backoff_delay( 1, array() ) );
$scw_check( 'Escalera no array -> escalera de reserva', 30 === SCW_Retry_Policy::backoff_delay( 1, 'basura' ) );
$scw_check( 'Valores no numéricos se descartan', 7 === SCW_Retry_Policy::backoff_delay( 1, array( 'x', 7, null ) ) );
$scw_check( 'Valores negativos se descartan', 7 === SCW_Retry_Policy::backoff_delay( 1, array( -3, 7 ) ) );
$scw_check( 'Un 0 explícito es válido', 0 === SCW_Retry_Policy::backoff_delay( 1, array( 0, 10 ) ) );

// Determinismo: sin reloj, sin azar.
$scw_r1 = SCW_Retry_Policy::backoff_delay( 2, $scw_delays );
$scw_r2 = SCW_Retry_Policy::backoff_delay( 2, $scw_delays );
$scw_check( 'El backoff no usa aleatoriedad', $scw_r1 === $scw_r2 );

$scw_a = SCW_Retry_Policy::decide( $scw_ctx() );
$scw_b = SCW_Retry_Policy::decide( $scw_ctx() );
$scw_check( 'El mismo contexto produce exactamente la misma decisión', $scw_a === $scw_b );
$scw_check( 'available_at = now + delay', ( $scw_now + $scw_a['delay'] ) === $scw_a['available_at'], 'available_at=' . $scw_a['available_at'] );
$scw_check( 'available_at es estrictamente futuro respecto al now del contexto', $scw_a['available_at'] > $scw_now );
$scw_check( 'retry_index = attempts consumidos', 1 === $scw_a['retry_index'] );

$scw_c = SCW_Retry_Policy::decide( $scw_ctx( array( 'now' => $scw_now + 5000 ) ) );
$scw_check( 'Desplazar el now desplaza available_at igual', ( $scw_a['available_at'] + 5000 ) === $scw_c['available_at'] );

$scw_d = SCW_Retry_Policy::decide( array() );
$scw_check( 'Contexto vacío no rompe y no reintenta', SCW_Retry_Policy::DECISION_TERMINAL === $scw_d['decision'] );

// ---------------------------------------------------------------------------
// C. Integración con SCW_Queue
// ---------------------------------------------------------------------------
echo "\nC. Integración con la cola\n";

SCW_Queue::delete_by_source( $scw_source );

$scw_row   = $scw_enqueue( 'queue-defer', array( 'priority' => 200, 'max_attempts' => 3 ) );
$scw_claim = SCW_Queue::claim();

$scw_check( 'Precondición: reclamada, attempts = 1', $scw_claim && (int) $scw_claim['id'] === (int) $scw_row['id'] && 1 === (int) $scw_claim['attempts'] );

$scw_decision = SCW_Retry_Policy::decide(
	SCW_Retry_Policy::context(
		$scw_claim,
		array( 'ok' => true, 'http_status' => 503, 'error_type' => null ),
		array( 'result' => SCW_Content_Validator::RESULT_ERROR ),
		$scw_now
	)
);

$scw_check( 'context() + decide() sobre una fila real -> retry', SCW_Retry_Policy::DECISION_RETRY === $scw_decision['decision'] );
$scw_check( 'context() lee attempts y max_attempts de la fila', 1 === $scw_decision['attempts'] && 3 === $scw_decision['max_attempts'] );

$scw_runs_before = SCW_Runs::find_latest_by_queue_id( $scw_row['id'] );

SCW_Queue::defer( $scw_claim['id'], $scw_decision['available_at'], array( 'lock_token' => $scw_claim['lock_token'], 'last_result' => 'http_503' ) );

$scw_after = SCW_Queue::get( $scw_row['id'] );

$scw_check( 'processing -> pending', $scw_after && SCW_Queue::STATUS_PENDING === $scw_after['status'], 'status=' . ( $scw_after ? $scw_after['status'] : 'n/a' ) );
$scw_check( 'available_at es el calculado por la política', $scw_after && gmdate( 'Y-m-d H:i:s', $scw_decision['available_at'] ) === $scw_after['available_at'], 'available_at=' . ( $scw_after ? $scw_after['available_at'] : 'n/a' ) );
$scw_check( 'lock_token liberado', $scw_after && null === $scw_after['lock_token'] );
$scw_check( 'locked_at liberado', $scw_after && null === $scw_after['locked_at'] );
$scw_check( 'NO queda pending con lock activo a la vez', $scw_after && ! ( SCW_Queue::STATUS_PENDING === $scw_after['status'] && null !== $scw_after['lock_token'] ) );
$scw_check( 'La URL NO se queda en processing esperando el backoff', $scw_after && SCW_Queue::STATUS_PROCESSING !== $scw_after['status'] );
$scw_check( 'attempts se conserva en 1', $scw_after && 1 === (int) $scw_after['attempts'] );
$scw_check( 'max_attempts se conserva en 3', $scw_after && 3 === (int) $scw_after['max_attempts'] );
$scw_check( 'defer() NO ha creado ninguna fila en scw_runs', SCW_Runs::find_latest_by_queue_id( $scw_row['id'] ) === $scw_runs_before );

// El Tick Planner de F5.1 debe ver ese trabajo aplazado sin cambio alguno.
$scw_metrics = SCW_Queue::metrics( $scw_now );
$scw_check( 'La fila aplazada cuenta como pending', $scw_metrics[ SCW_Queue::STATUS_PENDING ] >= 1 );
$scw_check( 'next_available_at refleja el backoff', $scw_metrics['next_available_at'] <= $scw_decision['available_at'] );

// ---------------------------------------------------------------------------
// D. Integración con el Worker
// ---------------------------------------------------------------------------
echo "\nD. Integración con el Worker\n";

SCW_Queue::delete_by_source( $scw_source );
SCW_Runs::delete_by_session( $scw_source );

// D.1 Error retryable con intentos disponibles -> defer.
$scw_row  = $scw_enqueue( 'worker-retry', array( 'priority' => 200, 'max_attempts' => 3 ) );
$scw_mock = $scw_install_mock( $scw_response( 503, array(), '' ) );
$scw_out  = SCW_Worker::run_once();
$scw_calls = $scw_mock['calls']();
$scw_mock['remove']();

$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_run   = SCW_Runs::find_latest_by_queue_id( $scw_row['id'] );

$scw_check( '503 con intentos disponibles -> result = retry', SCW_Worker::RESULT_RETRY === $scw_out['result'], 'result=' . $scw_out['result'] );
$scw_check( 'La fila vuelve a pending', $scw_after && SCW_Queue::STATUS_PENDING === $scw_after['status'], 'status=' . ( $scw_after ? $scw_after['status'] : 'n/a' ) );
$scw_check( 'available_at queda en el futuro', $scw_after && strtotime( $scw_after['available_at'] . ' UTC' ) > time(), 'available_at=' . ( $scw_after ? $scw_after['available_at'] : 'n/a' ) );
// available_at = now + escalón, con now tomado dentro de run_once(). Al leerlo
// aquí ya ha pasado algo de tiempo, así que la espera restante está en
// [escalón - tolerancia, escalón]. Una cota inferior sola no bastaría: también
// la cumpliría el segundo escalón.
$scw_step1     = SCW_Retry_Policy::backoff_delay( 1 );
$scw_remaining = $scw_after ? strtotime( $scw_after['available_at'] . ' UTC' ) - time() : null;
$scw_check(
	'El backoff aplicado es exactamente el primer escalón',
	null !== $scw_remaining && $scw_remaining <= $scw_step1 && $scw_remaining >= $scw_step1 - 5,
	'espera restante=' . var_export( $scw_remaining, true ) . 's, primer escalón=' . $scw_step1 . 's, ventana=[' . ( $scw_step1 - 5 ) . ', ' . $scw_step1 . ']'
);
$scw_check( 'lock liberado', $scw_after && null === $scw_after['lock_token'] && null === $scw_after['locked_at'] );
$scw_check( 'Exactamente 1 petición HTTP en el ciclo', 1 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check( 'Se ha creado su fila en scw_runs', null !== $scw_run );
$scw_check( 'scw_runs.http_status = 503', $scw_run && 503 === (int) $scw_run['http_status'] );
$scw_check( 'scw_runs.attempt = 1', $scw_run && 1 === (int) $scw_run['attempt'] );
$scw_check( 'El outcome expone la decisión de reintento', is_array( $scw_out['retry'] ) && SCW_Retry_Policy::DECISION_RETRY === $scw_out['retry']['decision'] );
$scw_check( 'last_result conserva el motivo real del fallo', $scw_after && 'http_503' === $scw_after['last_result'], 'last_result=' . ( $scw_after ? var_export( $scw_after['last_result'], true ) : 'n/a' ) );

// D.2 Segundo y tercer ciclo: se agotan los intentos y acaba en failed.
$scw_run_ids = array();

for ( $scw_i = 2; $scw_i <= 3; $scw_i++ ) {
	// El backoff real impide reclamarla ya; se adelanta available_at a mano,
	// que es el único modo de simular el paso del tiempo sin esperar.
	global $wpdb;
	$wpdb->update( SCW_Queue::table(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $scw_row['id'] ) );

	$scw_mock = $scw_install_mock( $scw_response( 503, array(), '' ) );
	$scw_out  = SCW_Worker::run_once();
	$scw_mock['remove']();

	$scw_after     = SCW_Queue::get( $scw_row['id'] );
	$scw_run_ids[] = SCW_Runs::find_latest_by_queue_id( $scw_row['id'] )['id'];

	if ( 2 === $scw_i ) {
		$scw_check( 'Intento 2/3 -> sigue siendo retry', SCW_Worker::RESULT_RETRY === $scw_out['result'], 'result=' . $scw_out['result'] );
		$scw_check( 'attempts = 2', 2 === (int) $scw_after['attempts'] );
		$scw_check( 'El backoff usa el segundo escalón', ( strtotime( $scw_after['available_at'] . ' UTC' ) - time() ) > ( SCW_Retry_Policy::backoff_delay( 2 ) - 5 ) );
	} else {
		$scw_check( 'Intento 3/3 -> failed', SCW_Queue::STATUS_FAILED === $scw_out['queue_status'], 'queue_status=' . $scw_out['queue_status'] );
		$scw_check( 'attempts = 3 = max_attempts', 3 === (int) $scw_after['attempts'] && 3 === (int) $scw_after['max_attempts'] );
		$scw_check( 'last_result = retries_exhausted', SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED === $scw_after['last_result'], 'last_result=' . $scw_after['last_result'] );
	}
}

global $wpdb;
$scw_total_runs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SCW_Runs::table() . ' WHERE queue_id = %d', $scw_row['id'] ) ); // phpcs:ignore WordPress.DB
$scw_check( 'Cada petición real tiene su propia fila en scw_runs (3 en total)', 3 === $scw_total_runs, 'runs=' . $scw_total_runs );
$scw_check( 'Las filas de runs de los reintentos son distintas', 2 === count( array_unique( $scw_run_ids ) ) );

$scw_attempts_logged = $wpdb->get_col( $wpdb->prepare( 'SELECT attempt FROM ' . SCW_Runs::table() . ' WHERE queue_id = %d ORDER BY attempt ASC', $scw_row['id'] ) ); // phpcs:ignore WordPress.DB
$scw_check( 'Los runs registran los intentos 1, 2 y 3', array( '1', '2', '3' ) === array_map( 'strval', $scw_attempts_logged ), implode( ',', $scw_attempts_logged ) );

// No debe existir un 4.º intento.
//
// Aislamiento: run_once() reclama la fila pending de mayor prioridad de TODA la
// tabla, y antes recupera cualquier lease caducado, sea del test o no. En este
// punto todas las filas del test ya son terminales, así que un run_once() sin
// protección trabajaría sobre una fila REAL de la cola, si la hubiera, contra
// el mock 503. Por eso la comprobación va en dos capas:
//
//   1. Siempre, y sin modificar nada: la fila está agotada y get_next(), que es
//      de sólo lectura, no puede devolverla.
//   2. Sólo si no existe trabajo abierto ajeno al test (pending o processing
//      con otro source): run_once() de verdad, exigiendo que no reclame nada y
//      no haga ninguna petición. Si hay trabajo ajeno, se omite y se avisa.
$scw_final_row = SCW_Queue::get( $scw_row['id'] );

$scw_check( 'La fila agotada es terminal: failed', $scw_final_row && SCW_Queue::STATUS_FAILED === $scw_final_row['status'], 'status=' . ( $scw_final_row ? $scw_final_row['status'] : 'n/a' ) );
$scw_check( 'attempts = max_attempts: no queda margen para otro intento', $scw_final_row && (int) $scw_final_row['attempts'] === (int) $scw_final_row['max_attempts'], 'attempts=' . ( $scw_final_row ? $scw_final_row['attempts'] . '/' . $scw_final_row['max_attempts'] : 'n/a' ) );

$scw_next = SCW_Queue::get_next();
$scw_check( 'get_next() (sólo lectura) no puede devolver la fila agotada', ! $scw_next || (int) $scw_next['id'] !== (int) $scw_row['id'], 'get_next=' . ( $scw_next ? $scw_next['id'] : 'null' ) );

$scw_foreign_open = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		'SELECT COUNT(*) FROM ' . SCW_Queue::table() . ' WHERE status IN (%s, %s) AND ( source IS NULL OR source <> %s )',
		SCW_Queue::STATUS_PENDING,
		SCW_Queue::STATUS_PROCESSING,
		$scw_source
	)
);

if ( 0 === $scw_foreign_open ) {
	$scw_mock  = $scw_install_mock( $scw_response( 503, array(), '' ) );
	$scw_out4  = SCW_Worker::run_once();
	$scw_calls = $scw_mock['calls']();
	$scw_mock['remove']();

	$scw_check( 'run_once() no reclama ninguna fila: queue_id = null', null === $scw_out4['queue_id'], 'queue_id=' . var_export( $scw_out4['queue_id'], true ) );
	$scw_check( 'run_once() no hace ninguna petición HTTP', 0 === $scw_calls, 'llamadas=' . $scw_calls );
	$scw_check( 'La fila sigue en failed tras el ciclo', SCW_Queue::STATUS_FAILED === SCW_Queue::get( $scw_row['id'] )['status'] );
} else {
	echo "  [AVISO]  Hay {$scw_foreign_open} fila(s) pending/processing ajenas al test en la cola.\n";
	echo "           Se omite el run_once() del 4.º intento para no reclamar ni modificar\n";
	echo "           datos reales. La comprobación de sólo lectura de arriba sigue siendo válida.\n";
}

// D.3 Error retryable sin intentos -> failed en el primer ciclo.
SCW_Queue::delete_by_source( $scw_source );

$scw_row  = $scw_enqueue( 'worker-exhausted', array( 'priority' => 200, 'max_attempts' => 1 ) );
$scw_mock = $scw_install_mock( $scw_response( 500, array(), '' ) );
$scw_out  = SCW_Worker::run_once();
$scw_mock['remove']();

$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_check( '500 con max_attempts = 1 -> failed en el primer ciclo', SCW_Queue::STATUS_FAILED === $scw_after['status'], 'status=' . $scw_after['status'] );
$scw_check( 'El motivo es retries_exhausted', SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED === $scw_after['last_result'] );
$scw_check( 'Aun así se ha registrado su run', null !== SCW_Runs::find_latest_by_queue_id( $scw_row['id'] ) );

// D.4 Errores terminales.
foreach ( array(
	'404' => $scw_response( 404, array(), '' ),
	'403' => $scw_response( 403, array(), '' ),
	'301' => $scw_response( 301, array( 'location' => 'https://otro.example/' ), '' ),
	'302' => $scw_response( 302, array( 'location' => 'https://otro.example/' ), '' ),
	'501' => $scw_response( 501, array(), '' ),
	'505' => $scw_response( 505, array(), '' ),
) as $scw_label => $scw_canned ) {
	$scw_row  = $scw_enqueue( 'terminal-' . $scw_label, array( 'priority' => 190, 'max_attempts' => 5 ) );
	$scw_mock = $scw_install_mock( $scw_canned );
	$scw_out  = SCW_Worker::run_once();
	$scw_mock['remove']();

	$scw_after = SCW_Queue::get( $scw_row['id'] );

	$scw_check(
		"{$scw_label} -> failed en el primer intento pese a tener 4 libres",
		SCW_Queue::STATUS_FAILED === $scw_after['status'] && 1 === (int) $scw_after['attempts'],
		'status=' . $scw_after['status'] . ' attempts=' . $scw_after['attempts']
	);
	$scw_check( "{$scw_label} -> el motivo NO es retries_exhausted", SCW_Retry_Policy::REASON_RETRIES_EXHAUSTED !== $scw_after['last_result'], 'last_result=' . $scw_after['last_result'] );
}

// D.5 WP_Error / timeout con intentos disponibles -> retry.
SCW_Queue::delete_by_source( $scw_source );

$scw_row  = $scw_enqueue( 'worker-wp-error', array( 'priority' => 200, 'max_attempts' => 2 ) );
$scw_mock = $scw_install_mock( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$scw_out  = SCW_Worker::run_once();
$scw_mock['remove']();

$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_run   = SCW_Runs::find_latest_by_queue_id( $scw_row['id'] );

$scw_check( 'WP_Error/timeout con intentos -> retry', SCW_Worker::RESULT_RETRY === $scw_out['result'], 'result=' . $scw_out['result'] );
$scw_check( 'La fila vuelve a pending', SCW_Queue::STATUS_PENDING === $scw_after['status'] );
$scw_check( 'Se ha registrado el run con su error_type', $scw_run && SCW_HTTP_Client::ERROR_TIMEOUT === $scw_run['error_type'], 'error_type=' . var_export( $scw_run ? $scw_run['error_type'] : null, true ) );

// D.6 success y suspicious siguen funcionando igual.
SCW_Queue::delete_by_source( $scw_source );

$scw_row  = $scw_enqueue( 'worker-success', array( 'priority' => 200, 'max_attempts' => 3 ) );
$scw_mock = $scw_install_mock( $scw_response( 200 ) );
$scw_out  = SCW_Worker::run_once();
$scw_mock['remove']();

$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_check( '200 correcto -> success', SCW_Queue::STATUS_SUCCESS === $scw_after['status'], 'status=' . $scw_after['status'] );
$scw_check( 'success NO se aplaza', SCW_Worker::RESULT_SUCCESS === $scw_out['result'] );
$scw_check( 'La decisión registrada es terminal por not_error', SCW_Retry_Policy::REASON_NOT_ERROR === $scw_out['retry']['reason'] );

$scw_row  = $scw_enqueue( 'worker-suspicious', array( 'priority' => 200, 'max_attempts' => 3 ) );
$scw_mock = $scw_install_mock( $scw_response( 200, array( 'content-type' => 'application/json' ), '{"a":1}' ) );
$scw_out  = SCW_Worker::run_once();
$scw_mock['remove']();

$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_check( '2xx con contenido dudoso -> suspicious, NO retry', SCW_Queue::STATUS_SUSPICIOUS === $scw_after['status'], 'status=' . $scw_after['status'] );
$scw_check( 'suspicious es terminal aunque queden intentos', SCW_Worker::RESULT_SUSPICIOUS === $scw_out['result'] && 1 === (int) $scw_after['attempts'] );
$scw_check( 'suspicious conserva su last_result de F4', 0 === strpos( (string) $scw_after['last_result'], 'suspicious_' ), 'last_result=' . $scw_after['last_result'] );

// D.7 Un retry no genera eventos de sistema.
SCW_Queue::delete_by_source( $scw_source );

global $wpdb;
$scw_events_table  = $wpdb->prefix . 'scw_events';
$scw_events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scw_events_table}" ); // phpcs:ignore WordPress.DB

$scw_row  = $scw_enqueue( 'worker-no-events', array( 'priority' => 200, 'max_attempts' => 3 ) );
$scw_mock = $scw_install_mock( $scw_response( 502, array(), '' ) );
SCW_Worker::run_once();
$scw_mock['remove']();

$scw_events_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scw_events_table}" ); // phpcs:ignore WordPress.DB

$scw_check( 'Un reintento NO escribe en scw_events', $scw_events_before === $scw_events_after, 'antes=' . $scw_events_before . ' después=' . $scw_events_after );

// El diagnóstico del retry se reconstruye con lo que ya existe.
$scw_after = SCW_Queue::get( $scw_row['id'] );
$scw_run   = SCW_Runs::find_latest_by_queue_id( $scw_row['id'] );

$scw_check( 'Diagnóstico: queue item identificable', null !== $scw_after && '' !== $scw_after['url'] );
$scw_check( 'Diagnóstico: motivo disponible (queue.last_result y runs.http_status)', 'http_502' === $scw_after['last_result'] && 502 === (int) $scw_run['http_status'] );
$scw_check( 'Diagnóstico: intento actual disponible', 1 === (int) $scw_after['attempts'] && 1 === (int) $scw_run['attempt'] );
$scw_check( 'Diagnóstico: máximo de intentos disponible', 3 === (int) $scw_after['max_attempts'] );
$scw_check( 'Diagnóstico: próxima disponibilidad disponible', null !== $scw_after['available_at'] );

// ---------------------------------------------------------------------------
// E. Limpieza
// ---------------------------------------------------------------------------
echo "\nE. Limpieza\n";

$scw_deleted_queue = SCW_Queue::delete_by_source( $scw_source );
$scw_deleted_runs  = SCW_Runs::delete_by_session( $scw_source );

SCW_State::set( $scw_original_state );

$scw_final = SCW_Queue::stats();

$scw_check( 'Filas de cola eliminadas', $scw_deleted_queue > 0, $scw_deleted_queue . ' filas' );
$scw_check( 'Filas de runs eliminadas', $scw_deleted_runs > 0, $scw_deleted_runs . ' filas' );
$scw_check( 'La cola vuelve a su recuento inicial', (int) $scw_final['total'] === (int) $scw_initial_stats['total'], 'total=' . $scw_final['total'] . ' inicial=' . $scw_initial_stats['total'] );
$scw_check( 'El test no ha modificado http_retry_delays', SCW_Settings::get( 'http_retry_delays' ) === $scw_original_delays, 'valor=' . wp_json_encode( SCW_Settings::get( 'http_retry_delays' ) ) );
$scw_check( 'El esquema sigue en la versión 2', '2' === (string) get_option( SCW_Schema::OPTION_DB_VERSION ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
