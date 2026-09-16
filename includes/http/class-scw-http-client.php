<?php
/**
 * Cliente HTTP para las peticiones de calentamiento.
 *
 * Responsabilidad única: realizar UNA petición HTTP contra una URL y devolver
 * un resultado estructurado con los metadatos que SCW_Worker necesita para
 * registrar el run. Esta clase no decide qué URL calentar, no escribe en
 * ninguna tabla y no aplica ninguna política de reintentos, pacing ni circuit
 * breaker: eso pertenece a fases posteriores.
 *
 * Usa exclusivamente la API HTTP de WordPress (wp_safe_remote_get()). No hay
 * sockets manuales, cURL directo ni librerías externas.
 *
 * -----------------------------------------------------------------------
 * DECISIÓN F3 SOBRE REDIRECTS Y SSRF (ver informe de preparación, sección 10
 * de las instrucciones de F3):
 *
 * Esta clase NUNCA sigue redirects automáticamente ('redirection' => 0). La
 * documentación pública de wp_safe_remote_get() afirma que "la URL, y cada
 * URL a la que redirige, se validan con wp_http_validate_url()", pero existe
 * un issue abierto en el propio tracker de documentación de WordPress
 * (WordPress/Documentation-Issue-Tracker#1193) que señala que esa validación
 * de saltos de redirección no está realmente implementada. Además, aunque lo
 * estuviera, wp_http_validate_url() sólo bloquea IPs privadas/loopback y
 * puertos no estándar (protección genérica contra SSRF hacia red interna),
 * NO restringe el destino a un único host público concreto como
 * www.servisello.es. Un redirect hacia otro dominio público legítimo no
 * quedaría bloqueado por esa función aunque la revalidación de saltos
 * existiera de verdad.
 *
 * Ante esa doble incertidumbre (documentación contradicha por un issue
 * abierto, y protección que en cualquier caso no cubre el requisito real de
 * "no salir de este host"), la única forma verificable de garantizar que el
 * warmer nunca termina pidiendo un host no autorizado es no seguir ningún
 * redirect automáticamente. Un 3xx se registra como resultado HTTP en
 * scw_runs y la fila de Queue se marca failed, sin haber llegado nunca a
 * solicitar el destino del redirect.
 *
 * No se implementa (deliberadamente, por ser F3) un resolutor de redirects
 * salto a salto: si en el futuro se necesita seguir redirects de forma
 * segura, deberá revalidarse el host permitido en cada salto, uno a uno.
 * -----------------------------------------------------------------------
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_HTTP_Client {

	// Tipos de error, en el mismo estilo snake_case que el resto del proyecto.
	const ERROR_INVALID_URL = 'invalid_url';
	const ERROR_HOST        = 'host_not_allowed';
	const ERROR_TIMEOUT     = 'timeout';
	const ERROR_TRANSPORT   = 'transport_error';

	/**
	 * Límite de memoria explícito para el body de la respuesta.
	 *
	 * No es un límite de validación de contenido ni una forma de truncar
	 * páginas: es sólo una red de seguridad de memoria ante una respuesta
	 * anómala. 10 MB es muy superior a cualquier página real del sitio
	 * (min_html_bytes son 20 KB; ni una categoría con YITH se acerca a un
	 * múltiplo de eso), así que una página normal nunca lo alcanza y por
	 * tanto nunca se trunca en un caso real. Si alguna vez se alcanza, el
	 * resultado lo señala explícitamente en diagnostics (possibly_truncated),
	 * nunca en silencio.
	 */
	const MAX_BODY_BYTES = 10485760;

	/**
	 * Realiza la petición de calentamiento.
	 *
	 * @param string $url    URL a solicitar (ya normalizada por quien llama).
	 * @param array  $config Sobrescrituras: timeout, user_agent, allowed_host.
	 * @return array {
	 *     @type bool        $ok                        True si se ha recibido una respuesta HTTP real.
	 *     @type int         $http_status               Código HTTP, 0 si no hubo respuesta.
	 *     @type int         $duration_ms               Duración de la petición.
	 *     @type int         $bytes                     Bytes reales del body recibido.
	 *     @type string      $body                      Cuerpo de la respuesta, cadena vacía si no la hubo.
	 *                                                  Se devuelve para que la validación de contenido (F4)
	 *                                                  pueda analizarlo en el mismo ciclo. NUNCA se persiste
	 *                                                  en base de datos: quien lo reciba debe consumirlo y
	 *                                                  soltarlo. El límite de MAX_BODY_BYTES sigue vigente.
	 *     @type string|null $content_type
	 *     @type string|null $x_litespeed_cache
	 *     @type string|null $x_litespeed_cache_control
	 *     @type string|null $vary
	 *     @type string|null $redirect_location         Location del 3xx, si lo hubo.
	 *     @type string      $user_agent                User-Agent efectivamente enviado.
	 *     @type string|null $error_type
	 *     @type string|null $error_message
	 *     @type array       $diagnostics               Datos adicionales para diagnóstico.
	 * }
	 */
	public static function fetch( $url, $config = array() ) {
		$config = self::config( is_array( $config ) ? $config : array() );

		$url = is_string( $url ) ? $url : '';

		$base = array(
			'ok'                        => false,
			'http_status'               => 0,
			'duration_ms'               => 0,
			'bytes'                     => 0,
			'body'                      => '',
			'content_type'              => null,
			'x_litespeed_cache'         => null,
			'x_litespeed_cache_control' => null,
			'vary'                      => null,
			'redirect_location'         => null,
			'user_agent'                => $config['user_agent'],
			'error_type'                => null,
			'error_message'             => null,
			'diagnostics'               => array(),
		);

		$host_error = self::check_host( $url, $config );

		if ( null !== $host_error ) {
			$base['error_type']    = $host_error['error_type'];
			$base['error_message'] = $host_error['error_message'];

			return $base;
		}

		$args = array(
			'timeout'             => $config['timeout'],
			// Decisión F3: nunca se siguen redirects automáticamente. Ver docblock de la clase.
			'redirection'         => 0,
			'sslverify'           => true,
			'user-agent'          => $config['user_agent'],
			'limit_response_size' => self::MAX_BODY_BYTES,
			// Sin cookies: se omite la clave a propósito, no se pasa ningún jar.
		);

		$started  = microtime( true );
		$response = wp_safe_remote_get( $url, $args );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		$base['duration_ms'] = $duration;

		if ( is_wp_error( $response ) ) {
			$base['error_type']    = self::classify_wp_error( $response );
			$base['error_message'] = $response->get_error_message();

			return $base;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = (int) wp_remote_retrieve_response_code( $response );

		$base['ok']          = true;
		$base['http_status'] = $code;
		$base['bytes']       = strlen( (string) $body );
		$base['body']        = (string) $body;

		$base['content_type']              = self::header( $response, 'content-type' );
		$base['x_litespeed_cache']         = self::header( $response, 'x-litespeed-cache' );
		$base['x_litespeed_cache_control'] = self::header( $response, 'x-litespeed-cache-control' );
		$base['vary']                      = self::header( $response, 'vary' );

		if ( $code >= 300 && $code < 400 ) {
			$base['redirect_location'] = self::header( $response, 'location' );
		}

		if ( self::MAX_BODY_BYTES === $base['bytes'] ) {
			$base['diagnostics']['possibly_truncated'] = true;
		}

		$content_length_header = self::header( $response, 'content-length' );

		if ( null !== $content_length_header && (int) $content_length_header !== $base['bytes'] ) {
			$base['diagnostics']['content_length_header'] = (int) $content_length_header;
		}

		return $base;
	}

	/**
	 * Configuración efectiva.
	 *
	 * @param array $overrides Claves a sobrescribir.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$normalizer = SCW_URL_Normalizer::config();

		$timeout = (int) SCW_Settings::get( 'http_timeout', 30 );

		if ( $timeout < 1 ) {
			$timeout = 30;
		}

		$config = array(
			'timeout'      => $timeout,
			'user_agent'   => (string) SCW_Settings::get( 'user_agent', '' ),
			'allowed_host' => $normalizer['allowed_host'],
		);

		if ( is_array( $overrides ) ) {
			$config = array_merge( $config, $overrides );
		}

		return $config;
	}

	/**
	 * Comprueba el host y el esquema de la URL antes de hacer ninguna petición.
	 *
	 * Es una red de seguridad, no una segunda normalización: quien llame a
	 * esta clase (SCW_Worker) ya debe haber comprobado la URL con
	 * SCW_URL_Normalizer y SCW_URL_Exclusions. Esta comprobación existe para
	 * que, aunque algo llamara a esta clase sin pasar por esa validación,
	 * nunca se llegue a hacer una petición a un host distinto del permitido.
	 *
	 * @param string $url    URL a comprobar.
	 * @param array  $config Configuración efectiva.
	 * @return array|null Null si es aceptable, o { error_type, error_message }.
	 */
	private static function check_host( $url, $config ) {
		if ( '' === trim( $url ) ) {
			return array(
				'error_type'    => self::ERROR_INVALID_URL,
				'error_message' => 'La URL está vacía.',
			);
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array(
				'error_type'    => self::ERROR_INVALID_URL,
				'error_message' => 'La URL no se ha podido interpretar o no es absoluta.',
			);
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array(
				'error_type'    => self::ERROR_INVALID_URL,
				'error_message' => 'Esquema no admitido: ' . $scheme . '.',
			);
		}

		$host = rtrim( strtolower( trim( $parts['host'] ) ), '.' );

		if ( '' === $config['allowed_host'] || $host !== $config['allowed_host'] ) {
			return array(
				'error_type'    => self::ERROR_HOST,
				'error_message' => 'El host ' . $host . ' no coincide con el host permitido.',
			);
		}

		return null;
	}

	/**
	 * Clasifica un WP_Error de transporte.
	 *
	 * Heurística basada en el mensaje: la API HTTP de WordPress no expone un
	 * código de error propio y estable para "timeout" en todos los
	 * transportes, así que se detecta por el texto del mensaje. No es
	 * infalible; si el mensaje no encaja con el patrón conocido se clasifica
	 * como error de transporte genérico.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private static function classify_wp_error( $error ) {
		$message = strtolower( $error->get_error_message() );

		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return self::ERROR_TIMEOUT;
		}

		return self::ERROR_TRANSPORT;
	}

	/**
	 * Extrae un header y lo normaliza a null si viene vacío.
	 *
	 * @param array  $response Respuesta de wp_safe_remote_get().
	 * @param string $name     Nombre del header, en minúsculas.
	 * @return string|null
	 */
	private static function header( $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );

		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
