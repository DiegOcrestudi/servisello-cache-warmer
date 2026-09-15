<?php
/**
 * TEST · Validador de integridad HTML (F4.1).
 *
 * No hace ninguna petición de red, no escribe en ninguna tabla y no modifica
 * ningún ajuste: SCW_HTML_Validator es una función pura y este test se limita
 * a alimentarla con cuerpos construidos a medida.
 *
 * Los umbrales se pasan como sobrescrituras explícitas en la mayoría de
 * comprobaciones, para que el resultado no dependa de cómo esté configurado el
 * sitio donde se ejecute el test. Una sección aparte comprueba que config()
 * sí lee los ajustes reales.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-1-html-validator-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_HTML_Validator' ) ) {
	exit( "SCW_HTML_Validator no está cargada. ¿Está activo el plugin?\n" );
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
 * Construye un documento HTML de un tamaño exacto en bytes.
 *
 * @param int   $target_bytes Tamaño deseado. Si es menor que el esqueleto
 *                            mínimo, el documento resultante será ese mínimo.
 * @param array $options      doctype, html_tag, close_html, close_body.
 * @return string
 */
$scw_doc = function ( $target_bytes = 30000, $options = array() ) {
	$options = array_merge(
		array(
			'doctype'    => true,
			'html_tag'   => true,
			'close_html' => true,
			'close_body' => true,
		),
		is_array( $options ) ? $options : array()
	);

	$head = ( $options['doctype'] ? '<!DOCTYPE html>' : '' )
		. ( $options['html_tag'] ? '<html lang="es">' : '' )
		. '<head><title>Servisello</title></head><body><div class="contenido">';

	$tail = '</div>'
		. ( $options['close_body'] ? '</body>' : '' )
		. ( $options['close_html'] ? '</html>' : '' );

	$padding = (int) $target_bytes - strlen( $head ) - strlen( $tail );

	if ( $padding < 0 ) {
		$padding = 0;
	}

	return $head . str_repeat( 'x', $padding ) . $tail;
};

/**
 * ¿Contiene el resultado un código de anomalía fuerte concreto?
 *
 * @param array  $result Resultado de check().
 * @param string $code   Código.
 * @return bool
 */
$scw_has_anomaly = function ( $result, $code ) {
	return in_array( $code, $result['anomaly_codes'], true );
};

/**
 * ¿Contiene el resultado una señal informativa concreta?
 *
 * @param array  $result Resultado de check().
 * @param string $code   Código.
 * @return bool
 */
$scw_has_signal = function ( $result, $code ) {
	return in_array( $code, $result['signal_codes'], true );
};

/** Content-Type correcto, para no repetirlo en cada llamada. */
$scw_html_ct = array( 'content_type' => 'text/html; charset=UTF-8' );

echo "\n=== F4.1 · SCW_HTML_Validator ===\n";

// --- 1. Forma del resultado ---------------------------------------------------------
echo "\n1. Forma del resultado\n";

$scw_result = SCW_HTML_Validator::check( $scw_doc( 30000 ), $scw_html_ct );

$scw_expected_keys = array( 'ok', 'bytes', 'mime', 'anomalies', 'signals', 'anomaly_codes', 'signal_codes' );
$scw_missing_keys  = array();

foreach ( $scw_expected_keys as $scw_key ) {
	if ( ! array_key_exists( $scw_key, $scw_result ) ) {
		$scw_missing_keys[] = $scw_key;
	}
}

$scw_check( 'El resultado contiene todas las claves esperadas', empty( $scw_missing_keys ), empty( $scw_missing_keys ) ? '' : 'faltan: ' . implode( ', ', $scw_missing_keys ) );
$scw_check( 'ok es booleano', is_bool( $scw_result['ok'] ) );
$scw_check( 'bytes coincide con el tamaño real del cuerpo', 30000 === $scw_result['bytes'], 'bytes=' . $scw_result['bytes'] );
$scw_check( 'mime se normaliza descartando el charset', 'text/html' === $scw_result['mime'], 'mime=' . var_export( $scw_result['mime'], true ) );
$scw_check( 'anomaly_codes se corresponde con anomalies', count( $scw_result['anomaly_codes'] ) === count( $scw_result['anomalies'] ) );
$scw_check( 'signal_codes se corresponde con signals', count( $scw_result['signal_codes'] ) === count( $scw_result['signals'] ) );

// --- 2. Página correcta -------------------------------------------------------------
echo "\n2. Página correcta: ni anomalías ni señales\n";

$scw_result = SCW_HTML_Validator::check( $scw_doc( 30000 ), $scw_html_ct );

$scw_check( 'ok = true', true === $scw_result['ok'] );
$scw_check( 'Sin anomalías fuertes', empty( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );
$scw_check( 'Sin señales informativas', empty( $scw_result['signals'] ), implode( ',', $scw_result['signal_codes'] ) );

// --- 3. Cuerpo vacío ----------------------------------------------------------------
echo "\n3. Cuerpo vacío\n";

$scw_result = SCW_HTML_Validator::check( '', $scw_html_ct );

$scw_check( 'Cuerpo vacío -> ok = false', false === $scw_result['ok'] );
$scw_check( 'Cuerpo vacío -> anomalía empty_body', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_EMPTY_BODY ) );
$scw_check( 'Cuerpo vacío -> exactamente UNA anomalía (no se repite la misma causa)', 1 === count( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );

$scw_result = SCW_HTML_Validator::check( "\n\n   \t  ", $scw_html_ct );
$scw_check( 'Sólo espacios en blanco también es empty_body', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_EMPTY_BODY ) );

$scw_result = SCW_HTML_Validator::check( '', array( 'content_type' => 'application/json' ) );
$scw_check(
	'Cuerpo vacío con Content-Type incorrecto no acumula una segunda anomalía',
	1 === count( $scw_result['anomalies'] ) && $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_EMPTY_BODY ),
	implode( ',', $scw_result['anomaly_codes'] )
);

// --- 4. Tamaño: suelo duro (anomalía fuerte) ----------------------------------------
echo "\n4. Tamaño: suelo duro = anomalía fuerte\n";

$scw_floor = SCW_HTML_Validator::ABSOLUTE_MIN_BYTES;

$scw_result = SCW_HTML_Validator::check( $scw_doc( $scw_floor - 1 ), $scw_html_ct );
$scw_check( 'Justo por debajo del suelo -> body_too_small', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_BODY_TOO_SMALL ), 'bytes=' . $scw_result['bytes'] );
$scw_check( 'Justo por debajo del suelo -> ok = false', false === $scw_result['ok'] );

$scw_result = SCW_HTML_Validator::check( $scw_doc( $scw_floor ), $scw_html_ct );
$scw_check( 'Justo en el suelo -> sin body_too_small', ! $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_BODY_TOO_SMALL ), 'bytes=' . $scw_result['bytes'] );
$scw_check( 'Justo en el suelo -> ok = true', true === $scw_result['ok'], implode( ',', $scw_result['anomaly_codes'] ) );

$scw_result = SCW_HTML_Validator::check( '<html>ok</html>', array( 'content_type' => 'text/html' ) );
$scw_check(
	'El cuerpo de 15 bytes que usaban los tests de F3 es ahora body_too_small',
	$scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_BODY_TOO_SMALL ),
	'bytes=' . $scw_result['bytes']
);

// --- 5. Tamaño: min_html_bytes es SEÑAL, no anomalía --------------------------------
echo "\n5. Tamaño: min_html_bytes es señal informativa, no anomalía\n";

$scw_args = array_merge( $scw_html_ct, array( 'min_html_bytes' => 20000 ) );

$scw_result = SCW_HTML_Validator::check( $scw_doc( 19999 ), $scw_args );
$scw_check( 'Justo por debajo del esperado -> señal body_below_min_html_bytes', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_BELOW_MIN_BYTES ), 'bytes=' . $scw_result['bytes'] );
$scw_check( 'Justo por debajo del esperado -> NINGUNA anomalía fuerte', empty( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );
$scw_check( 'Justo por debajo del esperado -> ok = true (no descalifica la página)', true === $scw_result['ok'] );

$scw_result = SCW_HTML_Validator::check( $scw_doc( 20000 ), $scw_args );
$scw_check( 'Justo en el esperado -> sin señal de tamaño', ! $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_BELOW_MIN_BYTES ), 'bytes=' . $scw_result['bytes'] );

$scw_result = SCW_HTML_Validator::check( $scw_doc( 1000 ), $scw_args );
$scw_check(
	'Entre el suelo y el esperado -> señal pero nunca anomalía',
	$scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_BELOW_MIN_BYTES ) && empty( $scw_result['anomalies'] ),
	'bytes=' . $scw_result['bytes'] . ' anomalías=' . implode( ',', $scw_result['anomaly_codes'] )
);

$scw_result = SCW_HTML_Validator::check( $scw_doc( 200 ), array_merge( $scw_html_ct, array( 'min_html_bytes' => 100 ) ) );
$scw_check(
	'Con min_html_bytes por debajo del suelo, manda el ajuste (no hay anomalía)',
	empty( $scw_result['anomalies'] ),
	'bytes=' . $scw_result['bytes'] . ' anomalías=' . implode( ',', $scw_result['anomaly_codes'] )
);

$scw_check( 'absolute_min_bytes() nunca supera min_html_bytes', 100 === SCW_HTML_Validator::absolute_min_bytes( array( 'min_html_bytes' => 100 ) ) );
$scw_check( 'absolute_min_bytes() usa el suelo cuando min_html_bytes es mayor', $scw_floor === SCW_HTML_Validator::absolute_min_bytes( array( 'min_html_bytes' => 20000 ) ) );

// --- 6. Content-Type ----------------------------------------------------------------
echo "\n6. Content-Type\n";

$scw_body = $scw_doc( 30000 );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => 'application/json' ) );
$scw_check( 'application/json -> anomalía content_type_not_html', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_CONTENT_TYPE ) );
$scw_check( 'application/json -> ok = false', false === $scw_result['ok'] );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => 'text/plain' ) );
$scw_check( 'text/plain -> anomalía content_type_not_html', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_CONTENT_TYPE ) );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => 'TEXT/HTML; CharSet=utf-8' ) );
$scw_check( 'Content-Type en mayúsculas se acepta', true === $scw_result['ok'], implode( ',', $scw_result['anomaly_codes'] ) );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => 'application/xhtml+xml' ) );
$scw_check( 'application/xhtml+xml se acepta', true === $scw_result['ok'], implode( ',', $scw_result['anomaly_codes'] ) );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => null ) );
$scw_check( 'Content-Type ausente -> señal, no anomalía', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_CONTENT_TYPE_MISSING ) && empty( $scw_result['anomalies'] ) );
$scw_check( 'Content-Type ausente -> ok = true', true === $scw_result['ok'] );

$scw_result = SCW_HTML_Validator::check( $scw_body, array( 'content_type' => '   ' ) );
$scw_check( 'Content-Type vacío se trata como ausente', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_CONTENT_TYPE_MISSING ) );
$scw_check( 'mime es null cuando no hay Content-Type utilizable', null === $scw_result['mime'] );

// --- 7. Truncamiento ----------------------------------------------------------------
echo "\n7. Truncamiento confirmado\n";

$scw_result = SCW_HTML_Validator::check( $scw_body, array_merge( $scw_html_ct, array( 'truncated' => true ) ) );
$scw_check( 'truncated = true -> anomalía truncated', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_TRUNCATED ) );
$scw_check( 'truncated = true -> ok = false', false === $scw_result['ok'] );

$scw_cut    = $scw_doc( 30000, array( 'close_html' => false, 'close_body' => false ) );
$scw_result = SCW_HTML_Validator::check( $scw_cut, array_merge( $scw_html_ct, array( 'truncated' => true ) ) );
$scw_check(
	'Truncado y sin </html> -> una sola anomalía (no se cuenta dos veces la misma causa)',
	1 === count( $scw_result['anomalies'] ) && $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_TRUNCATED ),
	implode( ',', $scw_result['anomaly_codes'] )
);

$scw_result = SCW_HTML_Validator::check( $scw_body, array_merge( $scw_html_ct, array( 'truncated' => false ) ) );
$scw_check( 'truncated = false no genera anomalía', ! $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_TRUNCATED ) );

// --- 8. Etiquetas de cierre ---------------------------------------------------------
echo "\n8. Etiquetas de cierre\n";

$scw_no_close = $scw_doc( 30000, array( 'close_html' => false, 'close_body' => false ) );

$scw_result = SCW_HTML_Validator::check( $scw_no_close, array_merge( $scw_html_ct, array( 'require_closing_tags' => true ) ) );
$scw_check( 'Sin </html> y con la regla activa -> anomalía missing_closing_html', $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_MISSING_CLOSING_HTML ) );
$scw_check( 'Sin </html> y con la regla activa -> ok = false', false === $scw_result['ok'] );

$scw_result = SCW_HTML_Validator::check( $scw_no_close, array_merge( $scw_html_ct, array( 'require_closing_tags' => false ) ) );
$scw_check( 'Sin </html> y con la regla desactivada -> sin anomalía', empty( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );
$scw_check( 'Con la regla desactivada tampoco se emite la señal de </body>', ! $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_MISSING_CLOSING_BODY ) );

$scw_result = SCW_HTML_Validator::check( $scw_doc( 30000, array( 'close_body' => false ) ), array_merge( $scw_html_ct, array( 'require_closing_tags' => true ) ) );
$scw_check( 'Con </html> pero sin </body> -> señal, no anomalía', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_MISSING_CLOSING_BODY ) && empty( $scw_result['anomalies'] ) );

$scw_upper  = str_replace( array( '</body>', '</html>' ), array( '</BODY>', '</HTML>' ), $scw_doc( 30000 ) );
$scw_result = SCW_HTML_Validator::check( $scw_upper, array_merge( $scw_html_ct, array( 'require_closing_tags' => true ) ) );
$scw_check( 'Etiquetas de cierre en mayúsculas se detectan', true === $scw_result['ok'], implode( ',', $scw_result['anomaly_codes'] ) );

$scw_trailing = $scw_doc( 30000 ) . "\n<!-- Page generated by LiteSpeed Cache -->\n";
$scw_result   = SCW_HTML_Validator::check( $scw_trailing, array_merge( $scw_html_ct, array( 'require_closing_tags' => true ) ) );
$scw_check(
	'Contenido después de </html> es normal: ni anomalía ni señal',
	empty( $scw_result['anomalies'] ) && empty( $scw_result['signals'] ),
	'anomalías=' . implode( ',', $scw_result['anomaly_codes'] ) . ' señales=' . implode( ',', $scw_result['signal_codes'] )
);

// --- 9. Esto NO es un validador W3C -------------------------------------------------
echo "\n9. HTML imperfecto pero completo: no es una anomalía\n";

$scw_messy = '<!DOCTYPE html><html lang="es"><head><title>Servisello</title></head><body>'
	. '<div class="a"><p>Texto sin cerrar<div class="b">' . str_repeat( 'x', 30000 ) . '</div>'
	. '<img src="/x.png"><br>'
	. '</body></html>';

$scw_result = SCW_HTML_Validator::check( $scw_messy, $scw_html_ct );
$scw_check( 'HTML mal anidado pero completo -> ok = true', true === $scw_result['ok'], implode( ',', $scw_result['anomaly_codes'] ) );
$scw_check( 'HTML mal anidado pero completo -> sin señales', empty( $scw_result['signals'] ), implode( ',', $scw_result['signal_codes'] ) );

$scw_result = SCW_HTML_Validator::check( $scw_doc( 30000, array( 'doctype' => false ) ), $scw_html_ct );
$scw_check( 'Sin DOCTYPE -> señal no_doctype, no anomalía', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_NO_DOCTYPE ) && empty( $scw_result['anomalies'] ) );

$scw_fragment = '<div class="contenido">' . str_repeat( 'x', 30000 ) . '</div>';
$scw_result   = SCW_HTML_Validator::check( $scw_fragment, array_merge( $scw_html_ct, array( 'require_closing_tags' => false ) ) );
$scw_check( 'Fragmento sin <html -> señal no_html_element', $scw_has_signal( $scw_result, SCW_HTML_Validator::SIGNAL_NO_HTML_ELEMENT ) );
$scw_check( 'Fragmento sin <html -> sigue sin ser anomalía fuerte por sí solo', empty( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );

// --- 10. Varias anomalías a la vez --------------------------------------------------
echo "\n10. Acumulación de anomalías\n";

$scw_result = SCW_HTML_Validator::check(
	'<p>error</p>',
	array(
		'content_type'         => 'text/plain',
		'require_closing_tags' => true,
	)
);

$scw_check( 'Cuerpo minúsculo + Content-Type malo + sin </html> -> 3 anomalías', 3 === count( $scw_result['anomalies'] ), implode( ',', $scw_result['anomaly_codes'] ) );
$scw_check( 'ok = false con varias anomalías', false === $scw_result['ok'] );

// --- 11. Configuración: se leen los ajustes reales ----------------------------------
echo "\n11. Configuración efectiva\n";

$scw_config       = SCW_HTML_Validator::config();
$scw_setting_min  = (int) SCW_Settings::get( 'min_html_bytes', 0 );
$scw_setting_tags = (bool) SCW_Settings::get( 'require_closing_tags', true );

$scw_check( 'config() lee min_html_bytes de los ajustes', $scw_setting_min === (int) $scw_config['min_html_bytes'], 'min_html_bytes=' . $scw_config['min_html_bytes'] );
$scw_check( 'config() lee require_closing_tags de los ajustes', $scw_setting_tags === (bool) $scw_config['require_closing_tags'], 'require_closing_tags=' . var_export( $scw_config['require_closing_tags'], true ) );

$scw_config = SCW_HTML_Validator::config( array( 'min_html_bytes' => 12345, 'require_closing_tags' => false ) );
$scw_check( 'Las sobrescrituras tienen prioridad sobre los ajustes', 12345 === (int) $scw_config['min_html_bytes'] && false === $scw_config['require_closing_tags'] );

$scw_config = SCW_HTML_Validator::config( array( 'min_html_bytes' => -50 ) );
$scw_check( 'Un min_html_bytes negativo se acota a 0', 0 === (int) $scw_config['min_html_bytes'] );

// --- 12. Robustez y pureza ----------------------------------------------------------
echo "\n12. Robustez y pureza\n";

foreach ( array( 'null' => null, 'array' => array(), 'int' => 123, 'bool' => true ) as $scw_label => $scw_input ) {
	$scw_result = SCW_HTML_Validator::check( $scw_input, $scw_html_ct );
	$scw_check( "Cuerpo de tipo {$scw_label} se trata como vacío, sin error", $scw_has_anomaly( $scw_result, SCW_HTML_Validator::ANOMALY_EMPTY_BODY ) );
}

$scw_result = SCW_HTML_Validator::check( $scw_doc( 30000 ), 'no-es-un-array' );
$scw_check( 'Unos args que no son array no rompen la llamada', is_array( $scw_result ) && true === $scw_result['ok'] );

$scw_args_before = array_merge( $scw_html_ct, array( 'min_html_bytes' => 20000 ) );
$scw_args_copy   = $scw_args_before;
$scw_body_before = $scw_doc( 19999 );

$scw_first  = SCW_HTML_Validator::check( $scw_body_before, $scw_args_before );
$scw_second = SCW_HTML_Validator::check( $scw_body_before, $scw_args_before );

$scw_check( 'Dos llamadas idénticas devuelven el mismo resultado', $scw_first === $scw_second );
$scw_check( 'check() no modifica los args recibidos', $scw_args_copy === $scw_args_before );
$scw_check( 'check() no modifica el cuerpo recibido', $scw_doc( 19999 ) === $scw_body_before );

$scw_stored_settings = get_option( SCW_Settings::OPTION, array() );
SCW_HTML_Validator::check( $scw_doc( 30000 ), $scw_html_ct );
$scw_check( 'check() no altera los ajustes almacenados', $scw_stored_settings === get_option( SCW_Settings::OPTION, array() ) );

// --- 13. Rendimiento ----------------------------------------------------------------
echo "\n13. Rendimiento sobre un documento grande\n";

$scw_big   = $scw_doc( 800000 );
$scw_start = microtime( true );
$scw_result = SCW_HTML_Validator::check( $scw_big, $scw_html_ct );
$scw_elapsed = microtime( true ) - $scw_start;

$scw_check( 'Un documento de 800 KB se valida correctamente', true === $scw_result['ok'] && 800000 === $scw_result['bytes'] );
$scw_check( 'Se valida en menos de 1 segundo', $scw_elapsed < 1.0, sprintf( '%.1f ms', $scw_elapsed * 1000 ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
