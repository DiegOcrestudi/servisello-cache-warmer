<?php
/**
 * Prueba del normalizador de URLs (F2.2).
 *
 * No hace ninguna petición HTTP, no toca la base de datos y no encola nada.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f2-2-url-normalizer-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_URL_Normalizer' ) ) {
	exit( "SCW_URL_Normalizer no está cargada. ¿Está activo el plugin?\n" );
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
 * Comprueba que una URL se normaliza a la esperada.
 *
 * @param string $input    Entrada.
 * @param string $expected Salida esperada.
 * @return void
 */
$scw_expect = function ( $input, $expected ) use ( $scw_check ) {
	$result = SCW_URL_Normalizer::normalize( $input );
	$actual = $result['valid'] ? $result['url'] : ( 'RECHAZADA:' . $result['error'] );

	$scw_check(
		$input,
		$actual === $expected,
		$actual === $expected ? '' : 'esperado ' . $expected . ' / obtenido ' . $actual
	);
};

/**
 * Comprueba que una URL se rechaza con un código concreto.
 *
 * @param string $input    Entrada.
 * @param string $expected Código de error esperado.
 * @return void
 */
$scw_reject = function ( $input, $expected ) use ( $scw_check ) {
	$result = SCW_URL_Normalizer::normalize( $input );
	$actual = $result['valid'] ? 'ACEPTADA:' . $result['url'] : $result['error'];

	$scw_check(
		'' === $input ? '(cadena vacía)' : $input,
		! $result['valid'] && $result['error'] === $expected,
		$actual === $expected ? '' : 'esperado ' . $expected . ' / obtenido ' . $actual
	);
};

$scw_config = SCW_URL_Normalizer::config();

echo "\n=== F2.2 · Prueba del normalizador de URLs ===\n";
echo 'Dominio permitido: ' . $scw_config['allowed_host'] . "\n";
echo 'Esquema forzado:   ' . $scw_config['force_scheme'] . "\n";
echo 'Slash final:       ' . $scw_config['trailing_slash'] . "\n";
echo 'Ignorados:         ' . implode( ', ', $scw_config['ignored_params'] ) . "\n";
echo 'Permitidos:        ' . ( empty( $scw_config['allowed_params'] ) ? '(ninguno)' : implode( ', ', $scw_config['allowed_params'] ) ) . "\n";

// --- 1. Básicos ---------------------------------------------------------------
echo "\n1. Esquema y hostname\n";
$scw_expect( 'https://www.servisello.es/', 'https://www.servisello.es/' );
$scw_expect( 'http://www.servisello.es/', 'https://www.servisello.es/' );
$scw_expect( 'https://WWW.SERVISELLO.ES/', 'https://www.servisello.es/' );
$scw_expect( 'https://www.servisello.es', 'https://www.servisello.es/' );
$scw_expect( 'https://www.servisello.es./', 'https://www.servisello.es/' );
$scw_expect( "  https://www.servisello.es/\n", 'https://www.servisello.es/' );

// --- 2. Slash final ------------------------------------------------------------
echo "\n2. Slash final\n";
$scw_expect( 'https://www.servisello.es/categoria', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria-producto/design-stamp', 'https://www.servisello.es/categoria-producto/design-stamp/' );
$scw_expect( 'https://www.servisello.es/categoria-producto/design-stamp/', 'https://www.servisello.es/categoria-producto/design-stamp/' );
$scw_expect( 'https://www.servisello.es/wp-login.php', 'https://www.servisello.es/wp-login.php' );
$scw_expect( 'https://www.servisello.es/post-sitemap.xml', 'https://www.servisello.es/post-sitemap.xml' );

$scw_slash_a = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria' );
$scw_slash_b = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria/' );
$scw_check( 'Con y sin slash producen el mismo hash', $scw_slash_a['hash'] === $scw_slash_b['hash'] );

// --- 3. Fragmentos ---------------------------------------------------------------
echo "\n3. Fragmentos\n";
$scw_expect( 'https://www.servisello.es/categoria/#productos', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/#filtro', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?foo=1#seccion', 'https://www.servisello.es/categoria/?foo=1' );

// --- 4. Tracking -------------------------------------------------------------------
echo "\n4. Parámetros de tracking\n";
$scw_expect( 'https://www.servisello.es/categoria/?utm_source=google', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?gclid=123', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?utm_source=a&utm_medium=b&utm_campaign=c&utm_term=d&utm_content=e', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?fbclid=xyz', 'https://www.servisello.es/categoria/' );

// --- 5. YITH y filter_* ---------------------------------------------------------------
echo "\n5. YITH y filter_*\n";
$scw_expect( 'https://www.servisello.es/categoria/?yith_wcan=1', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?filter_marca=colop', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?filter_forma=redondo', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?filter_color=rojo&filter_marca=colop', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria-producto/design-stamp/?yith_wcan=1&filter_marca=colop', 'https://www.servisello.es/categoria-producto/design-stamp/' );
$scw_expect( 'https://www.servisello.es/categoria/?yith_wcan=1&filter_marca=colop&utm_source=google', 'https://www.servisello.es/categoria/' );

// El patrón es de prefijo exacto: "myfilter_x" NO es un filtro YITH.
$scw_expect( 'https://www.servisello.es/categoria/?myfilter_marca=colop', 'https://www.servisello.es/categoria/?myfilter_marca=colop' );
$scw_expect( 'https://www.servisello.es/categoria/?nofilter=1', 'https://www.servisello.es/categoria/?nofilter=1' );

// --- 6. No destructividad ---------------------------------------------------------------
echo "\n6. No destructividad con parámetros desconocidos\n";
$scw_expect( 'https://www.servisello.es/categoria/?foo=bar', 'https://www.servisello.es/categoria/?foo=bar' );
$scw_expect( 'https://www.servisello.es/categoria/?parametro_legitimo=valor', 'https://www.servisello.es/categoria/?parametro_legitimo=valor' );
$scw_expect( 'https://www.servisello.es/?s=sello', 'https://www.servisello.es/?s=sello' );
$scw_expect( 'https://www.servisello.es/categoria/?page=2', 'https://www.servisello.es/categoria/?page=2' );
$scw_expect( 'https://www.servisello.es/categoria/?vacio=', 'https://www.servisello.es/categoria/?vacio=' );
$scw_expect( 'https://www.servisello.es/categoria/?solo_nombre', 'https://www.servisello.es/categoria/?solo_nombre' );
$scw_expect( 'https://www.servisello.es/categoria/?foo=1&utm_source=x&bar=2', 'https://www.servisello.es/categoria/?bar=2&foo=1' );

// --- 7. Orden de parámetros ---------------------------------------------------------------
echo "\n7. Orden determinista\n";
$scw_expect( 'https://www.servisello.es/categoria/?foo=1&bar=2', 'https://www.servisello.es/categoria/?bar=2&foo=1' );
$scw_expect( 'https://www.servisello.es/categoria/?bar=2&foo=1', 'https://www.servisello.es/categoria/?bar=2&foo=1' );

$scw_order_a = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria/?foo=1&bar=2' );
$scw_order_b = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria/?bar=2&foo=1' );
$scw_check( 'Orden inverso produce el mismo hash', $scw_order_a['hash'] === $scw_order_b['hash'] );

// Los valores repetidos de un mismo parámetro conservan su orden relativo.
$scw_expect( 'https://www.servisello.es/categoria/?b=1&a=primero&a=segundo', 'https://www.servisello.es/categoria/?a=primero&a=segundo&b=1' );
$scw_expect( 'https://www.servisello.es/categoria/?a=segundo&a=primero', 'https://www.servisello.es/categoria/?a=segundo&a=primero' );

$scw_multi_a = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/c/?a=1&a=2' );
$scw_multi_b = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/c/?a=2&a=1' );
$scw_check( 'Dos órdenes distintos del MISMO parámetro no se confunden', $scw_multi_a['hash'] !== $scw_multi_b['hash'] );

// --- 8. Codificación ---------------------------------------------------------------
echo "\n8. Porcentaje-codificado\n";
$scw_expect( 'https://www.servisello.es/categoria/?q=hola%20mundo', 'https://www.servisello.es/categoria/?q=hola%20mundo' );
$scw_expect( 'https://www.servisello.es/categoria/?q=hola+mundo', 'https://www.servisello.es/categoria/?q=hola+mundo' );
$scw_expect( 'https://www.servisello.es/categoria/?q=a%2fb', 'https://www.servisello.es/categoria/?q=a%2Fb' );
$scw_expect( 'https://www.servisello.es/dise%c3%b1os/', 'https://www.servisello.es/dise%C3%B1os/' );
$scw_expect( 'https://www.servisello.es/a%7Eb/', 'https://www.servisello.es/a~b/' );
$scw_expect( 'https://www.servisello.es/categoria/?q=100%25', 'https://www.servisello.es/categoria/?q=100%25' );

$scw_enc_a = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/dise%c3%b1os/' );
$scw_enc_b = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/dise%C3%B1os/' );
$scw_check( 'Hexadecimal en mayúsculas y minúsculas convergen', $scw_enc_a['hash'] === $scw_enc_b['hash'] );

$scw_double = SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria/?q=a%252Fb' );
$scw_check( 'No hay doble decodificación', 'https://www.servisello.es/categoria/?q=a%252Fb' === $scw_double['url'] );

// --- 9. Dominio ---------------------------------------------------------------
echo "\n9. Validación de dominio\n";
$scw_reject( 'https://google.com/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://example.com/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://servisello.es.evil.com/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://evil-servisello.es/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://servisello.es/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://www.servisello.es.evil.com/categoria/', SCW_URL_Normalizer::ERROR_HOST );
$scw_reject( 'https://evil.com/?x=https://www.servisello.es/', SCW_URL_Normalizer::ERROR_HOST );

// --- 10. Userinfo, puertos y esquemas ---------------------------------------------------------------
echo "\n10. Userinfo, puertos y esquemas\n";
$scw_reject( 'https://usuario:password@www.servisello.es/', SCW_URL_Normalizer::ERROR_USERINFO );
$scw_reject( 'https://usuario@www.servisello.es/', SCW_URL_Normalizer::ERROR_USERINFO );
$scw_expect( 'https://www.servisello.es:443/', 'https://www.servisello.es/' );
$scw_expect( 'http://www.servisello.es:80/', 'https://www.servisello.es/' );
$scw_reject( 'https://www.servisello.es:8080/', SCW_URL_Normalizer::ERROR_PORT );
$scw_reject( 'https://www.servisello.es:22/', SCW_URL_Normalizer::ERROR_PORT );
$scw_reject( 'ftp://www.servisello.es/', SCW_URL_Normalizer::ERROR_SCHEME );
$scw_reject( 'javascript:alert(1)', SCW_URL_Normalizer::ERROR_INVALID_URL );

// --- 11. Entradas inválidas ---------------------------------------------------------------
echo "\n11. Entradas inválidas\n";
$scw_reject( 'no-es-una-url', SCW_URL_Normalizer::ERROR_INVALID_URL );
$scw_reject( '/categoria/', SCW_URL_Normalizer::ERROR_INVALID_URL );
$scw_reject( 'www.servisello.es/categoria/', SCW_URL_Normalizer::ERROR_INVALID_URL );
$scw_reject( '', SCW_URL_Normalizer::ERROR_EMPTY_URL );
$scw_reject( '   ', SCW_URL_Normalizer::ERROR_EMPTY_URL );
$scw_reject( 'https://www.servisello.es/' . str_repeat( 'a', 2100 ) . '/', SCW_URL_Normalizer::ERROR_TOO_LONG );

// --- 12. Hash ---------------------------------------------------------------
echo "\n12. Hash\n";
$scw_hash_result = SCW_URL_Normalizer::normalize( 'HTTP://WWW.SERVISELLO.ES/categoria?utm_source=x#top' );
$scw_check( 'La URL se normaliza por completo', 'https://www.servisello.es/categoria/' === $scw_hash_result['url'] );
$scw_check( 'El hash tiene 40 caracteres', 40 === strlen( (string) $scw_hash_result['hash'] ) );
$scw_check( 'El hash es sha1 de la URL normalizada', sha1( 'https://www.servisello.es/categoria/' ) === $scw_hash_result['hash'] );

if ( class_exists( 'SCW_Queue' ) ) {
	$scw_check(
		'El hash coincide con el de SCW_Queue::hash_url()',
		SCW_Queue::hash_url( $scw_hash_result['url'] ) === $scw_hash_result['hash']
	);
}

$scw_check( 'Determinismo: dos llamadas idénticas dan el mismo hash', SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria' )['hash'] === SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria' )['hash'] );

// --- 13. URLs distintas no colisionan ---------------------------------------------------------------
echo "\n13. URLs realmente distintas\n";
$scw_distintas = array(
	'https://www.servisello.es/categoria/',
	'https://www.servisello.es/categoria-producto/',
	'https://www.servisello.es/categoria/sub/',
	'https://www.servisello.es/categoria/?foo=1',
	'https://www.servisello.es/categoria/?foo=2',
	'https://www.servisello.es/',
);

$scw_hashes = array();

foreach ( $scw_distintas as $scw_u ) {
	$scw_r                          = SCW_URL_Normalizer::normalize( $scw_u );
	$scw_hashes[ $scw_r['hash'] ] = $scw_r['url'];
}

$scw_check( 'Seis URLs distintas producen seis hashes distintos', count( $scw_hashes ) === count( $scw_distintas ), count( $scw_hashes ) . ' de ' . count( $scw_distintas ) );

// --- 14. Configuración inyectada ---------------------------------------------------------------
echo "\n14. Políticas configurables\n";
$scw_check(
	'trailing_slash=remove quita el slash',
	'https://www.servisello.es/categoria' === SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria/', array( 'trailing_slash' => 'remove' ) )['url']
);
$scw_check(
	'trailing_slash=keep respeta la entrada',
	'https://www.servisello.es/categoria' === SCW_URL_Normalizer::normalize( 'https://www.servisello.es/categoria', array( 'trailing_slash' => 'keep' ) )['url']
);
$scw_check(
	'allowed_params rescata un parámetro que encaja con un patrón ignorado',
	'https://www.servisello.es/categoria/?filter_marca=colop' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?filter_marca=colop',
		array( 'allowed_params' => array( 'filter_marca' ) )
	)['url']
);
$scw_check(
	'Otro dominio permitido cambia la validación',
	SCW_URL_Normalizer::is_valid( 'https://ejemplo.test/x/', array( 'allowed_host' => 'ejemplo.test' ) )
);
$scw_check(
	'Sin dominio configurado se rechaza todo',
	SCW_URL_Normalizer::ERROR_NO_HOST_SET === SCW_URL_Normalizer::normalize( 'https://www.servisello.es/', array( 'allowed_host' => '' ) )['error']
);

// --- 15. Semántica del signo "+" en los nombres -------------------------------
echo "\n15. Signo \"+\": semántica RFC 3986, no formulario\n";

// "+" es un carácter literal en el nombre y se conserva tal cual.
$scw_expect( 'https://www.servisello.es/categoria/?foo+bar=1', 'https://www.servisello.es/categoria/?foo+bar=1' );

// "%2B" codifica un "+" reservado: se conserva codificado, en mayúsculas.
$scw_expect( 'https://www.servisello.es/categoria/?foo%2Bbar=1', 'https://www.servisello.es/categoria/?foo%2Bbar=1' );
$scw_expect( 'https://www.servisello.es/categoria/?foo%2bbar=1', 'https://www.servisello.es/categoria/?foo%2Bbar=1' );

// El "+" del VALOR tampoco se toca.
$scw_expect( 'https://www.servisello.es/categoria/?q=a+b', 'https://www.servisello.es/categoria/?q=a+b' );

// Un nombre percent-codificado sí debe reconocerse contra la configuración.
$scw_expect( 'https://www.servisello.es/categoria/?%66ilter_marca=colop', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?%75tm_source=google', 'https://www.servisello.es/categoria/' );
$scw_expect( 'https://www.servisello.es/categoria/?yith%5Fwcan=1', 'https://www.servisello.es/categoria/' );

// La prueba decisiva: con urldecode, "foo+bar" se habría comparado como
// "foo bar" y estas dos comprobaciones darían el resultado contrario.
$scw_check(
	'"foo+bar" NO coincide con la entrada "foo bar" de la lista',
	'https://www.servisello.es/categoria/?foo+bar=1' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?foo+bar=1',
		array( 'ignored_params' => array( 'foo bar' ) )
	)['url']
);
$scw_check(
	'"foo+bar" SÍ coincide con la entrada "foo+bar" de la lista',
	'https://www.servisello.es/categoria/' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?foo+bar=1',
		array( 'ignored_params' => array( 'foo+bar' ) )
	)['url']
);

// --- 16. ignored_params por configuración inyectada ---------------------------
echo "\n16. ignored_params inyectados\n";
$scw_check(
	'Nombre exacto: ?foo=1&bar=2 pasa a ?bar=2',
	'https://www.servisello.es/categoria/?bar=2' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?foo=1&bar=2',
		array( 'ignored_params' => array( 'foo' ) )
	)['url']
);
$scw_check(
	'Patrón de prefijo: ?test_a=1&test_b=2&other=3 pasa a ?other=3',
	'https://www.servisello.es/categoria/?other=3' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?test_a=1&test_b=2&other=3',
		array( 'ignored_params' => array( 'test_*' ) )
	)['url']
);
$scw_check(
	'El patrón es de prefijo exacto: mytest_a no coincide con test_*',
	'https://www.servisello.es/categoria/?mytest_a=1' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?mytest_a=1',
		array( 'ignored_params' => array( 'test_*' ) )
	)['url']
);
$scw_check(
	'Una lista de ignorados vacía no elimina nada',
	'https://www.servisello.es/categoria/?utm_source=google' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?utm_source=google',
		array( 'ignored_params' => array() )
	)['url']
);
$scw_check(
	'allowed_params gana sobre ignored_params',
	'https://www.servisello.es/categoria/?foo=1' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?foo=1',
		array(
			'ignored_params' => array( 'foo' ),
			'allowed_params' => array( 'foo' ),
		)
	)['url']
);
$scw_check(
	'allowed_params rescata también frente a un patrón',
	'https://www.servisello.es/categoria/?test_a=1' === SCW_URL_Normalizer::normalize(
		'https://www.servisello.es/categoria/?test_a=1',
		array(
			'ignored_params' => array( 'test_*' ),
			'allowed_params' => array( 'test_a' ),
		)
	)['url']
);

// --- 17. Entradas que no son cadenas ------------------------------------------
echo "\n17. Entradas no-string\n";
$scw_php_errors = array();

set_error_handler(
	function ( $errno, $errstr ) use ( &$scw_php_errors ) {
		$scw_php_errors[] = $errstr;
		return true;
	}
);

$scw_non_strings = array(
	'null'    => null,
	'false'   => false,
	'true'    => true,
	'123'     => 123,
	'12.5'    => 12.5,
	'array()' => array(),
);

$scw_non_string_results = array();

foreach ( $scw_non_strings as $scw_label => $scw_value ) {
	$scw_non_string_results[ $scw_label ] = SCW_URL_Normalizer::normalize( $scw_value );
}

restore_error_handler();

foreach ( $scw_non_string_results as $scw_label => $scw_result ) {
	$scw_check(
		$scw_label . ' devuelve valid=false y error=empty_url',
		false === $scw_result['valid'] && SCW_URL_Normalizer::ERROR_EMPTY_URL === $scw_result['error'],
		'error=' . var_export( $scw_result['error'], true )
	);
}

$scw_check(
	'Ninguna entrada no-string genera warnings ni notices',
	empty( $scw_php_errors ),
	empty( $scw_php_errors ) ? '' : implode( ' | ', $scw_php_errors )
);

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
