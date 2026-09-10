<?php
/**
 * Normalizador de URLs.
 *
 * Convierte una URL de entrada en su representación canónica interna y en su
 * hash estable. Es la capa que prepara las URLs ANTES de que lleguen a la cola:
 * SCW_Queue sigue asumiendo que recibe URLs ya normalizadas y no incorpora nada
 * de esta lógica.
 *
 * Esta clase es completamente independiente de la red. No hace peticiones HTTP,
 * no resuelve DNS y no consulta la canonical declarada por el servidor. La
 * canonicalización de esta fase es puramente sintáctica.
 *
 * Tampoco toca la base de datos ni encola nada.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_URL_Normalizer {

	/** Longitud máxima, alineada con la columna url varchar(2048) de la cola. */
	const MAX_URL_LENGTH = 2048;

	/** Esquemas de entrada admitidos. */
	const SUPPORTED_SCHEMES = array( 'http', 'https' );

	/** Puertos que se consideran por defecto y se eliminan de la URL canónica. */
	const DEFAULT_PORTS = array(
		'http'  => 80,
		'https' => 443,
	);

	// Códigos de error, en el mismo estilo snake_case que SCW_Queue::insert().
	const ERROR_EMPTY_URL    = 'empty_url';
	const ERROR_INVALID_URL  = 'invalid_url';
	const ERROR_TOO_LONG     = 'url_too_long';
	const ERROR_SCHEME       = 'unsupported_scheme';
	const ERROR_USERINFO     = 'userinfo_not_allowed';
	const ERROR_HOST         = 'host_not_allowed';
	const ERROR_PORT         = 'port_not_allowed';
	const ERROR_NO_HOST_SET  = 'allowed_host_not_configured';

	/**
	 * Normaliza una URL.
	 *
	 * No lanza excepciones: una entrada inválida es un caso normal y se informa
	 * mediante el resultado.
	 *
	 * @param string $url      URL de entrada.
	 * @param array  $config   Sobrescrituras de configuración. Ver config().
	 * @return array {
	 *     @type bool        $valid         True si la URL es utilizable.
	 *     @type string|null $url           URL canónica, o null si no es válida.
	 *     @type string|null $hash          sha1 de la URL canónica, o null.
	 *     @type string|null $error         Código de error, o null.
	 *     @type string|null $error_message Mensaje legible, o null.
	 *     @type string      $original      La entrada tal cual se recibió.
	 * }
	 */
	public static function normalize( $url, $config = array() ) {
		$original = is_string( $url ) ? $url : '';
		$config   = self::config( is_array( $config ) ? $config : array() );

		// El normalizador SÍ recorta espacios: es su trabajo producir una forma
		// canónica, y los sitemaps suelen traer saltos de línea alrededor de
		// <loc>. SCW_Queue, en cambio, nunca modifica lo que recibe.
		$candidate = trim( $original );

		if ( '' === $candidate ) {
			return self::failure( self::ERROR_EMPTY_URL, 'La URL está vacía.', $original );
		}

		if ( strlen( $candidate ) > self::MAX_URL_LENGTH ) {
			return self::failure( self::ERROR_TOO_LONG, 'La URL supera los ' . self::MAX_URL_LENGTH . ' caracteres.', $original );
		}

		$parts = wp_parse_url( $candidate );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::failure( self::ERROR_INVALID_URL, 'La URL no se ha podido interpretar o no es absoluta.', $original );
		}

		// Userinfo: no se necesita para este crawler y se rechaza de plano.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::failure( self::ERROR_USERINFO, 'Las URLs con usuario o contraseña no están permitidas.', $original );
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, self::SUPPORTED_SCHEMES, true ) ) {
			return self::failure( self::ERROR_SCHEME, 'Esquema no admitido: ' . $scheme . '.', $original );
		}

		$host = self::normalize_host( $parts['host'] );

		if ( '' === $config['allowed_host'] ) {
			return self::failure( self::ERROR_NO_HOST_SET, 'No hay ningún hostname permitido configurado.', $original );
		}

		if ( $host !== $config['allowed_host'] ) {
			return self::failure( self::ERROR_HOST, 'El hostname ' . $host . ' no coincide con el dominio permitido.', $original );
		}

		$port_error = self::check_port( $parts, $scheme );

		if ( null !== $port_error ) {
			return self::failure( self::ERROR_PORT, $port_error, $original );
		}

		$final_scheme = self::final_scheme( $scheme, $config['force_scheme'] );
		$path         = self::normalize_path( isset( $parts['path'] ) ? $parts['path'] : '', $config['trailing_slash'] );
		$query        = self::normalize_query( isset( $parts['query'] ) ? $parts['query'] : '', $config );

		// El fragmento se descarta siempre: no viaja al servidor y no puede
		// generar una entrada de caché distinta.
		$normalized = $final_scheme . '://' . $host . $path;

		if ( '' !== $query ) {
			$normalized .= '?' . $query;
		}

		if ( strlen( $normalized ) > self::MAX_URL_LENGTH ) {
			return self::failure( self::ERROR_TOO_LONG, 'La URL normalizada supera los ' . self::MAX_URL_LENGTH . ' caracteres.', $original );
		}

		return array(
			'valid'         => true,
			'url'           => $normalized,
			'hash'          => self::hash( $normalized ),
			'error'         => null,
			'error_message' => null,
			'original'      => $original,
		);
	}

	/**
	 * Atajo booleano.
	 *
	 * @param string $url    URL.
	 * @param array  $config Configuración.
	 * @return bool
	 */
	public static function is_valid( $url, $config = array() ) {
		$result = self::normalize( $url, $config );

		return (bool) $result['valid'];
	}

	/**
	 * Hash estable de una URL ya normalizada.
	 *
	 * sha1 en hexadecimal: 40 caracteres exactos, que es lo que espera la
	 * columna url_hash char(40) de la cola. Coincide por construcción con
	 * SCW_Queue::hash_url(), de modo que normalizar y encolar producen siempre
	 * el mismo identificador.
	 *
	 * @param string $normalized_url URL canónica.
	 * @return string
	 */
	public static function hash( $normalized_url ) {
		return sha1( (string) $normalized_url );
	}

	/**
	 * Configuración efectiva.
	 *
	 * Lee los ajustes ya existentes de F1 y admite sobrescrituras explícitas,
	 * lo que permite probar la clase de forma aislada y que F2.4 le pase una
	 * configuración concreta sin tocar la base de datos.
	 *
	 * @param array $overrides Claves a sobrescribir.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$config = array(
			'allowed_host'   => (string) SCW_Settings::get( 'allowed_host', '' ),
			'force_scheme'   => (string) SCW_Settings::get( 'force_scheme', 'https' ),
			'trailing_slash' => (string) SCW_Settings::get( 'trailing_slash', 'add' ),
			'ignored_params' => (array) SCW_Settings::get( 'ignored_params', array() ),
			'allowed_params' => (array) SCW_Settings::get( 'allowed_params', array() ),
		);

		if ( is_array( $overrides ) ) {
			$config = array_merge( $config, $overrides );
		}

		$config['allowed_host'] = self::normalize_host( $config['allowed_host'] );

		return $config;
	}

	/**
	 * Normaliza un hostname.
	 *
	 * Reglas: minúsculas y eliminación del punto final absoluto. No se convierte
	 * IDN a punycode, porque el dominio del proyecto es ASCII y depender de la
	 * extensión intl introduciría un comportamiento distinto según el servidor.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );

		return rtrim( $host, '.' );
	}

	/**
	 * Comprueba el puerto.
	 *
	 * Sólo se admite el puerto por defecto del esquema de entrada, y en ese caso
	 * se elimina de la URL canónica. Cualquier otro puerto se rechaza: el sitio
	 * público es HTTPS estándar y aceptar puertos arbitrarios abriría una vía de
	 * SSRF hacia servicios internos.
	 *
	 * @param array  $parts  Partes de la URL.
	 * @param string $scheme Esquema de entrada.
	 * @return string|null Mensaje de error, o null si es aceptable.
	 */
	private static function check_port( $parts, $scheme ) {
		if ( ! isset( $parts['port'] ) || '' === $parts['port'] ) {
			return null;
		}

		$port    = (int) $parts['port'];
		$default = isset( self::DEFAULT_PORTS[ $scheme ] ) ? self::DEFAULT_PORTS[ $scheme ] : 0;

		if ( $port === $default ) {
			return null;
		}

		return 'Puerto no permitido: ' . $port . '.';
	}

	/**
	 * Esquema final.
	 *
	 * @param string $scheme Esquema de entrada.
	 * @param string $policy https|http|keep.
	 * @return string
	 */
	private static function final_scheme( $scheme, $policy ) {
		$policy = strtolower( (string) $policy );

		if ( in_array( $policy, self::SUPPORTED_SCHEMES, true ) ) {
			return $policy;
		}

		return $scheme;
	}

	/**
	 * Normaliza el path.
	 *
	 * Lo que SÍ se hace:
	 *  - path vacío pasa a "/";
	 *  - normalización del porcentaje-codificado (ver normalize_percent_encoding);
	 *  - política de slash final.
	 *
	 * Lo que NO se hace, deliberadamente: no se resuelven segmentos "." ni "..",
	 * no se colapsan barras repetidas y no se elimina ningún segmento. Cualquiera
	 * de esas transformaciones podría igualar dos paths que el servidor sirve de
	 * forma distinta.
	 *
	 * @param string $path   Path de entrada.
	 * @param string $policy Política de slash final.
	 * @return string
	 */
	private static function normalize_path( $path, $policy ) {
		$path = (string) $path;

		if ( '' === $path ) {
			$path = '/';
		}

		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		$path = self::normalize_percent_encoding( $path );

		return self::apply_trailing_slash_policy( $path, $policy );
	}

	/**
	 * Aplica la política de slash final.
	 *
	 * add    : añade "/" salvo que el último segmento parezca un archivo.
	 * remove : quita el "/" final salvo en la raíz.
	 * keep   : deja el path como está, salvo la raíz vacía.
	 *
	 * "Parece un archivo" significa que el último segmento termina en un punto
	 * seguido de entre 1 y 8 caracteres alfanuméricos, como .php, .xml o .html.
	 *
	 * @param string $path   Path.
	 * @param string $policy add|remove|keep.
	 * @return string
	 */
	private static function apply_trailing_slash_policy( $path, $policy ) {
		if ( '/' === $path ) {
			return '/';
		}

		$policy = strtolower( (string) $policy );

		if ( 'remove' === $policy ) {
			$trimmed = rtrim( $path, '/' );

			return '' === $trimmed ? '/' : $trimmed;
		}

		if ( 'add' === $policy ) {
			if ( '/' === substr( $path, -1 ) ) {
				return $path;
			}

			if ( self::looks_like_file( $path ) ) {
				return $path;
			}

			return $path . '/';
		}

		return $path;
	}

	/**
	 * ¿El último segmento del path parece un archivo con extensión?
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function looks_like_file( $path ) {
		$position = strrpos( $path, '/' );
		$segment  = false === $position ? $path : substr( $path, $position + 1 );

		return 1 === preg_match( '/\.[A-Za-z0-9]{1,8}$/', $segment );
	}

	/**
	 * Normaliza la query string.
	 *
	 * No se usa parse_str, que altera los nombres de los parámetros (los puntos
	 * pasan a guiones bajos) y descarta los repetidos. El troceado es manual
	 * para conservar exactamente lo que llega.
	 *
	 * @param string $query  Query de entrada, sin "?".
	 * @param array  $config Configuración efectiva.
	 * @return string Query canónica, sin "?", o cadena vacía.
	 */
	private static function normalize_query( $query, $config ) {
		$query = (string) $query;

		if ( '' === $query ) {
			return '';
		}

		$pairs = array();
		$index = 0;

		foreach ( explode( '&', $query ) as $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}

			$separator = strpos( $chunk, '=' );

			if ( false === $separator ) {
				$raw_key   = $chunk;
				$raw_value = null;
			} else {
				$raw_key   = substr( $chunk, 0, $separator );
				$raw_value = substr( $chunk, $separator + 1 );
			}

			if ( '' === $raw_key ) {
				continue;
			}

			// El nombre se decodifica sólo para compararlo con las listas; lo
			// que se emite es siempre la forma original, con el porcentaje
			// codificado normalizado.
			//
			// Se usa rawurldecode y NO urldecode: urldecode aplica la semántica
			// de application/x-www-form-urlencoded, donde "+" significa espacio.
			// Aquí trabajamos con semántica de URL (RFC 3986), en la que "+" es
			// un carácter literal. Así, "%66oo" se compara como "foo", pero
			// "foo+bar" sigue llamándose "foo+bar" y nunca "foo bar".
			$name = rawurldecode( $raw_key );

			if ( ! self::keeps_param( $name, $config ) ) {
				continue;
			}

			$pairs[] = array(
				'name'  => $name,
				'key'   => self::normalize_percent_encoding( $raw_key ),
				'value' => null === $raw_value ? null : self::normalize_percent_encoding( $raw_value ),
				'index' => $index,
			);

			$index++;
		}

		if ( empty( $pairs ) ) {
			return '';
		}

		$pairs = self::sort_pairs( $pairs );

		$rebuilt = array();

		foreach ( $pairs as $pair ) {
			$rebuilt[] = null === $pair['value'] ? $pair['key'] : $pair['key'] . '=' . $pair['value'];
		}

		return implode( '&', $rebuilt );
	}

	/**
	 * Decide si un parámetro se conserva.
	 *
	 * Política, en este orden:
	 *  1. Si está en allowed_params, se conserva SIEMPRE, aunque también encaje
	 *     en la lista de ignorados. Es la vía para rescatar un parámetro
	 *     legítimo que colisione con un patrón.
	 *  2. Si está en ignored_params, por nombre exacto o por patrón con "*"
	 *     final, se elimina.
	 *  3. En cualquier otro caso se CONSERVA. Un parámetro desconocido nunca se
	 *     elimina en silencio: destruir una URL legítima es peor que calentar
	 *     una variante de más.
	 *
	 * La comparación de nombres distingue mayúsculas y minúsculas, porque los
	 * nombres de parámetro son sensibles a ellas. El nombre llega ya decodificado
	 * con semántica RFC 3986: los tripletes por ciento se interpretan, pero el
	 * signo "+" se trata como carácter literal, no como espacio.
	 *
	 * @param string $name   Nombre decodificado.
	 * @param array  $config Configuración efectiva.
	 * @return bool
	 */
	private static function keeps_param( $name, $config ) {
		foreach ( (array) $config['allowed_params'] as $pattern ) {
			if ( self::matches_param( $name, (string) $pattern ) ) {
				return true;
			}
		}

		foreach ( (array) $config['ignored_params'] as $pattern ) {
			if ( self::matches_param( $name, (string) $pattern ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Compara un nombre de parámetro con una entrada de las listas.
	 *
	 * Una entrada que termina en "*" es un patrón de prefijo: "filter_*" encaja
	 * con "filter_marca" pero no con "myfilter_marca". El resto son nombres
	 * exactos. El "*" sólo se interpreta al final de la entrada.
	 *
	 * @param string $name    Nombre decodificado.
	 * @param string $pattern Entrada de la lista.
	 * @return bool
	 */
	private static function matches_param( $name, $pattern ) {
		if ( '' === $pattern ) {
			return false;
		}

		if ( '*' === substr( $pattern, -1 ) ) {
			$prefix = substr( $pattern, 0, -1 );

			return '' !== $prefix && 0 === strpos( $name, $prefix );
		}

		return $name === $pattern;
	}

	/**
	 * Ordena los parámetros conservados de forma estable.
	 *
	 * Regla exacta: orden ascendente por nombre decodificado usando comparación
	 * binaria (strcmp). Cuando dos parámetros comparten nombre se conserva su
	 * orden original de aparición, de modo que los valores repetidos de un mismo
	 * parámetro nunca se reordenan entre sí.
	 *
	 * El desempate por índice es necesario porque la ordenación de PHP no está
	 * garantizada como estable antes de la versión 8.0.
	 *
	 * @param array $pairs Pares.
	 * @return array
	 */
	private static function sort_pairs( $pairs ) {
		usort(
			$pairs,
			static function ( $a, $b ) {
				$comparison = strcmp( $a['name'], $b['name'] );

				if ( 0 !== $comparison ) {
					return $comparison;
				}

				return $a['index'] - $b['index'];
			}
		);

		return $pairs;
	}

	/**
	 * Normaliza el porcentaje-codificado de una cadena.
	 *
	 * Dos únicas transformaciones, ambas de equivalencia garantizada por el
	 * RFC 3986:
	 *  - los tripletes que codifican un carácter no reservado (A-Z a-z 0-9 - . _ ~)
	 *    se decodifican: %7E pasa a ~;
	 *  - el resto de tripletes conservan su valor pero con los dígitos
	 *    hexadecimales en mayúsculas: %2f pasa a %2F.
	 *
	 * No se decodifica nada más, no se vuelve a codificar nada y no se toca el
	 * signo "+", de modo que no puede producirse doble codificación.
	 *
	 * @param string $value Cadena.
	 * @return string
	 */
	private static function normalize_percent_encoding( $value ) {
		return (string) preg_replace_callback(
			'/%([0-9A-Fa-f]{2})/',
			static function ( $matches ) {
				$character = chr( hexdec( $matches[1] ) );

				if ( 1 === preg_match( '/^[A-Za-z0-9\-._~]$/', $character ) ) {
					return $character;
				}

				return '%' . strtoupper( $matches[1] );
			},
			(string) $value
		);
	}

	/**
	 * Construye un resultado de fallo.
	 *
	 * @param string $code     Código de error.
	 * @param string $message  Mensaje legible.
	 * @param string $original Entrada original.
	 * @return array
	 */
	private static function failure( $code, $message, $original ) {
		return array(
			'valid'         => false,
			'url'           => null,
			'hash'          => null,
			'error'         => $code,
			'error_message' => $message,
			'original'      => $original,
		);
	}
}
