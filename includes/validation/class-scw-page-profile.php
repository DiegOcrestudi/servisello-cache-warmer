<?php
/**
 * Perfil de expectativa de contenido de una página.
 *
 * Responsabilidad única: dada una URL, decir qué esperamos encontrar en ella.
 * Nada más. Esta clase NO mira el HTML, no sabe qué es un preset de YITH, no
 * conoce Elementor, no accede a la base de datos y no hace peticiones. Es una
 * función pura sobre el path de la URL y la configuración del plugin.
 *
 * Tres perfiles posibles:
 *
 *  - REQUIRES_YITH : hemos declarado que esta página debe llevar filtros YITH.
 *  - NO_YITH       : hemos declarado que esta página NO lleva filtros YITH.
 *  - UNVERIFIED    : no tenemos ninguna expectativa declarada para ella.
 *
 * UNVERIFIED NO ES UN ERROR. Es el caso por defecto y el más frecuente: la
 * inmensa mayoría de las URLs del sitio (productos, entradas, páginas) no están
 * en ninguna de las dos listas y no tienen por qué estarlo. Quien consuma esta
 * clase debe tratar UNVERIFIED como "sin expectativa", nunca como sospecha.
 *
 * -----------------------------------------------------------------------
 * POR QUÉ COINCIDENCIA EXACTA Y NO PREFIJOS
 *
 * Las listas configuradas se solapan por naturaleza:
 *
 *   NO_YITH        /categoria-producto/design-stamp/exlibris/
 *   REQUIRES_YITH  /categoria-producto/design-stamp/exlibris/exlibris-anime/
 *
 * Con coincidencia por prefijo, la subcategoría encajaría con la regla del
 * padre y quedaría clasificada como NO_YITH: la detección se desactivaría
 * silenciosamente justo en las páginas que más nos importan, y todo saldría
 * correcto sin serlo. Por eso la comparación es EXACTA sobre el path completo.
 * Una ruta hija sólo obtiene perfil si está configurada ella misma.
 *
 * Como red adicional, si una misma ruta apareciera en las dos listas, gana
 * REQUIRES_YITH y el conflicto se registra como aviso: ante la duda, se
 * mantiene la expectativa más exigente en vez de rebajarla en silencio.
 * -----------------------------------------------------------------------
 *
 * Todas las rutas son DATOS DE CONFIGURACIÓN: viven en los ajustes
 * profiles_requires_yith y profiles_no_yith, definidos desde F1. En este
 * fichero no hay ni una sola ruta escrita, ni ningún caso especial para
 * ninguna categoría concreta de Servisello.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Page_Profile {

	/** Perfiles. */
	const PROFILE_REQUIRES_YITH = 'REQUIRES_YITH';
	const PROFILE_NO_YITH       = 'NO_YITH';
	const PROFILE_UNVERIFIED    = 'UNVERIFIED';

	/** Ajuste del que procede cada regla, para poder trazar el origen del perfil. */
	const SOURCE_REQUIRES_YITH = 'profiles_requires_yith';
	const SOURCE_NO_YITH       = 'profiles_no_yith';

	/** Avisos sobre patrones mal formados o contradictorios. */
	const WARN_EMPTY_PATTERN = 'invalid_pattern_empty';
	const WARN_NOT_ABSOLUTE  = 'invalid_pattern_not_absolute';
	const WARN_NOT_A_PATH    = 'invalid_pattern_not_a_path';
	const WARN_CONFLICT      = 'pattern_in_both_lists';

	/**
	 * Resuelve el perfil de una URL.
	 *
	 * No lanza excepciones: una URL inutilizable es un caso normal y se informa
	 * en el resultado. En ese caso el perfil es UNVERIFIED, nunca un error de
	 * contenido: no poder resolver una expectativa no significa que la página
	 * esté mal.
	 *
	 * @param string $url    URL absoluta, normalmente ya normalizada por
	 *                       SCW_URL_Normalizer.
	 * @param array  $config Sobrescrituras. Ver config().
	 * @return array {
	 *     @type string      $profile       REQUIRES_YITH|NO_YITH|UNVERIFIED.
	 *     @type bool        $matched       True si alguna regla ha coincidido.
	 *     @type string|null $path          Path canónico evaluado.
	 *     @type string|null $pattern       Patrón configurado que ha coincidido.
	 *     @type string|null $source        Ajuste del que procede ese patrón.
	 *     @type bool        $processable   False si la URL no era utilizable.
	 *     @type string|null $error         Código de error, o null.
	 *     @type string|null $error_message Mensaje legible, o null.
	 *     @type array       $warnings      Patrones descartados o en conflicto.
	 * }
	 */
	public static function resolve( $url, $config = array() ) {
		$config = self::config( is_array( $config ) ? $config : array() );

		$error         = null;
		$error_message = null;

		$path = self::extract_path( $url, $config, $error, $error_message );

		if ( null === $path ) {
			return self::result( self::PROFILE_UNVERIFIED, null, null, null, array(), false, $error, $error_message );
		}

		$warnings = array();

		// 1. REQUIRES_YITH primero. Ver la nota sobre conflictos en el docblock
		// de la clase: ante una ruta presente en las dos listas, se conserva la
		// expectativa más exigente.
		$match = self::find_match( $path, $config['profiles_requires_yith'], self::SOURCE_REQUIRES_YITH, $warnings );

		if ( null !== $match ) {
			// El conflicto sólo se busca si ya hay coincidencia exigente: no
			// tiene sentido recorrer la segunda lista para nada.
			self::detect_conflict( $path, $config['profiles_no_yith'], $warnings );

			return self::result( self::PROFILE_REQUIRES_YITH, $path, $match, self::SOURCE_REQUIRES_YITH, $warnings, true, null, null );
		}

		// 2. NO_YITH.
		$match = self::find_match( $path, $config['profiles_no_yith'], self::SOURCE_NO_YITH, $warnings );

		if ( null !== $match ) {
			return self::result( self::PROFILE_NO_YITH, $path, $match, self::SOURCE_NO_YITH, $warnings, true, null, null );
		}

		// 3. Sin expectativa declarada.
		return self::result( self::PROFILE_UNVERIFIED, $path, null, null, $warnings, true, null, null );
	}

	/**
	 * Atajo: sólo el perfil.
	 *
	 * @param string $url    URL.
	 * @param array  $config Configuración.
	 * @return string REQUIRES_YITH|NO_YITH|UNVERIFIED.
	 */
	public static function profile( $url, $config = array() ) {
		$result = self::resolve( $url, $config );

		return $result['profile'];
	}

	/**
	 * Todos los perfiles posibles.
	 *
	 * @return string[]
	 */
	public static function profiles() {
		return array(
			self::PROFILE_REQUIRES_YITH,
			self::PROFILE_NO_YITH,
			self::PROFILE_UNVERIFIED,
		);
	}

	/**
	 * Configuración efectiva.
	 *
	 * Reutiliza los ajustes que ya existen desde F1 y el host permitido del
	 * normalizador. No define ninguna clave nueva.
	 *
	 * @param array $overrides profiles_requires_yith, profiles_no_yith, allowed_host.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$normalizer = SCW_URL_Normalizer::config();

		$config = array(
			'profiles_requires_yith' => (array) SCW_Settings::get( 'profiles_requires_yith', array() ),
			'profiles_no_yith'       => (array) SCW_Settings::get( 'profiles_no_yith', array() ),
			'allowed_host'           => $normalizer['allowed_host'],
		);

		if ( ! is_array( $overrides ) ) {
			return $config;
		}

		foreach ( array( 'profiles_requires_yith', 'profiles_no_yith' ) as $key ) {
			if ( isset( $overrides[ $key ] ) ) {
				$config[ $key ] = (array) $overrides[ $key ];
			}
		}

		if ( isset( $overrides['allowed_host'] ) ) {
			$config['allowed_host'] = rtrim( strtolower( trim( (string) $overrides['allowed_host'] ) ), '.' );
		}

		return $config;
	}

	/**
	 * Revisa la configuración de perfiles y devuelve los patrones defectuosos.
	 *
	 * Pensado para diagnóstico: permite detectar una ruta mal escrita sin
	 * esperar a que una URL concreta la active. No evalúa ninguna URL.
	 *
	 * @param array $config Sobrescrituras de configuración.
	 * @return array Lista de avisos con source, pattern y code.
	 */
	public static function validate_patterns( $config = array() ) {
		$config   = self::config( is_array( $config ) ? $config : array() );
		$warnings = array();

		$lists = array(
			self::SOURCE_REQUIRES_YITH => $config['profiles_requires_yith'],
			self::SOURCE_NO_YITH       => $config['profiles_no_yith'],
		);

		$canonical = array(
			self::SOURCE_REQUIRES_YITH => array(),
			self::SOURCE_NO_YITH       => array(),
		);

		foreach ( $lists as $source => $patterns ) {
			foreach ( (array) $patterns as $pattern ) {
				$normalized = self::normalize_pattern( $pattern, $source, $warnings );

				if ( null !== $normalized ) {
					$canonical[ $source ][] = $normalized;
				}
			}
		}

		// Rutas declaradas a la vez en las dos listas.
		foreach ( array_intersect( $canonical[ self::SOURCE_REQUIRES_YITH ], $canonical[ self::SOURCE_NO_YITH ] ) as $conflict ) {
			$warnings[] = array(
				'source'  => self::SOURCE_NO_YITH,
				'pattern' => $conflict,
				'code'    => self::WARN_CONFLICT,
			);
		}

		return $warnings;
	}

	/**
	 * Busca una coincidencia exacta del path en una lista de patrones.
	 *
	 * @param string $path     Path canónico.
	 * @param array  $patterns Patrones configurados.
	 * @param string $source   Ajuste del que proceden.
	 * @param array  $warnings Avisos, por referencia.
	 * @return string|null Patrón coincidente en su forma canónica, o null.
	 */
	private static function find_match( $path, $patterns, $source, &$warnings ) {
		$match = null;

		// La lista se recorre entera aunque ya haya coincidencia: así los
		// patrones mal formados posteriores también quedan registrados, y el
		// resultado no depende del orden de la lista.
		foreach ( (array) $patterns as $pattern ) {
			$normalized = self::normalize_pattern( $pattern, $source, $warnings );

			if ( null === $normalized ) {
				continue;
			}

			if ( null === $match && $normalized === $path ) {
				$match = $normalized;
			}
		}

		return $match;
	}

	/**
	 * Registra un aviso si el path también está declarado en la otra lista.
	 *
	 * @param string $path     Path canónico.
	 * @param array  $patterns Patrones de la otra lista.
	 * @param array  $warnings Avisos, por referencia.
	 * @return void
	 */
	private static function detect_conflict( $path, $patterns, &$warnings ) {
		$discarded = array();

		foreach ( (array) $patterns as $pattern ) {
			$normalized = self::normalize_pattern( $pattern, self::SOURCE_NO_YITH, $discarded );

			if ( null !== $normalized && $normalized === $path ) {
				$warnings[] = array(
					'source'  => self::SOURCE_NO_YITH,
					'pattern' => $normalized,
					'code'    => self::WARN_CONFLICT,
				);

				return;
			}
		}
	}

	/**
	 * Lleva un patrón configurado a su forma canónica de comparación.
	 *
	 * Un patrón describe un PATH absoluto, nunca una URL completa ni un path
	 * con query o fragmento. Lo que no encaje se descarta con un aviso en vez
	 * de intentar adivinar qué quería decir: silenciar una ruta mal escrita
	 * sería peor que no aplicarla, porque desactivaría la expectativa sin que
	 * nadie se entere.
	 *
	 * @param mixed  $pattern  Patrón tal cual está configurado.
	 * @param string $source   Ajuste del que procede.
	 * @param array  $warnings Avisos, por referencia.
	 * @return string|null Forma canónica, o null si se descarta.
	 */
	private static function normalize_pattern( $pattern, $source, &$warnings ) {
		if ( ! is_string( $pattern ) || '' === trim( $pattern ) ) {
			$warnings[] = array(
				'source'  => $source,
				'pattern' => is_string( $pattern ) ? $pattern : '',
				'code'    => self::WARN_EMPTY_PATTERN,
			);

			return null;
		}

		$value = trim( $pattern );

		if ( '/' !== substr( $value, 0, 1 ) ) {
			$warnings[] = array(
				'source'  => $source,
				'pattern' => $value,
				'code'    => self::WARN_NOT_ABSOLUTE,
			);

			return null;
		}

		if ( false !== strpos( $value, '?' ) || false !== strpos( $value, '#' ) ) {
			$warnings[] = array(
				'source'  => $source,
				'pattern' => $value,
				'code'    => self::WARN_NOT_A_PATH,
			);

			return null;
		}

		return self::canonical_path( $value );
	}

	/**
	 * Forma canónica de comparación de un path.
	 *
	 * Única transformación: se elimina la barra final, de modo que "/x" y "/x/"
	 * son el mismo path. La raíz "/" se conserva tal cual.
	 *
	 * Deliberadamente NO se cambia el uso de mayúsculas ni se toca el
	 * percent-encoding: los paths son sensibles a mayúsculas y SCW_URL_Normalizer
	 * ya se ocupa de la codificación antes de que una URL llegue hasta aquí.
	 * Repetir esa normalización aquí crearía una segunda política de la misma
	 * cosa, que es exactamente lo que este proyecto evita.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function canonical_path( $path ) {
		$path = (string) $path;

		if ( '/' === $path || '' === $path ) {
			return '/';
		}

		$trimmed = rtrim( $path, '/' );

		return '' === $trimmed ? '/' : $trimmed;
	}

	/**
	 * Valida la URL y extrae el path sobre el que se resuelve el perfil.
	 *
	 * Reutiliza los códigos de error de SCW_URL_Normalizer para no inventar un
	 * segundo vocabulario para lo mismo, igual que ya hace SCW_URL_Exclusions.
	 *
	 * El query string y el fragmento se ignoran por completo: el perfil de una
	 * categoría no cambia porque se le añadan parámetros.
	 *
	 * @param mixed  $url           URL de entrada.
	 * @param array  $config        Configuración efectiva.
	 * @param string $error         Salida: código de error.
	 * @param string $error_message Salida: mensaje legible.
	 * @return string|null Path canónico, o null si la URL no es utilizable.
	 */
	private static function extract_path( $url, $config, &$error, &$error_message ) {
		$error         = null;
		$error_message = null;

		$url = is_string( $url ) ? trim( $url ) : '';

		if ( '' === $url ) {
			$error         = SCW_URL_Normalizer::ERROR_EMPTY_URL;
			$error_message = 'La URL está vacía.';
			return null;
		}

		if ( strlen( $url ) > SCW_URL_Normalizer::MAX_URL_LENGTH ) {
			$error         = SCW_URL_Normalizer::ERROR_TOO_LONG;
			$error_message = 'La URL supera la longitud máxima admitida.';
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			$error         = SCW_URL_Normalizer::ERROR_INVALID_URL;
			$error_message = 'La URL no se ha podido interpretar o no es absoluta.';
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, SCW_URL_Normalizer::SUPPORTED_SCHEMES, true ) ) {
			$error         = SCW_URL_Normalizer::ERROR_SCHEME;
			$error_message = 'Esquema no admitido: ' . $scheme . '.';
			return null;
		}

		if ( '' === $config['allowed_host'] ) {
			$error         = SCW_URL_Normalizer::ERROR_NO_HOST_SET;
			$error_message = 'No hay ningún hostname permitido configurado.';
			return null;
		}

		$host = rtrim( strtolower( trim( $parts['host'] ) ), '.' );

		if ( $host !== $config['allowed_host'] ) {
			$error         = SCW_URL_Normalizer::ERROR_HOST;
			$error_message = 'El hostname ' . $host . ' no coincide con el dominio permitido.';
			return null;
		}

		$path = ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';

		return self::canonical_path( $path );
	}

	/**
	 * Construye el resultado.
	 *
	 * @param string      $profile       Perfil.
	 * @param string|null $path          Path evaluado.
	 * @param string|null $pattern       Patrón coincidente.
	 * @param string|null $source        Ajuste de origen.
	 * @param array       $warnings      Avisos.
	 * @param bool        $processable   Si la URL era utilizable.
	 * @param string|null $error         Código de error.
	 * @param string|null $error_message Mensaje del error.
	 * @return array
	 */
	private static function result( $profile, $path, $pattern, $source, $warnings, $processable, $error, $error_message ) {
		return array(
			'profile'       => $profile,
			'matched'       => null !== $pattern,
			'path'          => $path,
			'pattern'       => $pattern,
			'source'        => $source,
			'processable'   => (bool) $processable,
			'error'         => $error,
			'error_message' => $error_message,
			'warnings'      => is_array( $warnings ) ? $warnings : array(),
		);
	}
}
