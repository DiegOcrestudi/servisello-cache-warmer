<?php
/**
 * TEST 0 · Pipeline de sitemap (F2.4).
 *
 * Ejecuta el pipeline completo sin realizar NINGUNA petición HTTP: el XML de
 * los sitemaps va embebido en el propio test. Incluye una comprobación activa de
 * que no se intenta ninguna petición.
 *
 * Las filas que crea llevan source = 'f2-4-test' y se eliminan al final.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f2-4-sitemap-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Sitemap_Pipeline' ) ) {
	exit( "SCW_Sitemap_Pipeline no está cargada. ¿Está activo el plugin?\n" );
}

$scw_pass   = 0;
$scw_fail   = 0;
$scw_source = 'f2-4-test';

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
 * Construye un urlset con las URLs indicadas.
 *
 * @param array $locs URLs.
 * @return string
 */
$scw_urlset = function ( array $locs ) {
	$body = '';

	foreach ( $locs as $loc ) {
		// El "&" debe ir escapado: un sitemap real escribe &amp; y un XML con
		// "&" crudo es inválido. El bloque 1 lo comprueba explícitamente.
		$body .= "  <url><loc>" . htmlspecialchars( $loc, ENT_QUOTES ) . "</loc><lastmod>2026-01-01</lastmod></url>\n";
	}

	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
		. $body
		. '</urlset>';
};

echo "\n=== F2.4 · TEST 0: pipeline de sitemap ===\n";
echo 'Tabla de cola: ' . SCW_Queue::table() . "\n";

SCW_Queue::delete_by_source( $scw_source );
$scw_initial = SCW_Queue::stats();
echo 'Filas en la cola antes de empezar: ' . $scw_initial['total'] . "\n";

// --- 1. Parser ------------------------------------------------------------------
echo "\n1. Parser XML\n";

$scw_valid_xml = $scw_urlset(
	array(
		'https://www.servisello.es/categoria-producto/design-stamp/',
		'https://www.servisello.es/tienda/sello-a/',
	)
);

$scw_parsed = SCW_Sitemap_Parser::parse( $scw_valid_xml );
$scw_check( 'Un urlset válido se parsea', $scw_parsed['valid'] );
$scw_check( 'Se detecta el tipo urlset', SCW_Sitemap_Parser::TYPE_URLSET === $scw_parsed['type'] );
$scw_check( 'Se extraen los dos <loc>', 2 === $scw_parsed['count'], 'count=' . $scw_parsed['count'] );
$scw_check( 'El primer <loc> es correcto', 'https://www.servisello.es/categoria-producto/design-stamp/' === $scw_parsed['locs'][0] );
$scw_check( 'No es un índice', ! SCW_Sitemap_Parser::is_index( $scw_parsed ) );

$scw_index_xml = '<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://www.servisello.es/post-sitemap.xml</loc></sitemap>
  <sitemap><loc>https://www.servisello.es/page-sitemap.xml</loc></sitemap>
</sitemapindex>';

$scw_index = SCW_Sitemap_Parser::parse( $scw_index_xml );
$scw_check( 'Un sitemapindex se parsea', $scw_index['valid'] );
$scw_check( 'Se identifica como índice', SCW_Sitemap_Parser::is_index( $scw_index ) );
$scw_check( 'Se extraen los sitemaps hijos', 2 === $scw_index['count'], 'count=' . $scw_index['count'] );

// XML sin espacio de nombres.
$scw_no_ns = SCW_Sitemap_Parser::parse( '<urlset><url><loc>https://www.servisello.es/a/</loc></url></urlset>' );
$scw_check( 'Funciona sin espacio de nombres declarado', $scw_no_ns['valid'] && 1 === $scw_no_ns['count'] );

// Entradas defectuosas.
$scw_php_errors = array();

set_error_handler(
	function ( $errno, $errstr ) use ( &$scw_php_errors ) {
		$scw_php_errors[] = $errstr;
		return true;
	}
);

$scw_broken   = SCW_Sitemap_Parser::parse( '<urlset><url><loc>https://x/</loc></urlset>' );
$scw_empty    = SCW_Sitemap_Parser::parse( '' );
$scw_notxml   = SCW_Sitemap_Parser::parse( 'esto no es xml' );
$scw_html     = SCW_Sitemap_Parser::parse( '<html><body><a href="/x/">x</a></body></html>' );
$scw_nonstr   = SCW_Sitemap_Parser::parse( null );

restore_error_handler();

$scw_check( 'XML mal formado devuelve error controlado', ! $scw_broken['valid'] && SCW_Sitemap_Parser::ERROR_MALFORMED === $scw_broken['error'], 'error=' . var_export( $scw_broken['error'], true ) );
$scw_check( 'XML vacío devuelve empty_xml', ! $scw_empty['valid'] && SCW_Sitemap_Parser::ERROR_EMPTY === $scw_empty['error'] );
$scw_check( 'Texto plano devuelve error', ! $scw_notxml['valid'] );
$scw_check( 'HTML devuelve raíz no soportada', ! $scw_html['valid'] && SCW_Sitemap_Parser::ERROR_ROOT === $scw_html['error'], 'error=' . var_export( $scw_html['error'], true ) );
$scw_check( 'Una entrada no-string devuelve empty_xml', ! $scw_nonstr['valid'] && SCW_Sitemap_Parser::ERROR_EMPTY === $scw_nonstr['error'] );
$scw_check( 'Ninguna entrada defectuosa genera warnings', empty( $scw_php_errors ), empty( $scw_php_errors ) ? '' : implode( ' | ', $scw_php_errors ) );

// XXE y bomba de entidades.
$scw_xxe = SCW_Sitemap_Parser::parse(
	'<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset><url><loc>&x;</loc></url></urlset>'
);
$scw_raw_amp = SCW_Sitemap_Parser::parse(
	'<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.servisello.es/c/?a=1&b=2</loc></url></urlset>'
);
$scw_check( 'Un "&" sin escapar invalida el XML y se detecta', ! $scw_raw_amp['valid'] && SCW_Sitemap_Parser::ERROR_MALFORMED === $scw_raw_amp['error'], 'error=' . var_export( $scw_raw_amp['error'], true ) );

$scw_escaped_amp = SCW_Sitemap_Parser::parse(
	'<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.servisello.es/c/?a=1&amp;b=2</loc></url></urlset>'
);
$scw_check( 'Con &amp; escapado se recupera el "&" original', $scw_escaped_amp['valid'] && 'https://www.servisello.es/c/?a=1&b=2' === $scw_escaped_amp['locs'][0], $scw_escaped_amp['valid'] ? $scw_escaped_amp['locs'][0] : 'inválido' );

$scw_check( 'Un documento con DOCTYPE se rechaza', ! $scw_xxe['valid'] && SCW_Sitemap_Parser::ERROR_DOCTYPE === $scw_xxe['error'], 'error=' . var_export( $scw_xxe['error'], true ) );
$scw_check( 'Y no devuelve ningún <loc>', 0 === $scw_xxe['count'] );

// --- 2. Fuentes: type y priority ----------------------------------------------
echo "\n2. Asociación sitemap → type → priority\n";

$scw_types = array(
	'https://www.servisello.es/post-sitemap.xml'        => 'post',
	'https://www.servisello.es/page-sitemap.xml'        => 'page',
	'https://www.servisello.es/product-sitemap1.xml'    => 'product',
	'https://www.servisello.es/product-sitemap5.xml'    => 'product',
	'https://www.servisello.es/category-sitemap.xml'    => 'category',
	'https://www.servisello.es/product_cat-sitemap.xml' => 'product_cat',
);

foreach ( $scw_types as $scw_url => $scw_expected_type ) {
	$scw_check( basename( $scw_url ) . ' → ' . $scw_expected_type, SCW_Sitemap_Sources::type_for_url( $scw_url ) === $scw_expected_type, 'obtenido ' . SCW_Sitemap_Sources::type_for_url( $scw_url ) );
}

$scw_check( 'Un sitemap desconocido da unknown', 'unknown' === SCW_Sitemap_Sources::type_for_url( 'https://www.servisello.es/otra-cosa.xml' ) );
$scw_check( 'El índice no se confunde con un tipo', 'unknown' === SCW_Sitemap_Sources::type_for_url( 'https://www.servisello.es/sitemap_index.xml' ) );

$scw_priorities = array(
	'product_cat' => 100,
	'product'     => 80,
	'page'        => 60,
	'post'        => 40,
);

foreach ( $scw_priorities as $scw_type => $scw_expected_priority ) {
	$scw_check( 'priority(' . $scw_type . ') = ' . $scw_expected_priority, SCW_Sitemap_Sources::priority_for_type( $scw_type ) === $scw_expected_priority, 'obtenida ' . SCW_Sitemap_Sources::priority_for_type( $scw_type ) );
}

$scw_check( 'priority(category) = 50 por decisión documentada', 50 === SCW_Sitemap_Sources::priority_for_type( 'category' ), 'obtenida ' . SCW_Sitemap_Sources::priority_for_type( 'category' ) );
$scw_check(
	'La prioridad de category es configurable sin tocar código',
	77 === SCW_Sitemap_Sources::priority_for_type( 'category', array( 'priorities' => array( 'category' => 77 ) ) )
);
$scw_check(
	'Un mapa explícito sitemap_types gana a la deducción',
	'page' === SCW_Sitemap_Sources::type_for_url(
		'https://www.servisello.es/post-sitemap.xml',
		array( 'sitemap_types' => array( 'https://www.servisello.es/post-sitemap.xml' => 'page' ) )
	)
);

$scw_all = SCW_Sitemap_Sources::all();
$scw_check( 'La configuración real declara 9 sitemaps', 9 === count( $scw_all ), count( $scw_all ) . ' sitemaps' );
$scw_check( 'Todos tienen tipo resuelto distinto de unknown', 0 === count( array_filter( $scw_all, function ( $s ) { return 'unknown' === $s['type']; } ) ) );

// --- 3. Pipeline en dry-run -----------------------------------------------------
echo "\n3. Pipeline en dry-run\n";

$scw_mixed_xml = $scw_urlset(
	array(
		'https://www.servisello.es/categoria-producto/design-stamp/',
		'HTTP://WWW.SERVISELLO.ES/categoria-producto/design-stamp',        // misma URL tras normalizar
		'https://www.servisello.es/categoria/?utm_source=google&yith_wcan=1', // parámetros ignorados por F2.2
		'https://www.servisello.es/carrito/',                              // excluida por F2.3
		'https://www.servisello.es/mi-cuenta/pedidos/',                    // excluida por prefijo
		'https://evil.com/categoria/',                                     // host ajeno
		'no-es-una-url',                                                   // inválida
		'https://usuario:clave@www.servisello.es/x/',                      // userinfo
	)
);

$scw_pipeline = new SCW_Sitemap_Pipeline( array( 'dry_run' => true ) );
$scw_report   = $scw_pipeline->process(
	$scw_mixed_xml,
	array(
		'sitemap_url' => 'https://www.servisello.es/product_cat-sitemap.xml',
		'source'      => $scw_source,
	)
);

$scw_check( 'Se han parseado 8 <loc>', 8 === $scw_report['parsed'], 'parsed=' . $scw_report['parsed'] );
$scw_check( 'El tipo se ha deducido del sitemap', 'product_cat' === $scw_report['type'], 'type=' . $scw_report['type'] );
$scw_check( 'La prioridad se ha resuelto', 100 === $scw_report['priority'], 'priority=' . $scw_report['priority'] );
$scw_check( 'Dry-run: 2 URLs se encolarían', 2 === $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_WOULD_ENQUEUE ], 'would_enqueue=' . $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_WOULD_ENQUEUE ] );
$scw_check( 'Dry-run: 1 duplicada dentro del sitemap', 1 === $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE ], 'duplicate=' . $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE ] );
$scw_check( 'Dry-run: 2 excluidas', 2 === $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_EXCLUDED ], 'excluded=' . $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_EXCLUDED ] );
$scw_check( 'Dry-run: 3 inválidas o no procesables', 3 === ( $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_INVALID ] + $scw_report['counts'][ SCW_Sitemap_Pipeline::DECISION_NOT_PROCESSABLE ] ) );
$scw_check( 'Dry-run: la cola sigue vacía de filas de prueba', 0 === SCW_Queue::stats()['total'] - $scw_initial['total'] );

// Normalización aplicada y motivos identificables.
$scw_by_loc = array();

foreach ( $scw_report['items'] as $scw_item ) {
	$scw_by_loc[ $scw_item['loc'] ] = $scw_item;
}

/**
 * Acceso seguro a un campo del informe por <loc>.
 *
 * @param string $loc   Loc.
 * @param string $field Campo.
 * @return mixed
 */
$scw_field = function ( $loc, $field ) use ( $scw_by_loc ) {
	return isset( $scw_by_loc[ $loc ][ $field ] ) ? $scw_by_loc[ $loc ][ $field ] : null;
};

$scw_check( 'El informe detalla las 8 URLs', 8 === count( $scw_by_loc ), count( $scw_by_loc ) . ' items' );

$scw_check(
	'La URL en mayúsculas se normalizó a la canónica',
	'https://www.servisello.es/categoria-producto/design-stamp/' === $scw_field( 'HTTP://WWW.SERVISELLO.ES/categoria-producto/design-stamp', 'url' )
);
$scw_check(
	'Y por eso se marcó como duplicada dentro de la ejecución',
	SCW_Sitemap_Pipeline::DECISION_DUPLICATE === $scw_field( 'HTTP://WWW.SERVISELLO.ES/categoria-producto/design-stamp', 'decision' )
		&& 'duplicate_in_run' === $scw_field( 'HTTP://WWW.SERVISELLO.ES/categoria-producto/design-stamp', 'reason' )
);
$scw_check(
	'F2.2 eliminó utm_source y yith_wcan',
	'https://www.servisello.es/categoria/' === $scw_field( 'https://www.servisello.es/categoria/?utm_source=google&yith_wcan=1', 'url' ),
	(string) $scw_field( 'https://www.servisello.es/categoria/?utm_source=google&yith_wcan=1', 'url' )
);
$scw_check(
	'La exclusión indica motivo y patrón',
	'excluded_prefix' === $scw_field( 'https://www.servisello.es/carrito/', 'reason' )
		&& '/carrito/' === $scw_field( 'https://www.servisello.es/carrito/', 'pattern' ),
	var_export( $scw_field( 'https://www.servisello.es/carrito/', 'reason' ), true ) . ' / ' . var_export( $scw_field( 'https://www.servisello.es/carrito/', 'pattern' ), true )
);
$scw_check( 'El host ajeno se descarta como no procesable', 'host_not_allowed' === $scw_field( 'https://evil.com/categoria/', 'reason' ) );
$scw_check( 'La URL inválida se descarta con su motivo', 'invalid_url' === $scw_field( 'no-es-una-url', 'reason' ) );
$scw_check( 'El userinfo se descarta', 'userinfo_not_allowed' === $scw_field( 'https://usuario:clave@www.servisello.es/x/', 'reason' ) );

// Dedupe entre sitemaps distintos, dentro de la misma ejecución.
$scw_second = $scw_pipeline->process(
	$scw_urlset( array( 'https://www.servisello.es/categoria-producto/design-stamp/' ) ),
	array(
		'sitemap_url' => 'https://www.servisello.es/product-sitemap1.xml',
		'source'      => $scw_source,
	)
);
$scw_check( 'Dedupe entre dos sitemaps distintos', 1 === $scw_second['counts'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE ] );
$scw_check( 'El acumulado refleja ambas ejecuciones', 9 === $scw_pipeline->totals()['parsed'], 'parsed=' . $scw_pipeline->totals()['parsed'] );

// --- 4. Índice de sitemaps -------------------------------------------------------
echo "\n4. Un índice no se trata como contenido\n";
$scw_index_pipeline = new SCW_Sitemap_Pipeline( array( 'dry_run' => false ) );
$scw_index_report   = $scw_index_pipeline->process(
	$scw_index_xml,
	array(
		'sitemap_url' => 'https://www.servisello.es/sitemap_index.xml',
		'source'      => $scw_source,
	)
);

$scw_check( 'Se marca como índice', true === $scw_index_report['is_index'] );
$scw_check( 'No se encola ninguna URL', 0 === $scw_index_report['counts'][ SCW_Sitemap_Pipeline::DECISION_ENQUEUED ] );
$scw_check( 'Los sitemaps hijos se devuelven aparte', 2 === count( $scw_index_report['child_sitemaps'] ) );
$scw_check( 'El motivo es sitemap_index', SCW_Sitemap_Pipeline::REASON_SITEMAP_INDEX === $scw_index_report['error'] );
$scw_check( 'La cola no ha cambiado', 0 === SCW_Queue::stats()['total'] - $scw_initial['total'] );

// --- 5. Encolado real -------------------------------------------------------------
echo "\n5. Encolado real\n";
$scw_enqueue_pipeline = new SCW_Sitemap_Pipeline( array( 'dry_run' => false ) );
$scw_enqueue_report   = $scw_enqueue_pipeline->process(
	$scw_mixed_xml,
	array(
		'sitemap_url' => 'https://www.servisello.es/product_cat-sitemap.xml',
		'source'      => $scw_source,
	)
);

$scw_check( 'Se han encolado 2 URLs', 2 === $scw_enqueue_report['counts'][ SCW_Sitemap_Pipeline::DECISION_ENQUEUED ], 'enqueued=' . $scw_enqueue_report['counts'][ SCW_Sitemap_Pipeline::DECISION_ENQUEUED ] );
$scw_check( 'La cola tiene 2 filas de prueba más', 2 === SCW_Queue::stats()['total'] - $scw_initial['total'] );

$scw_row = SCW_Queue::find_by_url( 'https://www.servisello.es/categoria-producto/design-stamp/' );
$scw_check( 'La fila existe', (bool) $scw_row );
$scw_check( 'Estado pending', $scw_row && SCW_Queue::STATUS_PENDING === $scw_row['status'], $scw_row ? $scw_row['status'] : '-' );
$scw_check( 'Type product_cat', $scw_row && 'product_cat' === $scw_row['type'], $scw_row ? $scw_row['type'] : '-' );
$scw_check( 'Priority 100', $scw_row && 100 === (int) $scw_row['priority'], $scw_row ? $scw_row['priority'] : '-' );
$scw_check( 'Source registrado', $scw_row && $scw_source === $scw_row['source'] );
$scw_check( 'attempts a 0', $scw_row && 0 === (int) $scw_row['attempts'] );
$scw_check( 'Sin lock', $scw_row && null === $scw_row['lock_token'] && null === $scw_row['locked_at'] );

// Segunda pasada: todo duplicado, ninguna fila nueva.
$scw_again = new SCW_Sitemap_Pipeline( array( 'dry_run' => false ) );
$scw_again_report = $scw_again->process(
	$scw_mixed_xml,
	array(
		'sitemap_url' => 'https://www.servisello.es/product_cat-sitemap.xml',
		'source'      => $scw_source,
	)
);

$scw_check( 'Segunda pasada: 0 encoladas', 0 === $scw_again_report['counts'][ SCW_Sitemap_Pipeline::DECISION_ENQUEUED ] );
// Tres duplicadas: la repetida dentro del propio sitemap más las dos que ya
// estaban en la cola desde la pasada anterior.
$scw_check( 'Segunda pasada: 3 duplicadas (1 en ejecución + 2 ya en cola)', 3 === $scw_again_report['counts'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE ], 'duplicate=' . $scw_again_report['counts'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE ] );
$scw_check( 'Y las dos ya en cola se identifican como tales', 2 === ( isset( $scw_again_report['by_reason'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE . ':already_in_queue' ] ) ? $scw_again_report['by_reason'][ SCW_Sitemap_Pipeline::DECISION_DUPLICATE . ':already_in_queue' ] : 0 ) );
$scw_check( 'La cola sigue con 2 filas de prueba', 2 === SCW_Queue::stats()['total'] - $scw_initial['total'] );

// Un tipo distinto encola con su prioridad.
$scw_page_pipeline = new SCW_Sitemap_Pipeline( array( 'dry_run' => false ) );
$scw_page_pipeline->process(
	$scw_urlset( array( 'https://www.servisello.es/contacto/' ) ),
	array(
		'sitemap_url' => 'https://www.servisello.es/page-sitemap.xml',
		'source'      => $scw_source,
	)
);
$scw_page_row = SCW_Queue::find_by_url( 'https://www.servisello.es/contacto/' );
$scw_check( 'Una página se encola como page con prioridad 60', $scw_page_row && 'page' === $scw_page_row['type'] && 60 === (int) $scw_page_row['priority'], $scw_page_row ? $scw_page_row['type'] . '/' . $scw_page_row['priority'] : '-' );

// --- 6. Sin HTTP y sin tocar el planificador -------------------------------------
echo "\n6. Ausencia de HTTP y de efectos sobre el planificador\n";

$GLOBALS['scw_http_attempts'] = 0;

$scw_http_guard = function ( $preempt ) {
	$GLOBALS['scw_http_attempts']++;
	return new WP_Error( 'scw_test_blocked', 'Petición HTTP bloqueada por el test.' );
};

add_filter( 'pre_http_request', $scw_http_guard, 1 );

$scw_tick_before     = wp_next_scheduled( 'scw_tick' );
$scw_watchdog_before = wp_next_scheduled( 'scw_watchdog' );
$scw_status_before   = SCW_State::get( 'run_status' );

$scw_guarded = new SCW_Sitemap_Pipeline( array( 'dry_run' => false ) );
$scw_guarded->process(
	$scw_urlset( array( 'https://www.servisello.es/prueba-http/' ) ),
	array(
		'sitemap_url' => 'https://www.servisello.es/page-sitemap.xml',
		'source'      => $scw_source,
	)
);

remove_filter( 'pre_http_request', $scw_http_guard, 1 );

$scw_check( 'El pipeline no ha intentado ninguna petición HTTP', 0 === $GLOBALS['scw_http_attempts'], $GLOBALS['scw_http_attempts'] . ' intentos' );
$scw_check( 'scw_tick sigue igual', wp_next_scheduled( 'scw_tick' ) === $scw_tick_before );
$scw_check( 'scw_watchdog sigue igual', wp_next_scheduled( 'scw_watchdog' ) === $scw_watchdog_before );
$scw_check( 'run_status sigue igual', SCW_State::get( 'run_status' ) === $scw_status_before, SCW_State::get( 'run_status' ) );

// Comprobación estática: las clases de F2.4 no contienen ninguna API de red.
$scw_files = array(
	'class-scw-sitemap-parser.php',
	'class-scw-sitemap-sources.php',
	'class-scw-sitemap-pipeline.php',
);

$scw_net_free = true;

foreach ( $scw_files as $scw_file ) {
	$scw_code = (string) file_get_contents( SCW_PLUGIN_DIR . 'includes/crawler/' . $scw_file );

	// Se descartan comentarios y cadenas: sólo interesa el código ejecutable, no
	// que la documentación mencione el nombre de una función de red.
	$scw_executable = '';

	foreach ( token_get_all( $scw_code ) as $scw_token ) {
		if ( is_array( $scw_token ) ) {
			if ( in_array( $scw_token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML ), true ) ) {
				continue;
			}

			$scw_executable .= $scw_token[1] . ' ';
			continue;
		}

		$scw_executable .= $scw_token . ' ';
	}

	if ( preg_match( '/wp_remote_|wp_safe_remote_|curl_init|curl_exec|curl_multi|fsockopen|stream_socket_client|gethostby|dns_get_record|file_get_contents|fopen|readfile/i', $scw_executable ) ) {
		$scw_net_free = false;
	}
}

$scw_check( 'Las clases de F2.4 no contienen ninguna API de red', $scw_net_free );

// --- 7. Limpieza -----------------------------------------------------------------
echo "\n7. Limpieza\n";
$scw_deleted = SCW_Queue::delete_by_source( $scw_source );
$scw_final   = SCW_Queue::stats();

$scw_check( 'Se eliminan las filas de prueba', $scw_deleted > 0, $scw_deleted . ' filas' );
$scw_check( 'La cola queda como estaba', (int) $scw_final['total'] === (int) $scw_initial['total'], 'total=' . $scw_final['total'] );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
