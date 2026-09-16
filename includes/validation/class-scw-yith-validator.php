<?php
/**
 * Evidencia del filtro YITH en el HTML recibido.
 *
 * Responsabilidad única: mirar un HTML y describir QUÉ EVIDENCIA del filtro
 * YITH WooCommerce Ajax Product Filter contiene. Nada más.
 *
 * Esta clase NO decide si una página es correcta, sospechosa o errónea, y no
 * sabe si la URL debería o no llevar filtros: eso lo determina
 * SCW_Page_Profile (F4.2) y lo combina SCW_Content_Validator (F4.4). Aquí sólo
 * se recoge evidencia.
 *
 * Ausencia de YITH NO es un error. Hay categorías del sitio que legítimamente
 * no llevan filtro (por ejemplo la paginación de sellos-textiles comprobada en
 * producción). Un resultado con detected = false es un resultado normal y
 * perfectamente válido de esta clase.
 *
 * -----------------------------------------------------------------------
 * EVIDENCIA REAL SOBRE LA QUE SE HA DISEÑADO ESTO
 *
 * HTML de producción de /categoria-producto/design-stamp/exlibris/exlibris-anime/:
 *
 *   <div class="yith-wcan-filters no-title" id="preset_6432" data-preset-id="6432" data-target="">
 *
 * De ahí salen las tres piezas que se buscan, y en este orden de importancia:
 *
 *   1. El contenedor: una etiqueta cuyo atributo class contiene el token
 *      exacto "yith-wcan-filters". Es el marcador funcional del componente.
 *   2. El id del contenedor con la forma preset_NNNN.
 *   3. El atributo data-preset-id="NNNN" del mismo contenedor.
 *
 * Las dos últimas SÓLO cuentan si están en el contenedor de la primera. Un
 * "preset_6432" suelto en un script, en un comentario, en una URL o en un
 * bloque JSON no es evidencia de que el filtro esté renderizado: es
 * exactamente el falso positivo que este proyecto existe para no cometer, y
 * por eso se registra aparte como preset huérfano, nunca como válido.
 * -----------------------------------------------------------------------
 *
 * NO se usa ningún identificador de Elementor. En particular,
 * elementor-element-7a2976f4 fue útil durante la investigación pero no es una
 * regla: hay páginas con YITH correcto que no lo llevan. Aquí no aparece.
 *
 * Tampoco se mira el User-Agent, ni las cabeceras de LiteSpeed, ni nada que no
 * sea el HTML recibido. La clase es pura respecto a su entrada: no hace
 * peticiones, no toca la base de datos y no consulta el estado de WordPress.
 * La única lectura de configuración es la lista de presets válidos, igual que
 * F4.1 lee min_html_bytes y F4.2 lee las listas de rutas.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_YITH_Validator {

	/** Token de clase que identifica el contenedor del filtro. */
	const FILTER_CLASS = 'yith-wcan-filters';

	/**
	 * Presets válidos conocidos, en un único sitio.
	 *
	 * Son los tres presets confirmados del sitio. NO se ha verificado que
	 * ninguno de ellos corresponda a una variante concreta (móvil, escritorio
	 * u otra): son simplemente presets válidos, y esta clase los trata a los
	 * tres exactamente igual.
	 *
	 * El ajuste yith_presets, que existe desde F1, puede sobrescribir esta
	 * lista sin tocar código. Esta constante es el valor de respaldo.
	 */
	const DEFAULT_PRESETS = array( 6432, 6683, 13269 );

	/** Errores de entrada. */
	const ERROR_INVALID_INPUT = 'invalid_input';
	const ERROR_EMPTY_HTML    = 'empty_html';

	/** Avisos diagnósticos. */
	const WARN_MARKER_WITHOUT_PRESET = 'marker_without_preset';
	const WARN_UNKNOWN_PRESET        = 'unknown_preset';
	const WARN_PRESET_MISMATCH       = 'preset_id_mismatch';
	const WARN_PRESET_OUT_OF_CONTEXT = 'preset_outside_yith_context';
	const WARN_MARKER_NOT_IN_CLASS   = 'marker_not_in_class_attribute';
	const WARN_SANITIZE_FAILED       = 'sanitize_failed';
	const WARN_PCRE_FAILED           = 'pcre_failed';

	/** Longitud máxima del fragmento de evidencia que se guarda por contenedor. */
	const MAX_SNIPPET_LENGTH = 300;

	/**
	 * Analiza el HTML y devuelve la evidencia YITH encontrada.
	 *
	 * No lanza excepciones ni emite avisos de PHP: una entrada inutilizable es
	 * un caso normal y se informa en el resultado.
	 *
	 * @param string $html   HTML completo de la respuesta.
	 * @param array  $config Sobrescrituras. Ver config().
	 * @return array {
	 *     @type bool        $detected        True si hay al menos un contenedor con el marcador del filtro.
	 *     @type bool        $valid           True si al menos un contenedor lleva un preset válido.
	 *     @type bool        $filter_marker   True si el token yith-wcan-filters aparece como clase real.
	 *     @type int[]       $presets         Presets válidos hallados en contenedores, únicos y ordenados.
	 *     @type int[]       $invalid_presets Presets desconocidos hallados en contenedores.
	 *     @type int[]       $orphan_presets  Tokens preset_N hallados FUERA de cualquier contenedor.
	 *     @type bool        $consistent      False si algún contenedor se contradice a sí mismo.
	 *     @type array       $containers      Evidencia detallada de cada contenedor encontrado.
	 *     @type bool        $processable     False si el HTML no era utilizable.
	 *     @type string|null $error           Código de error, o null.
	 *     @type string|null $error_message   Mensaje legible, o null.
	 *     @type array       $warnings        Avisos diagnósticos: { code, message, context }.
	 * }
	 */
	public static function resolve( $html, $config = array() ) {
		$config = self::config( is_array( $config ) ? $config : array() );

		$result = self::empty_result();

		if ( ! is_string( $html ) ) {
			$result['error']         = self::ERROR_INVALID_INPUT;
			$result['error_message'] = 'El HTML recibido no es una cadena.';

			return $result;
		}

		if ( '' === trim( $html ) ) {
			$result['error']         = self::ERROR_EMPTY_HTML;
			$result['error_message'] = 'El HTML recibido está vacío.';

			return $result;
		}

		$result['processable']   = true;
		$result['error']         = null;
		$result['error_message'] = null;

		// 1. Se retiran scripts, hojas de estilo y comentarios ANTES de buscar
		// nada. Es la primera y más eficaz barrera contra los falsos positivos:
		// un preset citado en JavaScript o comentado no puede llegar siquiera a
		// evaluarse como contenedor.
		$searchable = self::sanitize( $html, $result['warnings'] );

		// 2. Puerta rápida: sin el token no hay nada que analizar, y el 99 % de
		// las páginas del sitio caen aquí sin pagar ningún coste de regex.
		if ( false === stripos( $searchable, self::FILTER_CLASS ) ) {
			self::note_orphans( $result, $html, array() );

			return $result;
		}

		// 3. Contenedores candidatos y evidencia de cada uno.
		$tags = self::find_candidate_tags( $searchable, $result['warnings'] );

		$raw_tags = array();

		foreach ( $tags as $tag ) {
			$container = self::inspect_tag( $tag, $config, $result['warnings'] );

			if ( null === $container ) {
				continue;
			}

			$raw_tags[]            = $tag;
			$result['containers'][] = $container;
		}

		// 4. Agregados.
		self::aggregate( $result, $config );

		// 5. Presets fuera de contexto: sólo diagnóstico, nunca evidencia.
		self::note_orphans( $result, $html, $raw_tags );

		return $result;
	}

	/**
	 * Registra los presets hallados fuera de cualquier contenedor.
	 *
	 * Se hace en un único sitio para que las dos salidas de resolve() —la
	 * rápida, cuando no hay marcador, y la completa— informen exactamente
	 * igual.
	 *
	 * @param array    $result Resultado por referencia.
	 * @param string   $html   HTML original.
	 * @param string[] $tags   Etiquetas de contenedor reconocidas.
	 * @return void
	 */
	private static function note_orphans( &$result, $html, $tags ) {
		$result['orphan_presets'] = self::find_orphan_presets( $html, $tags, $result['warnings'] );

		if ( empty( $result['orphan_presets'] ) ) {
			return;
		}

		self::add_warning(
			$result['warnings'],
			self::WARN_PRESET_OUT_OF_CONTEXT,
			'Se han encontrado identificadores de preset fuera de cualquier contenedor del filtro. No cuentan como evidencia.',
			array( 'presets' => $result['orphan_presets'] )
		);
	}

	/**
	 * Atajo booleano: ¿hay evidencia de un filtro YITH válido?
	 *
	 * Deliberadamente NO responde "¿está bien la página?". Eso depende del
	 * perfil de la URL y lo decide F4.4.
	 *
	 * @param string $html   HTML.
	 * @param array  $config Configuración.
	 * @return bool
	 */
	public static function has_valid_filter( $html, $config = array() ) {
		$result = self::resolve( $html, $config );

		return (bool) $result['valid'];
	}

	/**
	 * Configuración efectiva.
	 *
	 * @param array $overrides presets (lista de enteros o de cadenas preset_N).
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$config = array(
			'presets' => self::normalize_preset_list( SCW_Settings::get( 'yith_presets', self::DEFAULT_PRESETS ) ),
		);

		// Un ajuste vacío o ilegible no puede dejar el validador sin presets:
		// se vuelve a la lista conocida en vez de aceptar cualquier cosa.
		if ( empty( $config['presets'] ) ) {
			$config['presets'] = self::normalize_preset_list( self::DEFAULT_PRESETS );
		}

		if ( is_array( $overrides ) && isset( $overrides['presets'] ) ) {
			$config['presets'] = self::normalize_preset_list( $overrides['presets'] );
		}

		return $config;
	}

	/**
	 * Presets válidos vigentes.
	 *
	 * @param array $config Sobrescrituras.
	 * @return int[]
	 */
	public static function presets( $config = array() ) {
		$config = self::config( is_array( $config ) ? $config : array() );

		return $config['presets'];
	}

	/**
	 * Normaliza una lista de presets a enteros únicos y ordenados.
	 *
	 * Acepta tanto 6432 como 'preset_6432' o '6432', porque el ajuste
	 * yith_presets de F1 guarda las cadenas con prefijo y no se va a cambiar su
	 * formato en esta fase.
	 *
	 * @param mixed $list Lista.
	 * @return int[]
	 */
	public static function normalize_preset_list( $list ) {
		$out = array();

		foreach ( (array) $list as $item ) {
			$id = self::preset_id( $item );

			if ( null !== $id ) {
				$out[] = $id;
			}
		}

		$out = array_values( array_unique( $out ) );

		sort( $out );

		return $out;
	}

	/**
	 * Extrae el número de un preset expresado de cualquiera de las formas
	 * admitidas. Devuelve null si no lo es.
	 *
	 * @param mixed $value Valor.
	 * @return int|null
	 */
	public static function preset_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( 1 === preg_match( '/^preset_(\d+)$/i', $value, $matches ) ) {
			return (int) $matches[1] > 0 ? (int) $matches[1] : null;
		}

		if ( 1 === preg_match( '/^\d+$/', $value ) ) {
			return (int) $value > 0 ? (int) $value : null;
		}

		return null;
	}

	/**
	 * Retira scripts, estilos y comentarios del HTML.
	 *
	 * Los patrones están "desenrollados" (se consume carácter a carácter con
	 * clases negadas en vez de usar .*?) para no depender del backtracking de
	 * PCRE en documentos grandes. Aun así, si algún reemplazo fallara —PCRE
	 * devuelve null al superar sus límites— se sigue adelante con el HTML
	 * original y se deja constancia: es preferible analizar de más y avisarlo
	 * que quedarse sin analizar.
	 *
	 * @param string $html     HTML original.
	 * @param array  $warnings Avisos, por referencia.
	 * @return string
	 */
	private static function sanitize( $html, &$warnings ) {
		$patterns = array(
			'comentarios' => '/<!--(?:[^-]|-(?!->))*-->/',
			'scripts'     => '#<script\b[^>]*>(?:[^<]|<(?!/script\s*>))*</script\s*>#i',
			'estilos'     => '#<style\b[^>]*>(?:[^<]|<(?!/style\s*>))*</style\s*>#i',
		);

		$clean = $html;

		foreach ( $patterns as $label => $pattern ) {
			$replaced = preg_replace( $pattern, ' ', $clean );

			if ( null === $replaced ) {
				self::add_warning(
					$warnings,
					self::WARN_SANITIZE_FAILED,
					'No se ha podido descartar una parte del documento antes de analizarlo; el análisis continúa sobre el HTML completo.',
					array( 'section' => $label )
				);

				continue;
			}

			$clean = $replaced;
		}

		return $clean;
	}

	/**
	 * Localiza las etiquetas de apertura que mencionan el token del filtro.
	 *
	 * El patrón está acotado a una sola etiqueta ([^>]*, que no puede cruzar el
	 * cierre) para que no exista riesgo de backtracking catastrófico ni de
	 * tomar por contenedor algo que abarque medio documento.
	 *
	 * @param string $html     HTML ya saneado.
	 * @param array  $warnings Avisos, por referencia.
	 * @return string[] Etiquetas completas.
	 */
	private static function find_candidate_tags( $html, &$warnings ) {
		$pattern = '/<[a-zA-Z][a-zA-Z0-9:_-]*\s[^>]*' . preg_quote( self::FILTER_CLASS, '/' ) . '[^>]*>/i';

		$matches = array();
		$found   = preg_match_all( $pattern, $html, $matches );

		if ( false === $found ) {
			self::add_warning(
				$warnings,
				self::WARN_PCRE_FAILED,
				'La búsqueda de contenedores del filtro no se ha podido completar.',
				array()
			);

			return array();
		}

		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * Extrae la evidencia de una etiqueta candidata.
	 *
	 * @param string $tag      Etiqueta completa.
	 * @param array  $config   Configuración efectiva.
	 * @param array  $warnings Avisos, por referencia.
	 * @return array|null Null si la etiqueta no es realmente un contenedor.
	 */
	private static function inspect_tag( $tag, $config, &$warnings ) {
		$attributes = self::parse_attributes( $tag );

		$classes = isset( $attributes['class'] ) ? preg_split( '/\s+/', trim( $attributes['class'] ) ) : array();
		$classes = is_array( $classes ) ? $classes : array();

		// El token tiene que ser una CLASE completa, no una subcadena. Así
		// "mi-yith-wcan-filters-viejo" o un data-* que mencione el nombre no se
		// toman por el componente.
		if ( ! in_array( self::FILTER_CLASS, $classes, true ) ) {
			self::add_warning(
				$warnings,
				self::WARN_MARKER_NOT_IN_CLASS,
				'Se ha encontrado el nombre del filtro en una etiqueta, pero no como clase propia. No se considera contenedor.',
				array( 'snippet' => self::snippet( $tag ) )
			);

			return null;
		}

		$id_preset   = isset( $attributes['id'] ) ? self::preset_from_id( $attributes['id'] ) : null;
		$data_preset = isset( $attributes['data-preset-id'] ) ? self::preset_id( $attributes['data-preset-id'] ) : null;

		$sources = array();

		if ( null !== $id_preset ) {
			$sources[] = 'id';
		}

		if ( null !== $data_preset ) {
			$sources[] = 'data-preset-id';
		}

		$consistent = true;

		if ( null !== $id_preset && null !== $data_preset && $id_preset !== $data_preset ) {
			$consistent = false;

			self::add_warning(
				$warnings,
				self::WARN_PRESET_MISMATCH,
				'El id del contenedor y su data-preset-id no coinciden.',
				array(
					'id_preset'      => $id_preset,
					'data_preset_id' => $data_preset,
					'snippet'        => self::snippet( $tag ),
				)
			);
		}

		// Ambos valores se conservan: si se contradicen, F4.4 debe poder ver
		// exactamente qué decía cada uno en vez de recibir ya una elección
		// hecha por esta clase.
		$found = array();

		foreach ( array( $id_preset, $data_preset ) as $candidate ) {
			if ( null !== $candidate && ! in_array( $candidate, $found, true ) ) {
				$found[] = $candidate;
			}
		}

		$valid   = array();
		$invalid = array();

		foreach ( $found as $preset ) {
			if ( in_array( $preset, $config['presets'], true ) ) {
				$valid[] = $preset;
				continue;
			}

			$invalid[] = $preset;

			self::add_warning(
				$warnings,
				self::WARN_UNKNOWN_PRESET,
				'El contenedor del filtro declara un preset que no está en la lista de presets válidos.',
				array(
					'preset'  => $preset,
					'snippet' => self::snippet( $tag ),
				)
			);
		}

		if ( empty( $found ) ) {
			self::add_warning(
				$warnings,
				self::WARN_MARKER_WITHOUT_PRESET,
				'Hay un contenedor del filtro sin ningún preset declarado.',
				array( 'snippet' => self::snippet( $tag ) )
			);
		}

		return array(
			'tag'             => strtolower( self::tag_name( $tag ) ),
			'classes'         => $classes,
			'id_preset'       => $id_preset,
			'data_preset_id'  => $data_preset,
			'sources'         => $sources,
			'presets'         => $valid,
			'invalid_presets' => $invalid,
			'consistent'      => $consistent,
			'valid'           => ! empty( $valid ),
			'snippet'         => self::snippet( $tag ),
		);
	}

	/**
	 * Calcula los agregados del resultado a partir de los contenedores.
	 *
	 * @param array $result Resultado por referencia.
	 * @param array $config Configuración efectiva.
	 * @return void
	 */
	private static function aggregate( &$result, $config ) {
		$presets    = array();
		$invalid    = array();
		$consistent = true;

		foreach ( $result['containers'] as $container ) {
			$presets = array_merge( $presets, $container['presets'] );
			$invalid = array_merge( $invalid, $container['invalid_presets'] );

			if ( ! $container['consistent'] ) {
				$consistent = false;
			}
		}

		$result['detected']        = ! empty( $result['containers'] );
		$result['filter_marker']   = ! empty( $result['containers'] );
		$result['presets']         = self::unique_sorted( $presets );
		$result['invalid_presets'] = self::unique_sorted( $invalid );
		$result['consistent']      = $consistent;
		$result['valid']           = ! empty( $result['presets'] );

		unset( $config );
	}

	/**
	 * Busca identificadores preset_N que NO estén dentro de un contenedor.
	 *
	 * Se evalúa sobre el HTML ORIGINAL, sin sanear, porque el objetivo es
	 * justamente localizar las menciones en scripts, comentarios, URLs o JSON
	 * que no deben contar como evidencia pero que sí interesa poder ver al
	 * diagnosticar una página.
	 *
	 * @param string   $html     HTML original.
	 * @param string[] $tags     Etiquetas de contenedor ya reconocidas.
	 * @param array    $warnings Avisos, por referencia.
	 * @return int[]
	 */
	private static function find_orphan_presets( $html, $tags, &$warnings ) {
		$rest = $html;

		foreach ( $tags as $tag ) {
			$rest = str_replace( $tag, ' ', $rest );
		}

		$matches = array();
		$found   = preg_match_all( '/preset_(\d+)/i', $rest, $matches );

		if ( false === $found ) {
			self::add_warning(
				$warnings,
				self::WARN_PCRE_FAILED,
				'La búsqueda de presets fuera de contexto no se ha podido completar.',
				array()
			);

			return array();
		}

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$ids = array();

		foreach ( $matches[1] as $number ) {
			$ids[] = (int) $number;
		}

		return self::unique_sorted( $ids );
	}

	/**
	 * Extrae los atributos de una etiqueta de apertura.
	 *
	 * Admite valores entre comillas dobles, simples o sin comillas. Los nombres
	 * se normalizan a minúsculas; los valores se conservan tal cual.
	 *
	 * @param string $tag Etiqueta completa.
	 * @return array Mapa nombre => valor.
	 */
	private static function parse_attributes( $tag ) {
		$attributes = array();
		$matches    = array();

		$pattern = '/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/';

		if ( false === preg_match_all( $pattern, $tag, $matches, PREG_SET_ORDER ) ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );

			if ( isset( $attributes[ $name ] ) ) {
				// Atributo repetido: manda el primero, igual que hacen los
				// navegadores.
				continue;
			}

			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$attributes[ $name ] = $match[2];
			} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
				$attributes[ $name ] = $match[3];
			} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
				$attributes[ $name ] = $match[4];
			} else {
				$attributes[ $name ] = '';
			}
		}

		return $attributes;
	}

	/**
	 * Nombre de la etiqueta.
	 *
	 * @param string $tag Etiqueta completa.
	 * @return string
	 */
	private static function tag_name( $tag ) {
		$matches = array();

		if ( 1 === preg_match( '/^<([a-zA-Z][a-zA-Z0-9:_-]*)/', $tag, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Número de preset a partir de un atributo id.
	 *
	 * Sólo se acepta la forma exacta preset_NNNN. Un id como
	 * "algo-preset_6432-mas" no cuenta: la evidencia tiene que ser el
	 * identificador del contenedor, no una coincidencia dentro de otra cadena.
	 *
	 * @param string $id Valor del atributo id.
	 * @return int|null
	 */
	private static function preset_from_id( $id ) {
		$matches = array();

		if ( 1 === preg_match( '/^preset_(\d+)$/i', trim( (string) $id ), $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * Fragmento de evidencia, acotado en longitud.
	 *
	 * @param string $tag Etiqueta.
	 * @return string
	 */
	private static function snippet( $tag ) {
		$tag = preg_replace( '/\s+/', ' ', (string) $tag );
		$tag = null === $tag ? (string) $tag : $tag;

		return substr( trim( $tag ), 0, self::MAX_SNIPPET_LENGTH );
	}

	/**
	 * Enteros únicos y ordenados.
	 *
	 * @param array $values Valores.
	 * @return int[]
	 */
	private static function unique_sorted( $values ) {
		$values = array_values( array_unique( array_map( 'intval', (array) $values ) ) );

		sort( $values );

		return $values;
	}

	/**
	 * Añade un aviso diagnóstico.
	 *
	 * @param array  $warnings Avisos, por referencia.
	 * @param string $code     Código estable.
	 * @param string $message  Mensaje legible.
	 * @param array  $context  Datos de apoyo.
	 * @return void
	 */
	private static function add_warning( &$warnings, $code, $message, $context = array() ) {
		$warnings[] = array(
			'code'    => $code,
			'message' => $message,
			'context' => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * Resultado base, sin evidencia y sin procesar.
	 *
	 * @return array
	 */
	private static function empty_result() {
		return array(
			'detected'        => false,
			'valid'           => false,
			'filter_marker'   => false,
			'presets'         => array(),
			'invalid_presets' => array(),
			'orphan_presets'  => array(),
			'consistent'      => true,
			'containers'      => array(),
			'processable'     => false,
			'error'           => null,
			'error_message'   => null,
			'warnings'        => array(),
		);
	}
}
