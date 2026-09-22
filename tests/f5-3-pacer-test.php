<?php
/**
 * Prueba controlada del ritmo adaptativo (F5.3).
 *
 * Bloques:
 *
 *   A. Configuración      -> normalize_config(), pura.
 *   B. Bandas             -> compute() con duraciones límite.
 *   C. Duty factor        -> valores conocidos y redondeo ceil().
 *   D. Especiales         -> 429, 503, 5xx, timeout, transport_error, >10 s.
 *   E. Clamps             -> 0, negativos, enormes, config corrupta, barrido.
 *   F. EWMA               -> sin previo, con previo, independencia del delay.
 *   G. is_real_request()  -> rechazos previos a la red.
 *   H. Pureza             -> determinismo, sin consultas, análisis estático.
 *   I. Worker             -> integración con pre_http_request simulado.
 *   J. Planner            -> regresión contra el intervalo 0 (F5.1 sin cambios).
 *   K. Extremo a extremo  -> dos ticks consecutivos con trabajo disponible.
 *   L. Limpieza.
 *
 * NO hace ninguna petición HTTP real. NO hay breaker ni watchdog.
 *
 * Ejecución recomendada, desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f5-3-pacer-test.php
 *
 * Todas las filas llevan source = 'f5-3-test' y se eliminan al final. El
 * estado del plugin se restaura siempre. El test NO modifica ningún ajuste.
 *
 * Si la cola contiene trabajo abierto ajeno (filas pending o processing con
 * otro source), la suite se ABORTA antes de escribir nada y sale con código 1.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Pacer' ) ) {
	exit( "SCW_Pacer no está cargada. ¿Está instalada la versión de F5.3?\n" );
}

$scw_source = 'f5-3-test';
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

// Configuración de referencia: los defaults de código, normalizados. Los
// bloques A-H no dependen de lo que haya persistido en esta instalación.
$scw_cfg = SCW_Pacer::normalize_config( SCW_Settings::defaults() );

/**
 * compute() sobre la configuración de referencia.
 *
 * @param int         $status   Código HTTP.
 * @param int         $duration Duración en ms.
 * @param string|null $error    Tipo de error.
 * @param int         $prev     EWMA previo.
 * @param array|null  $cfg      Configuración alternativa.
 * @return array
 */
$scw_pace = function ( $status, $duration, $error = null, $prev = 0, $cfg = null ) use ( &$scw_cfg ) {
	return SCW_Pacer::compute(
		array(
			'http_status' => $status,
			'error_type'  => $error,
			'duration_ms' => $duration,
		),
		$prev,
		null === $cfg ? $scw_cfg : $cfg
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
 * @param int    $code Código.
 * @param string $body Cuerpo; null = HTML válido y completo.
 * @return array
 */
$scw_response = function ( $code, $body = null ) {
	if ( null === $body ) {
		$body = '<!DOCTYPE html><html><head><title>t</title></head><body>' . str_repeat( 'x', 30000 ) . '</body></html>';
	}

	return array(
		'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
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
 * @param string $path Path relativo (sin barra inicial).
 * @return array|null Fila completa.
 */
$scw_enqueue = function ( $path ) use ( $scw_source, $scw_host ) {
	$url = 'https://' . $scw_host . '/' . $path;
	$res = SCW_Queue::insert( $url, array( 'source' => $scw_source ) );

	return $res['id'] ? SCW_Queue::get( $res['id'] ) : null;
};

/**
 * Las tres claves de pacing en SCW_State.
 *
 * @return array
 */
$scw_pacing_state = function () {
	return array(
		'current_delay'    => (int) SCW_State::get( 'current_delay', 0 ),
		'ewma_duration_ms' => (int) SCW_State::get( 'ewma_duration_ms', 0 ),
		'last_request_at'  => (int) SCW_State::get( 'last_request_at', 0 ),
	);
};

/**
 * Ejecuta un ciclo del Worker contra un mock y recoge todo lo relevante.
 *
 * @param mixed $canned Respuesta o WP_Error.
 * @return array
 */
$scw_cycle = function ( $canned ) use ( $scw_install_mock, $scw_pacing_state ) {
	$before    = $scw_pacing_state();
	$mock      = $scw_install_mock( $canned );
	$t_before  = time();
	$outcome   = SCW_Worker::run_once();
	$t_after   = time();
	$calls     = $mock['calls']();
	$mock['remove']();

	$run = $outcome['queue_id'] ? SCW_Runs::find_latest_by_queue_id( $outcome['queue_id'] ) : null;
	$row = $outcome['queue_id'] ? SCW_Queue::get( $outcome['queue_id'] ) : null;

	return array(
		'before'   => $before,
		'after'    => $scw_pacing_state(),
		'outcome'  => $outcome,
		'run'      => $run,
		'row'      => $row,
		'calls'    => $calls,
		't_before' => $t_before,
		't_after'  => $t_after,
	);
};

echo "\n=== F5.3 · Pacer ===\n";

$scw_original_state = SCW_State::all();
$scw_original_raw   = get_option( SCW_Settings::OPTION ); // Sólo para comprobar al final que no ha cambiado.
$scw_initial_stats  = SCW_Queue::stats();

// ---------------------------------------------------------------------------
// Guarda de aislamiento (mismo criterio que F5.2)
// ---------------------------------------------------------------------------
// SCW_Worker::run_once() reclama la fila pending de mayor prioridad de TODA la
// tabla y recupera cualquier lease caducado. Con trabajo ajeno en la cola, los
// bloques I, J y K lo reclamarían contra los mocks. Esta comprobación es de
// sólo lectura y ocurre antes de la primera escritura del test.
global $wpdb;

$scw_foreign_sql = 'FROM ' . SCW_Queue::table() . ' WHERE status IN (%s, %s) AND ( source IS NULL OR source <> %s )';

$scw_foreign_total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
	$wpdb->prepare( 'SELECT COUNT(*) ' . $scw_foreign_sql, SCW_Queue::STATUS_PENDING, SCW_Queue::STATUS_PROCESSING, $scw_source )
);

if ( $scw_foreign_total > 0 ) {
	echo "  [ABORTADO]  La cola contiene {$scw_foreign_total} fila(s) pending/processing ajenas a este test.\n";
	echo "              El test NO se ejecuta, para no reclamar ni modificar datos reales.\n";
	echo "              No se ha escrito nada: ni filas de cola, ni runs, ni estado del plugin.\n";
	echo "\n=== SUITE ABORTADA: trabajo ajeno en la cola. 0 comprobaciones ejecutadas. ===\n\n";

	exit( 1 );
}

// Los runs de este test se agrupan por session_id = source, para poder
// borrarlos al final con SCW_Runs::delete_by_session().
SCW_State::set( array( 'session_id' => $scw_source ) );

// Configuración efectiva de ESTA instalación (valores persistidos). Se informa,
// y se usa en I/J/K, porque es la que aplica el Worker real.
$scw_live_cfg = SCW_Pacer::config();

echo 'Config efectiva (persistida): min=' . $scw_live_cfg['min_delay'] . ' max=' . $scw_live_cfg['max_delay']
	. ' base=' . $scw_live_cfg['base_delay'] . ' duty=' . $scw_live_cfg['duty_factor']
	. ' bandas=' . wp_json_encode( $scw_live_cfg['band_multipliers'] ) . "\n\n";

// ---------------------------------------------------------------------------
// A. Configuración
// ---------------------------------------------------------------------------
echo "A. Configuración\n";

$scw_check( 'Defaults: min_delay = 3', 3 === $scw_cfg['min_delay'] );
$scw_check( 'Defaults: max_delay = 300', 300 === $scw_cfg['max_delay'] );
$scw_check( 'Defaults: base_delay = 5 (OD-1: base de las bandas)', 5.0 === (float) $scw_cfg['base_delay'] );
$scw_check( 'Defaults: duty_factor = 2', 2.0 === (float) $scw_cfg['duty_factor'] );
$scw_check( 'Defaults: especiales 120/300/90/120/60', 120 === $scw_cfg['delay_over_10s'] && 300 === $scw_cfg['delay_timeout'] && 90 === $scw_cfg['delay_429'] && 120 === $scw_cfg['delay_503'] && 60 === $scw_cfg['delay_5xx'] );
$scw_check( 'Defaults: bandas 1 / 1.5 / 3 / 6', array( 'under_2s' => 1.0, '2_4s' => 1.5, '4_6s' => 3.0, '6_10s' => 6.0 ) === array_map( 'floatval', $scw_cfg['band_multipliers'] ) );

foreach ( array( 0, -5, 'abc', null, 0.5 ) as $scw_bad ) {
	$scw_c = SCW_Pacer::normalize_config( array( 'pace_min_delay' => $scw_bad ) );
	$scw_check( 'pace_min_delay = ' . var_export( $scw_bad, true ) . ' -> 3 (suelo duro)', 3 === $scw_c['min_delay'], 'min=' . $scw_c['min_delay'] );
}

$scw_c = SCW_Pacer::normalize_config( array( 'pace_min_delay' => 10, 'pace_max_delay' => 4 ) );
$scw_check( 'pace_max_delay < pace_min_delay -> max = min', 10 === $scw_c['min_delay'] && 10 === $scw_c['max_delay'], 'min=' . $scw_c['min_delay'] . ' max=' . $scw_c['max_delay'] );

$scw_c = SCW_Pacer::normalize_config( array( 'pace_max_delay' => 0 ) );
$scw_check( 'pace_max_delay inválido -> 300', 300 === $scw_c['max_delay'] );

$scw_c = SCW_Pacer::normalize_config( array( 'pace_band_multipliers' => array( '2_4s' => 2 ) ) );
$scw_check( 'Banda persistida incompleta: se respeta la presente', 2.0 === (float) $scw_c['band_multipliers']['2_4s'] );
$scw_check( 'Banda persistida incompleta: las ausentes toman su default', 1.0 === (float) $scw_c['band_multipliers']['under_2s'] && 6.0 === (float) $scw_c['band_multipliers']['6_10s'] );

$scw_c = SCW_Pacer::normalize_config( array( 'pace_band_multipliers' => array( 'under_2s' => -1, '4_6s' => 'x' ) ) );
$scw_check( 'Multiplicadores inválidos -> default de esa banda', 1.0 === (float) $scw_c['band_multipliers']['under_2s'] && 3.0 === (float) $scw_c['band_multipliers']['4_6s'] );

$scw_c = SCW_Pacer::normalize_config( 'no-es-un-array' );
$scw_check( 'Config basura -> defaults completos', 3 === $scw_c['min_delay'] && 300 === $scw_c['max_delay'] );

$scw_check( 'config() real: min_delay >= 1', $scw_live_cfg['min_delay'] >= 1 );
$scw_check( 'config() real: max_delay >= min_delay', $scw_live_cfg['max_delay'] >= $scw_live_cfg['min_delay'] );

// ---------------------------------------------------------------------------
// B. Bandas
// ---------------------------------------------------------------------------
echo "\nB. Bandas (200, base 5, factor 2)\n";

$scw_band_cases = array(
	// duración => array( band, delay esperado ).
	0     => array( SCW_Pacer::BAND_UNDER_2S, 5 ),
	1999  => array( SCW_Pacer::BAND_UNDER_2S, 5 ),
	2000  => array( SCW_Pacer::BAND_2_4S, 8 ),
	3999  => array( SCW_Pacer::BAND_2_4S, 8 ),
	4000  => array( SCW_Pacer::BAND_4_6S, 15 ),
	5999  => array( SCW_Pacer::BAND_4_6S, 15 ),
	6000  => array( SCW_Pacer::BAND_6_10S, 30 ),
	10000 => array( SCW_Pacer::BAND_6_10S, 30 ),
	10001 => array( SCW_Pacer::BAND_OVER_10S, 120 ),
);

foreach ( $scw_band_cases as $scw_ms => $scw_expected ) {
	$scw_p = $scw_pace( 200, $scw_ms );
	$scw_check(
		"{$scw_ms} ms -> {$scw_expected[0]} / {$scw_expected[1]} s",
		$scw_expected[0] === $scw_p['band'] && $scw_expected[1] === $scw_p['delay'],
		'band=' . $scw_p['band'] . ' delay=' . $scw_p['delay']
	);
}

$scw_check( 'band_for_duration(10000) = 6_10s (límite inclusivo)', SCW_Pacer::BAND_6_10S === SCW_Pacer::band_for_duration( 10000 ) );
$scw_check( 'band_for_duration(-1) = under_2s', SCW_Pacer::BAND_UNDER_2S === SCW_Pacer::band_for_duration( -1 ) );

// ---------------------------------------------------------------------------
// C. Duty factor
// ---------------------------------------------------------------------------
echo "\nC. Duty factor\n";

$scw_p = $scw_pace( 200, 9000 );
$scw_check( 'factor 2, 9000 ms: duty 18 < banda 30 -> 30', 30 === $scw_p['delay'], 'delay=' . $scw_p['delay'] );

$scw_c10 = SCW_Pacer::normalize_config( array( 'pace_duty_factor' => 10 ) );
$scw_p   = $scw_pace( 200, 3000, null, 0, $scw_c10 );
$scw_check( 'factor 10, 3000 ms: duty 30 > banda 8 -> 30', 30 === $scw_p['delay'], 'delay=' . $scw_p['delay'] );

$scw_p = $scw_pace( 200, 1100, null, 0, $scw_c10 );
$scw_check( 'factor 10, 1100 ms: duty ceil(11) = 11 > banda 5 -> 11', 11 === $scw_p['delay'], 'delay=' . $scw_p['delay'] );

$scw_c3 = SCW_Pacer::normalize_config( array( 'pace_duty_factor' => 3 ) );
$scw_p  = $scw_pace( 200, 1901, null, 0, $scw_c3 );
$scw_check( 'factor 3, 1901 ms: duty ceil(5.703) = 6 > banda 5 -> 6', 6 === $scw_p['delay'], 'delay=' . $scw_p['delay'] );

$scw_p = $scw_pace( 200, 100, null, 0, $scw_c3 );
$scw_check( 'factor 3, 100 ms: 0.3 no se convierte en 1 por ruido flotante; manda la banda (5)', 5 === $scw_p['delay'], 'delay=' . $scw_p['delay'] );

$scw_c0 = SCW_Pacer::normalize_config( array( 'pace_duty_factor' => 0 ) );
$scw_p  = $scw_pace( 200, 9000, null, 0, $scw_c0 );
$scw_check( 'factor 0: sólo manda la banda (30)', 30 === $scw_p['delay'] );

$scw_p = $scw_pace( 200, 2000 );
$scw_check( 'ceil(): 5 × 1.5 = 7.5 -> 8', 8 === $scw_p['delay'] );

// ---------------------------------------------------------------------------
// D. Especiales
// ---------------------------------------------------------------------------
echo "\nD. Delays especiales\n";

$scw_special = array(
	array( 'HTTP 429 rápido', 429, 100, null, SCW_Pacer::BAND_HTTP_429, 90 ),
	array( 'HTTP 503 rápido', 503, 100, null, SCW_Pacer::BAND_HTTP_503, 120 ),
	array( 'HTTP 500', 500, 100, null, SCW_Pacer::BAND_HTTP_5XX, 60 ),
	array( 'HTTP 502', 502, 100, null, SCW_Pacer::BAND_HTTP_5XX, 60 ),
	array( 'HTTP 504', 504, 100, null, SCW_Pacer::BAND_HTTP_5XX, 60 ),
	array( 'HTTP 501', 501, 100, null, SCW_Pacer::BAND_HTTP_5XX, 60 ),
	array( 'timeout', 0, 30000, SCW_HTTP_Client::ERROR_TIMEOUT, SCW_Pacer::BAND_TIMEOUT, 300 ),
	array( 'transport_error', 0, 50, SCW_HTTP_Client::ERROR_TRANSPORT, SCW_Pacer::BAND_TRANSPORT, 300 ),
	array( 'error desconocido', 0, 50, 'algo_raro', SCW_Pacer::BAND_TRANSPORT, 300 ),
	array( 'sin código y sin error', 0, 50, null, SCW_Pacer::BAND_TRANSPORT, 300 ),
	array( '200 en 12 s', 200, 12000, null, SCW_Pacer::BAND_OVER_10S, 120 ),
	array( '429 en 12 s (429 precede a >10 s)', 429, 12000, null, SCW_Pacer::BAND_HTTP_429, 90 ),
	array( '503 en 25 s (503 precede a >10 s)', 503, 25000, null, SCW_Pacer::BAND_HTTP_503, 120 ),
	array( 'timeout con código residual 200', 200, 30000, SCW_HTTP_Client::ERROR_TIMEOUT, SCW_Pacer::BAND_TIMEOUT, 300 ),
	array( '404 rápido -> banda', 404, 100, null, SCW_Pacer::BAND_UNDER_2S, 5 ),
	array( '301 rápido -> banda', 301, 100, null, SCW_Pacer::BAND_UNDER_2S, 5 ),
	array( '404 en 12 s -> over_10s', 404, 12000, null, SCW_Pacer::BAND_OVER_10S, 120 ),
);

foreach ( $scw_special as $scw_case ) {
	$scw_p = $scw_pace( $scw_case[1], $scw_case[2], $scw_case[3] );
	$scw_check(
		"{$scw_case[0]} -> {$scw_case[4]} / {$scw_case[5]} s",
		$scw_case[4] === $scw_p['band'] && $scw_case[5] === $scw_p['delay'],
		'band=' . $scw_p['band'] . ' delay=' . $scw_p['delay']
	);
}

$scw_cs = SCW_Pacer::normalize_config( array( 'pace_delay_429' => 45, 'pace_delay_timeout' => 200 ) );
$scw_check( 'Los especiales salen de la configuración (429 -> 45)', 45 === $scw_pace( 429, 100, null, 0, $scw_cs )['delay'] );
$scw_check( 'Los especiales salen de la configuración (timeout -> 200)', 200 === $scw_pace( 0, 100, SCW_HTTP_Client::ERROR_TIMEOUT, 0, $scw_cs )['delay'] );

// ---------------------------------------------------------------------------
// E. Clamps
// ---------------------------------------------------------------------------
echo "\nE. Clamps\n";

$scw_check( 'Duración 0 -> 5 (nunca 0)', 5 === $scw_pace( 200, 0 )['delay'] );
$scw_check( 'Duración negativa -> tratada como 0', 5 === $scw_pace( 200, -5000 )['delay'] && SCW_Pacer::BAND_UNDER_2S === $scw_pace( 200, -5000 )['band'] );

$scw_c_tiny = SCW_Pacer::normalize_config( array( 'pace_base_delay' => 0.1, 'pace_duty_factor' => 0 ) );
$scw_check( 'Delay calculado 1 < min 3 -> 3', 3 === $scw_pace( 200, 0, null, 0, $scw_c_tiny )['delay'] );

$scw_c_zero_special = SCW_Pacer::normalize_config( array( 'pace_delay_429' => 0 ) );
$scw_check( 'Especial configurado a 0 -> min 3', 3 === $scw_pace( 429, 0, null, 0, $scw_c_zero_special )['delay'] );

$scw_c_huge = SCW_Pacer::normalize_config( array( 'pace_duty_factor' => 1000000 ) );
$scw_check( 'Factor enorme -> max 300', 300 === $scw_pace( 200, 9000, null, 0, $scw_c_huge )['delay'] );

$scw_c_cap = SCW_Pacer::normalize_config( array( 'pace_max_delay' => 60 ) );
$scw_check( 'Especial > max (timeout 300, max 60) -> 60', 60 === $scw_pace( 0, 100, SCW_HTTP_Client::ERROR_TIMEOUT, 0, $scw_c_cap )['delay'] );

$scw_check( 'clamp(0, 3, 300) = 3', 3 === SCW_Pacer::clamp( 0, 3, 300 ) );
$scw_check( 'clamp(-100, 3, 300) = 3', 3 === SCW_Pacer::clamp( -100, 3, 300 ) );
$scw_check( 'clamp(1e12, 3, 300) = 300', 300 === SCW_Pacer::clamp( 1e12, 3, 300 ) );
$scw_check( 'clamp(7.2, 3, 300) = 8 (ceil)', 8 === SCW_Pacer::clamp( 7.2, 3, 300 ) );
$scw_check( 'clamp(basura, 3, 300) = 3', 3 === SCW_Pacer::clamp( 'x', 3, 300 ) );

// Config ya normalizada pero manipulada: compute() reafirma el suelo.
$scw_c_manip              = $scw_cfg;
$scw_c_manip['min_delay'] = 0;
$scw_c_manip['base_delay'] = 0.0001;
$scw_c_manip['duty_factor'] = 0;
$scw_check( 'Config normalizada con min_delay = 0 manipulado -> compute() usa 3', 3 === $scw_pace( 200, 0, null, 0, $scw_c_manip )['delay'] );

// Barrido: el invariante min <= delay <= max, y delay > 0, en todo el espacio.
$scw_sweep_ok    = true;
$scw_sweep_count = 0;
$scw_sweep_bad   = '';

foreach ( array( 0, 200, 301, 404, 429, 500, 503, 504, 599 ) as $scw_st ) {
	foreach ( array( -1, 0, 1, 1999, 2000, 5000, 9999, 10000, 10001, 30000, 999999 ) as $scw_ms ) {
		foreach ( array( null, SCW_HTTP_Client::ERROR_TIMEOUT, SCW_HTTP_Client::ERROR_TRANSPORT ) as $scw_err ) {
			foreach ( array( $scw_cfg, $scw_c_tiny, $scw_c_huge, $scw_c_zero_special ) as $scw_cc ) {
				$scw_sweep_count++;
				$scw_d = $scw_pace( $scw_st, $scw_ms, $scw_err, 0, $scw_cc )['delay'];

				if ( ! is_int( $scw_d ) || $scw_d <= 0 || $scw_d < $scw_cc['min_delay'] || $scw_d > $scw_cc['max_delay'] ) {
					$scw_sweep_ok  = false;
					$scw_sweep_bad = "status={$scw_st} ms={$scw_ms} err=" . var_export( $scw_err, true ) . " delay={$scw_d}";
				}
			}
		}
	}
}

$scw_check( "Barrido de {$scw_sweep_count} combinaciones: delay entero, > 0 y en [min, max]", $scw_sweep_ok, $scw_sweep_bad );

// ---------------------------------------------------------------------------
// E2. Configuración parcial o corrupta en compute()
// ---------------------------------------------------------------------------
echo "\nE2. Configuración parcial o corrupta en compute()\n";

/**
 * Ejecuta un callable registrando cualquier notice/warning de PHP.
 *
 * PHP 7.4 emite E_NOTICE "Undefined index"; PHP 8 emite E_WARNING
 * "Undefined array key". Se capturan ambos.
 *
 * @param callable $fn Código a ejecutar.
 * @return array { result, errors }
 */
$scw_capture = function ( $fn ) {
	$errors = array();

	set_error_handler(
		function ( $errno, $errstr ) use ( &$errors ) {
			$errors[] = $errno . ': ' . $errstr;
			return true;
		},
		E_ALL
	);

	try {
		$result = $fn();
	} finally {
		restore_error_handler();
	}

	return array(
		'result' => $result,
		'errors' => $errors,
	);
};

$scw_full_keys = array( 'min_delay', 'max_delay', 'base_delay', 'duty_factor', 'band_multipliers', 'delay_over_10s', 'delay_timeout', 'delay_429', 'delay_503', 'delay_5xx' );

// Casos que recorren TODAS las ramas de compute(): array( status, ms, error ).
$scw_all_branches = array(
	array( 200, 0, null ),
	array( 200, 2500, null ),
	array( 200, 4500, null ),
	array( 200, 8000, null ),
	array( 200, 12000, null ),
	array( 429, 100, null ),
	array( 503, 100, null ),
	array( 500, 100, null ),
	array( 0, 30000, SCW_HTTP_Client::ERROR_TIMEOUT ),
	array( 0, 50, SCW_HTTP_Client::ERROR_TRANSPORT ),
);

/**
 * compute() sobre todas las ramas con una config dada, capturando errores.
 *
 * @param mixed $cfg Configuración (posiblemente parcial).
 * @return array { results: array, errors: array }
 */
$scw_run_branches = function ( $cfg ) use ( $scw_capture, $scw_all_branches ) {
	return $scw_capture(
		function () use ( $cfg, $scw_all_branches ) {
			$out = array();

			foreach ( $scw_all_branches as $case ) {
				$out[] = SCW_Pacer::compute(
					array( 'http_status' => $case[0], 'duration_ms' => $case[1], 'error_type' => $case[2] ),
					0,
					$cfg
				);
			}

			return $out;
		}
	);
};

// Referencia: los mismos casos con la config completa de defaults.
$scw_ref_branches = $scw_run_branches( $scw_cfg );
$scw_check( 'Referencia: config completa sin notices/warnings', empty( $scw_ref_branches['errors'] ) );

/**
 * ¿Toda salida tiene exactamente {delay, ewma, band} con delay entero en [min, max]?
 *
 * @param array $results Salidas de compute().
 * @param int   $min     Mínimo esperado.
 * @param int   $max     Máximo esperado.
 * @return bool
 */
$scw_shape_ok = function ( $results, $min, $max ) {
	foreach ( $results as $r ) {
		if ( ! is_array( $r ) || array( 'delay', 'ewma', 'band' ) !== array_keys( $r ) ) {
			return false;
		}

		if ( ! is_int( $r['delay'] ) || $r['delay'] < $min || $r['delay'] > $max || $r['delay'] <= 0 ) {
			return false;
		}

		if ( ! is_int( $r['ewma'] ) || ! is_string( $r['band'] ) ) {
			return false;
		}
	}

	return true;
};

// E2.1 Sólo min_delay, max_delay y band_multipliers (el caso que antes se
// tomaba por "ya normalizada").
$scw_partial = array(
	'min_delay'        => 3,
	'max_delay'        => 300,
	'band_multipliers' => $scw_cfg['band_multipliers'],
);
$scw_r = $scw_run_branches( $scw_partial );
$scw_check( 'Sólo min/max/band_multipliers: 0 notices/warnings', empty( $scw_r['errors'] ), implode( ' | ', array_unique( $scw_r['errors'] ) ) );
$scw_check( 'Sólo min/max/band_multipliers: salida {delay, ewma, band} en [3, 300]', $scw_shape_ok( $scw_r['result'], 3, 300 ) );
$scw_check( 'Sólo min/max/band_multipliers: resultados idénticos a la config completa', $scw_r['result'] === $scw_ref_branches['result'], wp_json_encode( $scw_r['result'] ) );
$scw_check( 'Sólo min/max/band_multipliers: 429 -> 90 (no se degrada al mínimo)', 90 === $scw_r['result'][5]['delay'], 'delay=' . $scw_r['result'][5]['delay'] );

// E2.2 Sin base_delay ni duty_factor.
$scw_partial = $scw_cfg;
unset( $scw_partial['base_delay'], $scw_partial['duty_factor'] );
$scw_r = $scw_run_branches( $scw_partial );
$scw_check( 'Sin base_delay/duty_factor: 0 notices/warnings', empty( $scw_r['errors'] ), implode( ' | ', array_unique( $scw_r['errors'] ) ) );
$scw_check( 'Sin base_delay/duty_factor: resultados idénticos a los defaults (5/8/15/30)', $scw_r['result'] === $scw_ref_branches['result'] );
$scw_check( 'Sin base_delay/duty_factor: 2500 ms -> 8 s', 8 === $scw_r['result'][1]['delay'] );

$scw_partial = $scw_cfg;
unset( $scw_partial['base_delay'] );
$scw_r = $scw_capture(
	function () use ( $scw_partial ) {
		return SCW_Pacer::compute( array( 'http_status' => 200, 'duration_ms' => 4500 ), 0, $scw_partial );
	}
);
$scw_check( 'Sólo base_delay ausente: 0 notices y 4500 ms -> 15 s', empty( $scw_r['errors'] ) && 15 === $scw_r['result']['delay'] );

// E2.3 Cada clave especial ausente, una a una.
$scw_special_keys = array(
	'delay_429'      => array( 429, 100, null, 90 ),
	'delay_503'      => array( 503, 100, null, 120 ),
	'delay_5xx'      => array( 500, 100, null, 60 ),
	'delay_timeout'  => array( 0, 100, SCW_HTTP_Client::ERROR_TIMEOUT, 300 ),
	'delay_over_10s' => array( 200, 12000, null, 120 ),
);

foreach ( $scw_special_keys as $scw_key => $scw_case ) {
	$scw_partial = $scw_cfg;
	unset( $scw_partial[ $scw_key ] );

	$scw_r = $scw_capture(
		function () use ( $scw_partial, $scw_case ) {
			return SCW_Pacer::compute( array( 'http_status' => $scw_case[0], 'duration_ms' => $scw_case[1], 'error_type' => $scw_case[2] ), 0, $scw_partial );
		}
	);

	$scw_check(
		"Sin {$scw_key}: 0 notices y se usa su default ({$scw_case[3]} s)",
		empty( $scw_r['errors'] ) && $scw_case[3] === $scw_r['result']['delay'],
		'delay=' . $scw_r['result']['delay'] . ' errores=' . count( $scw_r['errors'] )
	);
}

$scw_partial = $scw_cfg;
unset( $scw_partial['delay_over_10s'], $scw_partial['delay_timeout'], $scw_partial['delay_429'], $scw_partial['delay_503'], $scw_partial['delay_5xx'] );
$scw_r = $scw_run_branches( $scw_partial );
$scw_check( 'Sin NINGUNA clave especial: 0 notices y resultados idénticos a los defaults', empty( $scw_r['errors'] ) && $scw_r['result'] === $scw_ref_branches['result'] );

// E2.4 band_multipliers ausente, vacío, parcial o no-array en forma normalizada.
foreach ( array(
	'ausente'      => null,
	'vacío'        => array(),
	'parcial'      => array( '2_4s' => 1.5 ),
	'no es array'  => 'basura',
) as $scw_label => $scw_bm ) {
	$scw_partial = $scw_cfg;

	if ( null === $scw_bm ) {
		unset( $scw_partial['band_multipliers'] );
	} else {
		$scw_partial['band_multipliers'] = $scw_bm;
	}

	$scw_r = $scw_run_branches( $scw_partial );
	$scw_check( "band_multipliers {$scw_label}: 0 notices y resultados idénticos a los defaults", empty( $scw_r['errors'] ) && $scw_r['result'] === $scw_ref_branches['result'], implode( ' | ', array_unique( $scw_r['errors'] ) ) );
}

// E2.5 Config vacía, no-array y con valores corruptos en forma normalizada.
foreach ( array(
	'array vacío' => array(),
	'null'        => null,
	'string'      => 'basura',
	'corrupta'    => array(
		'min_delay'        => 'x',
		'max_delay'        => -1,
		'base_delay'       => 'abc',
		'duty_factor'      => -3,
		'band_multipliers' => array( 'under_2s' => 0, '4_6s' => 'y' ),
		'delay_over_10s'   => null,
		'delay_timeout'    => array(),
		'delay_429'        => -90,
		'delay_503'        => 'z',
		'delay_5xx'        => false,
	),
) as $scw_label => $scw_bad_cfg ) {
	$scw_r = $scw_run_branches( $scw_bad_cfg );
	$scw_check( "Config {$scw_label}: 0 notices/warnings", empty( $scw_r['errors'] ), implode( ' | ', array_unique( $scw_r['errors'] ) ) );
	$scw_check( "Config {$scw_label}: se comporta exactamente como los defaults", $scw_r['result'] === $scw_ref_branches['result'] );
}

// E2.6 Los límites propios de una config parcial se respetan.
$scw_r = $scw_run_branches( array( 'min_delay' => 10, 'max_delay' => 50 ) );
$scw_check( 'Parcial {min 10, max 50}: 0 notices y todo delay en [10, 50]', empty( $scw_r['errors'] ) && $scw_shape_ok( $scw_r['result'], 10, 50 ) );
$scw_check( 'Parcial {min 10, max 50}: timeout -> 50 (recortado), 200 rápido -> 10 (elevado)', 50 === $scw_r['result'][8]['delay'] && 10 === $scw_r['result'][0]['delay'] );

$scw_r = $scw_run_branches( array( 'min_delay' => 0, 'max_delay' => 2 ) );
$scw_check( 'Parcial {min 0, max 2}: min -> 3 y max -> 3 (max < min), todo delay = 3', empty( $scw_r['errors'] ) && $scw_shape_ok( $scw_r['result'], 3, 3 ) );

// E2.7 La forma cruda (claves pace_*) sigue aceptándose, también parcial.
$scw_r = $scw_run_branches( array( 'pace_delay_429' => 45 ) );
$scw_check( 'Forma cruda parcial {pace_delay_429: 45}: 0 notices y 429 -> 45', empty( $scw_r['errors'] ) && 45 === $scw_r['result'][5]['delay'] );
$scw_check( 'Forma cruda parcial: el resto como los defaults', array_slice( $scw_r['result'], 0, 5 ) === array_slice( $scw_ref_branches['result'], 0, 5 ) );

$scw_r = $scw_capture(
	function () {
		return SCW_Pacer::compute( array( 'http_status' => 429, 'duration_ms' => 1 ), 0, array( 'delay_429' => 20, 'pace_delay_429' => 70 ) );
	}
);
$scw_check( 'Ambas formas presentes: prevalece la normalizada (20, no 70)', empty( $scw_r['errors'] ) && 20 === $scw_r['result']['delay'] );

// E2.8 normalize_config() sigue siendo la fuente de verdad, y es idempotente
// sobre su propia salida (una config normalizada no cambia al renormalizarse).
$scw_nca = new ReflectionMethod( 'SCW_Pacer', 'normalize_config_array' );
$scw_nca->setAccessible( true ); // Necesario en PHP 7.4; inocuo en 8.x.

$scw_check( 'Idempotencia: normalize_config_array( defaults normalizados ) === defaults normalizados', $scw_nca->invoke( null, $scw_cfg ) === $scw_cfg );

foreach ( array( $scw_c10, $scw_c_tiny, $scw_c_huge, $scw_c_cap, $scw_c_zero_special ) as $scw_i => $scw_cc ) {
	$scw_check( "Idempotencia sobre config normalizada #{$scw_i}", $scw_nca->invoke( null, $scw_cc ) === $scw_cc );
}

$scw_eff = $scw_nca->invoke( null, array( 'min_delay' => 3 ) );
$scw_check( 'Config efectiva de una parcial contiene TODAS las claves', $scw_full_keys === array_keys( $scw_eff ), wp_json_encode( array_keys( $scw_eff ) ) );
$scw_check( 'Config efectiva de una parcial = normalize_config( defaults )', $scw_eff === $scw_cfg );

// ---------------------------------------------------------------------------
// F. EWMA
// ---------------------------------------------------------------------------
echo "\nF. EWMA\n";

$scw_check( 'Sin previo (0): ewma = duración', 1234 === $scw_pace( 200, 1234, null, 0 )['ewma'] );
$scw_check( 'Previo negativo: tratado como ausente', 1234 === $scw_pace( 200, 1234, null, -50 )['ewma'] );
$scw_check( 'Previo 1000, duración 2000 -> 1300', 1300 === $scw_pace( 200, 2000, null, 1000 )['ewma'] );
$scw_check( 'Previo 500, duración 1500 -> round(450 + 350) = 800', 800 === SCW_Pacer::ewma( 1500, 500 ) );
$scw_check( 'Redondeo: previo 1, duración 2 -> round(1.3) = 1', 1 === SCW_Pacer::ewma( 2, 1 ) );
$scw_check( 'Redondeo: previo 3, duración 0 -> round(2.1) = 2', 2 === SCW_Pacer::ewma( 0, 3 ) );
$scw_check( 'Redondeo .5: previo 5, duración 0 -> round(3.5) = 4', 4 === SCW_Pacer::ewma( 0, 5 ) );
$scw_check( 'Timeout también alimenta el EWMA (OD-7b)', 30000 === $scw_pace( 0, 30000, SCW_HTTP_Client::ERROR_TIMEOUT, 0 )['ewma'] );
$scw_check( 'El EWMA es entero', is_int( $scw_pace( 200, 777, null, 333 )['ewma'] ) );

// Independencia: el EWMA NO es input del delay.
$scw_indep = true;

foreach ( array( array( 200, 100, null ), array( 200, 3000, null ), array( 200, 8000, null ), array( 429, 100, null ), array( 0, 30000, SCW_HTTP_Client::ERROR_TIMEOUT ) ) as $scw_case ) {
	$scw_ref = $scw_pace( $scw_case[0], $scw_case[1], $scw_case[2], 0 );

	foreach ( array( 1, 500, 99999, 10000000 ) as $scw_prev ) {
		$scw_alt = $scw_pace( $scw_case[0], $scw_case[1], $scw_case[2], $scw_prev );

		if ( $scw_alt['delay'] !== $scw_ref['delay'] || $scw_alt['band'] !== $scw_ref['band'] ) {
			$scw_indep = false;
		}
	}
}

$scw_check( 'Variar el EWMA previo (0 .. 10^7) no cambia delay ni band', $scw_indep );

// ---------------------------------------------------------------------------
// G. is_real_request()
// ---------------------------------------------------------------------------
echo "\nG. is_real_request()\n";

$scw_check( 'invalid_url -> no es petición real', false === SCW_Pacer::is_real_request( array( 'error_type' => SCW_HTTP_Client::ERROR_INVALID_URL ) ) );
$scw_check( 'host_not_allowed -> no es petición real', false === SCW_Pacer::is_real_request( array( 'error_type' => SCW_HTTP_Client::ERROR_HOST ) ) );
$scw_check( '200 -> real', true === SCW_Pacer::is_real_request( array( 'ok' => true, 'http_status' => 200, 'error_type' => null ) ) );
$scw_check( '503 -> real', true === SCW_Pacer::is_real_request( array( 'ok' => true, 'http_status' => 503, 'error_type' => null ) ) );
$scw_check( 'timeout -> real', true === SCW_Pacer::is_real_request( array( 'error_type' => SCW_HTTP_Client::ERROR_TIMEOUT ) ) );
$scw_check( 'transport_error -> real', true === SCW_Pacer::is_real_request( array( 'error_type' => SCW_HTTP_Client::ERROR_TRANSPORT ) ) );
$scw_check( 'Basura -> no real', false === SCW_Pacer::is_real_request( 'x' ) );

// Contra el HTTP Client real: un host ajeno se rechaza antes de la red.
$scw_mock    = $scw_install_mock( $scw_response( 200 ) );
$scw_foreign = SCW_HTTP_Client::fetch( 'https://ajeno.example/x/' );
$scw_calls   = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( 'fetch() de host ajeno: 0 llamadas de red', 0 === $scw_calls );
$scw_check( 'fetch() de host ajeno -> is_real_request() = false', false === SCW_Pacer::is_real_request( $scw_foreign ), 'error_type=' . var_export( $scw_foreign['error_type'], true ) );

// ---------------------------------------------------------------------------
// H. Pureza
// ---------------------------------------------------------------------------
echo "\nH. Pureza\n";

$scw_obs = array( 'http_status' => 200, 'error_type' => null, 'duration_ms' => 4321 );
$scw_r1  = SCW_Pacer::compute( $scw_obs, 1000, $scw_cfg );
$scw_r2  = SCW_Pacer::compute( $scw_obs, 1000, $scw_cfg );
$scw_check( 'Determinista: mismo input, mismo output', $scw_r1 === $scw_r2 );
$scw_check( 'Salida exacta {delay, ewma, band}', array( 'delay', 'ewma', 'band' ) === array_keys( $scw_r1 ) );

$scw_raw_state_before = get_option( SCW_State::OPTION );
$scw_queries_before   = (int) $wpdb->num_queries;

for ( $scw_i = 0; $scw_i < 50; $scw_i++ ) {
	SCW_Pacer::compute( array( 'http_status' => 200 + $scw_i, 'duration_ms' => $scw_i * 300 ), $scw_i, $scw_cfg );
	SCW_Pacer::is_real_request( $scw_obs );
	SCW_Pacer::normalize_config( SCW_Settings::defaults() );
}

$scw_check( 'compute()/is_real_request()/normalize_config(): 0 consultas a BD', (int) $wpdb->num_queries === $scw_queries_before, 'consultas=' . ( (int) $wpdb->num_queries - $scw_queries_before ) );
$scw_check( 'compute() no altera scw_runtime_state', get_option( SCW_State::OPTION ) === $scw_raw_state_before );

/**
 * Tokens de código de un fichero, sin comentarios ni espacios.
 *
 * @param string $file Ruta.
 * @return array Lista de array( name, text ).
 */
$scw_code_tokens = function ( $file ) {
	$out = array();

	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
		if ( is_array( $tok ) ) {
			if ( in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML ), true ) ) {
				continue;
			}

			$out[] = array( token_name( $tok[0] ), $tok[1] );
		} else {
			$out[] = array( 'CHAR', $tok );
		}
	}

	return $out;
};

$scw_pacer_file = SCW_PLUGIN_DIR . 'includes/runner/class-scw-pacer.php';
$scw_ptoks      = $scw_code_tokens( $scw_pacer_file );

$scw_forbidden_idents = array(
	'SCW_State', 'SCW_Queue', 'SCW_Runs', 'SCW_Scheduler', 'SCW_Tick_Planner', 'SCW_Logger', 'SCW_Worker',
	'get_option', 'update_option', 'add_option', 'delete_option', 'get_transient', 'set_transient', 'delete_transient',
	'wp_schedule_single_event', 'wp_schedule_event', 'wp_next_scheduled', 'wp_clear_scheduled_hook',
	'time', 'microtime', 'date', 'gmdate', 'current_time', 'hrtime', 'rand', 'mt_rand', 'random_int',
);

$scw_found = array();

foreach ( $scw_ptoks as $scw_tok ) {
	if ( 'T_STRING' === $scw_tok[0] && in_array( $scw_tok[1], $scw_forbidden_idents, true ) ) {
		$scw_found[] = $scw_tok[1];
	}

	if ( 'T_VARIABLE' === $scw_tok[0] && '$wpdb' === $scw_tok[1] ) {
		$scw_found[] = '$wpdb';
	}
}

$scw_check( 'Análisis estático: el Pacer no usa State/Queue/BD/cron/reloj', empty( $scw_found ), implode( ',', array_unique( $scw_found ) ) );

// SCW_Settings sólo puede aparecer dentro de config().
$scw_settings_hits = 0;
$scw_settings_in   = 0;
$scw_current_fn    = '';

foreach ( $scw_ptoks as $scw_idx => $scw_tok ) {
	if ( 'T_FUNCTION' === $scw_tok[0] && isset( $scw_ptoks[ $scw_idx + 1 ] ) ) {
		$scw_current_fn = $scw_ptoks[ $scw_idx + 1 ][1];
	}

	if ( 'T_STRING' === $scw_tok[0] && 'SCW_Settings' === $scw_tok[1] ) {
		$scw_settings_hits++;

		if ( 'config' === $scw_current_fn ) {
			$scw_settings_in++;
		}
	}
}

$scw_check( 'SCW_Settings aparece una sola vez, dentro de config()', 1 === $scw_settings_hits && 1 === $scw_settings_in, "total={$scw_settings_hits} en_config={$scw_settings_in}" );

// Compatibilidad PHP 7.4 sobre los ficheros de F5.3.
$scw_php8_tokens = array( 'T_MATCH', 'T_ENUM', 'T_READONLY', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE' );
$scw_php8_funcs  = array( 'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'fdiv' );

foreach ( array(
	'includes/runner/class-scw-pacer.php',
	'includes/runner/class-scw-worker.php',
	'includes/class-scw-state.php',
	'servisello-cache-warmer.php',
	'tests/f5-3-pacer-test.php',
) as $scw_rel ) {
	$scw_bad   = array();
	$scw_toks  = $scw_code_tokens( SCW_PLUGIN_DIR . $scw_rel );
	$scw_count = count( $scw_toks );

	for ( $scw_i = 0; $scw_i < $scw_count; $scw_i++ ) {
		$scw_tok = $scw_toks[ $scw_i ];

		if ( in_array( $scw_tok[0], $scw_php8_tokens, true ) ) {
			$scw_bad[] = $scw_tok[0];
		}

		if ( 'T_STRING' === $scw_tok[0] && in_array( $scw_tok[1], $scw_php8_funcs, true ) ) {
			$scw_bad[] = $scw_tok[1];
		}

		// Sintaxis de PHP 8 que PHP 8 tokeniza como T_STRING: match / enum / readonly.
		if ( 'T_STRING' === $scw_tok[0] && in_array( strtolower( $scw_tok[1] ), array( 'match', 'enum', 'readonly' ), true )
			&& isset( $scw_toks[ $scw_i + 1 ] ) && in_array( $scw_toks[ $scw_i + 1 ][1], array( '(', '{' ), true ) ) {
			$scw_bad[] = $scw_tok[1];
		}

		// Propiedades tipadas: visibilidad [static] TIPO $var.
		if ( in_array( $scw_tok[0], array( 'T_PUBLIC', 'T_PRIVATE', 'T_PROTECTED', 'T_VAR' ), true ) ) {
			$scw_j = $scw_i + 1;

			if ( isset( $scw_toks[ $scw_j ] ) && 'T_STATIC' === $scw_toks[ $scw_j ][0] ) {
				$scw_j++;
			}

			if ( isset( $scw_toks[ $scw_j ], $scw_toks[ $scw_j + 1 ] ) && in_array( $scw_toks[ $scw_j ][0], array( 'T_STRING', 'T_ARRAY', 'T_NAME_QUALIFIED' ), true ) && 'T_VARIABLE' === $scw_toks[ $scw_j + 1 ][0] ) {
				$scw_bad[] = 'typed_property';
			}
		}
	}

	$scw_check( "PHP 7.4: {$scw_rel} sin sintaxis/funciones de PHP 8", empty( $scw_bad ), implode( ',', array_unique( $scw_bad ) ) );
}

// ---------------------------------------------------------------------------
// I. Integración Worker
// ---------------------------------------------------------------------------
echo "\nI. Integración Worker\n";

// Punto de partida conocido para las tres claves de pacing.
$scw_sentinel = array(
	'current_delay'    => 77,
	'ewma_duration_ms' => 4444,
	'last_request_at'  => 1600000000,
);
SCW_State::set( $scw_sentinel );

// I.1 Cola vacía (la guarda garantiza que no hay pending ajenos).
$scw_c = $scw_cycle( $scw_response( 200 ) );
$scw_check( "Cola vacía: result = 'empty'", SCW_Worker::RESULT_EMPTY === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( 'Cola vacía: 0 peticiones HTTP', 0 === $scw_c['calls'] );
$scw_check( 'Cola vacía: pacing intacto (current_delay, ewma, last_request_at)', $scw_sentinel === $scw_c['after'], wp_json_encode( $scw_c['after'] ) );
$scw_check( 'Cola vacía: outcome.pace = null', null === $scw_c['outcome']['pace'] );

// I.2 Skipped por exclusión (/carrito/ está en los defaults de exclude_prefix).
$scw_ins = $scw_enqueue( 'carrito/prueba-f5-3-excluida/' );
$scw_c   = $scw_cycle( $scw_response( 200 ) );
$scw_check( 'Excluida: la fila reclamada es la del test', $scw_ins && (int) $scw_ins['id'] === (int) $scw_c['outcome']['queue_id'] );
$scw_check( "Excluida: result = 'skipped'", SCW_Worker::RESULT_SKIPPED === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( 'Excluida: 0 peticiones HTTP', 0 === $scw_c['calls'] );
$scw_check( 'Excluida: pacing intacto', $scw_sentinel === $scw_c['after'], wp_json_encode( $scw_c['after'] ) );
$scw_check( 'Excluida: outcome.pace = null', null === $scw_c['outcome']['pace'] );

// I.3 Skipped por el normalizer: se fuerza en BD una URL de host ajeno en una
// fila PROPIA del test (Queue::insert() no la aceptaría).
$scw_ins = $scw_enqueue( 'prueba-f5-3-normalizer/' );
$wpdb->update( SCW_Queue::table(), array( 'url' => 'https://ajeno.example/prueba-f5-3/' ), array( 'id' => (int) $scw_ins['id'], 'source' => $scw_source ) ); // phpcs:ignore WordPress.DB
$scw_c = $scw_cycle( $scw_response( 200 ) );
$scw_check( "Normalizer: result = 'skipped'", SCW_Worker::RESULT_SKIPPED === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] . ' error=' . $scw_c['outcome']['error_type'] );
$scw_check( 'Normalizer: 0 peticiones HTTP', 0 === $scw_c['calls'] );
$scw_check( 'Normalizer: pacing intacto', $scw_sentinel === $scw_c['after'], wp_json_encode( $scw_c['after'] ) );

// I.4 200 OK: se escriben las tres claves, coherentes con la fila de scw_runs.
$scw_ins = $scw_enqueue( 'prueba-f5-3-ok/' );
$scw_c   = $scw_cycle( $scw_response( 200 ) );
$scw_p   = $scw_c['outcome']['pace'];

$scw_check( "200: result = 'success'", SCW_Worker::RESULT_SUCCESS === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( '200: exactamente 1 petición HTTP', 1 === $scw_c['calls'] );
$scw_check( '200: outcome.pace = {delay, ewma, band}', is_array( $scw_p ) && array( 'delay', 'ewma', 'band' ) === array_keys( $scw_p ) );
$scw_check( '200: existe fila en scw_runs', null !== $scw_c['run'] );

$scw_run_ms  = $scw_c['run'] ? (int) $scw_c['run']['duration_ms'] : -1;
$scw_expect  = SCW_Pacer::compute(
	array( 'http_status' => 200, 'error_type' => null, 'duration_ms' => $scw_run_ms ),
	$scw_sentinel['ewma_duration_ms'],
	$scw_live_cfg
);

$scw_check( '200: pace recalculado con el duration_ms de scw_runs coincide exactamente', $scw_expect === $scw_p, 'runs.duration_ms=' . $scw_run_ms . ' pace=' . wp_json_encode( $scw_p ) );
$scw_check( '200: State.current_delay = pace.delay', $scw_p && $scw_p['delay'] === $scw_c['after']['current_delay'] );
$scw_check( '200: State.ewma_duration_ms = pace.ewma', $scw_p && $scw_p['ewma'] === $scw_c['after']['ewma_duration_ms'] );
$scw_check( '200: EWMA encadena sobre el previo (0.3·d + 0.7·4444)', $scw_p && (int) round( 0.3 * $scw_run_ms + 0.7 * 4444 ) === $scw_p['ewma'], 'ewma=' . ( $scw_p ? $scw_p['ewma'] : '?' ) );
$scw_check(
	'200: last_request_at dentro del ciclo [t_antes, t_después]',
	$scw_c['after']['last_request_at'] >= $scw_c['t_before'] && $scw_c['after']['last_request_at'] <= $scw_c['t_after'],
	'last=' . $scw_c['after']['last_request_at'] . ' rango=' . $scw_c['t_before'] . '..' . $scw_c['t_after']
);
$scw_check( '200: current_delay >= pace_min_delay (> 0)', $scw_c['after']['current_delay'] >= $scw_live_cfg['min_delay'] && $scw_c['after']['current_delay'] > 0, 'delay=' . $scw_c['after']['current_delay'] );
$scw_check( '200: band = ' . SCW_Pacer::band_for_duration( $scw_run_ms ), $scw_p && SCW_Pacer::band_for_duration( $scw_run_ms ) === $scw_p['band'] );

// I.5 Segunda petición: el EWMA encadena sobre el valor recién escrito.
$scw_prev_ewma = $scw_c['after']['ewma_duration_ms'];
$scw_ins       = $scw_enqueue( 'prueba-f5-3-ok-2/' );
$scw_c         = $scw_cycle( $scw_response( 200 ) );
$scw_run_ms    = $scw_c['run'] ? (int) $scw_c['run']['duration_ms'] : -1;
$scw_check( 'Segunda petición: EWMA = ewma(d, previo escrito)', SCW_Pacer::ewma( $scw_run_ms, $scw_prev_ewma ) === $scw_c['after']['ewma_duration_ms'], 'ewma=' . $scw_c['after']['ewma_duration_ms'] . ' previo=' . $scw_prev_ewma );

// I.5b Duración real no nula: el mock tarda ~2,1 s DENTRO de fetch(), de modo
// que duration_ms lo mide el HTTP Client real. Comprueba que el Pacer usa
// exactamente ese valor (el mismo que queda en scw_runs) y no otro reloj.
$scw_ins        = $scw_enqueue( 'prueba-f5-3-lenta/' );
$scw_slow_mock  = function () use ( $scw_response ) {
	usleep( 2100000 );
	return $scw_response( 200 );
};
add_filter( 'pre_http_request', $scw_slow_mock, 10, 3 );
$scw_prev_ewma  = (int) SCW_State::get( 'ewma_duration_ms', 0 );
$scw_slow_out   = SCW_Worker::run_once();
remove_filter( 'pre_http_request', $scw_slow_mock, 10 );
$scw_slow_run   = SCW_Runs::find_latest_by_queue_id( $scw_slow_out['queue_id'] );
$scw_slow_ms    = $scw_slow_run ? (int) $scw_slow_run['duration_ms'] : -1;
$scw_slow_exp   = SCW_Pacer::compute( array( 'http_status' => 200, 'error_type' => null, 'duration_ms' => $scw_slow_ms ), $scw_prev_ewma, $scw_live_cfg );

$scw_check( 'Lenta: scw_runs.duration_ms >= 2000 (medido por fetch())', $scw_slow_ms >= 2000, 'duration_ms=' . $scw_slow_ms );
$scw_check( 'Lenta: band = 2_4s', $scw_slow_out['pace'] && SCW_Pacer::BAND_2_4S === $scw_slow_out['pace']['band'], wp_json_encode( $scw_slow_out['pace'] ) );
$scw_check( 'Lenta: pace = compute( scw_runs.duration_ms ) exactamente', $scw_slow_exp === $scw_slow_out['pace'] );
$scw_check( 'Lenta: current_delay = clamp(8) con la config efectiva', $scw_slow_exp['delay'] === (int) SCW_State::get( 'current_delay' ), 'delay=' . SCW_State::get( 'current_delay' ) );
$scw_check( 'Lenta: ewma_duration_ms = ewma( scw_runs.duration_ms, previo )', SCW_Pacer::ewma( $scw_slow_ms, $scw_prev_ewma ) === (int) SCW_State::get( 'ewma_duration_ms' ) );

// I.6 Suspicious: actualiza pacing y NO se reintenta.
$scw_ins = $scw_enqueue( 'prueba-f5-3-suspicious/' );
SCW_State::set( $scw_sentinel );
$scw_c = $scw_cycle( $scw_response( 200, '<html><body>x</body></html>' ) );

$scw_check( "Suspicious: result = 'suspicious'", SCW_Worker::RESULT_SUSPICIOUS === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( 'Suspicious: la fila queda terminal suspicious (sin retry)', $scw_c['row'] && SCW_Queue::STATUS_SUSPICIOUS === $scw_c['row']['status'], 'status=' . ( $scw_c['row'] ? $scw_c['row']['status'] : '?' ) );
$scw_check( 'Suspicious: la decisión de retry es terminal por not_error', SCW_Retry_Policy::DECISION_TERMINAL === $scw_c['outcome']['retry']['decision'] && SCW_Retry_Policy::REASON_NOT_ERROR === $scw_c['outcome']['retry']['reason'] );
$scw_check( 'Suspicious: 1 petición HTTP', 1 === $scw_c['calls'] );
$scw_check( 'Suspicious: pacing actualizado (last_request_at cambia)', $scw_c['after']['last_request_at'] !== $scw_sentinel['last_request_at'] && $scw_c['after']['last_request_at'] >= $scw_c['t_before'] );
$scw_check( 'Suspicious: current_delay = pace.delay (>= min)', $scw_c['outcome']['pace'] && $scw_c['outcome']['pace']['delay'] === $scw_c['after']['current_delay'] && $scw_c['after']['current_delay'] >= $scw_live_cfg['min_delay'] );

// I.7 Retry 503: pace 120 y la fila sigue aplazándose con el backoff de F5.2.
$scw_retry_delays = SCW_Retry_Policy::configured_delays();
$scw_ins          = $scw_enqueue( 'prueba-f5-3-503/' );
$scw_c            = $scw_cycle( $scw_response( 503 ) );
$scw_503_row      = $scw_c['row'];
$scw_503_last     = $scw_c['after']['last_request_at'];

$scw_check( "503: result = 'retry' (F5.2 intacta)", SCW_Worker::RESULT_RETRY === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( '503: la fila vuelve a pending', $scw_503_row && SCW_Queue::STATUS_PENDING === $scw_503_row['status'] );
$scw_check(
	'503: available_at = decisión de retry (backoff ' . $scw_retry_delays[0] . ' s), no el pacing',
	$scw_503_row && gmdate( 'Y-m-d H:i:s', (int) $scw_c['outcome']['retry']['available_at'] ) === $scw_503_row['available_at'] && (int) $scw_c['outcome']['retry']['delay'] === (int) $scw_retry_delays[0],
	'available_at=' . ( $scw_503_row ? $scw_503_row['available_at'] : '?' ) . ' retry=' . wp_json_encode( $scw_c['outcome']['retry'] )
);
$scw_check( '503: pace band = http_503', $scw_c['outcome']['pace'] && SCW_Pacer::BAND_HTTP_503 === $scw_c['outcome']['pace']['band'] );
$scw_check( '503: current_delay = clamp(120)', SCW_Pacer::clamp( $scw_live_cfg['delay_503'], $scw_live_cfg['min_delay'], $scw_live_cfg['max_delay'] ) === $scw_c['after']['current_delay'], 'delay=' . $scw_c['after']['current_delay'] );

// available_at se almacena como DATETIME UTC (igual que compara la suite F5.2).

// El Planner combina ambos ejes con max(): manda el pacing (120 > 30).
SCW_State::set( array( 'run_status' => SCW_State::STATUS_RUNNING ) );
$scw_plan = SCW_Tick_Planner::plan( SCW_Tick_Planner::context( $scw_503_last ) );
SCW_State::set( array( 'run_status' => $scw_original_state['run_status'] ) );

$scw_check( '503 + Planner: at = last_request_at + current_delay (max, no suma)', SCW_Tick_Planner::ACTION_SCHEDULE === $scw_plan['action'] && $scw_503_last + $scw_c['after']['current_delay'] === $scw_plan['at'], 'plan=' . wp_json_encode( $scw_plan ) );
$scw_check( '503 + Planner: reason = paced', SCW_Tick_Planner::REASON_PACED === $scw_plan['reason'] );

// I.8 Retry 429: pace 90.
$scw_ins = $scw_enqueue( 'prueba-f5-3-429/' );
$scw_c   = $scw_cycle( $scw_response( 429 ) );
$scw_check( "429: result = 'retry'", SCW_Worker::RESULT_RETRY === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( '429: la fila aplazada con el backoff de F5.2', $scw_c['row'] && gmdate( 'Y-m-d H:i:s', (int) $scw_c['outcome']['retry']['available_at'] ) === $scw_c['row']['available_at'] );
$scw_check( '429: pace band = http_429, current_delay = clamp(90)', $scw_c['outcome']['pace'] && SCW_Pacer::BAND_HTTP_429 === $scw_c['outcome']['pace']['band'] && SCW_Pacer::clamp( $scw_live_cfg['delay_429'], $scw_live_cfg['min_delay'], $scw_live_cfg['max_delay'] ) === $scw_c['after']['current_delay'] );

// I.9 Timeout: pace 300 y el EWMA también se actualiza.
$scw_ins = $scw_enqueue( 'prueba-f5-3-timeout/' );
$scw_c   = $scw_cycle( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$scw_check( "Timeout: result = 'retry'", SCW_Worker::RESULT_RETRY === $scw_c['outcome']['result'], 'result=' . $scw_c['outcome']['result'] );
$scw_check( 'Timeout: pace band = timeout, current_delay = clamp(300)', $scw_c['outcome']['pace'] && SCW_Pacer::BAND_TIMEOUT === $scw_c['outcome']['pace']['band'] && SCW_Pacer::clamp( $scw_live_cfg['delay_timeout'], $scw_live_cfg['min_delay'], $scw_live_cfg['max_delay'] ) === $scw_c['after']['current_delay'] );
$scw_check( 'Timeout: EWMA escrito = pace.ewma', $scw_c['outcome']['pace'] && $scw_c['outcome']['pace']['ewma'] === $scw_c['after']['ewma_duration_ms'] );

// I.10 Transport error.
$scw_ins = $scw_enqueue( 'prueba-f5-3-transport/' );
$scw_c   = $scw_cycle( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
$scw_check( 'Transport error: pace band = transport_error, current_delay = clamp(300)', $scw_c['outcome']['pace'] && SCW_Pacer::BAND_TRANSPORT === $scw_c['outcome']['pace']['band'] && SCW_Pacer::clamp( $scw_live_cfg['delay_timeout'], $scw_live_cfg['min_delay'], $scw_live_cfg['max_delay'] ) === $scw_c['after']['current_delay'] );

// ---------------------------------------------------------------------------
// J. Planner: regresión contra el intervalo 0
// ---------------------------------------------------------------------------
echo "\nJ. Planner: regresión contra el intervalo 0\n";

// J.1 Documenta el gap de F5.1, que sigue existiendo en el Planner (no se ha
// tocado): con el estado previo a F5.3 el intervalo podía ser 0.
$scw_syn_now = 1700000000;
$scw_syn     = array(
	'now'               => $scw_syn_now,
	'run_status'        => SCW_State::STATUS_RUNNING,
	'breaker_state'     => SCW_State::BREAKER_CLOSED,
	'pending'           => 5,
	'processing'        => 0,
	'claimable_now'     => 5,
	'next_available_at' => $scw_syn_now,
	'open_work'         => 5,
	'lease_seconds'     => 120,
	'pace_max_delay'    => 300,
);

$scw_plan = SCW_Tick_Planner::plan( array_merge( $scw_syn, array( 'last_request_at' => 0, 'current_delay' => 0 ) ) );
$scw_check( 'Estado pre-F5.3 (0/0): el Planner de F5.1 devuelve at = now (gap documentado)', $scw_syn_now === $scw_plan['at'] );

// J.2 Con cualquier salida del Pacer tras una petición en now, el intervalo es > 0.
$scw_all_positive = true;

foreach ( array( array( 200, 0, null ), array( 200, 2500, null ), array( 200, 12000, null ), array( 429, 10, null ), array( 503, 10, null ), array( 0, 30000, SCW_HTTP_Client::ERROR_TIMEOUT ) ) as $scw_case ) {
	$scw_pp   = $scw_pace( $scw_case[0], $scw_case[1], $scw_case[2] );
	$scw_plan = SCW_Tick_Planner::plan( array_merge( $scw_syn, array( 'last_request_at' => $scw_syn_now, 'current_delay' => $scw_pp['delay'] ) ) );

	if ( ! ( $scw_plan['at'] > $scw_syn_now && $scw_plan['delay'] > 0 && $scw_plan['at'] - $scw_syn_now >= $scw_cfg['min_delay'] ) ) {
		$scw_all_positive = false;
	}
}

$scw_check( 'Sintético: para toda salida del Pacer, at > last_request_at y delay planificado > 0', $scw_all_positive );

// J.3 Sobre el estado REAL escrito por el Worker, con trabajo reclamable propio.
$scw_ins_j1 = $scw_enqueue( 'prueba-f5-3-planner-a/' );
$scw_ins_j2 = $scw_enqueue( 'prueba-f5-3-planner-b/' );
$scw_c      = $scw_cycle( $scw_response( 200 ) );
$scw_last   = $scw_c['after']['last_request_at'];

SCW_State::set( array( 'run_status' => SCW_State::STATUS_RUNNING ) );
$scw_ctx  = SCW_Tick_Planner::context( $scw_last );
$scw_plan = SCW_Tick_Planner::plan( $scw_ctx );
SCW_State::set( array( 'run_status' => $scw_original_state['run_status'] ) );

$scw_check( 'Real: queda trabajo reclamable ahora (claimable_now > 0)', $scw_ctx['claimable_now'] > 0, 'claimable_now=' . $scw_ctx['claimable_now'] );
$scw_check( 'Real: context() lee last_request_at escrito por el Worker', $scw_last === $scw_ctx['last_request_at'] && $scw_last > 0 );
$scw_check( 'Real: context() lee current_delay >= min', $scw_ctx['current_delay'] >= $scw_live_cfg['min_delay'] );
$scw_check( 'Real: action = schedule', SCW_Tick_Planner::ACTION_SCHEDULE === $scw_plan['action'] );
$scw_check( 'Real: next_tick > last_request_at', $scw_plan['at'] > $scw_last, 'at=' . $scw_plan['at'] . ' last=' . $scw_last );
$scw_check( 'Real: delay planificado != 0 (>= pace_min_delay)', $scw_plan['delay'] > 0 && $scw_plan['delay'] >= $scw_live_cfg['min_delay'], 'delay=' . $scw_plan['delay'] );
$scw_check( 'Real: reason = paced (el trabajo está listo; el ritmo manda)', SCW_Tick_Planner::REASON_PACED === $scw_plan['reason'] );

// ---------------------------------------------------------------------------
// K. Extremo a extremo: dos ticks consecutivos con trabajo disponible
// ---------------------------------------------------------------------------
echo "\nK. Extremo a extremo: dos ticks consecutivos con trabajo disponible\n";

// Queda 1 fila claimable de J; se añaden dos más para que, tras dos ticks,
// siga habiendo trabajo y el Planner no pare por cola agotada.
$scw_enqueue( 'prueba-f5-3-e2e-a/' );
$scw_enqueue( 'prueba-f5-3-e2e-b/' );

delete_transient( SCW_Scheduler::LOCK_TRANSIENT );
wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );
SCW_State::set( array( 'run_status' => SCW_State::STATUS_RUNNING, 'status_reason' => 'f5-3-test' ) );

$scw_ticks = array();

for ( $scw_t = 1; $scw_t <= 2; $scw_t++ ) {
	$scw_mock = $scw_install_mock( $scw_response( 200 ) );
	SCW_Plugin::instance()->scheduler()->handle_tick();
	$scw_calls = $scw_mock['calls']();
	$scw_mock['remove']();

	$scw_ticks[ $scw_t ] = array(
		'calls'    => $scw_calls,
		'next'     => (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ),
		'expected' => (int) SCW_State::get( 'expected_next_tick_at', 0 ),
		'last_req' => (int) SCW_State::get( 'last_request_at', 0 ),
		'last_tick' => (int) SCW_State::get( 'last_tick_at', 0 ),
		'delay'    => (int) SCW_State::get( 'current_delay', 0 ),
	);

	$scw_k = $scw_ticks[ $scw_t ];

	$scw_check( "Tick {$scw_t}: exactamente 1 petición HTTP", 1 === $scw_k['calls'], 'llamadas=' . $scw_k['calls'] );
	$scw_check( "Tick {$scw_t}: hay un siguiente scw_tick armado", $scw_k['next'] > 0 );
	$scw_check( "Tick {$scw_t}: expected_next_tick_at = tick armado", $scw_k['next'] === $scw_k['expected'] );
	$scw_check( "Tick {$scw_t}: next_tick_timestamp > last_request_at", $scw_k['next'] > $scw_k['last_req'], 'next=' . $scw_k['next'] . ' last_request_at=' . $scw_k['last_req'] );
	$scw_check( "Tick {$scw_t}: next_tick - last_request_at >= pace_min_delay", $scw_k['next'] - $scw_k['last_req'] >= $scw_live_cfg['min_delay'], 'separación=' . ( $scw_k['next'] - $scw_k['last_req'] ) );
	$scw_check( "Tick {$scw_t}: delay planificado respecto al fin del tick != 0", $scw_k['next'] - $scw_k['last_tick'] > 0, 'next - last_tick_at=' . ( $scw_k['next'] - $scw_k['last_tick'] ) );
	$scw_check( "Tick {$scw_t}: current_delay >= pace_min_delay", $scw_k['delay'] >= $scw_live_cfg['min_delay'] );
}

$scw_check( 'Tick 2 no es anterior al tick 1 en last_request_at', $scw_ticks[2]['last_req'] >= $scw_ticks[1]['last_req'] );
$scw_check( 'El instante planificado para el tick 2 es posterior a la petición del tick 1', $scw_ticks[1]['next'] > $scw_ticks[1]['last_req'] );
$scw_check( 'Sigue en RUNNING (no se ha parado por cola agotada)', SCW_State::is_running() );

wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );
delete_transient( SCW_Scheduler::LOCK_TRANSIENT );

// ---------------------------------------------------------------------------
// L. Limpieza y restauración
// ---------------------------------------------------------------------------
echo "\nL. Limpieza\n";

$scw_deleted_queue = SCW_Queue::delete_by_source( $scw_source );
$scw_deleted_runs  = SCW_Runs::delete_by_session( $scw_source );

SCW_State::set( $scw_original_state );

$scw_final = SCW_Queue::stats();

$scw_check( 'Filas de cola eliminadas', $scw_deleted_queue > 0, $scw_deleted_queue . ' filas' );
$scw_check( 'Filas de runs eliminadas', $scw_deleted_runs > 0, $scw_deleted_runs . ' filas' );
$scw_check( 'La cola vuelve a su recuento inicial', (int) $scw_final['total'] === (int) $scw_initial_stats['total'], 'total=' . $scw_final['total'] . ' inicial=' . $scw_initial_stats['total'] );
$scw_check( 'State restaurado (incluye last_request_at)', SCW_State::all() === $scw_original_state );
$scw_check( 'No queda ningún scw_tick armado por el test', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'El test no ha modificado scw_settings', get_option( SCW_Settings::OPTION ) === $scw_original_raw );
$scw_check( 'last_request_at forma parte de SCW_State::defaults()', array_key_exists( 'last_request_at', SCW_State::defaults() ) && 0 === SCW_State::defaults()['last_request_at'] );
$scw_check( 'El esquema sigue en la versión 2', '2' === (string) get_option( SCW_Schema::OPTION_DB_VERSION ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
