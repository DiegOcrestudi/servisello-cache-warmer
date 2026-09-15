<?php
/**
 * Validación de integridad básica del HTML recibido.
 *
 * Responsabilidad única: mirar el cuerpo de una respuesta HTTP y decir si
 * presenta señales de estar roto, vacío o incompleto. NO sabe nada de YITH, de
 * Elementor, de perfiles de página ni de caché, no accede a la base de datos,
 * no hace peticiones y no decide el estado final de una URL: eso lo combina
 * SCW_Content_Validator (F4.4). Esta clase es una función pura.
 *
 * -----------------------------------------------------------------------
 * ANOMALÍAS FUERTES FRENTE A SEÑALES INFORMATIVAS
 *
 * Esto NO es un validador W3C. Una página no es incorrecta por tener HTML
 * imperfecto: los sitios reales, y más uno con WooCommerce, Elementor y
 * optimización de LiteSpeed por delante, emiten a diario marcado que un
 * validador estricto rechazaría y que sin embargo se renderiza perfectamente.
 *
 * Por eso el resultado separa dos niveles:
 *
 *  - anomalies (FUERTES): hallazgos compatibles con una respuesta rota, vacía
 *    o cortada. Sólo estas hacen ok = false, y sólo estas deben poder llevar
 *    una URL a DUDOSA.
 *  - signals (INFORMATIVAS): observaciones que se registran para poder
 *    diagnosticar después, pero que NO descalifican la página por sí mismas.
 *
 * Quien llame a esta clase debe respetar esa separación: mirar 'ok' o
 * 'anomalies', nunca 'signals', para decidir un veredicto.
 * -----------------------------------------------------------------------
 *
 * SOBRE EL UMBRAL DE TAMAÑO (importante)
 *
 * Hay DOS umbrales distintos y deliberadamente separados:
 *
 *  1. ABSOLUTE_MIN_BYTES: suelo duro. Un documento por debajo de este tamaño
 *     no puede ser una página real de este sitio ni de ningún otro; es un
 *     fragmento, un error o una respuesta vacía. Cruzarlo es ANOMALÍA FUERTE.
 *
 *  2. El ajuste min_html_bytes (20000 por defecto desde F1): tamaño ESPERADO
 *     de una página completa. Ese valor NO está verificado contra páginas
 *     reales de Servisello todavía; se medirá en el TEST 1 controlado. Hasta
 *     entonces, quedarse por debajo de él se registra como SEÑAL, no como
 *     anomalía fuerte, para no marcar como DUDOSAS páginas legítimas basándose
 *     en un número que aún no hemos comprobado.
 *
 * Una vez medidas las páginas reales, hay dos caminos posibles: ajustar
 * min_html_bytes al valor observado (sin tocar código, es un ajuste) y/o
 * promover SIGNAL_BELOW_MIN_BYTES a anomalía fuerte (una línea en check()).
 * Mientras no haya esa evidencia, este fichero no da por buena ninguna de las
 * dos cosas.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_HTML_Validator {

	// --- Anomalías fuertes -------------------------------------------------
	// Sólo estas hacen ok = false.

	/** El cuerpo está vacío o sólo contiene espacios en blanco. */
	const ANOMALY_EMPTY_BODY = 'empty_body';

	/** El cuerpo es tan pequeño que no puede ser una página completa. */
	const ANOMALY_BODY_TOO_SMALL = 'body_too_small';

	/** El Content-Type indica algo que claramente no es HTML. */
	const ANOMALY_CONTENT_TYPE = 'content_type_not_html';

	/** Truncamiento confirmado por el cliente HTTP (se alcanzó el límite de body). */
	const ANOMALY_TRUNCATED = 'truncated';

	/** Falta la etiqueta de cierre </html> y la regla está habilitada. */
	const ANOMALY_MISSING_CLOSING_HTML = 'missing_closing_html';

	// --- Señales informativas ----------------------------------------------
	// Se registran para diagnóstico. NUNCA descalifican la página.

	/** Por debajo del tamaño esperado (min_html_bytes) pero por encima del suelo duro. */
	const SIGNAL_BELOW_MIN_BYTES = 'body_below_min_html_bytes';

	/** No venía cabecera Content-Type. Ausencia no es lo mismo que incorrecto. */
	const SIGNAL_CONTENT_TYPE_MISSING = 'content_type_missing';

	/** Falta </body> aunque </html> sí está presente. */
	const SIGNAL_MISSING_CLOSING_BODY = 'missing_closing_body';

	/** No se ha encontrado ninguna declaración DOCTYPE. */
	const SIGNAL_NO_DOCTYPE = 'no_doctype';

	/** No se ha encontrado ninguna etiqueta <html. */
	const SIGNAL_NO_HTML_ELEMENT = 'no_html_element';

	/**
	 * Suelo duro de tamaño, en bytes.
	 *
	 * Valor deliberadamente muy conservador: cualquier página real del sitio
	 * está uno o dos órdenes de magnitud por encima. Sólo pretende separar
	 * "respuesta rota o fragmento" de "página que quizá sea más corta de lo
	 * que esperábamos". No sustituye a min_html_bytes ni compite con él.
	 *
	 * Si min_html_bytes se configurase por debajo de este valor, el suelo
	 * efectivo baja hasta él: nunca puede ser más exigente que el ajuste.
	 */
	const ABSOLUTE_MIN_BYTES = 512;

	/**
	 * Tipos MIME aceptados como HTML.
	 *
	 * La comparación se hace sobre el tipo, ignorando parámetros: un
	 * "text/html; charset=UTF-8" es HTML perfectamente válido.
	 */
	const ACCEPTED_MIME_TYPES = array( 'text/html', 'application/xhtml+xml' );

	/**
	 * Comprueba la integridad básica de un cuerpo de respuesta.
	 *
	 * No lanza excepciones ni emite avisos: una entrada anómala es un caso
	 * normal y se informa en el resultado.
	 *
	 * @param string $body Cuerpo de la respuesta. Cualquier cosa que no sea
	 *                     una cadena se trata como cuerpo vacío.
	 * @param array  $args {
	 *     Contexto y sobrescrituras de configuración. Todas opcionales.
	 *
	 *     @type string|null $content_type         Cabecera Content-Type tal cual llegó.
	 *     @type bool        $truncated            True si el cliente HTTP confirmó truncamiento.
	 *     @type int         $min_html_bytes       Sobrescribe el ajuste.
	 *     @type bool        $require_closing_tags Sobrescribe el ajuste.
	 * }
	 * @return array {
	 *     @type bool     $ok            True si NO hay ninguna anomalía fuerte.
	 *     @type int      $bytes         Tamaño del cuerpo recibido.
	 *     @type string|null $mime       Tipo MIME normalizado, o null.
	 *     @type array    $anomalies     Anomalías fuertes: { code, message, context }.
	 *     @type array    $signals       Señales informativas: { code, message, context }.
	 *     @type string[] $anomaly_codes Atajo: sólo los códigos de $anomalies.
	 *     @type string[] $signal_codes  Atajo: sólo los códigos de $signals.
	 * }
	 */
	public static function check( $body, $args = array() ) {
		$args   = is_array( $args ) ? $args : array();
		$config = self::config( $args );

		$body  = is_string( $body ) ? $body : '';
		$bytes = strlen( $body );

		$content_type = isset( $args['content_type'] ) ? $args['content_type'] : null;
		$truncated    = ! empty( $args['truncated'] );

		$result = array(
			'ok'            => true,
			'bytes'         => $bytes,
			'mime'          => self::mime( $content_type ),
			'anomalies'     => array(),
			'signals'       => array(),
			'anomaly_codes' => array(),
			'signal_codes'  => array(),
		);

		// 1. Cuerpo vacío. Se corta aquí: comprobar tamaño, etiquetas de cierre
		// o estructura sobre un cuerpo vacío sólo produciría hallazgos
		// redundantes que repiten la misma causa.
		if ( '' === trim( $body ) ) {
			self::add_anomaly(
				$result,
				self::ANOMALY_EMPTY_BODY,
				'La respuesta no tiene contenido.',
				array( 'bytes' => $bytes )
			);

			return self::finalize( $result );
		}

		// 2. Content-Type. Ausente es una señal; presente y no HTML es fuerte.
		self::check_content_type( $result );

		// 3. Tamaño. Suelo duro -> fuerte. Por debajo de lo esperado -> señal.
		self::check_size( $result, $bytes, $config );

		// 4. Truncamiento confirmado por el cliente HTTP.
		if ( $truncated ) {
			self::add_anomaly(
				$result,
				self::ANOMALY_TRUNCATED,
				'El cliente HTTP ha confirmado que la respuesta se truncó al alcanzar el límite de tamaño.',
				array( 'bytes' => $bytes )
			);
		}

		// 5. Etiquetas de cierre. Si ya sabemos que la respuesta venía cortada,
		// la ausencia de </html> es consecuencia de eso y no un hallazgo
		// independiente: no se cuenta dos veces la misma causa.
		if ( ! $truncated ) {
			self::check_closing_tags( $result, $body, $config );
		}

		// 6. Estructura. Siempre informativo, nunca descalifica.
		self::check_structure( $result, $body );

		return self::finalize( $result );
	}

	/**
	 * Configuración efectiva.
	 *
	 * Reutiliza los ajustes que ya existen desde F1. No define ninguna clave
	 * nueva.
	 *
	 * @param array $overrides min_html_bytes, require_closing_tags.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$min = (int) SCW_Settings::get( 'min_html_bytes', 0 );

		$config = array(
			'min_html_bytes'       => max( 0, $min ),
			'require_closing_tags' => (bool) SCW_Settings::get( 'require_closing_tags', true ),
		);

		if ( ! is_array( $overrides ) ) {
			return $config;
		}

		if ( isset( $overrides['min_html_bytes'] ) ) {
			$config['min_html_bytes'] = max( 0, (int) $overrides['min_html_bytes'] );
		}

		if ( isset( $overrides['require_closing_tags'] ) ) {
			$config['require_closing_tags'] = (bool) $overrides['require_closing_tags'];
		}

		return $config;
	}

	/**
	 * Suelo duro efectivo, en bytes.
	 *
	 * Nunca es más exigente que min_html_bytes: si alguien configurase un
	 * tamaño esperado menor que el suelo, mandaría el ajuste.
	 *
	 * @param array $config Configuración efectiva.
	 * @return int
	 */
	public static function absolute_min_bytes( $config ) {
		$floor = self::ABSOLUTE_MIN_BYTES;
		$min   = isset( $config['min_html_bytes'] ) ? (int) $config['min_html_bytes'] : 0;

		if ( $min > 0 && $min < $floor ) {
			return $min;
		}

		return $floor;
	}

	/**
	 * Normaliza una cabecera Content-Type a su tipo MIME.
	 *
	 * Descarta parámetros ("; charset=UTF-8") y normaliza a minúsculas.
	 *
	 * @param mixed $content_type Cabecera tal cual llegó.
	 * @return string|null Null si no venía o venía vacía.
	 */
	public static function mime( $content_type ) {
		if ( ! is_string( $content_type ) ) {
			return null;
		}

		$value = trim( $content_type );

		if ( '' === $value ) {
			return null;
		}

		$parts = explode( ';', $value );

		$mime = strtolower( trim( $parts[0] ) );

		return '' === $mime ? null : $mime;
	}

	/**
	 * Evalúa el Content-Type.
	 *
	 * @param array $result Resultado por referencia.
	 * @return void
	 */
	private static function check_content_type( &$result ) {
		if ( null === $result['mime'] ) {
			self::add_signal(
				$result,
				self::SIGNAL_CONTENT_TYPE_MISSING,
				'La respuesta no traía cabecera Content-Type.'
			);

			return;
		}

		if ( in_array( $result['mime'], self::ACCEPTED_MIME_TYPES, true ) ) {
			return;
		}

		self::add_anomaly(
			$result,
			self::ANOMALY_CONTENT_TYPE,
			'El Content-Type recibido no corresponde a un documento HTML.',
			array( 'mime' => $result['mime'] )
		);
	}

	/**
	 * Evalúa el tamaño del cuerpo con los dos umbrales.
	 *
	 * @param array $result Resultado por referencia.
	 * @param int   $bytes  Tamaño del cuerpo.
	 * @param array $config Configuración efectiva.
	 * @return void
	 */
	private static function check_size( &$result, $bytes, $config ) {
		$floor = self::absolute_min_bytes( $config );

		if ( $bytes < $floor ) {
			self::add_anomaly(
				$result,
				self::ANOMALY_BODY_TOO_SMALL,
				'La respuesta es demasiado pequeña para ser una página completa.',
				array(
					'bytes' => $bytes,
					'floor' => $floor,
				)
			);

			return;
		}

		$min = (int) $config['min_html_bytes'];

		// Por debajo del tamaño esperado: se registra, pero no descalifica.
		// Ver la nota sobre umbrales en el docblock de la clase.
		if ( $min > 0 && $bytes < $min ) {
			self::add_signal(
				$result,
				self::SIGNAL_BELOW_MIN_BYTES,
				'La respuesta es más pequeña que el tamaño esperado configurado.',
				array(
					'bytes'          => $bytes,
					'min_html_bytes' => $min,
				)
			);
		}
	}

	/**
	 * Evalúa las etiquetas de cierre.
	 *
	 * Sólo se evalúan si require_closing_tags está habilitado: si la regla
	 * está desactivada, esta clase no opina sobre el cierre del documento, ni
	 * siquiera de forma informativa.
	 *
	 * Que haya contenido DESPUÉS de </html> (comentarios de LiteSpeed, scripts
	 * inyectados por plugins) es completamente normal y no se considera nada:
	 * aquí sólo importa que la etiqueta esté presente.
	 *
	 * @param array  $result Resultado por referencia.
	 * @param string $body   Cuerpo.
	 * @param array  $config Configuración efectiva.
	 * @return void
	 */
	private static function check_closing_tags( &$result, $body, $config ) {
		if ( empty( $config['require_closing_tags'] ) ) {
			return;
		}

		if ( false === stripos( $body, '</html>' ) ) {
			self::add_anomaly(
				$result,
				self::ANOMALY_MISSING_CLOSING_HTML,
				'Falta la etiqueta de cierre </html>: el documento podría estar incompleto.'
			);

			return;
		}

		if ( false === stripos( $body, '</body>' ) ) {
			self::add_signal(
				$result,
				self::SIGNAL_MISSING_CLOSING_BODY,
				'Falta </body> aunque el documento sí cierra </html>.'
			);
		}
	}

	/**
	 * Observaciones estructurales, siempre informativas.
	 *
	 * Deliberadamente mínimas: comprobar la presencia de DOCTYPE y de <html no
	 * es validar el documento, es dejar constancia de que la respuesta tenía
	 * forma de página. Nada de esto descalifica una página.
	 *
	 * @param array  $result Resultado por referencia.
	 * @param string $body   Cuerpo.
	 * @return void
	 */
	private static function check_structure( &$result, $body ) {
		if ( false === stripos( $body, '<html' ) ) {
			self::add_signal(
				$result,
				self::SIGNAL_NO_HTML_ELEMENT,
				'No se ha encontrado ninguna etiqueta <html en la respuesta.'
			);
		}

		if ( false === stripos( $body, '<!doctype' ) ) {
			self::add_signal(
				$result,
				self::SIGNAL_NO_DOCTYPE,
				'No se ha encontrado ninguna declaración DOCTYPE.'
			);
		}
	}

	/**
	 * Añade una anomalía fuerte.
	 *
	 * @param array  $result  Resultado por referencia.
	 * @param string $code    Código estable.
	 * @param string $message Mensaje legible.
	 * @param array  $context Datos de apoyo.
	 * @return void
	 */
	private static function add_anomaly( &$result, $code, $message, $context = array() ) {
		$result['anomalies'][] = array(
			'code'    => $code,
			'message' => $message,
			'context' => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * Añade una señal informativa.
	 *
	 * @param array  $result  Resultado por referencia.
	 * @param string $code    Código estable.
	 * @param string $message Mensaje legible.
	 * @param array  $context Datos de apoyo.
	 * @return void
	 */
	private static function add_signal( &$result, $code, $message, $context = array() ) {
		$result['signals'][] = array(
			'code'    => $code,
			'message' => $message,
			'context' => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * Cierra el resultado: calcula ok y los atajos de códigos.
	 *
	 * ok depende EXCLUSIVAMENTE de las anomalías fuertes. Las señales nunca
	 * intervienen.
	 *
	 * @param array $result Resultado.
	 * @return array
	 */
	private static function finalize( $result ) {
		$result['ok'] = empty( $result['anomalies'] );

		$result['anomaly_codes'] = wp_list_pluck( $result['anomalies'], 'code' );
		$result['signal_codes']  = wp_list_pluck( $result['signals'], 'code' );

		return $result;
	}
}
