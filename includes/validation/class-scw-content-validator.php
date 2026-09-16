<?php
/**
 * Decisión de integridad de una respuesta.
 *
 * Es la fachada de la validación: recibe el resultado de SCW_HTTP_Client y la
 * URL pedida, orquesta las tres piezas de F4 y devuelve un único veredicto
 * junto con toda la evidencia que lo respalda.
 *
 *   SCW_HTML_Validator  (F4.1) -> ¿el documento está entero?
 *   SCW_Page_Profile    (F4.2) -> ¿qué esperábamos de esta URL?
 *   SCW_YITH_Validator  (F4.3) -> ¿qué filtro YITH trae realmente?
 *
 * Esta clase es el ÚNICO sitio donde viven las reglas que combinan esas tres
 * respuestas. El Worker hace una sola llamada y traduce el resultado; no toma
 * ninguna decisión por su cuenta.
 *
 * Es una función pura: no toca la base de datos, no hace peticiones, no
 * escribe eventos, no purga nada y no reintenta nada. Observa y dictamina.
 *
 * -----------------------------------------------------------------------
 * PRECEDENCIA
 *
 *   ERROR  >  DUDOSA  >  OK
 *
 * Si la petición HTTP falló o no devolvió 2xx, el veredicto es ERROR y NO se
 * ejecuta ninguna validación de contenido: el cuerpo de una página de error no
 * dice nada útil sobre la integridad de la página que queríamos calentar.
 *
 * Sólo un 2xx puede acabar siendo OK o DUDOSA.
 *
 * ANOMALÍAS FUERTES FRENTE A SEÑALES
 *
 * Únicamente las anomalías fuertes de SCW_HTML_Validator y los motivos YITH
 * producen DUDOSA. Las señales informativas (tamaño por debajo del esperado,
 * Content-Type ausente, falta de </body>, ausencia de DOCTYPE, YITH inesperado
 * en una página NO_YITH) se registran íntegras y NUNCA cambian el veredicto.
 *
 * LO QUE NO INTERVIENE
 *
 * X-LiteSpeed-Cache no participa en ninguna regla. Un HIT no certifica nada:
 * precisamente el problema que este plugin existe para detectar es una
 * respuesta 200 + HIT con HTML incorrecto. La cabecera se guarda siempre como
 * dato, nunca como criterio.
 *
 * Elementor no participa en ninguna regla, ni como criterio ni como dato.
 * F4.3 demuestra que la evidencia YITH se valida sin depender de él.
 *
 * Tampoco intervienen el User-Agent, las cookies ni el momento de la petición.
 * -----------------------------------------------------------------------
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Content_Validator {

	/** Veredictos. Se guardan tal cual en scw_runs.validation_result. */
	const RESULT_OK         = 'ok';
	const RESULT_SUSPICIOUS = 'suspicious';
	const RESULT_ERROR      = 'error';

	/**
	 * Motivos de DUDOSA procedentes de la integridad del HTML.
	 *
	 * Coinciden con los códigos de anomalía fuerte de SCW_HTML_Validator: no
	 * se traducen ni se renombran, para que el motivo de la cola y el código
	 * registrado en diagnostics sean la misma palabra.
	 */
	const REASON_TRUNCATED      = SCW_HTML_Validator::ANOMALY_TRUNCATED;
	const REASON_EMPTY_BODY     = SCW_HTML_Validator::ANOMALY_EMPTY_BODY;
	const REASON_BODY_TOO_SMALL = SCW_HTML_Validator::ANOMALY_BODY_TOO_SMALL;
	const REASON_CONTENT_TYPE   = SCW_HTML_Validator::ANOMALY_CONTENT_TYPE;
	const REASON_NO_CLOSING     = SCW_HTML_Validator::ANOMALY_MISSING_CLOSING_HTML;

	/** Motivos de DUDOSA procedentes de la expectativa YITH. */
	const REASON_YITH_MISSING      = 'yith_missing';
	const REASON_YITH_NO_PRESET    = 'yith_no_preset';
	const REASON_YITH_UNKNOWN      = 'yith_unknown_preset';
	const REASON_YITH_INCONSISTENT = 'yith_inconsistent';

	/** Señales propias de esta fachada. Nunca cambian el veredicto. */
	const SIGNAL_UNEXPECTED_YITH      = 'unexpected_yith';
	const SIGNAL_YITH_INCONSISTENT    = 'yith_inconsistent_container';
	const SIGNAL_YITH_UNKNOWN_PRESETS = 'yith_unknown_presets_present';

	/**
	 * Gravedad de los motivos, de mayor a menor.
	 *
	 * Sirve para elegir el ÚNICO motivo que se escribe en la cola cuando
	 * concurren varios. El criterio es poner primero las causas que explican
	 * la respuesta entera: si el documento venía truncado o vacío, la falta
	 * del filtro YITH es una consecuencia, no un hallazgo independiente.
	 *
	 * Todas las anomalías se conservan igualmente en diagnostics.
	 */
	const SEVERITY = array(
		self::REASON_TRUNCATED,
		self::REASON_EMPTY_BODY,
		self::REASON_BODY_TOO_SMALL,
		self::REASON_CONTENT_TYPE,
		self::REASON_NO_CLOSING,
		self::REASON_YITH_MISSING,
		self::REASON_YITH_NO_PRESET,
		self::REASON_YITH_UNKNOWN,
		self::REASON_YITH_INCONSISTENT,
	);

	/**
	 * Etiqueta corta que se guarda en scw_queue.last_result.
	 *
	 * Se declaran explícitamente en vez de componerlas concatenando, para que
	 * ninguna pueda superar los 32 caracteres de la columna sin que nos demos
	 * cuenta. La más larga ocupa 28.
	 */
	const LAST_RESULT = array(
		self::REASON_TRUNCATED         => 'suspicious_truncated',
		self::REASON_EMPTY_BODY        => 'suspicious_empty_body',
		self::REASON_BODY_TOO_SMALL    => 'suspicious_body_too_small',
		self::REASON_CONTENT_TYPE      => 'suspicious_content_type',
		self::REASON_NO_CLOSING        => 'suspicious_no_closing_html',
		self::REASON_YITH_MISSING      => 'suspicious_yith_missing',
		self::REASON_YITH_NO_PRESET    => 'suspicious_yith_no_preset',
		self::REASON_YITH_UNKNOWN      => 'suspicious_yith_unknown',
		self::REASON_YITH_INCONSISTENT => 'suspicious_yith_inconsistent',
	);

	/** Longitud máxima del fragmento de evidencia que se guarda. */
	const MAX_SNIPPET_LENGTH = 300;

	/**
	 * Dictamina sobre una respuesta.
	 *
	 * @param array  $http   Resultado de SCW_HTTP_Client::fetch(), con body.
	 * @param string $url    URL pedida, tal como estaba en la cola.
	 * @param array  $config Sobrescrituras para las clases subyacentes:
	 *                       html, profile, yith.
	 * @return array {
	 *     @type string      $result       ok|suspicious|error.
	 *     @type string|null $reason       Motivo del veredicto, o null si es ok.
	 *     @type string      $last_result  Etiqueta corta para scw_queue.
	 *     @type string      $queue_status Estado terminal de la cola.
	 *     @type string|null $yith_presets Presets válidos en formato de columna.
	 *     @type string[]    $anomalies    Códigos de anomalía fuerte.
	 *     @type string[]    $signals      Códigos de señal informativa.
	 *     @type array       $diagnostics  Bloque listo para scw_runs.diagnostics.
	 * }
	 */
	public static function validate( $http, $url, $config = array() ) {
		$http   = is_array( $http ) ? $http : array();
		$config = is_array( $config ) ? $config : array();

		$ok     = ! empty( $http['ok'] );
		$status = isset( $http['http_status'] ) ? (int) $http['http_status'] : 0;

		// 1. ERROR: no hubo respuesta, o la hubo pero no es 2xx. Aquí no se
		// mira el contenido en absoluto.
		if ( ! $ok || $status < 200 || $status >= 300 ) {
			return self::error_verdict( $http, $ok, $status );
		}

		// 2. Se ejecutan SIEMPRE las tres validaciones, aunque el veredicto ya
		// esté decidido por la primera: así la evidencia que queda en
		// scw_runs es completa y una DUDOSA se puede diagnosticar sin volver a
		// pedir la página.
		$body = isset( $http['body'] ) && is_string( $http['body'] ) ? $http['body'] : '';

		$html = SCW_HTML_Validator::check(
			$body,
			array_merge(
				isset( $config['html'] ) && is_array( $config['html'] ) ? $config['html'] : array(),
				array(
					'content_type' => isset( $http['content_type'] ) ? $http['content_type'] : null,
					'truncated'    => self::was_truncated( $http ),
				)
			)
		);

		$profile = SCW_Page_Profile::resolve( $url, isset( $config['profile'] ) ? $config['profile'] : array() );
		$yith    = SCW_YITH_Validator::resolve( $body, isset( $config['yith'] ) ? $config['yith'] : array() );

		$signals = $html['signal_codes'];

		$yith_reason = self::yith_reason( $profile['profile'], $yith, $signals );

		$reasons = $html['anomaly_codes'];

		if ( null !== $yith_reason ) {
			$reasons[] = $yith_reason;
		}

		$reason = self::most_severe( $reasons );

		$result = ( null === $reason ) ? self::RESULT_OK : self::RESULT_SUSPICIOUS;

		return array(
			'result'       => $result,
			'reason'       => $reason,
			'last_result'  => ( null === $reason ) ? 'http_' . $status : self::last_result_for( $reason ),
			'queue_status' => ( null === $reason ) ? SCW_Queue::STATUS_SUCCESS : SCW_Queue::STATUS_SUSPICIOUS,
			'yith_presets' => self::presets_column( $yith ),
			'anomalies'    => $html['anomaly_codes'],
			'signals'      => $signals,
			'diagnostics'  => self::diagnostics( $result, $reason, $html, $profile, $yith, $signals ),
		);
	}

	/**
	 * Veredicto cuando no hay respuesta utilizable.
	 *
	 * @param array $http   Resultado del cliente HTTP.
	 * @param bool  $ok     Si hubo respuesta HTTP real.
	 * @param int   $status Código HTTP.
	 * @return array
	 */
	private static function error_verdict( $http, $ok, $status ) {
		if ( $ok ) {
			$last_result = 'http_' . $status;
		} else {
			$error_type  = isset( $http['error_type'] ) ? $http['error_type'] : null;
			$last_result = ( null === $error_type || '' === $error_type ) ? 'transport_error' : (string) $error_type;
		}

		return array(
			'result'       => self::RESULT_ERROR,
			'reason'       => null,
			'last_result'  => substr( $last_result, 0, 32 ),
			'queue_status' => SCW_Queue::STATUS_FAILED,
			'yith_presets' => null,
			'anomalies'    => array(),
			'signals'      => array(),
			'diagnostics'  => array(),
		);
	}

	/**
	 * Motivo YITH, si lo hay, y señales asociadas.
	 *
	 * Sólo un perfil REQUIRES_YITH puede producir motivo. En NO_YITH y
	 * UNVERIFIED la evidencia se registra, pero nunca descalifica: la ausencia
	 * de filtro en una página que no lo necesita es un resultado normal, y una
	 * página sin expectativa declarada no puede incumplir ninguna.
	 *
	 * @param string $profile  Perfil resuelto.
	 * @param array  $yith     Resultado de SCW_YITH_Validator.
	 * @param array  $signals  Señales, por referencia.
	 * @return string|null
	 */
	private static function yith_reason( $profile, $yith, &$signals ) {
		$inconsistent = empty( $yith['consistent'] );

		if ( SCW_Page_Profile::PROFILE_REQUIRES_YITH !== $profile ) {
			// Fuera de REQUIRES_YITH todo lo que encontremos es informativo.
			if ( ! empty( $yith['valid'] ) && SCW_Page_Profile::PROFILE_NO_YITH === $profile ) {
				// No sabemos si el perfil está desactualizado o si la página
				// lleva filtros legítimamente. Se anota para poder detectarlo
				// más adelante, sin penalizar la página.
				$signals[] = self::SIGNAL_UNEXPECTED_YITH;
			}

			if ( $inconsistent ) {
				$signals[] = self::SIGNAL_YITH_INCONSISTENT;
			}

			return null;
		}

		if ( empty( $yith['detected'] ) ) {
			return self::REASON_YITH_MISSING;
		}

		if ( empty( $yith['valid'] ) ) {
			// Hay contenedor, pero no sirve: o declara un preset que no
			// conocemos, o no declara ninguno. Son dos fallos distintos y se
			// distinguen.
			return empty( $yith['invalid_presets'] ) ? self::REASON_YITH_NO_PRESET : self::REASON_YITH_UNKNOWN;
		}

		if ( $inconsistent ) {
			// Hay preset válido, pero el contenedor se contradice a sí mismo.
			// El objetivo del proyecto es certificar la integridad del HTML
			// cacheado: una contradicción objetiva en la evidencia no se
			// certifica como correcta aunque no sepamos que rompa nada visible.
			return self::REASON_YITH_INCONSISTENT;
		}

		if ( ! empty( $yith['invalid_presets'] ) ) {
			// Hay al menos un preset válido y además alguno desconocido en
			// otro contenedor: la página cumple, pero conviene saberlo.
			$signals[] = self::SIGNAL_YITH_UNKNOWN_PRESETS;
		}

		return null;
	}

	/**
	 * Elige el motivo más grave de los concurrentes.
	 *
	 * @param array $reasons Motivos.
	 * @return string|null
	 */
	private static function most_severe( $reasons ) {
		foreach ( self::SEVERITY as $candidate ) {
			if ( in_array( $candidate, $reasons, true ) ) {
				return $candidate;
			}
		}

		// Un motivo no catalogado nunca debe perderse en silencio: si algún
		// día apareciera uno, se devuelve tal cual en vez de dar OK.
		foreach ( (array) $reasons as $reason ) {
			if ( is_string( $reason ) && '' !== $reason ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Etiqueta corta para la cola.
	 *
	 * @param string $reason Motivo.
	 * @return string
	 */
	private static function last_result_for( $reason ) {
		if ( isset( self::LAST_RESULT[ $reason ] ) ) {
			return self::LAST_RESULT[ $reason ];
		}

		return substr( 'suspicious_' . $reason, 0, 32 );
	}

	/**
	 * ¿Confirmó el cliente HTTP que la respuesta venía truncada?
	 *
	 * Se lee possibly_truncated, que SCW_HTTP_Client sólo marca al alcanzar
	 * exactamente el límite de body. La discrepancia de Content-Length NO se
	 * usa: con compresión activa, el cuerpo recibido está descomprimido y esa
	 * cabecera es el tamaño comprimido, así que difieren casi siempre.
	 *
	 * @param array $http Resultado del cliente HTTP.
	 * @return bool
	 */
	private static function was_truncated( $http ) {
		return isset( $http['diagnostics']['possibly_truncated'] ) && ! empty( $http['diagnostics']['possibly_truncated'] );
	}

	/**
	 * Presets válidos en el formato de la columna yith_presets.
	 *
	 * Sólo los válidos. Los desconocidos van a diagnostics.
	 *
	 * @param array $yith Resultado de SCW_YITH_Validator.
	 * @return string|null
	 */
	private static function presets_column( $yith ) {
		if ( empty( $yith['presets'] ) || ! is_array( $yith['presets'] ) ) {
			return null;
		}

		return substr( implode( ',', array_map( 'intval', $yith['presets'] ) ), 0, 191 );
	}

	/**
	 * Construye el bloque de evidencia para scw_runs.diagnostics.
	 *
	 * El cuerpo de la respuesta NO se guarda nunca. De los contenedores YITH se
	 * guarda el número, no el array completo, y un único fragmento acotado y
	 * sólo cuando el veredicto no es correcto: en una página correcta no aporta
	 * nada y engordaría todas las filas sin motivo.
	 *
	 * @param string      $result  Veredicto.
	 * @param string|null $reason  Motivo.
	 * @param array       $html    Resultado de SCW_HTML_Validator.
	 * @param array       $profile Resultado de SCW_Page_Profile.
	 * @param array       $yith    Resultado de SCW_YITH_Validator.
	 * @param array       $signals Señales acumuladas.
	 * @return array
	 */
	private static function diagnostics( $result, $reason, $html, $profile, $yith, $signals ) {
		$diagnostics = array(
			'validation' => array(
				'result'    => $result,
				'reason'    => $reason,
				'anomalies' => array_values( $html['anomaly_codes'] ),
				'signals'   => array_values( array_unique( $signals ) ),
				'bytes'     => (int) $html['bytes'],
				'mime'      => $html['mime'],
			),
			'profile'    => array(
				'profile' => $profile['profile'],
				'path'    => $profile['path'],
				'pattern' => $profile['pattern'],
				'source'  => $profile['source'],
			),
			'yith'       => array(
				'detected'        => (bool) $yith['detected'],
				'valid'           => (bool) $yith['valid'],
				'consistent'      => (bool) $yith['consistent'],
				'presets'         => array_values( $yith['presets'] ),
				'invalid_presets' => array_values( $yith['invalid_presets'] ),
				'orphan_presets'  => array_values( $yith['orphan_presets'] ),
				'containers'      => count( $yith['containers'] ),
				'warnings'        => array_values( array_unique( wp_list_pluck( $yith['warnings'], 'code' ) ) ),
			),
		);

		if ( self::RESULT_OK !== $result ) {
			$snippet = self::first_snippet( $yith );

			if ( null !== $snippet ) {
				$diagnostics['yith']['snippet'] = $snippet;
			}
		}

		return $diagnostics;
	}

	/**
	 * Primer fragmento de evidencia de contenedor, si lo hay.
	 *
	 * @param array $yith Resultado de SCW_YITH_Validator.
	 * @return string|null
	 */
	private static function first_snippet( $yith ) {
		if ( empty( $yith['containers'] ) || ! is_array( $yith['containers'] ) ) {
			return null;
		}

		$first = reset( $yith['containers'] );

		if ( ! is_array( $first ) || empty( $first['snippet'] ) ) {
			return null;
		}

		return substr( (string) $first['snippet'], 0, self::MAX_SNIPPET_LENGTH );
	}
}
