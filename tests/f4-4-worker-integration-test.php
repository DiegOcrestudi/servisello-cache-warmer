<?php
/**
 * TEST · Integración de la validación en el Worker (F4.4).
 *
 * No hace NINGUNA petición de red real: toda respuesta HTTP se simula mediante
 * 'pre_http_request'. Sí escribe de verdad en scw_queue y scw_runs, usando el
 * mismo camino que producción: SCW_Queue::claim(), SCW_Worker::run_once(),
 * SCW_Content_Validator y SCW_Queue::complete().
 *
 * Todas las filas que crea llevan source/session = 'f4-4-worker-test' y se
 * eliminan al final. El estado de ejecución se guarda antes de empezar y se
 * restaura al terminar, para no dejar el crawler arrancado.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-4-worker-integration-test.php
 *
 * Termina con código de salida 1 si alguna comprobación falla.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Content_Validator' ) ) {
	exit( "SCW_Content_Validator no está cargada. ¿Está activo el plugin?\n" );
}

$scw_pass   = 0;
$scw_fail   = 0;
$scw_source = 'f4-4-worker-test';

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
 * @param array|WP_Error $canned Respuesta a devolver.
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
 * Respuesta con la forma que produce WordPress.
 *
 * @param int    $code    Código HTTP.
 * @param array  $headers Headers en minúsculas.
 * @param string $body    Cuerpo.
 * @return array
 */
$scw_response = function ( $code, array $headers = array(), $body = '' ) {
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
 * Documento HTML válido y suficientemente grande.
 *
 * @param string $fragment Fragmento a insertar.
 * @return string
 */
$scw_doc = function ( $fragment = '' ) {
	return '<!DOCTYPE html><html lang="es"><head><title>Servisello</title></head><body>'
		. $fragment
		. '<ul class="products">' . str_repeat( '<li class="product">Sello</li>', 900 ) . '</ul>'
		. '</body></html>';
};

/** Contenedor YITH real de producción. */
$scw_yith_ok = '<div class="yith-wcan-filters no-title" id="preset_6432" data-preset-id="6432" data-target=""></div>';

$scw_settings = SCW_Settings::all();
$scw_host     = $scw_settings['allowed_host'];

if ( '' === $scw_host ) {
	exit( "No hay allowed_host configurado: este test necesita uno.\n" );
}

/**
 * Encola una URL de prueba.
 *
 * @param string $suffix Sufijo.
 * @return array Resultado de SCW_Queue::insert().
 */
$scw_enqueue = function ( $suffix, $extra = array() ) use ( $scw_source, $scw_host ) {
	$url = 'https://' . $scw_host . '/prueba-f4-4-' . $suffix . '/';

	return SCW_Queue::insert( $url, array_merge( array( 'source' => $scw_source ), $extra ) );
};

/**
 * Ejecuta un ciclo completo con una respuesta simulada.
 *
 * @param string         $suffix Sufijo de la URL.
 * @param array|WP_Error $canned Respuesta simulada.
 * @return array { queue_id, outcome, row, run, calls }
 */
$scw_cycle = function ( $suffix, $canned, $extra = array() ) use ( $scw_enqueue, $scw_install_mock ) {
	$ins = $scw_enqueue( $suffix, $extra );

	$mock    = $scw_install_mock( $canned );
	$outcome = SCW_Worker::run_once();
	$calls   = $mock['calls']();
	$mock['remove']();

	return array(
		'queue_id' => $ins['id'],
		'outcome'  => $outcome,
		'row'      => SCW_Queue::get( $ins['id'] ),
		'run'      => SCW_Runs::find_latest_by_queue_id( $ins['id'] ),
		'calls'    => $calls,
	);
};

echo "\n=== F4.4 · TEST: integración de la validación en el Worker ===\n";

$scw_original_state = SCW_State::all();

SCW_Queue::delete_by_source( $scw_source );
SCW_Runs::delete_by_session( $scw_source );

SCW_State::set( array( 'session_id' => $scw_source ) );

$scw_initial_stats = SCW_Queue::stats();

if ( 0 !== $scw_initial_stats['pending'] ) {
	echo "AVISO: la cola global tiene {$scw_initial_stats['pending']} filas pendientes. Este test reclama de la cola real, así que podría procesar otra URL.\n";
}

// Perfiles de prueba: se añaden temporalmente a los ajustes para poder ejercer
// REQUIRES_YITH y NO_YITH sobre URLs controladas, y se restauran al final.
$scw_original_requires = (array) SCW_Settings::get( 'profiles_requires_yith', array() );
$scw_original_no_yith  = (array) SCW_Settings::get( 'profiles_no_yith', array() );

SCW_Settings::update(
	array(
		'profiles_requires_yith' => array_merge( $scw_original_requires, array( '/prueba-f4-4-requires-ok/', '/prueba-f4-4-requires-missing/', '/prueba-f4-4-requires-inconsistent/' ) ),
		'profiles_no_yith'       => array_merge( $scw_original_no_yith, array( '/prueba-f4-4-no-yith/' ) ),
	)
);

// --- 1. REQUIRES_YITH + 200 + HTML correcto + preset válido -> success --------------
echo "\n1. REQUIRES_YITH con filtro correcto\n";

$scw_r = $scw_cycle( 'requires-ok', $scw_response( 200, array( 'content-type' => 'text/html; charset=UTF-8', 'x-litespeed-cache' => 'hit' ), $scw_doc( $scw_yith_ok ) ) );

$scw_check( 'Se ha hecho exactamente 1 petición HTTP', 1 === $scw_r['calls'], 'llamadas=' . $scw_r['calls'] );
$scw_check( "queue_status = 'success'", SCW_Queue::STATUS_SUCCESS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "result = 'success'", SCW_Worker::RESULT_SUCCESS === $scw_r['outcome']['result'] );
$scw_check( "outcome.validation_result = 'ok'", SCW_Content_Validator::RESULT_OK === $scw_r['outcome']['validation_result'] );
$scw_check( 'La fila de la cola queda en success', $scw_r['row'] && SCW_Queue::STATUS_SUCCESS === $scw_r['row']['status'] );
$scw_check( 'lock_token liberado', $scw_r['row'] && null === $scw_r['row']['lock_token'] );
$scw_check( "scw_runs.validation_result = 'ok'", $scw_r['run'] && SCW_Content_Validator::RESULT_OK === $scw_r['run']['validation_result'], 'validation_result=' . var_export( $scw_r['run'] ? $scw_r['run']['validation_result'] : null, true ) );
$scw_check( "scw_runs.yith_presets = '6432'", $scw_r['run'] && '6432' === $scw_r['run']['yith_presets'], 'yith_presets=' . var_export( $scw_r['run'] ? $scw_r['run']['yith_presets'] : null, true ) );
$scw_check( 'scw_runs.x_litespeed_cache = hit', $scw_r['run'] && 'hit' === $scw_r['run']['x_litespeed_cache'] );

$scw_diag = ( $scw_r['run'] && null !== $scw_r['run']['diagnostics'] ) ? json_decode( $scw_r['run']['diagnostics'], true ) : null;

$scw_check( 'diagnostics es JSON válido', is_array( $scw_diag ) );
$scw_check( 'diagnostics trae el bloque de validación', isset( $scw_diag['validation']['result'] ) && SCW_Content_Validator::RESULT_OK === $scw_diag['validation']['result'] );
$scw_check( 'diagnostics trae el perfil REQUIRES_YITH', isset( $scw_diag['profile']['profile'] ) && SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_diag['profile']['profile'], 'perfil=' . ( isset( $scw_diag['profile']['profile'] ) ? $scw_diag['profile']['profile'] : '?' ) );
$scw_check( 'diagnostics trae la evidencia YITH', isset( $scw_diag['yith']['valid'] ) && true === $scw_diag['yith']['valid'] );

// --- 2. EL CASO FUNDACIONAL: 200 + HIT + REQUIRES_YITH sin filtro -------------------
echo "\n2. 200 + X-LiteSpeed-Cache: hit + REQUIRES_YITH sin filtro -> suspicious\n";

$scw_r = $scw_cycle( 'requires-missing', $scw_response( 200, array( 'content-type' => 'text/html', 'x-litespeed-cache' => 'hit' ), $scw_doc( '' ) ) );

$scw_check( "queue_status = 'suspicious'", SCW_Queue::STATUS_SUSPICIOUS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "result = 'suspicious'", SCW_Worker::RESULT_SUSPICIOUS === $scw_r['outcome']['result'] );
$scw_check( 'La fila de la cola queda en suspicious', $scw_r['row'] && SCW_Queue::STATUS_SUSPICIOUS === $scw_r['row']['status'], 'status=' . ( $scw_r['row'] ? $scw_r['row']['status'] : '?' ) );
$scw_check( "last_result = 'suspicious_yith_missing'", $scw_r['row'] && 'suspicious_yith_missing' === $scw_r['row']['last_result'], 'last_result=' . ( $scw_r['row'] ? $scw_r['row']['last_result'] : '?' ) );
$scw_check( 'lock_token liberado también en suspicious', $scw_r['row'] && null === $scw_r['row']['lock_token'] );
$scw_check( 'La fila de cola es terminal', $scw_r['row'] && in_array( $scw_r['row']['status'], SCW_Queue::terminal_statuses(), true ) );
$scw_check( "scw_runs.validation_result = 'suspicious'", $scw_r['run'] && SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_r['run']['validation_result'] );
$scw_check( 'scw_runs.http_status sigue siendo 200', $scw_r['run'] && 200 === (int) $scw_r['run']['http_status'] );
$scw_check( 'scw_runs.yith_presets es NULL', $scw_r['run'] && null === $scw_r['run']['yith_presets'] );
$scw_check( 'El HIT de LiteSpeed queda registrado, pero no ha certificado nada', $scw_r['run'] && 'hit' === $scw_r['run']['x_litespeed_cache'] );

// --- 3. Contenedor inconsistente ----------------------------------------------------
echo "\n3. REQUIRES_YITH con contenedor inconsistente\n";

$scw_r = $scw_cycle( 'requires-inconsistent', $scw_response( 200, array( 'content-type' => 'text/html' ), $scw_doc( '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6683"></div>' ) ) );

$scw_check( "queue_status = 'suspicious'", SCW_Queue::STATUS_SUSPICIOUS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "last_result = 'suspicious_yith_inconsistent'", $scw_r['row'] && 'suspicious_yith_inconsistent' === $scw_r['row']['last_result'], 'last_result=' . ( $scw_r['row'] ? $scw_r['row']['last_result'] : '?' ) );
$scw_check( 'Se conservan ambos presets en la columna', $scw_r['run'] && '6432,6683' === $scw_r['run']['yith_presets'], 'yith_presets=' . var_export( $scw_r['run'] ? $scw_r['run']['yith_presets'] : null, true ) );

$scw_diag = ( $scw_r['run'] && null !== $scw_r['run']['diagnostics'] ) ? json_decode( $scw_r['run']['diagnostics'], true ) : null;

$scw_check( 'diagnostics conserva consistent = false', is_array( $scw_diag ) && isset( $scw_diag['yith']['consistent'] ) && false === $scw_diag['yith']['consistent'] );
$scw_check( 'diagnostics conserva el aviso preset_id_mismatch', is_array( $scw_diag ) && isset( $scw_diag['yith']['warnings'] ) && in_array( SCW_YITH_Validator::WARN_PRESET_MISMATCH, $scw_diag['yith']['warnings'], true ) );

// --- 4. NO_YITH y UNVERIFIED --------------------------------------------------------
echo "\n4. NO_YITH y UNVERIFIED\n";

$scw_r = $scw_cycle( 'no-yith', $scw_response( 200, array( 'content-type' => 'text/html' ), $scw_doc( '' ) ) );
$scw_check( 'NO_YITH sin filtro -> success', SCW_Queue::STATUS_SUCCESS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "NO_YITH sin filtro -> validation_result = 'ok'", $scw_r['run'] && SCW_Content_Validator::RESULT_OK === $scw_r['run']['validation_result'] );

$scw_r = $scw_cycle( 'unverified', $scw_response( 200, array( 'content-type' => 'text/html' ), $scw_doc( $scw_yith_ok ) ) );
$scw_check( 'UNVERIFIED con filtro -> success', SCW_Queue::STATUS_SUCCESS === $scw_r['outcome']['queue_status'] );
$scw_check( 'UNVERIFIED con filtro -> se registran los presets igualmente', $scw_r['run'] && '6432' === $scw_r['run']['yith_presets'] );

// --- 5. Anomalías de integridad -----------------------------------------------------
echo "\n5. Anomalías de integridad del HTML\n";

$scw_r = $scw_cycle( 'tiny', $scw_response( 200, array( 'content-type' => 'text/html' ), '<html>ok</html>' ) );
$scw_check( 'HTML de 15 bytes -> suspicious', SCW_Queue::STATUS_SUSPICIOUS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "HTML de 15 bytes -> last_result = 'suspicious_body_too_small'", $scw_r['row'] && 'suspicious_body_too_small' === $scw_r['row']['last_result'], 'last_result=' . ( $scw_r['row'] ? $scw_r['row']['last_result'] : '?' ) );

$scw_r = $scw_cycle( 'empty', $scw_response( 200, array( 'content-type' => 'text/html' ), '' ) );
$scw_check( '200 con cuerpo vacío -> suspicious, no failed', SCW_Queue::STATUS_SUSPICIOUS === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
$scw_check( "200 con cuerpo vacío -> last_result = 'suspicious_empty_body'", $scw_r['row'] && 'suspicious_empty_body' === $scw_r['row']['last_result'] );

$scw_r = $scw_cycle( 'ctype', $scw_response( 200, array( 'content-type' => 'application/json' ), $scw_doc( $scw_yith_ok ) ) );
$scw_check( 'Content-Type incorrecto -> suspicious', SCW_Queue::STATUS_SUSPICIOUS === $scw_r['outcome']['queue_status'] );
$scw_check( "Content-Type incorrecto -> last_result = 'suspicious_content_type'", $scw_r['row'] && 'suspicious_content_type' === $scw_r['row']['last_result'] );

// --- 6. Errores HTTP ----------------------------------------------------------------
echo "\n6. Errores HTTP: ni se valida el contenido\n";

$scw_http_cases = array(
	'302' => $scw_response( 302, array( 'location' => 'https://otro-host.example/' ), '' ),
	'404' => $scw_response( 404, array( 'content-type' => 'text/html' ), '' ),
	'500' => $scw_response( 500, array( 'content-type' => 'text/html' ), '' ),
);

foreach ( $scw_http_cases as $scw_label => $scw_canned ) {
	// CC-1 (F5.2): un solo intento, para seguir comprobando la clasificación
	// terminal del error y no el número de reintentos. Ver f5-2-retry-test.
	$scw_r = $scw_cycle( 'http-' . $scw_label, $scw_canned, array( 'max_attempts' => 1 ) );

	$scw_check( "{$scw_label} -> queue_status = 'failed'", SCW_Queue::STATUS_FAILED === $scw_r['outcome']['queue_status'], 'queue_status=' . $scw_r['outcome']['queue_status'] );
	$scw_check( "{$scw_label} -> validation_result = 'error'", $scw_r['run'] && SCW_Content_Validator::RESULT_ERROR === $scw_r['run']['validation_result'], 'validation_result=' . var_export( $scw_r['run'] ? $scw_r['run']['validation_result'] : null, true ) );
	$scw_check( "{$scw_label} -> yith_presets NULL", $scw_r['run'] && null === $scw_r['run']['yith_presets'] );

	$scw_diag = ( $scw_r['run'] && null !== $scw_r['run']['diagnostics'] ) ? json_decode( $scw_r['run']['diagnostics'], true ) : array();
	$scw_check( "{$scw_label} -> diagnostics sin bloques de validación", ! isset( $scw_diag['validation'] ) && ! isset( $scw_diag['yith'] ) );
}

$scw_r = $scw_cycle( 'wp-error', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), array( 'max_attempts' => 1 ) );
$scw_check( "WP_Error/timeout -> queue_status = 'failed'", SCW_Queue::STATUS_FAILED === $scw_r['outcome']['queue_status'] );
$scw_check( "WP_Error/timeout -> validation_result = 'error'", $scw_r['run'] && SCW_Content_Validator::RESULT_ERROR === $scw_r['run']['validation_result'] );
$scw_check( 'WP_Error/timeout -> se ha registrado el error_type', $scw_r['run'] && null !== $scw_r['run']['error_type'], 'error_type=' . var_export( $scw_r['run'] ? $scw_r['run']['error_type'] : null, true ) );

// --- 7. Reglas que no deben haber cambiado respecto a F3 ----------------------------
echo "\n7. Invariantes de F3 que deben seguir cumpliéndose\n";

$scw_r = $scw_cycle( 'one-row', $scw_response( 200, array( 'content-type' => 'text/html' ), $scw_doc( $scw_yith_ok ) ) );

global $wpdb;
$scw_runs_table = $wpdb->prefix . 'scw_runs';
$scw_row_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$scw_runs_table} WHERE queue_id = %d", $scw_r['queue_id'] ) ); // phpcs:ignore WordPress.DB

$scw_check( 'Exactamente 1 fila en scw_runs por petición', 1 === $scw_row_count, 'filas=' . $scw_row_count );
$scw_check( 'El body NO se ha persistido en ninguna columna', $scw_r['run'] && false === strpos( wp_json_encode( $scw_r['run'] ), 'class="product"' ) );

// URL excluida: sigue sin generar fila en scw_runs.
$scw_original_exclusions = (array) SCW_Settings::get( 'exclude_prefix', array() );
SCW_Settings::update( array( 'exclude_prefix' => array_merge( $scw_original_exclusions, array( '/prueba-f4-4-excluida' ) ) ) );

$scw_ins    = $scw_enqueue( 'excluida' );
$scw_mock   = $scw_install_mock( $scw_response( 200, array( 'content-type' => 'text/html' ), $scw_doc( $scw_yith_ok ) ) );
$scw_result = SCW_Worker::run_once();
$scw_calls  = $scw_mock['calls']();
$scw_mock['remove']();

SCW_Settings::update( array( 'exclude_prefix' => $scw_original_exclusions ) );

$scw_check( "URL excluida -> result = 'skipped'", SCW_Worker::RESULT_SKIPPED === $scw_result['result'], 'result=' . $scw_result['result'] );
$scw_check( 'URL excluida -> no se ha hecho ninguna petición', 0 === $scw_calls, 'llamadas=' . $scw_calls );
$scw_check( 'URL excluida -> no hay fila en scw_runs', null === SCW_Runs::find_latest_by_queue_id( $scw_ins['id'] ) );
$scw_check( 'URL excluida -> outcome.validation_result es null', null === $scw_result['validation_result'] );

// --- 8. Limpieza --------------------------------------------------------------------
echo "\n8. Limpieza\n";

SCW_Settings::update(
	array(
		'profiles_requires_yith' => $scw_original_requires,
		'profiles_no_yith'       => $scw_original_no_yith,
	)
);

$scw_deleted_queue = SCW_Queue::delete_by_source( $scw_source );
$scw_deleted_runs  = SCW_Runs::delete_by_session( $scw_source );

SCW_State::set( $scw_original_state );

$scw_check( 'Se han borrado las filas de cola del test', $scw_deleted_queue > 0, 'borradas=' . $scw_deleted_queue );
$scw_check( 'Se han borrado las filas de runs del test', $scw_deleted_runs > 0, 'borradas=' . $scw_deleted_runs );
$scw_check( 'Los perfiles de prueba se han restaurado', $scw_original_requires === (array) SCW_Settings::get( 'profiles_requires_yith', array() ) );
$scw_check( 'Las exclusiones se han restaurado', $scw_original_exclusions === (array) SCW_Settings::get( 'exclude_prefix', array() ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n";
echo "PASS: {$scw_pass}\n";
echo "FAIL: {$scw_fail}\n\n";

exit( $scw_fail > 0 ? 1 : 0 );
