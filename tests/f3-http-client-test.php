<?php
/**
 * TEST · Cliente HTTP (F3).
 *
 * No hace NINGUNA petición de red real: toda respuesta se simula mediante el
 * filtro 'pre_http_request', que es el mecanismo que la propia documentación
 * de WordPress recomienda para interceptar peticiones en pruebas. Cada
 * escenario cuenta cuántas veces se ha invocado el filtro, para comprobar que
 * SCW_HTTP_Client nunca hace una segunda petición inesperada (por ejemplo,
 * para seguir un redirect).
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f3-http-client-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_HTTP_Client' ) ) {
	exit( "SCW_HTTP_Client no está cargada. ¿Está activo el plugin?\n" );
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

/**
 * Instala un mock de pre_http_request que cuenta sus invocaciones y devuelve
 * siempre la misma respuesta (o WP_Error) preparada.
 *
 * @param array|WP_Error $canned      Respuesta o error a devolver.
 * @param array           $captured   Salida por referencia: último $parsed_args recibido.
 * @return array { remove: callable, calls: callable }
 */
$scw_install_mock = function ( $canned, &$captured ) {
	$calls = 0;

	$callback = function ( $preempt, $parsed_args, $url ) use ( $canned, &$calls, &$captured ) {
		$calls++;
		$captured = $parsed_args;
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
 * Construye una respuesta "correcta" de wp_safe_remote_get(), con la misma
 * forma que produce WordPress cuando la petición se completa de verdad.
 *
 * @param int   $code    Código HTTP.
 * @param array $headers Headers en minúsculas.
 * @param string $body   Cuerpo.
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

echo "\n=== F3 · TEST: cliente HTTP ===\n";

$scw_settings = SCW_Settings::all();
echo 'Host permitido: ' . $scw_settings['allowed_host'] . "\n";
echo 'User-Agent configurado: ' . $scw_settings['user_agent'] . "\n";
echo 'Timeout configurado: ' . $scw_settings['http_timeout'] . " s\n";

$scw_target = 'https://' . $scw_settings['allowed_host'] . '/prueba-f3/';

// --- 1. HTTP 200 -----------------------------------------------------------------
echo "\n1. HTTP 200\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock(
	$scw_response(
		200,
		array(
			'content-type'      => 'text/html; charset=UTF-8',
			'x-litespeed-cache' => 'hit',
			'vary'              => 'Accept-Encoding',
		),
		'<html>contenido de prueba</html>'
	),
	$scw_captured
);

$scw_result = SCW_HTTP_Client::fetch( $scw_target );
$scw_mock['remove']();

$scw_check( 'ok = true', true === $scw_result['ok'] );
$scw_check( 'http_status = 200', 200 === $scw_result['http_status'], 'status=' . $scw_result['http_status'] );
$scw_check( 'bytes coincide con el body', strlen( '<html>contenido de prueba</html>' ) === $scw_result['bytes'], 'bytes=' . $scw_result['bytes'] );
$scw_check( 'content_type capturado', 'text/html; charset=UTF-8' === $scw_result['content_type'] );
$scw_check( 'x_litespeed_cache capturado', 'hit' === $scw_result['x_litespeed_cache'] );
$scw_check( 'vary capturado', 'Accept-Encoding' === $scw_result['vary'] );
$scw_check( 'duration_ms es un entero >= 0', is_int( $scw_result['duration_ms'] ) && $scw_result['duration_ms'] >= 0, 'duration_ms=' . $scw_result['duration_ms'] );
$scw_check( 'error_type es null en un 200', null === $scw_result['error_type'] );
$scw_check( 'Se ha llamado a pre_http_request exactamente 1 vez', 1 === $scw_mock['calls']() );
$scw_check(
	'sslverify = true',
	isset( $scw_captured['sslverify'] ) && true === $scw_captured['sslverify']
);
$scw_check(
	'No se ha pasado ningún jar de cookies',
	! isset( $scw_captured['cookies'] ) || empty( $scw_captured['cookies'] )
);
$scw_check(
	'redirection = 0 (no se siguen redirects automáticamente)',
	isset( $scw_captured['redirection'] ) && 0 === $scw_captured['redirection']
);
$scw_check(
	'User-Agent enviado es el configurado',
	isset( $scw_captured['user-agent'] ) && $scw_settings['user_agent'] === $scw_captured['user-agent']
);
$scw_check(
	'Timeout enviado es el configurado',
	isset( $scw_captured['timeout'] ) && (int) $scw_settings['http_timeout'] === (int) $scw_captured['timeout']
);

// --- 2. HTTP 3xx: no se sigue el redirect, ni siquiera a un host externo ---------
echo "\n2. HTTP 3xx (redirect no seguido, incluso a host externo)\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock(
	$scw_response( 302, array( 'location' => 'https://evil.invalid-external-host.example/' ) ),
	$scw_captured
);

$scw_result = SCW_HTTP_Client::fetch( $scw_target );
$scw_calls  = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( 'ok = true (sí ha habido una respuesta HTTP real)', true === $scw_result['ok'] );
$scw_check( 'http_status = 302', 302 === $scw_result['http_status'], 'status=' . $scw_result['http_status'] );
$scw_check(
	'redirect_location capturado tal cual, sin haberlo solicitado',
	'https://evil.invalid-external-host.example/' === $scw_result['redirect_location']
);
$scw_check(
	'pre_http_request se ha invocado exactamente 1 vez (no se ha seguido el redirect)',
	1 === $scw_calls,
	'llamadas=' . $scw_calls
);

// --- 3. HTTP 4xx -------------------------------------------------------------------
echo "\n3. HTTP 4xx\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock( $scw_response( 404, array( 'content-type' => 'text/html' ), 'no encontrado' ), $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( $scw_target );
$scw_mock['remove']();

$scw_check( 'ok = true', true === $scw_result['ok'] );
$scw_check( 'http_status = 404', 404 === $scw_result['http_status'] );
$scw_check( 'error_type sigue siendo null (un 404 no es un error de transporte)', null === $scw_result['error_type'] );

// --- 4. HTTP 5xx -------------------------------------------------------------------
echo "\n4. HTTP 5xx\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock( $scw_response( 500, array(), 'error interno' ), $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( $scw_target );
$scw_mock['remove']();

$scw_check( 'ok = true', true === $scw_result['ok'] );
$scw_check( 'http_status = 500', 500 === $scw_result['http_status'] );

// --- 5. WP_Error genérico (no timeout) ----------------------------------------------
echo "\n5. WP_Error de transporte (no timeout)\n";

$scw_captured = null;
$scw_error    = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
$scw_mock     = $scw_install_mock( $scw_error, $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( $scw_target );
$scw_mock['remove']();

$scw_check( 'ok = false', false === $scw_result['ok'] );
$scw_check( 'http_status = 0', 0 === $scw_result['http_status'] );
$scw_check( "error_type = 'transport_error'", SCW_HTTP_Client::ERROR_TRANSPORT === $scw_result['error_type'], 'error_type=' . $scw_result['error_type'] );
$scw_check( 'error_message capturado', false !== strpos( (string) $scw_result['error_message'], 'Could not resolve host' ) );

// --- 6. Timeout simulado -------------------------------------------------------------
echo "\n6. Timeout simulado\n";

$scw_captured = null;
$scw_error    = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds' );
$scw_mock     = $scw_install_mock( $scw_error, $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( $scw_target );
$scw_mock['remove']();

$scw_check( 'ok = false', false === $scw_result['ok'] );
$scw_check( "error_type = 'timeout'", SCW_HTTP_Client::ERROR_TIMEOUT === $scw_result['error_type'], 'error_type=' . $scw_result['error_type'] );

// --- 7. Host inválido: nunca llega a intentar la petición ---------------------------
echo "\n7. Host no permitido\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock( $scw_response( 200 ), $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( 'https://otro-dominio.example/pagina/' );
$scw_calls    = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( 'ok = false', false === $scw_result['ok'] );
$scw_check( "error_type = 'host_not_allowed'", SCW_HTTP_Client::ERROR_HOST === $scw_result['error_type'], 'error_type=' . $scw_result['error_type'] );
$scw_check(
	'pre_http_request NO se ha invocado (no se ha intentado ninguna petición real)',
	0 === $scw_calls,
	'llamadas=' . $scw_calls
);

// --- 8. URL inválida -----------------------------------------------------------------
echo "\n8. URL inválida\n";

$scw_captured = null;
$scw_mock     = $scw_install_mock( $scw_response( 200 ), $scw_captured );
$scw_result   = SCW_HTTP_Client::fetch( 'no-es-una-url' );
$scw_calls    = $scw_mock['calls']();
$scw_mock['remove']();

$scw_check( 'ok = false', false === $scw_result['ok'] );
$scw_check( "error_type = 'invalid_url'", SCW_HTTP_Client::ERROR_INVALID_URL === $scw_result['error_type'] );
$scw_check( 'pre_http_request NO se ha invocado', 0 === $scw_calls );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
