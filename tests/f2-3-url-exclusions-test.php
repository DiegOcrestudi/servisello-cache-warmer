<?php
/**
 * Prueba del motor de exclusiones (F2.3).
 *
 * No hace peticiones HTTP, no toca la base de datos y no encola nada.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f2-3-url-exclusions-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_URL_Exclusions' ) ) {
	exit( "SCW_URL_Exclusions no está cargada. ¿Está activo el plugin?\n" );
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
 * Comprueba que una URL queda excluida por el tipo esperado.
 *
 * @param string $url    URL normalizada.
 * @param string $type   Tipo esperado.
 * @param array  $config Configuración inyectada.
 * @return void
 */
$scw_excluded = function ( $url, $type, $config = array() ) use ( $scw_check ) {
	$result = SCW_URL_Exclusions::check( $url, $config );
	$actual = $result['excluded'] ? $result['type'] : ( $result['processable'] ? 'NO EXCLUIDA' : 'NO PROCESABLE:' . $result['error'] );

	$scw_check(
		$url . ' → ' . $type,
		$result['excluded'] && $result['type'] === $type,
		$actual === $type ? '' : 'obtenido ' . $actual
	);
};

/**
 * Comprueba que una URL NO queda excluida.
 *
 * @param string $url    URL normalizada.
 * @param array  $config Configuración inyectada.
 * @return void
 */
$scw_allowed = function ( $url, $config = array() ) use ( $scw_check ) {
	$result = SCW_URL_Exclusions::check( $url, $config );
	$actual = $result['excluded'] ? 'EXCLUIDA por ' . $result['type'] . ' (' . $result['pattern'] . ')' : ( $result['processable'] ? 'no excluida' : 'NO PROCESABLE:' . $result['error'] );

	$scw_check(
		$url . ' → no excluida',
		! $result['excluded'] && $result['processable'],
		'no excluida' === $actual ? '' : 'obtenido ' . $actual
	);
};

/**
 * Comprueba que una URL no es procesable por esta capa.
 *
 * @param string $url      URL.
 * @param string $expected Código de error esperado.
 * @param array  $config   Configuración inyectada.
 * @return void
 */
$scw_not_processable = function ( $url, $expected, $config = array() ) use ( $scw_check ) {
	$result = SCW_URL_Exclusions::check( $url, $config );
	$actual = $result['processable'] ? 'PROCESADA' : $result['error'];

	$scw_check(
		( '' === $url ? '(cadena vacía)' : $url ) . ' → ' . $expected,
		! $result['processable'] && $result['error'] === $expected,
		$actual === $expected ? '' : 'obtenido ' . $actual
	);
};

// Configuración de referencia del brief de F2.3, inyectada para poder probar la
// semántica de cada tipo por separado.
$scw_cfg = array(
	'exclude_exact'  => array( '/wp-admin/', '/wp-login.php' ),
	'exclude_prefix' => array( '/carrito/', '/finalizar-compra/', '/mi-cuenta/' ),
	'exclude_regex'  => array(),
);

// Base con las tres listas vacías. Sin esto, un override parcial dejaría entrar
// las reglas reales de SCW_Settings y el test mediría otra cosa.
$scw_base = array(
	'exclude_exact'  => array(),
	'exclude_prefix' => array(),
	'exclude_regex'  => array(),
);

$scw_real = SCW_URL_Exclusions::config();

echo "\n=== F2.3 · Prueba del motor de exclusiones ===\n";
echo 'Dominio permitido: ' . $scw_real['allowed_host'] . "\n";
echo 'exclude_exact  (real): ' . ( empty( $scw_real['exclude_exact'] ) ? '(vacía)' : implode( ', ', $scw_real['exclude_exact'] ) ) . "\n";
echo 'exclude_prefix (real): ' . ( empty( $scw_real['exclude_prefix'] ) ? '(vacía)' : implode( ', ', $scw_real['exclude_prefix'] ) ) . "\n";
echo 'exclude_regex  (real): ' . ( empty( $scw_real['exclude_regex'] ) ? '(vacía)' : implode( ', ', $scw_real['exclude_regex'] ) ) . "\n";

// --- 1. Exacta -----------------------------------------------------------------
echo "\n1. Exclusión exacta\n";
$scw_excluded( 'https://www.servisello.es/wp-admin/', SCW_URL_Exclusions::TYPE_EXACT, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/wp-login.php', SCW_URL_Exclusions::TYPE_EXACT, $scw_cfg );
$scw_allowed( 'https://www.servisello.es/wp-admin/users/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/wp-login.php/foo/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/wp-admin', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/mi-wp-admin/', $scw_cfg );

$scw_result = SCW_URL_Exclusions::check( 'https://www.servisello.es/wp-admin/', $scw_cfg );
$scw_check( 'El resultado incluye el patrón', '/wp-admin/' === $scw_result['pattern'], 'pattern=' . var_export( $scw_result['pattern'], true ) );
$scw_check( 'El resultado incluye el motivo estable', SCW_URL_Exclusions::REASON_EXACT === $scw_result['reason'], 'reason=' . var_export( $scw_result['reason'], true ) );
$scw_check( 'El resultado incluye el path evaluado', '/wp-admin/' === $scw_result['path'] );

// --- 2. Prefijo -----------------------------------------------------------------
echo "\n2. Exclusión por prefijo\n";
$scw_excluded( 'https://www.servisello.es/carrito/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/carrito/producto/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/carrito/pagina/2/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/carrito', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/finalizar-compra/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/finalizar-compra/pedido-recibido/123/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/mi-cuenta/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/mi-cuenta/pedidos/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );

// Límite de segmento.
$scw_allowed( 'https://www.servisello.es/carritos/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/carrito-de-compra/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/mi-cuenta-bancaria/', $scw_cfg );

$scw_autor = array_merge( $scw_base, array( 'exclude_prefix' => array( '/autor/' ) ) );
$scw_excluded( 'https://www.servisello.es/autor/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_autor );
$scw_excluded( 'https://www.servisello.es/autor/juan/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_autor );
$scw_excluded( 'https://www.servisello.es/autor/juan/pagina/2/', SCW_URL_Exclusions::TYPE_PREFIX, $scw_autor );
$scw_allowed( 'https://www.servisello.es/autoridad/', $scw_autor );
$scw_allowed( 'https://www.servisello.es/autores/', $scw_autor );

// --- 3. Regex -----------------------------------------------------------------
echo "\n3. Exclusión por regex\n";
$scw_regex_simple = array_merge( $scw_base, array( 'exclude_regex' => array( '#^/autor/#' ) ) );
$scw_excluded( 'https://www.servisello.es/autor/juan/', SCW_URL_Exclusions::TYPE_REGEX, $scw_regex_simple );
$scw_allowed( 'https://www.servisello.es/autoridad/', $scw_regex_simple );

$scw_regex_paged = array_merge( $scw_base, array( 'exclude_regex' => array( '#^/autor/[^/]+/pagina/[0-9]+/$#' ) ) );
$scw_excluded( 'https://www.servisello.es/autor/juan/pagina/2/', SCW_URL_Exclusions::TYPE_REGEX, $scw_regex_paged );
$scw_excluded( 'https://www.servisello.es/autor/maria/pagina/17/', SCW_URL_Exclusions::TYPE_REGEX, $scw_regex_paged );
$scw_allowed( 'https://www.servisello.es/autor/juan/', $scw_regex_paged );
$scw_allowed( 'https://www.servisello.es/autor/juan/pagina/dos/', $scw_regex_paged );
$scw_allowed( 'https://www.servisello.es/autor/juan/pagina/2/extra/', $scw_regex_paged );

// Regex inválida: no debe romper nada y la URL no queda excluida por ella.
echo "\n3b. Regex inválida y vacía\n";
$scw_broken = array_merge( $scw_base, array( 'exclude_regex' => array( '#(unclosed[' ) ) );
$scw_broken_result = SCW_URL_Exclusions::check( 'https://www.servisello.es/categoria/', $scw_broken );
$scw_check( 'Una regex inválida no excluye', false === $scw_broken_result['excluded'] );
$scw_check( 'La URL sigue siendo procesable', true === $scw_broken_result['processable'] );
$scw_check( 'Se registra un aviso', 1 === count( $scw_broken_result['warnings'] ), count( $scw_broken_result['warnings'] ) . ' avisos' );
$scw_check(
	'El aviso identifica el patrón y el código',
	! empty( $scw_broken_result['warnings'] )
		&& '#(unclosed[' === $scw_broken_result['warnings'][0]['pattern']
		&& SCW_URL_Exclusions::WARN_INVALID_REGEX === $scw_broken_result['warnings'][0]['code'],
	empty( $scw_broken_result['warnings'] ) ? '(sin avisos)' : $scw_broken_result['warnings'][0]['code']
);

$scw_empty_regex = SCW_URL_Exclusions::check( 'https://www.servisello.es/categoria/', array_merge( $scw_base, array( 'exclude_regex' => array( '' ) ) ) );
$scw_check( 'Una regex vacía no excluye', false === $scw_empty_regex['excluded'] );
$scw_check( 'Una regex vacía genera aviso', ! empty( $scw_empty_regex['warnings'] ) && SCW_URL_Exclusions::WARN_EMPTY_PATTERN === $scw_empty_regex['warnings'][0]['code'] );

// Una regex rota no impide que otra válida siga funcionando.
$scw_mixed = array_merge( $scw_base, array( 'exclude_regex' => array( '#(unclosed[', '#^/autor/#' ) ) );
$scw_excluded( 'https://www.servisello.es/autor/juan/', SCW_URL_Exclusions::TYPE_REGEX, $scw_mixed );

$scw_problems = SCW_URL_Exclusions::validate_patterns( $scw_mixed );
$scw_check( 'validate_patterns() detecta la regex rota sin evaluar URLs', 1 === count( $scw_problems ), count( $scw_problems ) . ' problemas' );

// --- 4. Patrones mal formados -----------------------------------------------------------------
echo "\n4. Patrones de path mal formados\n";
$scw_bad_pattern = SCW_URL_Exclusions::check(
	'https://www.servisello.es/carrito/',
	array_merge( $scw_base, array( 'exclude_prefix' => array( 'carrito/' ) ) )
);
$scw_check( 'Un prefijo sin barra inicial se descarta', false === $scw_bad_pattern['excluded'] );
$scw_check(
	'Y genera el aviso correspondiente',
	! empty( $scw_bad_pattern['warnings'] ) && SCW_URL_Exclusions::WARN_NOT_ABSOLUTE === $scw_bad_pattern['warnings'][0]['code'],
	empty( $scw_bad_pattern['warnings'] ) ? '(sin avisos)' : $scw_bad_pattern['warnings'][0]['code']
);

$scw_empty_pattern = SCW_URL_Exclusions::check(
	'https://www.servisello.es/carrito/',
	array_merge( $scw_base, array( 'exclude_exact' => array( '', '   ' ) ) )
);
$scw_check( 'Los patrones vacíos se descartan', false === $scw_empty_pattern['excluded'] );
$scw_check( 'Y generan dos avisos', 2 === count( $scw_empty_pattern['warnings'] ), count( $scw_empty_pattern['warnings'] ) . ' avisos' );

// Un patrón roto no impide que otro válido de la misma lista funcione.
$scw_excluded(
	'https://www.servisello.es/carrito/',
	SCW_URL_Exclusions::TYPE_PREFIX,
	array_merge( $scw_base, array( 'exclude_prefix' => array( 'carrito/', '/carrito/' ) ) )
);

// Un patrón nunca puede describir un dominio.
$scw_not_processable(
	'https://evil.com/carrito/',
	SCW_URL_Normalizer::ERROR_HOST,
	array_merge( $scw_base, array( 'exclude_prefix' => array( 'https://evil.com/' ) ) )
);

// --- 5. Seguridad -----------------------------------------------------------------
echo "\n5. Seguridad\n";
$scw_not_processable( 'https://evil.com/carrito/', SCW_URL_Normalizer::ERROR_HOST, $scw_cfg );
$scw_not_processable( 'https://www.servisello.es.evil.com/carrito/', SCW_URL_Normalizer::ERROR_HOST, $scw_cfg );
$scw_not_processable( 'https://evil-servisello.es/carrito/', SCW_URL_Normalizer::ERROR_HOST, $scw_cfg );
$scw_not_processable( 'https://servisello.es/carrito/', SCW_URL_Normalizer::ERROR_HOST, $scw_cfg );
$scw_not_processable( 'https://usuario:clave@www.servisello.es/carrito/', SCW_URL_Normalizer::ERROR_USERINFO, $scw_cfg );
$scw_not_processable( 'https://usuario@www.servisello.es/carrito/', SCW_URL_Normalizer::ERROR_USERINFO, $scw_cfg );
$scw_not_processable( 'ftp://www.servisello.es/carrito/', SCW_URL_Normalizer::ERROR_SCHEME, $scw_cfg );
$scw_not_processable( 'javascript:alert(1)', SCW_URL_Normalizer::ERROR_INVALID_URL, $scw_cfg );
$scw_not_processable( 'https://www.servisello.es:8080/carrito/', SCW_URL_Normalizer::ERROR_PORT, $scw_cfg );
$scw_not_processable( 'no-es-una-url', SCW_URL_Normalizer::ERROR_INVALID_URL, $scw_cfg );
$scw_not_processable( '/carrito/', SCW_URL_Normalizer::ERROR_INVALID_URL, $scw_cfg );
$scw_not_processable( '', SCW_URL_Normalizer::ERROR_EMPTY_URL, $scw_cfg );

$scw_check(
	'Sin dominio configurado nada es procesable',
	SCW_URL_Normalizer::ERROR_NO_HOST_SET === SCW_URL_Exclusions::check( 'https://www.servisello.es/carrito/', array( 'allowed_host' => '' ) )['error']
);
$scw_check(
	'El puerto por defecto sí es procesable',
	SCW_URL_Exclusions::check( 'https://www.servisello.es:443/carrito/', $scw_cfg )['excluded']
);
$scw_check(
	'is_excluded() no confunde no procesable con no excluida',
	false === SCW_URL_Exclusions::is_excluded( 'https://evil.com/carrito/', $scw_cfg )
		&& false === SCW_URL_Exclusions::check( 'https://evil.com/carrito/', $scw_cfg )['processable']
);

// Entradas que no son cadenas.
$scw_php_errors = array();

set_error_handler(
	function ( $errno, $errstr ) use ( &$scw_php_errors ) {
		$scw_php_errors[] = $errstr;
		return true;
	}
);

$scw_non_strings = array( null, false, true, 123, 12.5, array() );
$scw_non_string_ok = true;

foreach ( $scw_non_strings as $scw_value ) {
	$scw_r = SCW_URL_Exclusions::check( $scw_value );

	if ( $scw_r['processable'] || SCW_URL_Normalizer::ERROR_EMPTY_URL !== $scw_r['error'] ) {
		$scw_non_string_ok = false;
	}
}

restore_error_handler();

$scw_check( 'Las entradas no-string devuelven empty_url', $scw_non_string_ok );
$scw_check( 'Y no generan warnings ni notices', empty( $scw_php_errors ), empty( $scw_php_errors ) ? '' : implode( ' | ', $scw_php_errors ) );

// --- 6. Query string -----------------------------------------------------------------
echo "\n6. Query string\n";
$scw_excluded( 'https://www.servisello.es/carrito/?foo=1', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/carrito/?a=1&b=2', SCW_URL_Exclusions::TYPE_PREFIX, $scw_cfg );
$scw_excluded( 'https://www.servisello.es/wp-admin/?page=x', SCW_URL_Exclusions::TYPE_EXACT, $scw_cfg );
$scw_allowed( 'https://www.servisello.es/categoria/?carrito=1', $scw_cfg );

$scw_query_result = SCW_URL_Exclusions::check( 'https://www.servisello.es/carrito/?foo=1', $scw_cfg );
$scw_check( 'El path evaluado no incluye la query', '/carrito/' === $scw_query_result['path'], 'path=' . var_export( $scw_query_result['path'], true ) );

// --- 7. Prioridad -----------------------------------------------------------------
echo "\n7. Prioridad exact > prefix > regex\n";
$scw_excluded(
	'https://www.servisello.es/carrito/',
	SCW_URL_Exclusions::TYPE_EXACT,
	array_merge(
		$scw_base,
		array(
			'exclude_exact'  => array( '/carrito/' ),
			'exclude_prefix' => array( '/carrito/' ),
		)
	)
);
$scw_excluded(
	'https://www.servisello.es/carrito/',
	SCW_URL_Exclusions::TYPE_EXACT,
	array_merge(
		$scw_base,
		array(
			'exclude_exact' => array( '/carrito/' ),
			'exclude_regex' => array( '#^/carrito/$#' ),
		)
	)
);
$scw_excluded(
	'https://www.servisello.es/carrito/',
	SCW_URL_Exclusions::TYPE_PREFIX,
	array_merge(
		$scw_base,
		array(
			'exclude_prefix' => array( '/carrito/' ),
			'exclude_regex'  => array( '#^/carrito/$#' ),
		)
	)
);
$scw_excluded(
	'https://www.servisello.es/carrito/',
	SCW_URL_Exclusions::TYPE_EXACT,
	array(
		'exclude_exact'  => array( '/carrito/' ),
		'exclude_prefix' => array( '/carrito/' ),
		'exclude_regex'  => array( '#^/carrito/$#' ),
	)
);

// Una regex rota detrás de una coincidencia previa ni siquiera se evalúa.
$scw_short_circuit = SCW_URL_Exclusions::check(
	'https://www.servisello.es/carrito/',
	array_merge(
		$scw_base,
		array(
			'exclude_prefix' => array( '/carrito/' ),
			'exclude_regex'  => array( '#(unclosed[' ),
		)
	)
);
$scw_check( 'La regex no se evalúa si ya hubo coincidencia', empty( $scw_short_circuit['warnings'] ), count( $scw_short_circuit['warnings'] ) . ' avisos' );

// --- 8. No destructividad -----------------------------------------------------------------
echo "\n8. No destructividad\n";
$scw_allowed( 'https://www.servisello.es/categoria-producto/design-stamp/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/categoria-producto/design-stamp/eventos/sellos-bautizos/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/categoria-producto/accesorios-para-sellos/tampones/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/tienda/producto-123/', $scw_cfg );
$scw_allowed( 'https://www.servisello.es/post-sitemap.xml', $scw_cfg );

$scw_check(
	'Sin ninguna regla configurada nada se excluye',
	false === SCW_URL_Exclusions::is_excluded(
		'https://www.servisello.es/carrito/',
		array(
			'exclude_exact'  => array(),
			'exclude_prefix' => array(),
			'exclude_regex'  => array(),
		)
	)
);

// --- 9. Configuración real del plugin -----------------------------------------------------------------
echo "\n9. Configuración real de SCW_Settings\n";
$scw_check( 'La configuración real se lee sin inyección', is_array( $scw_real['exclude_prefix'] ) && ! empty( $scw_real['exclude_prefix'] ) );
$scw_check( 'La configuración real no tiene patrones defectuosos', empty( SCW_URL_Exclusions::validate_patterns() ), count( SCW_URL_Exclusions::validate_patterns() ) . ' problemas' );

foreach ( array( '/carrito/', '/finalizar-compra/', '/mi-cuenta/', '/wp-admin/', '/wp-login.php' ) as $scw_path ) {
	$scw_r = SCW_URL_Exclusions::check( 'https://www.servisello.es' . $scw_path );
	$scw_check( 'Config real: ' . $scw_path . ' excluida', $scw_r['excluded'], 'tipo=' . var_export( $scw_r['type'], true ) );
}

// Consecuencia de que hoy /wp-admin/ sea un prefijo y no una exacta.
$scw_r = SCW_URL_Exclusions::check( 'https://www.servisello.es/wp-admin/users/' );
$scw_check( 'Config real: /wp-admin/users/ también queda excluida (regla de prefijo)', $scw_r['excluded'] && SCW_URL_Exclusions::TYPE_PREFIX === $scw_r['type'], 'tipo=' . var_export( $scw_r['type'], true ) );

// --- 10. Encadenado con F2.2 -----------------------------------------------------------------
echo "\n10. Encadenado normalizador → exclusiones\n";
$scw_chain = array(
	'https://WWW.SERVISELLO.ES/carrito?utm_source=x'    => true,
	'http://www.servisello.es/mi-cuenta'                => true,
	'https://www.servisello.es/categoria/?filter_a=1#x' => false,
);

foreach ( $scw_chain as $scw_raw => $scw_should_exclude ) {
	$scw_norm = SCW_URL_Normalizer::normalize( $scw_raw );

	if ( ! $scw_norm['valid'] ) {
		$scw_check( $scw_raw . ' se normaliza', false, 'error=' . $scw_norm['error'] );
		continue;
	}

	$scw_r = SCW_URL_Exclusions::check( $scw_norm['url'], $scw_cfg );

	$scw_check(
		$scw_raw . ' → ' . $scw_norm['url'] . ' → ' . ( $scw_should_exclude ? 'excluida' : 'no excluida' ),
		$scw_r['processable'] && $scw_r['excluded'] === $scw_should_exclude
	);
}

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
