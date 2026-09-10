<?php
/**
 * Motor de exclusiones de URLs.
 *
 * Decide si una URL YA NORMALIZADA por SCW_URL_Normalizer debe quedar fuera del
 * crawler. Es sólo el motor de decisión: no encola, no descarta por su cuenta,
 * no toca la base de datos y no hace ninguna petición.
 *
 * El lugar previsto en el flujo, que se cableará en F2.4, es:
 *
 *   Sitemap -> extracción -> SCW_URL_Normalizer -> SCW_URL_Exclusions -> SCW_Queue
 *
 * Esta clase NO normaliza. Espera recibir la URL canónica que produjo F2.2 y se
 * limita a validarla antes de aplicar reglas, para no convertirse en un rodeo
 * del control de dominio.
 *
 * El match se hace siempre sobre el PATH de la URL, nunca sobre la URL completa,
 * porque las reglas del proyecto describen rutas internas. La query string se
 * ignora a efectos de exclusión: limpiarla es responsabilidad de F2.2.
 *
 * Aviso sobre configuración: un prefijo "/" excluiría el sitio entero. Es una
 * regla válida, pero conviene no escribirla por accidente.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_URL_Exclusions {

	const TYPE_EXACT  = 'exact';
	const TYPE_PREFIX = 'prefix';
	const TYPE_REGEX  = 'regex';

	// Motivos de exclusión. Estables: se escribirán en logs a partir de F3.
	const REASON_EXACT  = 'excluded_exact';
	const REASON_PREFIX = 'excluded_prefix';
	const REASON_REGEX  = 'excluded_regex';

	// Avisos sobre patrones mal configurados. No excluyen ni detienen nada.
	const WARN_EMPTY_PATTERN  = 'invalid_pattern_empty';
	const WARN_NOT_ABSOLUTE   = 'invalid_pattern_not_absolute';
	const WARN_INVALID_REGEX  = 'invalid_regex';
	const WARN_REGEX_RUNTIME  = 'regex_runtime_error';

	/**
	 * Comprueba si una URL normalizada está excluida.
	 *
	 * Orden de evaluación: exact, luego prefix, luego regex. La primera
	 * coincidencia gana y detiene la evaluación, de modo que un regex no llega a
	 * ejecutarse si ya hubo coincidencia exacta o por prefijo.
	 *
	 * @param string $normalized_url URL canónica producida por SCW_URL_Normalizer.
	 * @param array  $config         Sobrescrituras de configuración. Ver config().
	 * @return array {
	 *     @type bool        $excluded      True si alguna regla coincide.
	 *     @type bool        $processable   False si la URL no es válida para esta capa.
	 *     @type string|null $type          exact|prefix|regex, o null.
	 *     @type string|null $pattern       Patrón que provocó la exclusión, o null.
	 *     @type string|null $reason        Motivo estable, o null.
	 *     @type string|null $path          Path sobre el que se ha evaluado.
	 *     @type string|null $error         Código de error si no es procesable.
	 *     @type string|null $error_message Mensaje legible del error.
	 *     @type array       $warnings      Patrones descartados por estar mal formados.
	 * }
	 */
	public static function check( $normalized_url, $config = array() ) {
		$config = self::config( is_array( $config ) ? $config : array() );

		$path = self::extract_path( $normalized_url, $config, $error, $error_message );

		if ( null === $path ) {
			return self::result_not_processable( $error, $error_message );
		}

		$warnings = array();

		// 1. Exacta.
		foreach ( (array) $config['exclude_exact'] as $pattern ) {
			$pattern = (string) $pattern;

			if ( ! self::validate_path_pattern( $pattern, self::TYPE_EXACT, $warnings ) ) {
				continue;
			}

			if ( $path === $pattern ) {
				return self::result_excluded( self::TYPE_EXACT, $pattern, self::REASON_EXACT, $path, $warnings );
			}
		}

		// 2. Prefijo.
		foreach ( (array) $config['exclude_prefix'] as $pattern ) {
			$pattern = (string) $pattern;

			if ( ! self::validate_path_pattern( $pattern, self::TYPE_PREFIX, $warnings ) ) {
				continue;
			}

			if ( self::matches_prefix( $path, $pattern ) ) {
				return self::result_excluded( self::TYPE_PREFIX, $pattern, self::REASON_PREFIX, $path, $warnings );
			}
		}

		// 3. Regex.
		foreach ( (array) $config['exclude_regex'] as $pattern ) {
			$pattern = (string) $pattern;

			if ( '' === trim( $pattern ) ) {
				$warnings[] = array(
					'type'    => self::TYPE_REGEX,
					'pattern' => $pattern,
					'code'    => self::WARN_EMPTY_PATTERN,
				);
				continue;
			}

			$matched = self::safe_preg_match( $pattern, $path, $regex_error );

			if ( null === $matched ) {
				$warnings[] = array(
					'type'    => self::TYPE_REGEX,
					'pattern' => $pattern,
					'code'    => $regex_error,
				);
				continue;
			}

			if ( $matched ) {
				return self::result_excluded( self::TYPE_REGEX, $pattern, self::REASON_REGEX, $path, $warnings );
			}
		}

		return self::result_allowed( $path, $warnings );
	}

	/**
	 * Atajo booleano. Una URL no procesable NO se considera excluida: quien
	 * llame debe mirar el resultado estructurado para distinguir ambos casos.
	 *
	 * @param string $normalized_url URL.
	 * @param array  $config         Configuración.
	 * @return bool
	 */
	public static function is_excluded( $normalized_url, $config = array() ) {
		$result = self::check( $normalized_url, $config );

		return (bool) $result['excluded'];
	}

	/**
	 * Revisa la configuración de exclusiones y devuelve los patrones defectuosos.
	 *
	 * Pensado para el diagnóstico: permite avisar de un regex roto sin esperar a
	 * que una URL concreta lo active. No evalúa ninguna URL.
	 *
	 * @param array $config Sobrescrituras de configuración.
	 * @return array Lista de avisos con type, pattern y code.
	 */
	public static function validate_patterns( $config = array() ) {
		$config   = self::config( is_array( $config ) ? $config : array() );
		$warnings = array();

		foreach ( array( self::TYPE_EXACT, self::TYPE_PREFIX ) as $type ) {
			foreach ( (array) $config[ 'exclude_' . $type ] as $pattern ) {
				self::validate_path_pattern( (string) $pattern, $type, $warnings );
			}
		}

		foreach ( (array) $config['exclude_regex'] as $pattern ) {
			$pattern = (string) $pattern;

			if ( '' === trim( $pattern ) ) {
				$warnings[] = array(
					'type'    => self::TYPE_REGEX,
					'pattern' => $pattern,
					'code'    => self::WARN_EMPTY_PATTERN,
				);
				continue;
			}

			if ( null === self::safe_preg_match( $pattern, '/', $regex_error ) ) {
				$warnings[] = array(
					'type'    => self::TYPE_REGEX,
					'pattern' => $pattern,
					'code'    => $regex_error,
				);
			}
		}

		return $warnings;
	}

	/**
	 * Configuración efectiva.
	 *
	 * Reutiliza las tres opciones que ya existen desde F1 y, para la validación
	 * de la URL, la configuración de dominio de F2.2. No se crea ninguna opción
	 * nueva.
	 *
	 * @param array $overrides Claves a sobrescribir.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$normalizer = SCW_URL_Normalizer::config();

		$config = array(
			'allowed_host'   => $normalizer['allowed_host'],
			'exclude_exact'  => (array) SCW_Settings::get( 'exclude_exact', array() ),
			'exclude_prefix' => (array) SCW_Settings::get( 'exclude_prefix', array() ),
			'exclude_regex'  => (array) SCW_Settings::get( 'exclude_regex', array() ),
		);

		if ( is_array( $overrides ) ) {
			$config = array_merge( $config, $overrides );
		}

		$config['allowed_host'] = rtrim( strtolower( trim( (string) $config['allowed_host'] ) ), '.' );

		return $config;
	}

	/**
	 * Valida la URL y extrae el path sobre el que se evalúan las reglas.
	 *
	 * No normaliza nada: sólo comprueba que la URL es absoluta, del esquema
	 * admitido, sin userinfo, sin puerto extraño y del dominio permitido. Si algo
	 * falla devuelve null y rellena los parámetros por referencia.
	 *
	 * Reutiliza deliberadamente los códigos de error de SCW_URL_Normalizer para
	 * no inventar un segundo vocabulario para lo mismo.
	 *
	 * @param mixed  $url           URL de entrada.
	 * @param array  $config        Configuración efectiva.
	 * @param string $error         Salida: código de error.
	 * @param string $error_message Salida: mensaje legible.
	 * @return string|null Path, o null si la URL no es procesable.
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

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$error         = SCW_URL_Normalizer::ERROR_USERINFO;
			$error_message = 'Las URLs con usuario o contraseña no están permitidas.';
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

		if ( isset( $parts['port'] ) && '' !== $parts['port'] ) {
			$default = isset( SCW_URL_Normalizer::DEFAULT_PORTS[ $scheme ] ) ? SCW_URL_Normalizer::DEFAULT_PORTS[ $scheme ] : 0;

			if ( (int) $parts['port'] !== $default ) {
				$error         = SCW_URL_Normalizer::ERROR_PORT;
				$error_message = 'Puerto no permitido: ' . (int) $parts['port'] . '.';
				return null;
			}
		}

		return ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
	}

	/**
	 * Semántica del prefijo.
	 *
	 * El path queda excluido si empieza por el patrón. Como los patrones del
	 * proyecto terminan en "/", el límite de segmento se respeta solo: "/autor/"
	 * no encaja con "/autoridad/", y "/carrito/" no encaja con "/carritos/".
	 *
	 * Se añade una única tolerancia: si el patrón termina en "/", el path sin esa
	 * barra final también se considera excluido, de modo que "/carrito" encaje
	 * con "/carrito/". Es compatibilidad con la política de slash de F2.2, no una
	 * segunda política: no altera el path ni decide nada más sobre barras.
	 *
	 * @param string $path    Path de la URL.
	 * @param string $pattern Patrón.
	 * @return bool
	 */
	private static function matches_prefix( $path, $pattern ) {
		if ( 0 === strpos( $path, $pattern ) ) {
			return true;
		}

		if ( '/' === substr( $pattern, -1 ) ) {
			$without_slash = rtrim( $pattern, '/' );

			if ( '' !== $without_slash && $path === $without_slash ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Valida un patrón de path (exacta o prefijo).
	 *
	 * Un patrón vacío o que no empieza por "/" se descarta con un aviso, en vez
	 * de intentar adivinar qué quería decir. Los patrones describen paths
	 * absolutos, nunca URLs completas ni dominios.
	 *
	 * @param string $pattern  Patrón.
	 * @param string $type     Tipo.
	 * @param array  $warnings Lista de avisos, por referencia.
	 * @return bool True si el patrón es utilizable.
	 */
	private static function validate_path_pattern( $pattern, $type, &$warnings ) {
		if ( '' === trim( $pattern ) ) {
			$warnings[] = array(
				'type'    => $type,
				'pattern' => $pattern,
				'code'    => self::WARN_EMPTY_PATTERN,
			);
			return false;
		}

		if ( '/' !== substr( $pattern, 0, 1 ) ) {
			$warnings[] = array(
				'type'    => $type,
				'pattern' => $pattern,
				'code'    => self::WARN_NOT_ABSOLUTE,
			);
			return false;
		}

		return true;
	}

	/**
	 * Ejecuta un preg_match sin que un patrón roto rompa el plugin.
	 *
	 * Un patrón inválido hace que PHP emita un warning y que preg_match devuelva
	 * false. Se instala un manejador de errores temporal durante la llamada para
	 * que ese warning no llegue al log ni a la salida, y se devuelve null para
	 * que quien llame lo trate como patrón descartado.
	 *
	 * No hay sandbox ni análisis del patrón: PCRE ya aplica sus propios límites
	 * de backtracking y recursión, y cuando los supera devuelve false igual que
	 * un patrón inválido, de modo que un regex catastrófico se descarta en lugar
	 * de colgar el proceso.
	 *
	 * @param string $pattern Patrón PCRE completo, con delimitadores.
	 * @param string $subject Cadena a evaluar.
	 * @param string $error   Salida: código de aviso.
	 * @return bool|null True o false según el match, null si el patrón falló.
	 */
	private static function safe_preg_match( $pattern, $subject, &$error ) {
		$error = null;

		// Un patrón necesita como mínimo delimitador, contenido y delimitador.
		if ( strlen( $pattern ) < 3 ) {
			$error = self::WARN_INVALID_REGEX;
			return null;
		}

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			static function () {
				return true;
			}
		);

		$result = preg_match( $pattern, $subject );

		restore_error_handler();

		if ( false === $result ) {
			// Un patrón que no compila deja PREG_INTERNAL_ERROR. Los límites de
			// backtracking, recursión o UTF-8 son fallos de ejecución sobre un
			// patrón que sí compilaba, y conviene distinguirlos porque señalan
			// una regex peligrosa más que una mal escrita.
			$runtime_errors = array( PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR );

			if ( defined( 'PREG_JIT_STACKLIMIT_ERROR' ) ) {
				$runtime_errors[] = PREG_JIT_STACKLIMIT_ERROR;
			}

			$error = in_array( preg_last_error(), $runtime_errors, true ) ? self::WARN_REGEX_RUNTIME : self::WARN_INVALID_REGEX;

			return null;
		}

		return 1 === $result;
	}

	/**
	 * Resultado: URL excluida.
	 *
	 * @param string $type     Tipo.
	 * @param string $pattern  Patrón.
	 * @param string $reason   Motivo.
	 * @param string $path     Path evaluado.
	 * @param array  $warnings Avisos.
	 * @return array
	 */
	private static function result_excluded( $type, $pattern, $reason, $path, $warnings ) {
		return array(
			'excluded'      => true,
			'processable'   => true,
			'type'          => $type,
			'pattern'       => $pattern,
			'reason'        => $reason,
			'path'          => $path,
			'error'         => null,
			'error_message' => null,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Resultado: URL no excluida.
	 *
	 * @param string $path     Path evaluado.
	 * @param array  $warnings Avisos.
	 * @return array
	 */
	private static function result_allowed( $path, $warnings ) {
		return array(
			'excluded'      => false,
			'processable'   => true,
			'type'          => null,
			'pattern'       => null,
			'reason'        => null,
			'path'          => $path,
			'error'         => null,
			'error_message' => null,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Resultado: URL no procesable por esta capa.
	 *
	 * @param string $error         Código.
	 * @param string $error_message Mensaje.
	 * @return array
	 */
	private static function result_not_processable( $error, $error_message ) {
		return array(
			'excluded'      => false,
			'processable'   => false,
			'type'          => null,
			'pattern'       => null,
			'reason'        => null,
			'path'          => null,
			'error'         => $error,
			'error_message' => $error_message,
			'warnings'      => array(),
		);
	}
}
