<?php
/**
 * TEST · Validador de evidencia YITH (F4.3).
 *
 * No hace ninguna petición de red, no escribe en ninguna tabla y no modifica
 * ningún ajuste: SCW_YITH_Validator es una función pura sobre el HTML que
 * recibe.
 *
 * El HTML de referencia es el observado en producción en
 * /categoria-producto/design-stamp/exlibris/exlibris-anime/.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-3-yith-validator-test.php
 *
 * Termina con código de salida 1 si alguna comprobación falla.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_YITH_Validator' ) ) {
	exit( "SCW_YITH_Validator no está cargada. ¿Está activo el plugin?\n" );
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
 * ¿Contiene la lista de avisos un código concreto?
 *
 * @param array  $warnings Avisos.
 * @param string $code     Código.
 * @return bool
 */
$scw_has_warning = function ( $warnings, $code ) {
	foreach ( (array) $warnings as $warning ) {
		if ( isset( $warning['code'] ) && $code === $warning['code'] ) {
			return true;
		}
	}

	return false;
};

/**
 * Envuelve un fragmento en una página completa y realista.
 *
 * @param string $fragment Fragmento a insertar en el body.
 * @return string
 */
$scw_page = function ( $fragment ) {
	return '<!DOCTYPE html><html lang="es"><head><title>Servisello</title>'
		. '<link rel="stylesheet" href="/wp-content/cache/css/x.css"></head><body>'
		. '<div class="elementor elementor-1234"><div class="elementor-element elementor-element-7a2976f4">'
		. $fragment
		. '</div></div>'
		. '<footer>Servisello</footer></body></html>';
};

/** Contenedor real observado en producción. */
$scw_real_container = '<div class="yith-wcan-filters no-title" id="preset_6432" data-preset-id="6432" data-target="">'
	. '<div class="yith-wcan-filter"><h4>Color</h4><ul><li>Rojo</li></ul></div></div>';

echo "\n=== F4.3 · SCW_YITH_Validator ===\n";

// --- 1. HTML real de producción -----------------------------------------------------
echo "\n1. Contenedor real de producción (exlibris-anime)\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( $scw_real_container ) );

$scw_expected_keys = array( 'detected', 'valid', 'filter_marker', 'presets', 'invalid_presets', 'orphan_presets', 'consistent', 'containers', 'processable', 'error', 'error_message', 'warnings' );
$scw_missing       = array();

foreach ( $scw_expected_keys as $scw_key ) {
	if ( ! array_key_exists( $scw_key, $scw_result ) ) {
		$scw_missing[] = $scw_key;
	}
}

$scw_check( 'El resultado contiene todas las claves esperadas', empty( $scw_missing ), empty( $scw_missing ) ? '' : 'faltan: ' . implode( ', ', $scw_missing ) );
$scw_check( 'detected = true', true === $scw_result['detected'] );
$scw_check( 'valid = true', true === $scw_result['valid'] );
$scw_check( 'filter_marker = true', true === $scw_result['filter_marker'] );
$scw_check( 'presets = [6432]', array( 6432 ) === $scw_result['presets'], 'presets=' . implode( ',', $scw_result['presets'] ) );
$scw_check( 'Sin presets inválidos', array() === $scw_result['invalid_presets'] );
$scw_check( 'Sin presets huérfanos', array() === $scw_result['orphan_presets'], 'huérfanos=' . implode( ',', $scw_result['orphan_presets'] ) );
$scw_check( 'consistent = true', true === $scw_result['consistent'] );
$scw_check( 'processable = true y sin error', true === $scw_result['processable'] && null === $scw_result['error'] );
$scw_check( 'Sin avisos', empty( $scw_result['warnings'] ), 'avisos=' . count( $scw_result['warnings'] ) );
$scw_check( 'Un único contenedor', 1 === count( $scw_result['containers'] ), 'contenedores=' . count( $scw_result['containers'] ) );

$scw_container = $scw_result['containers'][0];

$scw_check( 'El contenedor es un div', 'div' === $scw_container['tag'], 'tag=' . $scw_container['tag'] );
$scw_check( 'id_preset = 6432', 6432 === $scw_container['id_preset'] );
$scw_check( 'data_preset_id = 6432', 6432 === $scw_container['data_preset_id'] );
$scw_check( 'Las dos fuentes de preset están presentes', array( 'id', 'data-preset-id' ) === $scw_container['sources'] );
$scw_check( 'El contenedor conserva sus clases', in_array( 'no-title', $scw_container['classes'], true ) );
$scw_check( 'El contenedor guarda un fragmento de evidencia', is_string( $scw_container['snippet'] ) && '' !== $scw_container['snippet'] );

// --- 2. Los tres presets válidos ----------------------------------------------------
echo "\n2. Los tres presets conocidos\n";

foreach ( array( 6432, 6683, 13269 ) as $scw_preset ) {
	$scw_html   = $scw_page( '<div class="yith-wcan-filters no-title" id="preset_' . $scw_preset . '" data-preset-id="' . $scw_preset . '" data-target=""></div>' );
	$scw_result = SCW_YITH_Validator::resolve( $scw_html );

	$scw_check(
		'preset_' . $scw_preset . ' es válido',
		true === $scw_result['detected'] && true === $scw_result['valid'] && array( $scw_preset ) === $scw_result['presets'],
		'presets=' . implode( ',', $scw_result['presets'] )
	);
}

$scw_check( 'La lista de presets válidos tiene los tres conocidos', array( 6432, 6683, 13269 ) === SCW_YITH_Validator::presets(), implode( ',', SCW_YITH_Validator::presets() ) );

// --- 3. Varios presets válidos ------------------------------------------------------
echo "\n3. Varios contenedores con presets distintos\n";

$scw_html = $scw_page(
	'<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div>'
	. '<aside class="sidebar"><div class="yith-wcan-filters no-title" id="preset_6683" data-preset-id="6683"></div></aside>'
);

$scw_result = SCW_YITH_Validator::resolve( $scw_html );

$scw_check( 'Se conservan los dos presets', array( 6432, 6683 ) === $scw_result['presets'], 'presets=' . implode( ',', $scw_result['presets'] ) );
$scw_check( 'Se registran los dos contenedores', 2 === count( $scw_result['containers'] ) );
$scw_check( 'valid = true con varios presets', true === $scw_result['valid'] );

$scw_html   = $scw_page( '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div><div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div>' );
$scw_result = SCW_YITH_Validator::resolve( $scw_html );

$scw_check( 'El mismo preset repetido no se duplica en presets', array( 6432 ) === $scw_result['presets'], 'presets=' . implode( ',', $scw_result['presets'] ) );
$scw_check( 'Pero sí se registran los dos contenedores', 2 === count( $scw_result['containers'] ) );

// --- 4. Marcador sin preset ---------------------------------------------------------
echo "\n4. Marcador del filtro sin ningún preset\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters"></div>' ) );

$scw_check( 'detected = true (el marcador está)', true === $scw_result['detected'] );
$scw_check( 'filter_marker = true', true === $scw_result['filter_marker'] );
$scw_check( 'valid = false (no hay preset)', false === $scw_result['valid'] );
$scw_check( 'presets vacío', array() === $scw_result['presets'] );
$scw_check( 'Aviso marker_without_preset', $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_MARKER_WITHOUT_PRESET ) );
$scw_check( 'Se distingue de YITH válido: detected sin valid', true === $scw_result['detected'] && false === $scw_result['valid'] );

// --- 5. Preset desconocido ----------------------------------------------------------
echo "\n5. Preset desconocido\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>' ) );

$scw_check( 'detected = true', true === $scw_result['detected'] );
$scw_check( 'valid = false con preset 9999', false === $scw_result['valid'] );
$scw_check( 'invalid_presets = [9999]', array( 9999 ) === $scw_result['invalid_presets'], 'inválidos=' . implode( ',', $scw_result['invalid_presets'] ) );
$scw_check( 'presets válidos vacío', array() === $scw_result['presets'] );
$scw_check( 'Aviso unknown_preset', $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_UNKNOWN_PRESET ) );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_1" data-preset-id="1"></div>' ) );
$scw_check( 'No vale cualquier cosa que empiece por preset_', false === $scw_result['valid'] && array( 1 ) === $scw_result['invalid_presets'] );

$scw_html = $scw_page(
	'<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>'
	. '<div class="yith-wcan-filters" id="preset_6683" data-preset-id="6683"></div>'
);

$scw_result = SCW_YITH_Validator::resolve( $scw_html );
$scw_check( 'Un contenedor válido y otro desconocido conviven', true === $scw_result['valid'] && array( 6683 ) === $scw_result['presets'] && array( 9999 ) === $scw_result['invalid_presets'] );

// --- 6. Preset dentro de JavaScript -------------------------------------------------
echo "\n6. Preset dentro de JavaScript\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<script>var foo = "preset_6432";</script>' ) );

$scw_check( 'detected = false', false === $scw_result['detected'] );
$scw_check( 'valid = false', false === $scw_result['valid'] );
$scw_check( 'filter_marker = false', false === $scw_result['filter_marker'] );
$scw_check( 'Se registra como huérfano para diagnóstico', array( 6432 ) === $scw_result['orphan_presets'], 'huérfanos=' . implode( ',', $scw_result['orphan_presets'] ) );
$scw_check( 'Aviso preset_outside_yith_context', $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_PRESET_OUT_OF_CONTEXT ) );
$scw_check( 'processable = true: no es un error', true === $scw_result['processable'] && null === $scw_result['error'] );

// Un script que contenga el marcado COMPLETO tampoco debe engañar al validador.
$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<script>var t = \'<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div>\';</script>' ) );
$scw_check( 'Marcado completo dentro de un script: detected = false', false === $scw_result['detected'] );
$scw_check( 'Marcado completo dentro de un script: valid = false', false === $scw_result['valid'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<style>#preset_6432 { display: none; }</style>' ) );
$scw_check( 'Un preset citado en CSS tampoco es evidencia', false === $scw_result['detected'] && false === $scw_result['valid'] );

// --- 7. Preset dentro de un comentario HTML -----------------------------------------
echo "\n7. Preset dentro de un comentario HTML\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<!-- preset_6432 -->' ) );

$scw_check( 'Comentario: detected = false', false === $scw_result['detected'] );
$scw_check( 'Comentario: valid = false', false === $scw_result['valid'] );
$scw_check( 'Comentario: queda como huérfano', array( 6432 ) === $scw_result['orphan_presets'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<!-- <div class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></div> -->' ) );
$scw_check( 'Contenedor entero comentado: detected = false', false === $scw_result['detected'] );
$scw_check( 'Contenedor entero comentado: valid = false', false === $scw_result['valid'] );

// --- 8. Preset válido fuera de contexto YITH ----------------------------------------
echo "\n8. Preset válido fuera del contenedor del filtro\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div id="preset_6432" class="otra-cosa">Contenido</div>' ) );

$scw_check( 'id preset_6432 sin la clase del filtro: detected = false', false === $scw_result['detected'] );
$scw_check( 'id preset_6432 sin la clase del filtro: valid = false', false === $scw_result['valid'] );
$scw_check( 'Se registra como huérfano', array( 6432 ) === $scw_result['orphan_presets'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<a href="/categoria/?preset_6432=1">Filtrar</a>' ) );
$scw_check( 'Un preset dentro de una URL no es evidencia', false === $scw_result['detected'] && false === $scw_result['valid'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div data-config=\'{"preset_6432":true}\'>x</div>' ) );
$scw_check( 'Un preset dentro de un JSON no es evidencia', false === $scw_result['detected'] && false === $scw_result['valid'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<p>Usamos el preset_6432 en esta página.</p>' ) );
$scw_check( 'Un preset citado en texto no es evidencia', false === $scw_result['detected'] && false === $scw_result['valid'] );

// El nombre del filtro como subcadena de otra clase tampoco vale.
$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="mi-yith-wcan-filters-viejo" id="preset_6432" data-preset-id="6432"></div>' ) );
$scw_check( 'El marcador como subcadena de otra clase no cuenta', false === $scw_result['detected'] && false === $scw_result['valid'] );
$scw_check( 'Aviso marker_not_in_class_attribute', $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_MARKER_NOT_IN_CLASS ) );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div data-widget="yith-wcan-filters" id="preset_6432">x</div>' ) );
$scw_check( 'El marcador en otro atributo que no es class no cuenta', false === $scw_result['detected'] && false === $scw_result['valid'] );

// --- 9. id y data-preset-id ---------------------------------------------------------
echo "\n9. Coherencia entre id y data-preset-id\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_6683" data-preset-id="6683"></div>' ) );

$scw_check( 'Coherentes: consistent = true', true === $scw_result['consistent'] );
$scw_check( 'Coherentes: sin aviso de discrepancia', ! $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_PRESET_MISMATCH ) );
$scw_check( 'Coherentes: un solo preset', array( 6683 ) === $scw_result['presets'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_6432" data-preset-id="6683"></div>' ) );

$scw_check( 'Discrepantes: consistent = false', false === $scw_result['consistent'] );
$scw_check( 'Discrepantes: aviso preset_id_mismatch', $scw_has_warning( $scw_result['warnings'], SCW_YITH_Validator::WARN_PRESET_MISMATCH ) );
$scw_check( 'Discrepantes: se conservan AMBOS valores', array( 6432, 6683 ) === $scw_result['presets'], 'presets=' . implode( ',', $scw_result['presets'] ) );
$scw_check( 'Discrepantes: el contenedor guarda los dos por separado', 6432 === $scw_result['containers'][0]['id_preset'] && 6683 === $scw_result['containers'][0]['data_preset_id'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_6432"></div>' ) );
$scw_check( 'Sólo id: válido y consistente', true === $scw_result['valid'] && true === $scw_result['consistent'] );
$scw_check( 'Sólo id: se registra la fuente', array( 'id' ) === $scw_result['containers'][0]['sources'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" data-preset-id="13269"></div>' ) );
$scw_check( 'Sólo data-preset-id: válido', true === $scw_result['valid'] && array( 13269 ) === $scw_result['presets'] );
$scw_check( 'Sólo data-preset-id: se registra la fuente', array( 'data-preset-id' ) === $scw_result['containers'][0]['sources'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="bloque-preset_6432-lateral" data-preset-id="6432"></div>' ) );
$scw_check( 'Un id que sólo contiene preset_N no cuenta como id de preset', null === $scw_result['containers'][0]['id_preset'] );
$scw_check( 'Pero el data-preset-id sigue siendo evidencia válida', true === $scw_result['valid'] && array( 6432 ) === $scw_result['presets'] );

// --- 10. HTML sin YITH --------------------------------------------------------------
echo "\n10. HTML normal sin filtro (caso legítimo: sellos-textiles/page/2/)\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<ul class="products"><li class="product">Sello</li></ul><nav class="woocommerce-pagination"><a href="/page/3/">3</a></nav>' ) );

$scw_check( 'detected = false', false === $scw_result['detected'] );
$scw_check( 'valid = false', false === $scw_result['valid'] );
$scw_check( 'filter_marker = false', false === $scw_result['filter_marker'] );
$scw_check( 'processable = true', true === $scw_result['processable'] );
$scw_check( 'error = null: ausencia de YITH no es un error', null === $scw_result['error'] );
$scw_check( 'Sin avisos', empty( $scw_result['warnings'] ), 'avisos=' . count( $scw_result['warnings'] ) );
$scw_check( 'Sin presets de ningún tipo', array() === $scw_result['presets'] && array() === $scw_result['invalid_presets'] && array() === $scw_result['orphan_presets'] );

// --- 11. Entradas no procesables ----------------------------------------------------
echo "\n11. Entradas vacías o inválidas\n";

$scw_result = SCW_YITH_Validator::resolve( '' );
$scw_check( 'HTML vacío: processable = false', false === $scw_result['processable'] );
$scw_check( 'HTML vacío: error = empty_html', SCW_YITH_Validator::ERROR_EMPTY_HTML === $scw_result['error'], 'error=' . var_export( $scw_result['error'], true ) );
$scw_check( 'HTML vacío: detected y valid en false', false === $scw_result['detected'] && false === $scw_result['valid'] );
$scw_check( 'HTML vacío: mensaje de error legible', is_string( $scw_result['error_message'] ) && '' !== $scw_result['error_message'] );

$scw_result = SCW_YITH_Validator::resolve( "   \n\t " );
$scw_check( 'Sólo espacios: error = empty_html', SCW_YITH_Validator::ERROR_EMPTY_HTML === $scw_result['error'] );

foreach ( array( 'null' => null, 'array' => array(), 'int' => 123, 'bool' => true, 'objeto' => new stdClass() ) as $scw_label => $scw_input ) {
	$scw_result = SCW_YITH_Validator::resolve( $scw_input );

	$scw_check(
		"Entrada de tipo {$scw_label}: invalid_input sin error de PHP",
		false === $scw_result['processable'] && SCW_YITH_Validator::ERROR_INVALID_INPUT === $scw_result['error'],
		'error=' . var_export( $scw_result['error'], true )
	);
}

$scw_result = SCW_YITH_Validator::resolve( $scw_page( $scw_real_container ), 'no-es-un-array' );
$scw_check( 'Una configuración que no es array no rompe la llamada', true === $scw_result['valid'] );

// --- 12. Variantes de marcado -------------------------------------------------------
echo "\n12. Variantes de marcado admitidas\n";

$scw_variants = array(
	'comillas simples'    => "<div class='yith-wcan-filters no-title' id='preset_6432' data-preset-id='6432'></div>",
	'sin comillas'        => '<div class=yith-wcan-filters id=preset_6432 data-preset-id=6432></div>',
	'mayúsculas'          => '<DIV CLASS="yith-wcan-filters" ID="preset_6432" DATA-PRESET-ID="6432"></DIV>',
	'multilínea'          => "<div class=\"yith-wcan-filters no-title\"\n     id=\"preset_6432\"\n     data-preset-id=\"6432\"\n     data-target=\"\">",
	'espacios extra'      => '<div   class = "yith-wcan-filters"   id = "preset_6432"   data-preset-id = "6432" >',
	'clase al final'      => '<div id="preset_6432" data-preset-id="6432" class="widget yith-wcan-filters"></div>',
	'otra etiqueta'       => '<section class="yith-wcan-filters" id="preset_6432" data-preset-id="6432"></section>',
);

foreach ( $scw_variants as $scw_label => $scw_fragment ) {
	$scw_result = SCW_YITH_Validator::resolve( $scw_page( $scw_fragment ) );

	$scw_check(
		'Variante ' . $scw_label . ': detectada y válida',
		true === $scw_result['detected'] && true === $scw_result['valid'] && array( 6432 ) === $scw_result['presets'],
		'detected=' . var_export( $scw_result['detected'], true ) . ' presets=' . implode( ',', $scw_result['presets'] )
	);
}

// --- 13. Elementor no interviene ----------------------------------------------------
echo "\n13. Elementor no forma parte de la regla\n";

$scw_result = SCW_YITH_Validator::resolve(
	'<!DOCTYPE html><html><body><div class="elementor-element elementor-element-7a2976f4">'
	. '<ul class="products"><li>Sello</li></ul></div></body></html>'
);
$scw_check( 'El marcador de Elementor sin YITH no produce evidencia', false === $scw_result['detected'] && false === $scw_result['valid'] );

$scw_result = SCW_YITH_Validator::resolve(
	'<!DOCTYPE html><html><body><div class="yith-wcan-filters" id="preset_6683" data-preset-id="6683"></div></body></html>'
);
$scw_check( 'YITH sin ningún marcador de Elementor sigue siendo válido', true === $scw_result['valid'] && array( 6683 ) === $scw_result['presets'] );

// --- 14. Configuración de presets ---------------------------------------------------
echo "\n14. Lista de presets configurable\n";

$scw_result = SCW_YITH_Validator::resolve( $scw_page( '<div class="yith-wcan-filters" id="preset_9999" data-preset-id="9999"></div>' ), array( 'presets' => array( 9999 ) ) );
$scw_check( 'Con 9999 configurado como válido, pasa a ser válido', true === $scw_result['valid'] && array( 9999 ) === $scw_result['presets'] );

$scw_result = SCW_YITH_Validator::resolve( $scw_page( $scw_real_container ), array( 'presets' => array( 6683 ) ) );
$scw_check( 'Con una lista que excluye 6432, deja de ser válido', false === $scw_result['valid'] && array( 6432 ) === $scw_result['invalid_presets'] );

$scw_check( 'La configuración admite el formato preset_N de los ajustes', array( 6432, 6683 ) === SCW_YITH_Validator::normalize_preset_list( array( 'preset_6683', 'preset_6432' ) ) );
$scw_check( 'La configuración admite enteros y cadenas numéricas', array( 6432, 6683 ) === SCW_YITH_Validator::normalize_preset_list( array( 6432, '6683' ) ) );
$scw_check( 'Los valores no válidos se descartan de la lista', array( 6432 ) === SCW_YITH_Validator::normalize_preset_list( array( 6432, 'preset_', 'abc', null, array(), 0, -5 ) ) );
$scw_check( 'config() lee el ajuste yith_presets', SCW_YITH_Validator::normalize_preset_list( SCW_Settings::get( 'yith_presets', array() ) ) === SCW_YITH_Validator::presets() );
$scw_check( 'DEFAULT_PRESETS es la lista conocida', array( 6432, 6683, 13269 ) === SCW_YITH_Validator::DEFAULT_PRESETS );

// --- 15. Determinismo y pureza ------------------------------------------------------
echo "\n15. Determinismo y pureza\n";

$scw_html   = $scw_page( $scw_real_container );
$scw_first  = SCW_YITH_Validator::resolve( $scw_html );
$scw_second = SCW_YITH_Validator::resolve( $scw_html );
$scw_third  = SCW_YITH_Validator::resolve( $scw_html );

$scw_check( 'Tres llamadas idénticas devuelven el mismo resultado', $scw_first === $scw_second && $scw_second === $scw_third );

$scw_html_copy = $scw_html;
SCW_YITH_Validator::resolve( $scw_html );
$scw_check( 'resolve() no modifica el HTML recibido', $scw_html_copy === $scw_html );

$scw_stored = get_option( SCW_Settings::OPTION, array() );
SCW_YITH_Validator::resolve( $scw_html );
$scw_check( 'resolve() no altera los ajustes almacenados', $scw_stored === get_option( SCW_Settings::OPTION, array() ) );

$scw_check( 'has_valid_filter() coincide con resolve()["valid"]', SCW_YITH_Validator::has_valid_filter( $scw_html ) === $scw_first['valid'] );
$scw_check( 'has_valid_filter() es false en una página sin filtro', false === SCW_YITH_Validator::has_valid_filter( $scw_page( '<p>Sin filtro</p>' ) ) );

// --- 16. Rendimiento sobre un documento grande --------------------------------------
echo "\n16. Rendimiento sobre un documento grande\n";

$scw_noise = '';

for ( $scw_i = 0; $scw_i < 4000; $scw_i++ ) {
	$scw_noise .= '<li class="product type-product post-' . $scw_i . '"><a href="/producto/sello-' . $scw_i . '/">Sello ' . $scw_i . '</a></li>';
}

$scw_big = $scw_page(
	'<script>var config = {"ajax":"/wp-admin/admin-ajax.php","preset_1111":true};</script>'
	. $scw_real_container
	. '<ul class="products">' . $scw_noise . '</ul>'
);

$scw_start   = microtime( true );
$scw_result  = SCW_YITH_Validator::resolve( $scw_big );
$scw_elapsed = microtime( true ) - $scw_start;

$scw_check( 'Documento grande: se localiza el contenedor', true === $scw_result['valid'] && array( 6432 ) === $scw_result['presets'], 'bytes=' . strlen( $scw_big ) );
$scw_check( 'Documento grande: el preset del script no contamina', array() === $scw_result['presets'] || ! in_array( 1111, $scw_result['presets'], true ) );
$scw_check( 'Documento grande: se resuelve en menos de 1 segundo', $scw_elapsed < 1.0, sprintf( '%.1f ms sobre %d KB', $scw_elapsed * 1000, strlen( $scw_big ) / 1024 ) );

// --- Resumen ------------------------------------------------------------------------
echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n";
echo "PASS: {$scw_pass}\n";
echo "FAIL: {$scw_fail}\n\n";

exit( $scw_fail > 0 ? 1 : 0 );
