<?php
/**
 * Ritmo adaptativo (pacer).
 *
 * F5.3. Decide cuánto debe DESCANSAR el servidor después de una petición HTTP
 * real, a partir exclusivamente de lo que esa petición ha observado: código
 * HTTP, tipo de error de transporte y duración.
 *
 * Esta clase es PURA salvo config(), que es el único punto que lee ajustes:
 *
 *   - no lee ni escribe SCW_State;
 *   - no toca la cola, la base de datos, WP-Cron ni el breaker;
 *   - no lee el reloj.
 *
 * Quién hace qué:
 *
 *   SCW_Worker        llama a compute() tras SCW_HTTP_Client::fetch() y
 *                     persiste el resultado (current_delay, ewma_duration_ms,
 *                     last_request_at) en UNA sola escritura de SCW_State.
 *   SCW_Tick_Planner  (F5.1, sin cambios) lee current_delay y last_request_at
 *                     y calcula pace_ready = last_request_at + current_delay.
 *
 * El Pacer no decide cuándo se ejecuta el siguiente tick: sólo produce el hecho
 * "tras esta petición, el servidor necesita N segundos". La combinación con el
 * trabajo disponible (available_at, backoff de F5.2) es max(), y la hace el
 * planificador.
 *
 * Reglas, en orden de precedencia (la primera que encaja decide):
 *
 *   1. error_type = timeout            -> pace_delay_timeout   (band timeout)
 *   2. otro error de transporte        -> pace_delay_timeout   (band transport_error)
 *   3. HTTP 429                        -> pace_delay_429       (band http_429)
 *   4. HTTP 503                        -> pace_delay_503       (band http_503)
 *   5. HTTP >= 500                     -> pace_delay_5xx       (band http_5xx)
 *   6. duración > 10 000 ms            -> pace_delay_over_10s  (band over_10s)
 *   7. resto: max( ceil( duración_s × pace_duty_factor ),
 *                  ceil( pace_base_delay × multiplicador_de_banda ) )
 *
 * y SIEMPRE al final: clamp( delay, pace_min_delay, pace_max_delay ).
 *
 * Bandas de duración (milisegundos):
 *
 *   [0, 2000)       under_2s
 *   [2000, 4000)    2_4s
 *   [4000, 6000)    4_6s
 *   [6000, 10000]   6_10s
 *   > 10000         over_10s
 *
 * EWMA: sólo observabilidad. Se calcula aquí para que viaje con el resto del
 * resultado, pero NUNCA interviene en el cálculo del delay.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Pacer {

	/* Vocabulario de band: identifica la regla que ha decidido el delay. */
	const BAND_UNDER_2S  = 'under_2s';
	const BAND_2_4S      = '2_4s';
	const BAND_4_6S      = '4_6s';
	const BAND_6_10S     = '6_10s';
	const BAND_OVER_10S  = 'over_10s';
	const BAND_TIMEOUT   = 'timeout';
	const BAND_TRANSPORT = 'transport_error';
	const BAND_HTTP_429  = 'http_429';
	const BAND_HTTP_503  = 'http_503';
	const BAND_HTTP_5XX  = 'http_5xx';

	/** Límite superior de la banda 6_10s, inclusivo, en milisegundos. */
	const OVER_10S_MS = 10000;

	/** Peso de la última observación en la media exponencial. */
	const EWMA_ALPHA = 0.3;

	/*
	 * Valores de reserva. NO son ajustes nuevos: replican los valores por
	 * defecto que SCW_Settings ya declara desde F1. Sólo se usan si el valor
	 * persistido falta o no es válido.
	 */
	const DEFAULT_MIN_DELAY      = 3;
	const DEFAULT_MAX_DELAY      = 300;
	const DEFAULT_BASE_DELAY     = 5;
	const DEFAULT_DUTY_FACTOR    = 2;
	const DEFAULT_DELAY_OVER_10S = 120;
	const DEFAULT_DELAY_TIMEOUT  = 300;
	const DEFAULT_DELAY_429      = 90;
	const DEFAULT_DELAY_503      = 120;
	const DEFAULT_DELAY_5XX      = 60;

	/**
	 * Multiplicadores de reserva por banda (mismos valores que SCW_Settings).
	 *
	 * @return array
	 */
	public static function default_band_multipliers() {
		return array(
			self::BAND_UNDER_2S => 1,
			self::BAND_2_4S     => 1.5,
			self::BAND_4_6S     => 3,
			self::BAND_6_10S    => 6,
		);
	}

	/**
	 * Configuración efectiva del pacer.
	 *
	 * ÚNICA parte impura de la clase: lee los ajustes ya existentes y delega
	 * toda la validación en normalize_config(), que es pura.
	 *
	 * @return array
	 */
	public static function config() {
		return self::normalize_config( SCW_Settings::all() );
	}

	/**
	 * Valida y completa una configuración.
	 *
	 * Garantía central de F5.3: pace_min_delay efectivo es SIEMPRE >= 1. Un
	 * valor persistido ausente, no numérico o < 1 se sustituye por el valor de
	 * reserva (3), de modo que una configuración corrupta no puede reintroducir
	 * un delay de 0 segundos. Si pace_max_delay queda por debajo del mínimo, se
	 * iguala al mínimo.
	 *
	 * @param array $raw Ajustes, normalmente SCW_Settings::all().
	 * @return array
	 */
	public static function normalize_config( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$min = self::positive_int( $raw, 'pace_min_delay', self::DEFAULT_MIN_DELAY );
		$max = self::positive_int( $raw, 'pace_max_delay', self::DEFAULT_MAX_DELAY );

		if ( $max < $min ) {
			$max = $min;
		}

		// Las reservas se tipifican igual que los valores válidos (float), para
		// que la config efectiva tenga siempre los mismos tipos.
		$base = (float) self::DEFAULT_BASE_DELAY;

		if ( isset( $raw['pace_base_delay'] ) && is_numeric( $raw['pace_base_delay'] ) && (float) $raw['pace_base_delay'] > 0 ) {
			$base = (float) $raw['pace_base_delay'];
		}

		$factor = (float) self::DEFAULT_DUTY_FACTOR;

		if ( isset( $raw['pace_duty_factor'] ) && is_numeric( $raw['pace_duty_factor'] ) && (float) $raw['pace_duty_factor'] >= 0 ) {
			$factor = (float) $raw['pace_duty_factor'];
		}

		// all() hace un merge superficial: un array persistido incompleto
		// sustituye entero al de defaults. Se completa banda a banda.
		$multipliers = array_map( 'floatval', self::default_band_multipliers() );
		$stored      = ( isset( $raw['pace_band_multipliers'] ) && is_array( $raw['pace_band_multipliers'] ) )
			? $raw['pace_band_multipliers']
			: array();

		foreach ( $multipliers as $band => $default ) {
			if ( isset( $stored[ $band ] ) && is_numeric( $stored[ $band ] ) && (float) $stored[ $band ] > 0 ) {
				$multipliers[ $band ] = (float) $stored[ $band ];
			}
		}

		return array(
			'min_delay'        => $min,
			'max_delay'        => $max,
			'base_delay'       => $base,
			'duty_factor'      => $factor,
			'band_multipliers' => $multipliers,
			'delay_over_10s'   => self::non_negative_int( $raw, 'pace_delay_over_10s', self::DEFAULT_DELAY_OVER_10S ),
			'delay_timeout'    => self::non_negative_int( $raw, 'pace_delay_timeout', self::DEFAULT_DELAY_TIMEOUT ),
			'delay_429'        => self::non_negative_int( $raw, 'pace_delay_429', self::DEFAULT_DELAY_429 ),
			'delay_503'        => self::non_negative_int( $raw, 'pace_delay_503', self::DEFAULT_DELAY_503 ),
			'delay_5xx'        => self::non_negative_int( $raw, 'pace_delay_5xx', self::DEFAULT_DELAY_5XX ),
		);
	}

	/**
	 * ¿Ha llegado esta petición al servidor?
	 *
	 * invalid_url y host_not_allowed son rechazos de SCW_HTTP_Client ANTES de
	 * abrir ninguna conexión: el servidor no ha recibido carga, así que no hay
	 * nada que pautar. Cualquier otro resultado de fetch() (respuesta HTTP,
	 * timeout o error de transporte) sí es una petición real.
	 *
	 * @param array $http Resultado de SCW_HTTP_Client::fetch().
	 * @return bool
	 */
	public static function is_real_request( $http ) {
		if ( ! is_array( $http ) ) {
			return false;
		}

		$error_type = isset( $http['error_type'] ) ? $http['error_type'] : null;

		return ! in_array( $error_type, array( SCW_HTTP_Client::ERROR_INVALID_URL, SCW_HTTP_Client::ERROR_HOST ), true );
	}

	/**
	 * Calcula el pacing de una petición real.
	 *
	 * Función PURA y determinista: mismo input, mismo output. Sólo lee las
	 * claves http_status, error_type y duration_ms de $observation, que son
	 * exactamente las que devuelve SCW_HTTP_Client::fetch(). No distingue si
	 * la petición fue real: eso es is_real_request(), y lo decide quien llama.
	 *
	 * @param array $observation   Resultado de fetch() (o subconjunto).
	 * @param int   $previous_ewma EWMA previo en ms. <= 0 significa "sin valor".
	 * @param array $config        Configuración, normalmente de config(). Puede
	 *                              ser parcial o corrupta: siempre se normaliza
	 *                              con normalize_config() antes de usarse.
	 * @return array {
	 *     @type int    $delay Segundos de descanso, en [min_delay, max_delay].
	 *     @type int    $ewma  Nueva media exponencial de duración, en ms.
	 *     @type string $band  Regla que ha decidido el delay (BAND_*).
	 * }
	 */
	public static function compute( $observation, $previous_ewma, $config ) {
		$observation = is_array( $observation ) ? $observation : array();
		$cfg         = self::normalize_config_array( $config );

		$status     = isset( $observation['http_status'] ) ? (int) $observation['http_status'] : 0;
		$error_type = ( isset( $observation['error_type'] ) && '' !== $observation['error_type'] ) ? (string) $observation['error_type'] : null;
		$duration   = isset( $observation['duration_ms'] ) ? max( 0, (int) $observation['duration_ms'] ) : 0;

		if ( SCW_HTTP_Client::ERROR_TIMEOUT === $error_type ) {
			$band = self::BAND_TIMEOUT;
			$raw  = $cfg['delay_timeout'];
		} elseif ( null !== $error_type || $status <= 0 ) {
			// Cualquier otro error, o ausencia de código HTTP: sin respuesta
			// utilizable, se trata como error de transporte.
			$band = self::BAND_TRANSPORT;
			$raw  = $cfg['delay_timeout'];
		} elseif ( 429 === $status ) {
			$band = self::BAND_HTTP_429;
			$raw  = $cfg['delay_429'];
		} elseif ( 503 === $status ) {
			$band = self::BAND_HTTP_503;
			$raw  = $cfg['delay_503'];
		} elseif ( $status >= 500 ) {
			$band = self::BAND_HTTP_5XX;
			$raw  = $cfg['delay_5xx'];
		} elseif ( $duration > self::OVER_10S_MS ) {
			$band = self::BAND_OVER_10S;
			$raw  = $cfg['delay_over_10s'];
		} else {
			$band       = self::band_for_duration( $duration );
			$duty       = self::ceil_seconds( ( $duration / 1000 ) * $cfg['duty_factor'] );
			$band_delay = self::ceil_seconds( $cfg['base_delay'] * $cfg['band_multipliers'][ $band ] );
			$raw        = max( $duty, $band_delay );
		}

		return array(
			'delay' => self::clamp( $raw, $cfg['min_delay'], $cfg['max_delay'] ),
			'ewma'  => self::ewma( $duration, $previous_ewma ),
			'band'  => $band,
		);
	}

	/**
	 * Banda de duración para una petición que NO supera los 10 s.
	 *
	 * @param int $duration_ms Duración en ms.
	 * @return string
	 */
	public static function band_for_duration( $duration_ms ) {
		$duration_ms = max( 0, (int) $duration_ms );

		if ( $duration_ms < 2000 ) {
			return self::BAND_UNDER_2S;
		}

		if ( $duration_ms < 4000 ) {
			return self::BAND_2_4S;
		}

		if ( $duration_ms < 6000 ) {
			return self::BAND_4_6S;
		}

		if ( $duration_ms <= self::OVER_10S_MS ) {
			return self::BAND_6_10S;
		}

		return self::BAND_OVER_10S;
	}

	/**
	 * Media exponencial de la duración. Sólo observabilidad.
	 *
	 * @param int $duration_ms   Duración de esta petición.
	 * @param int $previous_ewma Valor previo; <= 0 o no numérico = sin valor.
	 * @return int
	 */
	public static function ewma( $duration_ms, $previous_ewma ) {
		$duration_ms = max( 0, (int) $duration_ms );
		$previous    = is_numeric( $previous_ewma ) ? (int) $previous_ewma : 0;

		if ( $previous <= 0 ) {
			return $duration_ms;
		}

		return (int) round( self::EWMA_ALPHA * $duration_ms + ( 1 - self::EWMA_ALPHA ) * $previous );
	}

	/**
	 * Recorta un delay a [min, max] como entero.
	 *
	 * @param int|float $delay Delay propuesto.
	 * @param int       $min   Mínimo (>= 1 tras normalize_config()).
	 * @param int       $max   Máximo.
	 * @return int
	 */
	public static function clamp( $delay, $min, $max ) {
		$delay = is_numeric( $delay ) ? self::ceil_seconds( $delay ) : (int) $min;

		return (int) max( (int) $min, min( (int) $max, $delay ) );
	}

	/**
	 * ceil() a segundos enteros, inmune al ruido de coma flotante
	 * (p. ej. 0.1 × 3 = 0.30000000000000004 no debe convertirse en 1 por
	 * error de representación cuando el valor exacto es entero).
	 *
	 * @param int|float $value Valor.
	 * @return int
	 */
	private static function ceil_seconds( $value ) {
		return (int) ceil( round( (float) $value, 6 ) );
	}

	/**
	 * Correspondencia entre las claves de la configuración normalizada y los
	 * ajustes de SCW_Settings de los que proceden.
	 *
	 * @return array clave normalizada => clave de ajuste.
	 */
	private static function normalized_key_map() {
		return array(
			'min_delay'        => 'pace_min_delay',
			'max_delay'        => 'pace_max_delay',
			'base_delay'       => 'pace_base_delay',
			'duty_factor'      => 'pace_duty_factor',
			'band_multipliers' => 'pace_band_multipliers',
			'delay_over_10s'   => 'pace_delay_over_10s',
			'delay_timeout'    => 'pace_delay_timeout',
			'delay_429'        => 'pace_delay_429',
			'delay_503'        => 'pace_delay_503',
			'delay_5xx'        => 'pace_delay_5xx',
		);
	}

	/**
	 * Configuración efectiva para compute(), sea cual sea la forma recibida.
	 *
	 * compute() acepta tanto la salida de normalize_config() como un array de
	 * ajustes crudo (claves pace_*), completo, parcial o corrupto. En TODOS los
	 * casos el resultado pasa por normalize_config(), que es la única fuente de
	 * verdad de defaults y validación: no existe un camino "ya normalizada" que
	 * confíe en el contenido recibido. Así cada clave que compute() lee existe
	 * siempre y tiene un valor válido.
	 *
	 * Las claves normalizadas se traducen a su ajuste de origen y, si ambas
	 * formas están presentes, prevalece la normalizada. normalize_config() es
	 * idempotente sobre su propia salida, de modo que una config ya normalizada
	 * produce exactamente la misma config.
	 *
	 * @param mixed $config Configuración en cualquiera de las dos formas.
	 * @return array Configuración normalizada completa.
	 */
	private static function normalize_config_array( $config ) {
		if ( ! is_array( $config ) ) {
			return self::normalize_config( array() );
		}

		$raw = $config;

		foreach ( self::normalized_key_map() as $normalized => $setting ) {
			if ( array_key_exists( $normalized, $config ) ) {
				$raw[ $setting ] = $config[ $normalized ];
			}
		}

		return self::normalize_config( $raw );
	}

	/**
	 * Entero >= 1, o el valor de reserva.
	 *
	 * @param array  $raw     Ajustes.
	 * @param string $key     Clave.
	 * @param int    $default Reserva.
	 * @return int
	 */
	private static function positive_int( $raw, $key, $default ) {
		if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) && (float) $raw[ $key ] >= 1 ) {
			return self::ceil_seconds( $raw[ $key ] );
		}

		return (int) $default;
	}

	/**
	 * Entero >= 0, o el valor de reserva.
	 *
	 * @param array  $raw     Ajustes.
	 * @param string $key     Clave.
	 * @param int    $default Reserva.
	 * @return int
	 */
	private static function non_negative_int( $raw, $key, $default ) {
		if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) && (float) $raw[ $key ] >= 0 ) {
			return self::ceil_seconds( $raw[ $key ] );
		}

		return (int) $default;
	}
}
