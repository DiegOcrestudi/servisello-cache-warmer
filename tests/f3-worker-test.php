<?php
/**
 * TEST · Worker y scheduler real (F3).
 *
 * No hace NINGUNA petición de red real: toda respuesta HTTP se simula
 * mediante 'pre_http_request'. Sí escribe de verdad en scw_queue, scw_runs y
 * scw_events, usando el mismo mecanismo que producción (SCW_Queue::claim(),
 * SCW_Queue::complete(), SCW_Worker::run_once() y, en la última sección,
 * SCW_Scheduler::handle_tick() real).
 *
 * Todas las filas de cola/runs que crea llevan source/session = 'f3-worker-test'
 * y se eliminan al final. El estado de ejecución (run_status, session_id) se
 * guarda antes de empezar y se restaura al terminar, para no dejar el crawler
 * arrancado en un entorno real.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f3-worker-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Worker' ) ) {
	exit( "SCW_Worker no está cargada. ¿Está activo el plugin?\n" );
}

$scw_pass   = 0;
$scw_fail   = 0;
$scw_source = 'f3-worker-test';

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

/**
 * Instala un mock de pre_http_request que cuenta invocaciones.
 *
 * @param array|WP_Error $canned Respuesta a devolver en cada llamada.
 * @return array { remove: callable, calls: callable }
 */
$scw_install_mock = function ( $canned ) {
	$calls = 0;

	$callback = function ( $preempt, $parsed_args, $url ) use ( $canned, &$calls ) {
		$calls++;
		return $canned;
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );

	return array(
		'remove' => function () use ( $callback ) {
			remove_filter( 'pre_http_request', $callback, 10 );
		},
		'calls'  => function () use ( &$calls ) {
			return $calls;
		},
	);
};

/**
 * Respuesta con la misma forma que produce WordPress al completar una
 * petición de verdad.
 *
 * @param int    $code    Código HTTP.
 * @param array  $headers Headers en minúsculas.
 * @param string $body    Cuerpo.
 * @return array
 */
$scw_response = function ( $code, array $headers = array(), $body = 'ok' ) {
	return array(
		'headers'  => $headers,
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

/**
 * Inserta una URL de prueba válida y no excluida.
 *
 * @param string $suffix Sufijo para distinguir varias URLs.
 * @return array Resultado de SCW_Queue::insert().
 */
$scw_enqueue = function ( $suffix ) use ( $scw_source ) {
	$settings = SCW_Settings::all();
	$url      = 'https://' . $settings['allowed_host'] . '/prueba-f3-worker-' . $suffix . '/';

	return SCW_Queue::insert( $url, array( 'source' => $scw_source ) );
};

echo "\n=== F3 · TEST: worker y scheduler ===\n";

// Estado original: se restaura al final para no dejar el crawler arrancado.
$scw_original_state = SCW_State::all();

SCW_Queue::delete_by_source( $scw_source );
SCW_Runs::delete_by_session( $scw_source );
delete_transient( SCW_Scheduler::LOCK_TRANSIENT );
wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

$scw_initial_stats = SCW_Queue::stats();
echo 'Filas pendientes en la cola (global) antes de empezar: ' . $scw_initial_stats['pending'] . "\n";

// --- 1. Cola vacía ------------------------------------------------------------------
echo "\n1. Cola vacía\n";

if ( 0 === $scw_initial_stats['pending'] ) {
	$scw_result = SCW_Worker::run_once();
	$scw_check( "result = 'empty'", SCW_Worker::RESULT_EMPTY === $scw_result['result'], 'result=' . $scw_result['result'] );
	$scw_check( 'queue_id = null', null === $scw_result['queue_id'] );
} else {
	$scw_check( 'Precondición: la cola global está vacía antes de la prueba', false, 'pending=' . $scw_initial_stats['pending'] . ' (se omite el escenario para no dar un falso resultado)' );
}

// --- 2. Claim correcto + HTTP 2xx -> success + registro en scw_runs ----------------
echo "\n2. Claim correcto, HTTP 200 -> success, registro en scw_runs\n";

SCW_State::set( array( 'session_id' => $scw_source ) );

$scw_ins  = $scw_enqueue( '200' );
$scw_check( 'La URL se ha encolado', (bool) $scw_ins['inserted'], 'id=' . var_export( $scw_ins['id'], true ) );

// F4.4: el cuerpo simulado debe ser un documento válido y suficientemente
// grande. Desde F4.4 la validación de contenido es real, y un cuerpo de 15
// bytes sería una anomalía fuerte (body_too_small) que daría 'suspicious'.
$scw_body_ok = '<!DOCTYPE html><html lang="es"><head><title>Servisello</title></head><body>'
	. '<ul class="products">' . str_repeat( '<li class="product">Sello</li>', 900 ) . '</ul>'
	. '</body></html>';

$scw_mock   = $scw_install_mock( $scw_response( 200, array( 'x-litespeed-cache' => 'miss', 'content-type' => 'text/html' ), $scw_body_ok ) );
$scw_result = SCW_Worker::run_once();
$scw_calls  = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( 'queue_id coincide con el insertado', $scw_ins['id'] === $scw_result['queue_id'] );
$scw_check( "queue_status = 'success'", SCW_Queue::STATUS_SUCCESS === $scw_result['queue_status'], 'queue_status=' . $scw_result['queue_status'] );
$scw_check( 'Se ha hecho exactamente 1 petición HTTP', 1 === $scw_calls, 'llamadas=' . $scw_calls );

$scw_row = SCW_Queue::get( $scw_ins['id'] );
$scw_check( "La fila de Queue queda en 'success'", $scw_row && SCW_Queue::STATUS_SUCCESS === $scw_row['status'] );
$scw_check( 'lock_token queda liberado (NULL)', $scw_row && null === $scw_row['lock_token'] );
$scw_check( 'locked_at queda liberado (NULL)', $scw_row && null === $scw_row['locked_at'] );

$scw_run = SCW_Runs::find_latest_by_queue_id( $scw_ins['id'] );
$scw_check( 'Se ha creado una fila en scw_runs', null !== $scw_run );
$scw_check( 'scw_runs.http_status = 200', $scw_run && 200 === (int) $scw_run['http_status'] );
$scw_check( 'scw_runs.queue_id correcto', $scw_run && $scw_ins['id'] === (int) $scw_run['queue_id'] );
$scw_check( 'scw_runs.x_litespeed_cache = miss', $scw_run && 'miss' === $scw_run['x_litespeed_cache'] );
$scw_check( "scw_runs.validation_result = 'ok' (F4.4)", $scw_run && SCW_Content_Validator::RESULT_OK === $scw_run['validation_result'], 'validation_result=' . var_export( $scw_run ? $scw_run['validation_result'] : null, true ) );
$scw_check( 'scw_runs.yith_presets es NULL: la URL de prueba no espera YITH y el HTML no lo trae', $scw_run && null === $scw_run['yith_presets'], 'yith_presets=' . var_export( $scw_run ? $scw_run['yith_presets'] : null, true ) );
$scw_check( 'scw_runs.session_id coincide con la sesión activa', $scw_run && $scw_source === $scw_run['session_id'] );

// --- 3. HTTP 3xx / 4xx / 5xx / WP_Error -> failed ------------------------------------
echo "\n3. HTTP 3xx/4xx/5xx/WP_Error -> failed\n";

$scw_cases = array(
	'3xx'      => $scw_response( 302, array( 'location' => 'https://otro-host.example/' ) ),
	'4xx'      => $scw_response( 404 ),
	'5xx'      => $scw_response( 500 ),
	'wp_error' => new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ),
);

foreach ( $scw_cases as $scw_label => $scw_canned ) {
	$scw_ins = $scw_enqueue( 'case-' . $scw_label );

	$scw_mock   = $scw_install_mock( $scw_canned );
	$scw_result = SCW_Worker::run_once();
	$scw_mock['remove']();

	$scw_check(
		"{$scw_label}: queue_status = 'failed'",
		SCW_Queue::STATUS_FAILED === $scw_result['queue_status'],
		'queue_status=' . $scw_result['queue_status']
	);

	$scw_run = SCW_Runs::find_latest_by_queue_id( $scw_ins['id'] );
	$scw_check( "{$scw_label}: se ha creado una fila en scw_runs", null !== $scw_run );
}

// --- 4. Una única URL por tick, con varias pendientes ------------------------------
echo "\n4. Una única URL por tick habiendo varias pendientes\n";

$scw_ins_a = $scw_enqueue( 'multi-a' );
$scw_ins_b = $scw_enqueue( 'multi-b' );

$scw_mock   = $scw_install_mock( $scw_response( 200 ) );
$scw_result = SCW_Worker::run_once();
$scw_calls  = $scw_mock['calls']();
$scw_mock['remove']();

$scw_row_a = SCW_Queue::get( $scw_ins_a['id'] );
$scw_row_b = SCW_Queue::get( $scw_ins_b['id'] );

$scw_terminal_a = $scw_row_a && SCW_Queue::STATUS_PENDING !== $scw_row_a['status'];
$scw_terminal_b = $scw_row_b && SCW_Queue::STATUS_PENDING !== $scw_row_b['status'];

$scw_check( 'Se ha hecho exactamente 1 petición HTTP', 1 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check(
	'Exactamente una de las dos filas ha quedado en estado terminal (la otra sigue pending)',
	( $scw_terminal_a xor $scw_terminal_b ),
	'a=' . $scw_row_a['status'] . ' b=' . $scw_row_b['status']
);

// Se completa manualmente la que quedó pendiente, para no dejarla huérfana.
$scw_leftover = $scw_terminal_a ? $scw_row_b : $scw_row_a;
$scw_claimed  = SCW_Queue::claim();
if ( $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_leftover['id'] ) {
	SCW_Queue::complete( $scw_claimed['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claimed['lock_token'], 'last_result' => 'test_cleanup' ) );
}

// --- 5. complete() con lock_token incorrecto se rechaza ------------------------------
echo "\n5. complete() con lock_token incorrecto\n";

$scw_ins     = $scw_enqueue( 'bad-token' );
$scw_claimed = SCW_Queue::claim();

$scw_check( 'Se ha reclamado la fila esperada', $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_ins['id'] );

$scw_rejected = SCW_Queue::complete( $scw_claimed['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => 'token-incorrecto-de-otro-worker' ) );
$scw_check( 'complete() con token incorrecto devuelve false', false === $scw_rejected );

$scw_row = SCW_Queue::get( $scw_ins['id'] );
$scw_check( "La fila sigue en 'processing' (no se ha cerrado)", $scw_row && SCW_Queue::STATUS_PROCESSING === $scw_row['status'] );

// Cierre correcto con el token real, para no dejarla bloqueada.
SCW_Queue::complete( $scw_claimed['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claimed['lock_token'], 'last_result' => 'test_cleanup' ) );

// --- 6. Lease expirado recuperable mediante SCW_Queue --------------------------------
echo "\n6. Lease expirado recuperable\n";

global $wpdb;

$scw_ins     = $scw_enqueue( 'expired-lease' );
$scw_claimed = SCW_Queue::claim();

$scw_check( 'Se ha reclamado la fila para envejecer su lease', $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_ins['id'] );

// Se envejece el lock a mano (mismo mecanismo ya usado en el test de F2.1): el
// lease se mide contra locked_at, no existe otra forma de simularlo sin
// esperar de verdad.
$wpdb->update(
	SCW_Queue::table(),
	array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
	array( 'id' => $scw_claimed['id'] )
);

$scw_events_before = SCW_Logger::recent( 5 );

$scw_mock   = $scw_install_mock( $scw_response( 200 ) );
$scw_result = SCW_Worker::run_once();
$scw_mock['remove']();

$scw_check( 'recovered_leases >= 1', $scw_result['recovered_leases'] >= 1, 'recovered_leases=' . $scw_result['recovered_leases'] );
$scw_check( 'La fila con el lease caducado ha sido reclamada de nuevo y completada', $scw_ins['id'] === $scw_result['queue_id'] );

$scw_row = SCW_Queue::get( $scw_ins['id'] );
$scw_check( 'attempts = 2 (reclamada dos veces: la original y la de recuperación)', $scw_row && 2 === (int) $scw_row['attempts'], 'attempts=' . ( $scw_row ? $scw_row['attempts'] : 'n/a' ) );

$scw_lease_event_found = false;
foreach ( SCW_Logger::recent( 5 ) as $scw_event ) {
	if ( SCW_Logger::CODE_LEASE_EXPIRED === $scw_event['code'] ) {
		$scw_lease_event_found = true;
		break;
	}
}
$scw_check( 'Se ha registrado un evento LEASE_EXPIRED en scw_events', $scw_lease_event_found );

// --- 7. URL excluida -> skipped, sin fila en scw_runs y sin petición HTTP -----------
echo "\n7. URL excluida por configuración -> skipped, sin petición HTTP\n";

$scw_settings    = SCW_Settings::all();
$scw_excluded_url = 'https://' . $scw_settings['allowed_host'] . '/carrito/';
$scw_ins          = SCW_Queue::insert( $scw_excluded_url, array( 'source' => $scw_source ) );

$scw_mock   = $scw_install_mock( $scw_response( 200 ) );
$scw_result = SCW_Worker::run_once();
$scw_calls  = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( "queue_status = 'skipped'", SCW_Queue::STATUS_SKIPPED === $scw_result['queue_status'], 'queue_status=' . $scw_result['queue_status'] );
$scw_check( 'No se ha hecho ninguna petición HTTP', 0 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check( 'No se ha creado ninguna fila en scw_runs', null === SCW_Runs::find_latest_by_queue_id( $scw_ins['id'] ) );

// --- 8. Scheduler real: lock global evita ejecución simultánea ---------------------
echo "\n8. Scheduler real: lock global evita ejecución simultánea\n";

SCW_State::set(
	array(
		'run_status'    => SCW_State::STATUS_RUNNING,
		'status_reason' => 'f3-worker-test',
	)
);

$scw_ins = $scw_enqueue( 'lock-busy' );

set_transient( SCW_Scheduler::LOCK_TRANSIENT, time(), 90 );

$scw_mock  = $scw_install_mock( $scw_response( 200 ) );
$scw_calls_before = $scw_mock['calls']();
SCW_Plugin::instance()->scheduler()->handle_tick();
$scw_calls = $scw_mock['calls']();
$scw_mock['remove']();

delete_transient( SCW_Scheduler::LOCK_TRANSIENT );

$scw_row = SCW_Queue::get( $scw_ins['id'] );

$scw_check( 'Con el lock ya tomado, no se hace ninguna petición HTTP', 0 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check( 'La URL sigue pending: el tick no ha procesado nada', $scw_row && SCW_Queue::STATUS_PENDING === $scw_row['status'] );

// Se cierra manualmente esta fila para no dejarla pendiente: si siguiera
// pending, sería la más antigua de la cola y la sección 9 la reclamaría a
// ella en lugar de a las URLs nuevas que crea esa sección.
$scw_claimed = SCW_Queue::claim();
if ( $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_ins['id'] ) {
	SCW_Queue::complete( $scw_claimed['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claimed['lock_token'], 'last_result' => 'test_cleanup' ) );
}

// --- 9. Scheduler real: procesa 1 URL y programa el siguiente tick -----------------
echo "\n9. Scheduler real: procesa 1 URL y encadena el siguiente tick\n";

wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );
$scw_check( 'Precondición: no hay tick programado', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

$scw_ins_a = $scw_enqueue( 'chain-a' );
$scw_ins_b = $scw_enqueue( 'chain-b' );

$scw_mock   = $scw_install_mock( $scw_response( 200 ) );
SCW_Plugin::instance()->scheduler()->handle_tick();
$scw_calls = $scw_mock['calls']();
$scw_mock['remove']();

$scw_row_a = SCW_Queue::get( $scw_ins_a['id'] );
$scw_row_b = SCW_Queue::get( $scw_ins_b['id'] );

$scw_terminal_a = $scw_row_a && SCW_Queue::STATUS_PENDING !== $scw_row_a['status'];
$scw_terminal_b = $scw_row_b && SCW_Queue::STATUS_PENDING !== $scw_row_b['status'];

$scw_check( 'El tick ha hecho exactamente 1 petición HTTP', 1 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check(
	'Exactamente una de las dos URLs se ha procesado en este tick',
	( $scw_terminal_a xor $scw_terminal_b ),
	'a=' . $scw_row_a['status'] . ' b=' . $scw_row_b['status']
);
$scw_check( 'El scheduler ha programado el siguiente scw_tick', false !== wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

// Limpieza de la URL que quedó pendiente y del tick programado.
wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );
$scw_leftover = $scw_terminal_a ? $scw_row_b : $scw_row_a;
$scw_claimed  = SCW_Queue::claim();
if ( $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_leftover['id'] ) {
	SCW_Queue::complete( $scw_claimed['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claimed['lock_token'], 'last_result' => 'test_cleanup' ) );
}

// --- 10. Limpieza y restauración de estado ------------------------------------------
echo "\n10. Limpieza\n";

delete_transient( SCW_Scheduler::LOCK_TRANSIENT );
wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

$scw_deleted_queue = SCW_Queue::delete_by_source( $scw_source );
$scw_deleted_runs  = SCW_Runs::delete_by_session( $scw_source );

SCW_State::set( $scw_original_state );

$scw_final_stats = SCW_Queue::stats();

$scw_check( 'Filas de cola de prueba eliminadas', $scw_deleted_queue > 0, $scw_deleted_queue . ' filas' );
$scw_check( 'Filas de runs de prueba eliminadas', $scw_deleted_runs > 0, $scw_deleted_runs . ' filas' );
$scw_check(
	'La cola global vuelve a su recuento inicial',
	(int) $scw_final_stats['total'] === (int) $scw_initial_stats['total'],
	'total=' . $scw_final_stats['total']
);
$scw_check( 'run_status restaurado al original', $scw_original_state['run_status'] === SCW_State::get( 'run_status' ) );
$scw_check( 'No queda ningún scw_tick programado', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'No queda el lock global del tick', false === get_transient( SCW_Scheduler::LOCK_TRANSIENT ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
