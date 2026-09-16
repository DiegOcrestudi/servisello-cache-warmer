<?php
/**
 * TEST · Máquina de decisión de contenido (F4.4).
 *
 * No hace ninguna petición de red, no escribe en ninguna tabla y no modifica
 * ningún ajuste: SCW_Content_Validator es una función pura sobre el resultado
 * del cliente HTTP y la URL.
 *
 * Recorre uno a uno los 19 casos de la especificación de F4.4, más la
 * precedencia, el orden de severidad y el formato de salida.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-4-content-validator-test.php
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

$scw_pass = 0;
$scw_fail = 0;

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

$scw_normalizer_config = SCW_URL_Normalizer::config();
$scw_host              = $scw_normalizer_config['allowed_host'];

if ( '' === $scw_host ) {
	exit( "No hay allowed_host configurado: este test necesita uno para construir URLs.\n" );
}

/**
 * URL absoluta del host permitido.
 *
 * @param string $path Path.
 * @return string
 */
$scw_url = function ( $path ) use ( $scw_host ) {
	return 'https://' . $scw_host . $path;
};

/**
 * Documento HTML válido y suficientemente grande.
 *
 * @param string $fragment Fragmento a insertar en el body.
 * @param int    $bytes    Tamaño objetivo aproximado.
 * @return string
 */
$scw_doc = function ( $fragment = '', $bytes = 30000 ) {
	$head = '<!DOCTYPE html><html lang="es"><head><title>Servisello</title></head><body>';
	$tail = '</body></html>';

	$padding = (int) $bytes - strlen( $head ) - strlen( $tail ) - strlen( $fragment );

	if ( $padding < 0 ) {
		$padding = 0;
	}

	return $head . $fragment . str_repeat( 'x', $padding ) . $tail;
};

/**
 * Resultado simulado de SCW_HTTP_Client::fetch().
 *
 * @param array $overrides Claves a sobrescribir.
 * @return array
 */
$scw_http = function ( $overrides = array() ) {
	return array_merge(
		array(
			'ok'                        => true,
			'http_status'               => 200,
			'duration_ms'               => 120,
			'bytes'                     => 0,
			'body'                      => '',
			'content_type'              => 'text/html; charset=UTF-8',
			'x_litespeed_cache'         => 'hit',
			'x_litespeed_cache_control' => 'public,max-age=604800',
			'vary'                      => null,
			'redirect_location'         => null,
			'user_agent'                => 'Mozilla/5.0',
			'error_type'                => null,
			'error_message'             => null,
			'diagnostics'               => array(),
		),
		is_array( $overrides ) ? $overrides : array()
	);
};

/** Contenedor YITH real de producción. */
$scw_yith_ok = '<div class="yith-wcan-filters no-title" id="preset_6432" data-preset-id="6432" data-target=""></div>';

/** Perfiles de prueba, independientes de la configuración del sitio. */
$scw_profiles = array(
	'profile' => array(
		'profiles_requires_yith' => array( '/con-filtro/' ),
		'profiles_no_yith'       => array( '/sin-filtro/' ),
	),
);

$scw_url_requires    = $scw_url( '/con-filtro/' );
$scw_url_no_yith     = $scw_url( '/sin-filtro/' );
$scw_url_unverified  = $scw_url( '/cualquier-otra/' );

echo "\n=== F4.4 · SCW_Content_Validator ===\n";

// --- 1. Los 19 casos de la especificación -------------------------------------------
echo "\n1. Los 19 casos de la especificación\n";

// Caso 1.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 1 · REQUIRES + 200 + HTML ok + YITH válido -> ok/success', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && SCW_Queue::STATUS_SUCCESS === $scw_v['queue_status'], 'result=' . $scw_v['result'] . ' reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 1 · yith_presets = "6432"', '6432' === $scw_v['yith_presets'], 'yith_presets=' . var_export( $scw_v['yith_presets'], true ) );
$scw_check( 'Caso 1 · last_result = http_200', 'http_200' === $scw_v['last_result'] );

// Caso 2.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul class="products"></ul>' ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 2 · REQUIRES + YITH ausente -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] && SCW_Queue::STATUS_SUSPICIOUS === $scw_v['queue_status'] );
$scw_check( 'Caso 2 · reason = yith_missing', SCW_Content_Validator::REASON_YITH_MISSING === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 2 · last_result = suspicious_yith_missing', 'suspicious_yith_missing' === $scw_v['last_result'] );

// Caso 3.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<div class="yith-wcan-filters"></div>' ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 3 · REQUIRES + marcador sin preset -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 3 · reason = yith_no_preset', SCW_Content_Validator::REASON_YITH_NO_PRESET === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 3 · el diagnóstico distingue detected sin valid', true === $scw_v['diagnostics']['yith']['detected'] && false === $scw_v['diagnostics']['yith']['valid'] );

// Caso 4.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>' ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 4 · REQUIRES + preset desconocido -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 4 · reason = yith_unknown_preset', SCW_Content_Validator::REASON_YITH_UNKNOWN === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 4 · yith_presets es NULL (los inválidos no van a la columna)', null === $scw_v['yith_presets'] );
$scw_check( 'Caso 4 · el preset desconocido queda en diagnostics', array( 9999 ) === $scw_v['diagnostics']['yith']['invalid_presets'] );

// Caso 5.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6683"></div>' ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 5 · REQUIRES + preset válido pero inconsistente -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] && SCW_Queue::STATUS_SUSPICIOUS === $scw_v['queue_status'], 'result=' . $scw_v['result'] );
$scw_check( 'Caso 5 · reason = yith_inconsistent', SCW_Content_Validator::REASON_YITH_INCONSISTENT === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 5 · last_result = suspicious_yith_inconsistent', 'suspicious_yith_inconsistent' === $scw_v['last_result'] );
$scw_check( 'Caso 5 · se conservan AMBOS presets', '6432,6683' === $scw_v['yith_presets'], 'yith_presets=' . var_export( $scw_v['yith_presets'], true ) );
$scw_check( 'Caso 5 · consistent = false en diagnostics', false === $scw_v['diagnostics']['yith']['consistent'] );
$scw_check( 'Caso 5 · el aviso preset_id_mismatch queda registrado', in_array( SCW_YITH_Validator::WARN_PRESET_MISMATCH, $scw_v['diagnostics']['yith']['warnings'], true ) );
$scw_check( 'Caso 5 · se conserva el fragmento del contenedor', isset( $scw_v['diagnostics']['yith']['snippet'] ) && '' !== $scw_v['diagnostics']['yith']['snippet'] );

// Caso 6.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul class="products"></ul>' ) ) ), $scw_url_no_yith, $scw_profiles );
$scw_check( 'Caso 6 · NO_YITH + sin YITH -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && SCW_Queue::STATUS_SUCCESS === $scw_v['queue_status'] );
$scw_check( 'Caso 6 · sin señales', array() === $scw_v['signals'], implode( ',', $scw_v['signals'] ) );

// Caso 7.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_no_yith, $scw_profiles );
$scw_check( 'Caso 7 · NO_YITH + con YITH -> ok (no dudosa)', SCW_Content_Validator::RESULT_OK === $scw_v['result'], 'result=' . $scw_v['result'] );
$scw_check( 'Caso 7 · señal unexpected_yith', in_array( SCW_Content_Validator::SIGNAL_UNEXPECTED_YITH, $scw_v['signals'], true ), implode( ',', $scw_v['signals'] ) );
$scw_check( 'Caso 7 · la señal queda en diagnostics', in_array( SCW_Content_Validator::SIGNAL_UNEXPECTED_YITH, $scw_v['diagnostics']['validation']['signals'], true ) );

// Caso 8.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_unverified, $scw_profiles );
$scw_check( 'Caso 8a · UNVERIFIED + con YITH -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] );
$scw_check( 'Caso 8a · el perfil queda registrado como UNVERIFIED', SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_v['diagnostics']['profile']['profile'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul class="products"></ul>' ) ) ), $scw_url_unverified, $scw_profiles );
$scw_check( 'Caso 8b · UNVERIFIED + sin YITH -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] );
$scw_check( 'Caso 8b · sin señal unexpected_yith', ! in_array( SCW_Content_Validator::SIGNAL_UNEXPECTED_YITH, $scw_v['signals'], true ) );

// Caso 9.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 9 · 200 + HTML vacío -> suspicious, NO error', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'], 'result=' . $scw_v['result'] );
$scw_check( 'Caso 9 · reason = empty_body', SCW_Content_Validator::REASON_EMPTY_BODY === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 9 · last_result = suspicious_empty_body', 'suspicious_empty_body' === $scw_v['last_result'] );

// Caso 10.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<html>ok</html>' ) ), $scw_url_unverified, $scw_profiles );
$scw_check( 'Caso 10 · 200 + HTML minúsculo -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 10 · reason = body_too_small', SCW_Content_Validator::REASON_BODY_TOO_SMALL === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

// Caso 11.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok, 5000 ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 11 · entre el suelo y min_html_bytes -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'], 'result=' . $scw_v['result'] . ' reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 11 · señal body_below_min_html_bytes', in_array( SCW_HTML_Validator::SIGNAL_BELOW_MIN_BYTES, $scw_v['signals'], true ), implode( ',', $scw_v['signals'] ) );

// Caso 12.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ), 'content_type' => 'application/json' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 12 · Content-Type incorrecto -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 12 · reason = content_type_not_html', SCW_Content_Validator::REASON_CONTENT_TYPE === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 12 · last_result = suspicious_content_type', 'suspicious_content_type' === $scw_v['last_result'] );

// Caso 13.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ), 'content_type' => null ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 13 · Content-Type ausente -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'], 'result=' . $scw_v['result'] );
$scw_check( 'Caso 13 · señal content_type_missing', in_array( SCW_HTML_Validator::SIGNAL_CONTENT_TYPE_MISSING, $scw_v['signals'], true ) );

// Caso 14.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ), 'diagnostics' => array( 'possibly_truncated' => true ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 14 · truncamiento confirmado -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 14 · reason = truncated', SCW_Content_Validator::REASON_TRUNCATED === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ), 'diagnostics' => array( 'content_length_header' => 1234 ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 14b · la discrepancia de Content-Length NO es truncamiento', SCW_Content_Validator::RESULT_OK === $scw_v['result'], 'result=' . $scw_v['result'] . ' reason=' . var_export( $scw_v['reason'], true ) );

// Caso 15.
$scw_no_close = '<!DOCTYPE html><html lang="es"><head><title>x</title></head><body>' . $scw_yith_ok . str_repeat( 'x', 30000 );
$scw_v        = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_no_close ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 15 · falta </html> -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 15 · reason = missing_closing_html', SCW_Content_Validator::REASON_NO_CLOSING === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 15 · last_result = suspicious_no_closing_html', 'suspicious_no_closing_html' === $scw_v['last_result'] );

// Caso 16.
$scw_no_body = '<!DOCTYPE html><html lang="es"><head><title>x</title></head><body>' . $scw_yith_ok . str_repeat( 'x', 30000 ) . '</html>';
$scw_v       = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_no_body ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 16 · falta </body> con </html> -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'], 'result=' . $scw_v['result'] . ' reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'Caso 16 · señal missing_closing_body', in_array( SCW_HTML_Validator::SIGNAL_MISSING_CLOSING_BODY, $scw_v['signals'], true ) );

// Caso 17.
$scw_http_errors = array(
	'301'          => array( 'ok' => true, 'http_status' => 301, 'last' => 'http_301' ),
	'302'          => array( 'ok' => true, 'http_status' => 302, 'last' => 'http_302' ),
	'404'          => array( 'ok' => true, 'http_status' => 404, 'last' => 'http_404' ),
	'410'          => array( 'ok' => true, 'http_status' => 410, 'last' => 'http_410' ),
	'429'          => array( 'ok' => true, 'http_status' => 429, 'last' => 'http_429' ),
	'500'          => array( 'ok' => true, 'http_status' => 500, 'last' => 'http_500' ),
	'503'          => array( 'ok' => true, 'http_status' => 503, 'last' => 'http_503' ),
	'timeout'      => array( 'ok' => false, 'http_status' => 0, 'error_type' => 'timeout', 'last' => 'timeout' ),
	'transporte'   => array( 'ok' => false, 'http_status' => 0, 'error_type' => 'transport_error', 'last' => 'transport_error' ),
	'host'         => array( 'ok' => false, 'http_status' => 0, 'error_type' => 'host_not_allowed', 'last' => 'host_not_allowed' ),
	'sin tipo'     => array( 'ok' => false, 'http_status' => 0, 'error_type' => null, 'last' => 'transport_error' ),
);

foreach ( $scw_http_errors as $scw_label => $scw_case ) {
	$scw_expected_last = $scw_case['last'];
	unset( $scw_case['last'] );

	// Se envía un body correcto a propósito: aun así NO debe validarse.
	$scw_case['body'] = $scw_doc( $scw_yith_ok );

	$scw_v = SCW_Content_Validator::validate( $scw_http( $scw_case ), $scw_url_requires, $scw_profiles );

	$scw_check(
		"Caso 17 · {$scw_label} -> error/failed, sin validar contenido",
		SCW_Content_Validator::RESULT_ERROR === $scw_v['result']
			&& SCW_Queue::STATUS_FAILED === $scw_v['queue_status']
			&& null === $scw_v['yith_presets']
			&& array() === $scw_v['diagnostics']
			&& $scw_expected_last === $scw_v['last_result'],
		'result=' . $scw_v['result'] . ' last_result=' . $scw_v['last_result']
	);
}

// Caso 18: la omisión se decide antes de la petición, en SCW_Worker. Aquí sólo
// se documenta que la fachada nunca produce ese veredicto.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 18 · la fachada nunca devuelve "skipped"', in_array( $scw_v['result'], array( SCW_Content_Validator::RESULT_OK, SCW_Content_Validator::RESULT_SUSPICIOUS, SCW_Content_Validator::RESULT_ERROR ), true ) );

// Una URL no resoluble no puede penalizar: el perfil cae a UNVERIFIED.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul></ul>' ) ) ), 'https://otro-dominio.example/con-filtro/', $scw_profiles );
$scw_check( 'Caso 18b · URL de otro host -> perfil UNVERIFIED, veredicto ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_v['diagnostics']['profile']['profile'] );

// Caso 19.
$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<p>roto</p>', 'content_type' => 'text/plain' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Caso 19 · varias anomalías -> suspicious', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );
$scw_check( 'Caso 19 · se registran TODAS las anomalías', count( $scw_v['anomalies'] ) >= 3, 'anomalías=' . implode( ',', $scw_v['anomalies'] ) );
$scw_check( 'Caso 19 · el motivo elegido es el más grave presente', SCW_Content_Validator::REASON_BODY_TOO_SMALL === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

// --- 2. Precedencia -----------------------------------------------------------------
echo "\n2. Precedencia entre veredictos\n";

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'ok' => true, 'http_status' => 500, 'body' => '' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'ERROR gana a DUDOSA aunque el body esté vacío', SCW_Content_Validator::RESULT_ERROR === $scw_v['result'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok, 5000 ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Una señal nunca convierte un ok en dudoso', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && ! empty( $scw_v['signals'] ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<html>x</html>' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Una anomalía fuerte gana al motivo YITH', SCW_Content_Validator::REASON_BODY_TOO_SMALL === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul></ul>' ), 'x_litespeed_cache' => 'hit' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Un HIT de LiteSpeed no certifica nada: sigue siendo dudosa', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ), 'x_litespeed_cache' => 'miss' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Un MISS de LiteSpeed tampoco penaliza: sigue siendo ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] );

// --- 3. Orden de severidad ----------------------------------------------------------
echo "\n3. Orden de severidad con motivos concurrentes\n";

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<p>x</p>', 'content_type' => 'text/plain', 'diagnostics' => array( 'possibly_truncated' => true ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'truncated manda sobre todo lo demás', SCW_Content_Validator::REASON_TRUNCATED === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '', 'content_type' => 'text/plain' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'empty_body manda sobre content_type', SCW_Content_Validator::REASON_EMPTY_BODY === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<p>x</p>', 'content_type' => 'text/plain' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'body_too_small manda sobre content_type', SCW_Content_Validator::REASON_BODY_TOO_SMALL === $scw_v['reason'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<ul></ul>' ), 'content_type' => 'text/plain' ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'content_type manda sobre el motivo YITH', SCW_Content_Validator::REASON_CONTENT_TYPE === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );
$scw_check( 'pero el motivo YITH sigue estando implícito en la evidencia', false === $scw_v['diagnostics']['yith']['detected'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => '<!DOCTYPE html><html><body><ul></ul>' . str_repeat( 'x', 30000 ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'missing_closing_html manda sobre el motivo YITH', SCW_Content_Validator::REASON_NO_CLOSING === $scw_v['reason'], 'reason=' . var_export( $scw_v['reason'], true ) );

// --- 4. Matriz perfil × YITH --------------------------------------------------------
echo "\n4. Matriz perfil x evidencia YITH\n";

$scw_bodies = array(
	'YITH válido'     => $scw_yith_ok,
	'YITH ausente'    => '<ul class="products"></ul>',
	'YITH inválido'   => '<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>',
);

$scw_matrix = array(
	'REQUIRES' => array( 'url' => $scw_url_requires, 'esperado' => array( 'YITH válido' => SCW_Content_Validator::RESULT_OK, 'YITH ausente' => SCW_Content_Validator::RESULT_SUSPICIOUS, 'YITH inválido' => SCW_Content_Validator::RESULT_SUSPICIOUS ) ),
	'NO_YITH'  => array( 'url' => $scw_url_no_yith, 'esperado' => array( 'YITH válido' => SCW_Content_Validator::RESULT_OK, 'YITH ausente' => SCW_Content_Validator::RESULT_OK, 'YITH inválido' => SCW_Content_Validator::RESULT_OK ) ),
	'UNVERIF'  => array( 'url' => $scw_url_unverified, 'esperado' => array( 'YITH válido' => SCW_Content_Validator::RESULT_OK, 'YITH ausente' => SCW_Content_Validator::RESULT_OK, 'YITH inválido' => SCW_Content_Validator::RESULT_OK ) ),
);

foreach ( $scw_matrix as $scw_profile_label => $scw_row ) {
	foreach ( $scw_bodies as $scw_body_label => $scw_fragment ) {
		$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_fragment ) ) ), $scw_row['url'], $scw_profiles );

		$scw_check(
			"{$scw_profile_label} + {$scw_body_label} -> " . $scw_row['esperado'][ $scw_body_label ],
			$scw_row['esperado'][ $scw_body_label ] === $scw_v['result'],
			'result=' . $scw_v['result'] . ' reason=' . var_export( $scw_v['reason'], true )
		);
	}
}

// --- 5. Los tres presets conocidos --------------------------------------------------
echo "\n5. Los tres presets conocidos sobre un perfil REQUIRES_YITH\n";

foreach ( array( 6432, 6683, 13269 ) as $scw_preset ) {
	$scw_fragment = '<div class="yith-wcan-filters no-title" id="preset_' . $scw_preset . '" data-preset-id="' . $scw_preset . '"></div>';
	$scw_v        = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_fragment ) ) ), $scw_url_requires, $scw_profiles );

	$scw_check( 'preset_' . $scw_preset . ' -> ok', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && (string) $scw_preset === $scw_v['yith_presets'], 'result=' . $scw_v['result'] . ' presets=' . var_export( $scw_v['yith_presets'], true ) );
}

$scw_fragment = '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div><div class="yith-wcan-filters" id="preset_6683" data-preset-id="6683"></div>';
$scw_v        = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_fragment ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Varios presets válidos -> ok y ambos en la columna', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && '6432,6683' === $scw_v['yith_presets'], 'presets=' . var_export( $scw_v['yith_presets'], true ) );

$scw_fragment = '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div><div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>';
$scw_v        = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_fragment ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Válido + desconocido -> ok con señal', SCW_Content_Validator::RESULT_OK === $scw_v['result'] && in_array( SCW_Content_Validator::SIGNAL_YITH_UNKNOWN_PRESETS, $scw_v['signals'], true ), 'result=' . $scw_v['result'] . ' señales=' . implode( ',', $scw_v['signals'] ) );

// --- 6. Formato de salida -----------------------------------------------------------
echo "\n6. Formato de salida\n";

$scw_expected_keys = array( 'result', 'reason', 'last_result', 'queue_status', 'yith_presets', 'anomalies', 'signals', 'diagnostics' );
$scw_missing       = array();

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_requires, $scw_profiles );

foreach ( $scw_expected_keys as $scw_key ) {
	if ( ! array_key_exists( $scw_key, $scw_v ) ) {
		$scw_missing[] = $scw_key;
	}
}

$scw_check( 'El veredicto contiene todas las claves esperadas', empty( $scw_missing ), empty( $scw_missing ) ? '' : 'faltan: ' . implode( ', ', $scw_missing ) );

$scw_too_long = array();

foreach ( SCW_Content_Validator::LAST_RESULT as $scw_reason => $scw_last ) {
	if ( strlen( $scw_last ) > 32 ) {
		$scw_too_long[] = $scw_last;
	}
}

$scw_check( 'Todas las etiquetas last_result caben en varchar(32)', empty( $scw_too_long ), implode( ',', $scw_too_long ) );
$scw_check( 'Hay una etiqueta por cada motivo catalogado', count( SCW_Content_Validator::LAST_RESULT ) === count( SCW_Content_Validator::SEVERITY ) );
$scw_check( 'El veredicto ok cabe en varchar(32)', strlen( $scw_v['last_result'] ) <= 32 );
$scw_check( 'yith_presets cabe en varchar(191)', null === $scw_v['yith_presets'] || strlen( $scw_v['yith_presets'] ) <= 191 );
$scw_check( 'validation_result cabe en varchar(32)', strlen( $scw_v['result'] ) <= 32 );
$scw_check( 'diagnostics es serializable a JSON', is_string( wp_json_encode( $scw_v['diagnostics'] ) ) );
$scw_check( 'queue_status es un estado terminal de la cola', in_array( $scw_v['queue_status'], SCW_Queue::terminal_statuses(), true ) );

// --- 7. Reglas negativas y evidencia ------------------------------------------------
echo "\n7. Qué se guarda y qué no\n";

$scw_body = $scw_doc( $scw_yith_ok );
$scw_v    = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_body ) ), $scw_url_requires, $scw_profiles );
$scw_json = wp_json_encode( $scw_v['diagnostics'] );

$scw_check( 'El body NO aparece en diagnostics', false === strpos( $scw_json, 'xxxxxxxxxx' ) );
$scw_check( 'En un resultado ok no se guarda snippet', ! isset( $scw_v['diagnostics']['yith']['snippet'] ) );
$scw_check( 'Se guarda el número de contenedores, no el array', 1 === $scw_v['diagnostics']['yith']['containers'] );
$scw_check( 'El bloque de perfil identifica el patrón aplicado (en forma canónica)', '/con-filtro' === $scw_v['diagnostics']['profile']['pattern'], 'pattern=' . var_export( $scw_v['diagnostics']['profile']['pattern'], true ) );
$scw_check( 'El bloque de validación guarda bytes y mime', $scw_v['diagnostics']['validation']['bytes'] === strlen( $scw_body ) && 'text/html' === $scw_v['diagnostics']['validation']['mime'] );
$scw_check( 'No se guarda ninguna referencia a Elementor', false === strpos( $scw_json, 'elementor' ) );
$scw_check( 'No se guarda la cabecera de LiteSpeed en el bloque de validación', false === strpos( wp_json_encode( $scw_v['diagnostics']['validation'] ), 'litespeed' ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( '<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>' ) ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'En un resultado dudoso sí se guarda snippet', isset( $scw_v['diagnostics']['yith']['snippet'] ) );
$scw_check( 'El snippet está acotado a 300 caracteres', strlen( $scw_v['diagnostics']['yith']['snippet'] ) <= 300 );

// --- 8. Robustez --------------------------------------------------------------------
echo "\n8. Robustez\n";

$scw_v = SCW_Content_Validator::validate( array(), $scw_url_requires, $scw_profiles );
$scw_check( 'Un $http vacío se trata como error, sin fallo de PHP', SCW_Content_Validator::RESULT_ERROR === $scw_v['result'] );

$scw_v = SCW_Content_Validator::validate( 'no-es-un-array', $scw_url_requires, $scw_profiles );
$scw_check( 'Un $http que no es array se trata como error', SCW_Content_Validator::RESULT_ERROR === $scw_v['result'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => null ) ), $scw_url_requires, $scw_profiles );
$scw_check( 'Un body null se trata como cuerpo vacío', SCW_Content_Validator::REASON_EMPTY_BODY === $scw_v['reason'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), '', $scw_profiles );
$scw_check( 'Una URL vacía no rompe la llamada', is_array( $scw_v ) && SCW_Content_Validator::RESULT_OK === $scw_v['result'] );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) ), $scw_url_requires, 'no-es-un-array' );
$scw_check( 'Una config que no es array no rompe la llamada', is_array( $scw_v ) );

$scw_v = SCW_Content_Validator::validate( $scw_http( array( 'ok' => true, 'http_status' => 204, 'body' => '' ) ), $scw_url_unverified, $scw_profiles );
$scw_check( 'Un 204 sin cuerpo es dudoso, no error', SCW_Content_Validator::RESULT_SUSPICIOUS === $scw_v['result'] && SCW_Content_Validator::REASON_EMPTY_BODY === $scw_v['reason'] );

// --- 9. Pureza y determinismo -------------------------------------------------------
echo "\n9. Pureza y determinismo\n";

$scw_input = $scw_http( array( 'body' => $scw_doc( $scw_yith_ok ) ) );
$scw_copy  = $scw_input;

$scw_first  = SCW_Content_Validator::validate( $scw_input, $scw_url_requires, $scw_profiles );
$scw_second = SCW_Content_Validator::validate( $scw_input, $scw_url_requires, $scw_profiles );

$scw_check( 'Dos llamadas idénticas devuelven el mismo veredicto', $scw_first === $scw_second );
$scw_check( 'validate() no modifica el $http recibido', $scw_copy === $scw_input );

$scw_stored = get_option( SCW_Settings::OPTION, array() );
SCW_Content_Validator::validate( $scw_input, $scw_url_requires, $scw_profiles );
$scw_check( 'validate() no altera los ajustes almacenados', $scw_stored === get_option( SCW_Settings::OPTION, array() ) );

// --- 10. Rendimiento ----------------------------------------------------------------
echo "\n10. Rendimiento sobre un documento grande\n";

$scw_big     = $scw_doc( $scw_yith_ok, 800000 );
$scw_start   = microtime( true );
$scw_v       = SCW_Content_Validator::validate( $scw_http( array( 'body' => $scw_big ) ), $scw_url_requires, $scw_profiles );
$scw_elapsed = microtime( true ) - $scw_start;

$scw_check( 'Documento de 800 KB validado correctamente', SCW_Content_Validator::RESULT_OK === $scw_v['result'] );
$scw_check( 'Se valida en menos de 1 segundo', $scw_elapsed < 1.0, sprintf( '%.1f ms', $scw_elapsed * 1000 ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n";
echo "PASS: {$scw_pass}\n";
echo "FAIL: {$scw_fail}\n\n";

exit( $scw_fail > 0 ? 1 : 0 );
