<?php
/**
 * TEST · Perfil de expectativa de contenido (F4.2).
 *
 * No hace ninguna petición de red, no escribe en ninguna tabla y no modifica
 * ningún ajuste: SCW_Page_Profile es una función pura sobre el path de la URL
 * y la configuración.
 *
 * Las listas de rutas se pasan como sobrescrituras explícitas en casi todas
 * las comprobaciones, para que el resultado no dependa de cómo esté
 * configurado el sitio donde se ejecute el test. Hay secciones aparte que
 * comprueban que config() sí lee los ajustes reales y que las rutas conocidas
 * siguen viniendo de la configuración y no del código de la clase.
 *
 * Ejecución desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-2-page-profile-test.php
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Page_Profile' ) ) {
	exit( "SCW_Page_Profile no está cargada. ¿Está activo el plugin?\n" );
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

// Host permitido real del sitio, para construir URLs absolutas coherentes.
$scw_normalizer_config = SCW_URL_Normalizer::config();
$scw_host              = $scw_normalizer_config['allowed_host'];

if ( '' === $scw_host ) {
	exit( "No hay allowed_host configurado: este test necesita uno para construir URLs.\n" );
}

/**
 * Construye una URL absoluta del host permitido.
 *
 * @param string $path Path y, opcionalmente, query/fragmento.
 * @return string
 */
$scw_url = function ( $path ) use ( $scw_host ) {
	return 'https://' . $scw_host . $path;
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

// Rutas conocidas, escritas aquí como DATOS DEL TEST. Sirven para comprobar
// que siguen estando en la configuración del plugin; la clase no las conoce.
$scw_known_requires = array(
	'/categoria-producto/design-stamp/exlibris/exlibris-anime/',
	'/categoria-producto/design-stamp/exlibris/exlibris-batidogs/',
	'/categoria-producto/design-stamp/exlibris/exlibris-disenos/',
	'/categoria-producto/design-stamp/exlibris/exlibris-portadas-libros/',
	'/categoria-producto/design-stamp/exlibris/exlibris-sushicats/',
	'/categoria-producto/design-stamp/exlibris/exlibris-estandar/',
	'/categoria-producto/design-stamp/eventos/sellos-aniversario/',
	'/categoria-producto/design-stamp/eventos/sellos-bautizos/',
	'/categoria-producto/design-stamp/eventos/sello-bodas/',
	'/categoria-producto/design-stamp/eventos/sellos-comunion/',
	'/categoria-producto/design-stamp/eventos/sello-fiesta/',
	'/categoria-producto/design-stamp/eventos/eventos-estandar/',
	'/categoria-producto/accesorios-para-sellos/tampones/',
);

$scw_known_no_yith = array(
	'/categoria-producto/design-stamp/exlibris/',
	'/categoria-producto/design-stamp/eventos/',
);

// Configuración de referencia para las comprobaciones deterministas.
$scw_cfg = array(
	'profiles_requires_yith' => $scw_known_requires,
	'profiles_no_yith'       => $scw_known_no_yith,
);

echo "\n=== F4.2 · SCW_Page_Profile ===\n";

// --- 1. Forma del resultado ---------------------------------------------------------
echo "\n1. Forma del resultado\n";

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' ), $scw_cfg );

$scw_expected_keys = array( 'profile', 'matched', 'path', 'pattern', 'source', 'processable', 'error', 'error_message', 'warnings' );
$scw_missing       = array();

foreach ( $scw_expected_keys as $scw_key ) {
	if ( ! array_key_exists( $scw_key, $scw_result ) ) {
		$scw_missing[] = $scw_key;
	}
}

$scw_check( 'El resultado contiene todas las claves esperadas', empty( $scw_missing ), empty( $scw_missing ) ? '' : 'faltan: ' . implode( ', ', $scw_missing ) );
$scw_check( 'profile es uno de los tres perfiles', in_array( $scw_result['profile'], SCW_Page_Profile::profiles(), true ), 'profile=' . $scw_result['profile'] );
$scw_check( 'matched = true cuando hay coincidencia', true === $scw_result['matched'] );
$scw_check( 'path es el path canónico (sin barra final)', '/categoria-producto/accesorios-para-sellos/tampones' === $scw_result['path'], 'path=' . var_export( $scw_result['path'], true ) );
$scw_check( 'source identifica el ajuste de origen', SCW_Page_Profile::SOURCE_REQUIRES_YITH === $scw_result['source'], 'source=' . var_export( $scw_result['source'], true ) );
$scw_check( 'processable = true', true === $scw_result['processable'] );
$scw_check( 'Sin error', null === $scw_result['error'] );
$scw_check( 'Sin avisos con una configuración correcta', empty( $scw_result['warnings'] ) );
$scw_check( 'profiles() devuelve exactamente los tres perfiles', 3 === count( SCW_Page_Profile::profiles() ) );

// --- 2. Todas las rutas REQUIRES_YITH ----------------------------------------------
echo "\n2. Rutas configuradas como REQUIRES_YITH\n";

foreach ( $scw_known_requires as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_cfg );
	$scw_check( 'REQUIRES_YITH: ' . $scw_path, SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

// --- 3. Todas las rutas NO_YITH -----------------------------------------------------
echo "\n3. Rutas configuradas como NO_YITH\n";

foreach ( $scw_known_no_yith as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_cfg );
	$scw_check( 'NO_YITH: ' . $scw_path, SCW_Page_Profile::PROFILE_NO_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

// --- 4. Padre NO_YITH frente a hija REQUIRES_YITH -----------------------------------
echo "\n4. Conflicto padre/hija: la hija configurada debe ganar su propio perfil\n";

$scw_parent = '/categoria-producto/design-stamp/exlibris/';
$scw_child  = '/categoria-producto/design-stamp/exlibris/exlibris-anime/';

$scw_result_parent = SCW_Page_Profile::resolve( $scw_url( $scw_parent ), $scw_cfg );
$scw_result_child  = SCW_Page_Profile::resolve( $scw_url( $scw_child ), $scw_cfg );

$scw_check( 'El padre sigue siendo NO_YITH', SCW_Page_Profile::PROFILE_NO_YITH === $scw_result_parent['profile'], 'profile=' . $scw_result_parent['profile'] );
$scw_check( 'La hija es REQUIRES_YITH pese al padre NO_YITH', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result_child['profile'], 'profile=' . $scw_result_child['profile'] );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/eventos/sello-bodas/' ), $scw_cfg );
$scw_check( 'Lo mismo con eventos/sello-bodas', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

// Nieta no configurada: no hereda nada de ninguno de sus ancestros.
$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/exlibris/exlibris-anime/pagina/2/' ), $scw_cfg );
$scw_check( 'Una ruta más profunda no configurada es UNVERIFIED (no hereda)', SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

// --- 5. Ruta desconocida ------------------------------------------------------------
echo "\n5. Rutas sin expectativa declarada\n";

foreach ( array( '/', '/tienda/', '/producto/sello-personalizado/', '/blog/como-elegir-un-sello/', '/categoria-producto/' ) as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_cfg );
	$scw_check( 'UNVERIFIED: ' . $scw_path, SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/tienda/' ), $scw_cfg );
$scw_check( 'UNVERIFIED no es un error: processable = true', true === $scw_result['processable'] );
$scw_check( 'UNVERIFIED no trae patrón ni origen', null === $scw_result['pattern'] && null === $scw_result['source'] );
$scw_check( 'UNVERIFIED tiene matched = false', false === $scw_result['matched'] );

// --- 6. Barra final -----------------------------------------------------------------
echo "\n6. Barra final\n";

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones' ), $scw_cfg );
$scw_check( 'URL sin barra final contra patrón con barra -> coincide', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

$scw_result = SCW_Page_Profile::resolve(
	$scw_url( '/mi-ruta/' ),
	array( 'profiles_requires_yith' => array( '/mi-ruta' ), 'profiles_no_yith' => array() )
);
$scw_check( 'URL con barra final contra patrón sin barra -> coincide', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

$scw_result = SCW_Page_Profile::resolve(
	$scw_url( '/' ),
	array( 'profiles_no_yith' => array( '/' ), 'profiles_requires_yith' => array() )
);
$scw_check( 'La raíz "/" se puede configurar y coincide', SCW_Page_Profile::PROFILE_NO_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

$scw_result = SCW_Page_Profile::resolve( 'https://' . $scw_host, $scw_cfg );
$scw_check( 'URL sin path se evalúa como "/"', '/' === $scw_result['path'], 'path=' . var_export( $scw_result['path'], true ) );

// --- 7. Query string y fragmento ----------------------------------------------------
echo "\n7. Query string y fragmento se ignoran\n";

$scw_variants = array(
	'?yith_wcan=1',
	'?filter_color=rojo&filter_talla=m',
	'?utm_source=newsletter',
	'#contenido',
	'?orderby=price#top',
);

foreach ( $scw_variants as $scw_suffix ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' . $scw_suffix ), $scw_cfg );
	$scw_check( 'Sigue siendo REQUIRES_YITH con ' . $scw_suffix, SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/exlibris/?yith_wcan=1' ), $scw_cfg );
$scw_check( 'El query string tampoco altera un NO_YITH', SCW_Page_Profile::PROFILE_NO_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

// --- 8. Coincidencias parciales que NO deben coincidir ------------------------------
echo "\n8. Coincidencias parciales: no debe haber ninguna\n";

$scw_partials = array(
	'/categoria-producto/accesorios-para-sellos/tampones-de-tinta/',
	'/categoria-producto/accesorios-para-sellos/tampones/subcategoria/',
	'/categoria-producto/accesorios-para-sellos/tampon/',
	'/categoria-producto/accesorios-para-sellos/',
	'/accesorios-para-sellos/tampones/',
	'/categoria-producto/design-stamp/exlibris-anime/',
	'/categoria-producto/design-stamp/exlibris/exlibris-anime-2/',
	'/categoria-producto/design-stamp/exlibrisx/',
);

foreach ( $scw_partials as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_cfg );
	$scw_check( 'Sin match parcial: ' . $scw_path, SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

// --- 9. Configuración vacía ---------------------------------------------------------
echo "\n9. Configuración vacía\n";

$scw_empty = array( 'profiles_requires_yith' => array(), 'profiles_no_yith' => array() );

foreach ( array( $scw_known_requires[0], $scw_known_no_yith[0], '/tienda/' ) as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_empty );
	$scw_check( 'Sin reglas todo es UNVERIFIED: ' . $scw_path, SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
}

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/tienda/' ), $scw_empty );
$scw_check( 'Sin reglas no se generan avisos', empty( $scw_result['warnings'] ) );
$scw_check( 'Sin reglas sigue siendo procesable', true === $scw_result['processable'] );
$scw_check( 'Sin reglas el path se sigue resolviendo', '/tienda' === $scw_result['path'], 'path=' . var_export( $scw_result['path'], true ) );

// --- 10. Las rutas son configuración, no código -------------------------------------
echo "\n10. Las rutas conocidas viven en la configuración, no en la clase\n";

$scw_defaults = SCW_Settings::defaults();

$scw_missing_defaults = array_diff( $scw_known_requires, (array) $scw_defaults['profiles_requires_yith'] );
$scw_check( 'Las 13 rutas REQUIRES_YITH están en los ajustes por defecto', empty( $scw_missing_defaults ), empty( $scw_missing_defaults ) ? '' : 'faltan: ' . implode( ', ', $scw_missing_defaults ) );

$scw_missing_defaults = array_diff( $scw_known_no_yith, (array) $scw_defaults['profiles_no_yith'] );
$scw_check( 'Las 2 rutas NO_YITH están en los ajustes por defecto', empty( $scw_missing_defaults ), empty( $scw_missing_defaults ) ? '' : 'faltan: ' . implode( ', ', $scw_missing_defaults ) );

// La prueba de fuego del "sin hardcodeo": con las listas vacías, ninguna ruta
// conocida debe obtener perfil. Si la clase las conociera, esto fallaría.
$scw_hardcoded = false;

foreach ( array_merge( $scw_known_requires, $scw_known_no_yith ) as $scw_path ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_url( $scw_path ), $scw_empty );

	if ( SCW_Page_Profile::PROFILE_UNVERIFIED !== $scw_result['profile'] ) {
		$scw_hardcoded = true;
	}
}

$scw_check( 'Ninguna ruta de Servisello está hardcodeada en SCW_Page_Profile', ! $scw_hardcoded );

// Una configuración inventada funciona igual de bien: la clase es genérica.
$scw_result = SCW_Page_Profile::resolve(
	$scw_url( '/una/ruta/cualquiera/' ),
	array( 'profiles_requires_yith' => array( '/una/ruta/cualquiera/' ), 'profiles_no_yith' => array() )
);
$scw_check( 'Una ruta arbitraria configurada obtiene su perfil', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'] );

// --- 11. config() lee los ajustes reales --------------------------------------------
echo "\n11. Configuración efectiva\n";

$scw_config = SCW_Page_Profile::config();

$scw_check( 'config() lee profiles_requires_yith de los ajustes', (array) SCW_Settings::get( 'profiles_requires_yith', array() ) === $scw_config['profiles_requires_yith'] );
$scw_check( 'config() lee profiles_no_yith de los ajustes', (array) SCW_Settings::get( 'profiles_no_yith', array() ) === $scw_config['profiles_no_yith'] );
$scw_check( 'config() toma allowed_host del normalizador', $scw_host === $scw_config['allowed_host'], 'allowed_host=' . $scw_config['allowed_host'] );

$scw_config = SCW_Page_Profile::config( array( 'profiles_no_yith' => array( '/x/' ) ) );
$scw_check( 'Las sobrescrituras tienen prioridad', array( '/x/' ) === $scw_config['profiles_no_yith'] );
$scw_check( 'Una sobrescritura parcial no borra la otra lista', (array) SCW_Settings::get( 'profiles_requires_yith', array() ) === $scw_config['profiles_requires_yith'] );

// --- 12. Varias reglas, orden y conflicto -------------------------------------------
echo "\n12. Varias reglas, orden y conflicto entre listas\n";

$scw_many = array(
	'profiles_requires_yith' => array( '/a/', '/b/', '/c/', '/d/', '/e/' ),
	'profiles_no_yith'       => array( '/f/', '/g/', '/h/' ),
);

$scw_check( 'Primera regla de la lista', SCW_Page_Profile::PROFILE_REQUIRES_YITH === SCW_Page_Profile::profile( $scw_url( '/a/' ), $scw_many ) );
$scw_check( 'Última regla de la lista', SCW_Page_Profile::PROFILE_REQUIRES_YITH === SCW_Page_Profile::profile( $scw_url( '/e/' ), $scw_many ) );
$scw_check( 'Regla intermedia de la segunda lista', SCW_Page_Profile::PROFILE_NO_YITH === SCW_Page_Profile::profile( $scw_url( '/g/' ), $scw_many ) );

$scw_reordered = array(
	'profiles_requires_yith' => array_reverse( $scw_many['profiles_requires_yith'] ),
	'profiles_no_yith'       => array_reverse( $scw_many['profiles_no_yith'] ),
);

$scw_stable = true;

foreach ( array( '/a/', '/b/', '/c/', '/d/', '/e/', '/f/', '/g/', '/h/', '/z/' ) as $scw_path ) {
	if ( SCW_Page_Profile::profile( $scw_url( $scw_path ), $scw_many ) !== SCW_Page_Profile::profile( $scw_url( $scw_path ), $scw_reordered ) ) {
		$scw_stable = false;
	}
}

$scw_check( 'El orden de las listas no altera el resultado', $scw_stable );

$scw_conflict = array(
	'profiles_requires_yith' => array( '/conflicto/' ),
	'profiles_no_yith'       => array( '/conflicto/' ),
);

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/conflicto/' ), $scw_conflict );
$scw_check( 'Una ruta en ambas listas se resuelve como REQUIRES_YITH', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
$scw_check( 'El conflicto queda registrado como aviso', $scw_has_warning( $scw_result['warnings'], SCW_Page_Profile::WARN_CONFLICT ) );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/sin-conflicto/' ), $scw_conflict );
$scw_check( 'Otra URL no arrastra el aviso de conflicto', ! $scw_has_warning( $scw_result['warnings'], SCW_Page_Profile::WARN_CONFLICT ) );

// --- 13. Patrones mal formados ------------------------------------------------------
echo "\n13. Patrones mal formados\n";

$scw_bad = array(
	'profiles_requires_yith' => array(
		'',
		'   ',
		'categoria-producto/sin-barra/',
		'https://' . $scw_host . '/una-url-completa/',
		'/con-query/?a=1',
		'/con-fragmento/#x',
		null,
		123,
		array( 'anidado' ),
		'/valida/',
	),
	'profiles_no_yith'       => array(),
);

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/valida/' ), $scw_bad );
$scw_check( 'Los patrones defectuosos no impiden que el válido coincida', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'], 'profile=' . $scw_result['profile'] );
$scw_check( 'Se registran avisos de patrones vacíos', $scw_has_warning( $scw_result['warnings'], SCW_Page_Profile::WARN_EMPTY_PATTERN ) );
$scw_check( 'Se registran avisos de patrones no absolutos', $scw_has_warning( $scw_result['warnings'], SCW_Page_Profile::WARN_NOT_ABSOLUTE ) );
$scw_check( 'Se registran avisos de patrones que no son un path', $scw_has_warning( $scw_result['warnings'], SCW_Page_Profile::WARN_NOT_A_PATH ) );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/con-query/' ), $scw_bad );
$scw_check( 'Un patrón con query string no llega a coincidir', SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/una-url-completa/' ), $scw_bad );
$scw_check( 'Un patrón escrito como URL completa no coincide', SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'], 'profile=' . $scw_result['profile'] );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/tienda/' ), array( 'profiles_requires_yith' => 'no-es-un-array', 'profiles_no_yith' => null ) );
$scw_check( 'Unas listas que no son arrays no rompen la llamada', is_array( $scw_result ) && SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'] );

$scw_warnings = SCW_Page_Profile::validate_patterns( $scw_bad );
$scw_check( 'validate_patterns() detecta los patrones defectuosos sin evaluar ninguna URL', count( $scw_warnings ) >= 8, 'avisos=' . count( $scw_warnings ) );

$scw_warnings = SCW_Page_Profile::validate_patterns( $scw_conflict );
$scw_check( 'validate_patterns() detecta la ruta declarada en las dos listas', $scw_has_warning( $scw_warnings, SCW_Page_Profile::WARN_CONFLICT ) );

$scw_warnings = SCW_Page_Profile::validate_patterns( $scw_cfg );
$scw_check( 'La configuración conocida de Servisello no produce ningún aviso', empty( $scw_warnings ), 'avisos=' . count( $scw_warnings ) );

// --- 14. URLs no utilizables --------------------------------------------------------
echo "\n14. URLs no utilizables\n";

$scw_bad_urls = array(
	'vacía'           => '',
	'sólo espacios'   => '   ',
	'relativa'        => '/categoria-producto/accesorios-para-sellos/tampones/',
	'sin esquema'     => $scw_host . '/tampones/',
	'otro host'       => 'https://otro-dominio.example/categoria-producto/accesorios-para-sellos/tampones/',
	'esquema ftp'     => 'ftp://' . $scw_host . '/tampones/',
	'no es cadena'    => null,
	'array'           => array(),
);

foreach ( $scw_bad_urls as $scw_label => $scw_bad_url ) {
	$scw_result = SCW_Page_Profile::resolve( $scw_bad_url, $scw_cfg );

	$scw_check(
		"URL {$scw_label}: perfil UNVERIFIED y processable = false",
		SCW_Page_Profile::PROFILE_UNVERIFIED === $scw_result['profile'] && false === $scw_result['processable'],
		'profile=' . $scw_result['profile'] . ' processable=' . var_export( $scw_result['processable'], true )
	);
}

$scw_result = SCW_Page_Profile::resolve( 'https://otro-dominio.example/tampones/', $scw_cfg );
$scw_check( 'Un host distinto produce el código de error del normalizador', SCW_URL_Normalizer::ERROR_HOST === $scw_result['error'], 'error=' . var_export( $scw_result['error'], true ) );
$scw_check( 'Una URL no utilizable trae mensaje de error legible', is_string( $scw_result['error_message'] ) && '' !== $scw_result['error_message'] );

$scw_result = SCW_Page_Profile::resolve( 'https://' . $scw_host . '/' . str_repeat( 'x', SCW_URL_Normalizer::MAX_URL_LENGTH ), $scw_cfg );
$scw_check( 'Una URL demasiado larga se rechaza', SCW_URL_Normalizer::ERROR_TOO_LONG === $scw_result['error'], 'error=' . var_export( $scw_result['error'], true ) );

// --- 15. Determinismo y pureza ------------------------------------------------------
echo "\n15. Determinismo y pureza\n";

$scw_first  = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/eventos/sello-bodas/' ), $scw_cfg );
$scw_second = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/eventos/sello-bodas/' ), $scw_cfg );
$scw_third  = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/design-stamp/eventos/sello-bodas/' ), $scw_cfg );

$scw_check( 'Tres llamadas idénticas devuelven el mismo resultado', $scw_first === $scw_second && $scw_second === $scw_third );

$scw_cfg_copy = $scw_cfg;
SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' ), $scw_cfg );
$scw_check( 'resolve() no modifica la configuración recibida', $scw_cfg_copy === $scw_cfg );

$scw_stored = get_option( SCW_Settings::OPTION, array() );
SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' ) );
$scw_check( 'resolve() no altera los ajustes almacenados', $scw_stored === get_option( SCW_Settings::OPTION, array() ) );

$scw_result = SCW_Page_Profile::resolve( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' ), $scw_cfg );
$scw_check( 'profile() coincide con resolve()["profile"]', SCW_Page_Profile::profile( $scw_url( '/categoria-producto/accesorios-para-sellos/tampones/' ), $scw_cfg ) === $scw_result['profile'] );

// --- 16. Rendimiento con una lista grande -------------------------------------------
echo "\n16. Rendimiento con una lista grande\n";

$scw_big = array();

for ( $scw_i = 0; $scw_i < 5000; $scw_i++ ) {
	$scw_big[] = '/ruta-generada-' . $scw_i . '/';
}

$scw_big_cfg = array( 'profiles_requires_yith' => $scw_big, 'profiles_no_yith' => array() );

$scw_start   = microtime( true );
$scw_result  = SCW_Page_Profile::resolve( $scw_url( '/ruta-generada-4999/' ), $scw_big_cfg );
$scw_elapsed = microtime( true ) - $scw_start;

$scw_check( 'Coincide con la última de 5000 reglas', SCW_Page_Profile::PROFILE_REQUIRES_YITH === $scw_result['profile'] );
$scw_check( 'Se resuelve en menos de 1 segundo', $scw_elapsed < 1.0, sprintf( '%.1f ms', $scw_elapsed * 1000 ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
