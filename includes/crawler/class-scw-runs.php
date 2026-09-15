<?php
/**
 * Acceso a la tabla de runs.
 *
 * Cada fila de esta tabla representa UNA petición HTTP de calentamiento
 * realizada de verdad (o un intento real que ha fallado a nivel de
 * transporte). Esta clase se limita a insertar y leer esas filas: no decide
 * qué URL calentar, no conoce el ciclo de vida de la cola y no aplica ninguna
 * política de reintentos ni de pacing. Esas responsabilidades son de
 * SCW_Queue y de SCW_Worker respectivamente.
 *
 * Las URLs descartadas ANTES de intentar la petición (inválidas o excluidas)
 * no generan fila aquí: esa decisión la toma SCW_Worker y no pasa por esta
 * clase. Sólo llega aquí lo que ha supuesto un intento real de red.
 *
 * validation_result y yith_presets pertenecen a la validación de contenido de
 * F4. Esta clase los deja siempre a NULL: no inventa ningún resultado de
 * validación.
 *
 * El esquema de la tabla lo define SCW_Schema; esta clase nunca lo modifica
 * ni asume columnas que no existan en él.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Runs {

	/** Longitud máxima de error_message, como red de seguridad ante un mensaje anómalo. */
	const MAX_ERROR_MESSAGE_LENGTH = 2000;

	/**
	 * Nombre real de la tabla.
	 *
	 * @return string
	 */
	public static function table() {
		return SCW_Schema::table( 'runs' );
	}

	/**
	 * Inserta un run.
	 *
	 * @param array $data {
	 *     @type int         $queue_id                  Obligatorio.
	 *     @type string      $url_hash                  Obligatorio.
	 *     @type string      $url                       Obligatorio.
	 *     @type string      $session_id                Opcional.
	 *     @type int         $attempt                   Opcional, por defecto 1.
	 *     @type int         $http_status               Opcional, por defecto 0 (sin respuesta HTTP real).
	 *     @type int         $duration_ms               Opcional.
	 *     @type int         $bytes                     Opcional.
	 *     @type string|null $x_litespeed_cache         Opcional.
	 *     @type string|null $x_litespeed_cache_control Opcional.
	 *     @type string|null $vary                      Opcional.
	 *     @type string|null $content_type              Opcional.
	 *     @type string|null $user_agent                Opcional.
	 *     @type string|null $error_type                Opcional.
	 *     @type string|null $error_message             Opcional.
	 *     @type array|null  $diagnostics               Opcional, se guarda como JSON.
	 * }
	 * @return int|false ID insertado, o false si ha fallado o faltan datos obligatorios.
	 */
	public static function insert( $data ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'runs' ) ) {
			return false;
		}

		$data = is_array( $data ) ? $data : array();

		$queue_id = isset( $data['queue_id'] ) ? (int) $data['queue_id'] : 0;
		$url_hash = isset( $data['url_hash'] ) ? (string) $data['url_hash'] : '';
		$url      = isset( $data['url'] ) ? (string) $data['url'] : '';

		if ( $queue_id <= 0 || '' === $url_hash || '' === $url ) {
			return false;
		}

		$error_message = isset( $data['error_message'] ) && null !== $data['error_message']
			? substr( (string) $data['error_message'], 0, self::MAX_ERROR_MESSAGE_LENGTH )
			: null;

		$diagnostics = ( isset( $data['diagnostics'] ) && ! empty( $data['diagnostics'] ) )
			? wp_json_encode( $data['diagnostics'] )
			: null;

		$row = array(
			'queue_id'                  => $queue_id,
			'url_hash'                  => substr( $url_hash, 0, 40 ),
			'url'                       => substr( $url, 0, 2048 ),
			'session_id'                => substr( isset( $data['session_id'] ) ? (string) $data['session_id'] : '', 0, 32 ),
			'attempt'                   => max( 1, min( 255, isset( $data['attempt'] ) ? (int) $data['attempt'] : 1 ) ),
			'requested_at'              => self::now(),
			'http_status'               => max( 0, min( 65535, isset( $data['http_status'] ) ? (int) $data['http_status'] : 0 ) ),
			'duration_ms'               => max( 0, isset( $data['duration_ms'] ) ? (int) $data['duration_ms'] : 0 ),
			'bytes'                     => max( 0, isset( $data['bytes'] ) ? (int) $data['bytes'] : 0 ),
			'x_litespeed_cache'         => self::nullable_string( isset( $data['x_litespeed_cache'] ) ? $data['x_litespeed_cache'] : null, 32 ),
			'x_litespeed_cache_control' => self::nullable_string( isset( $data['x_litespeed_cache_control'] ) ? $data['x_litespeed_cache_control'] : null, 191 ),
			'vary'                      => self::nullable_string( isset( $data['vary'] ) ? $data['vary'] : null, 191 ),
			'content_type'              => self::nullable_string( isset( $data['content_type'] ) ? $data['content_type'] : null, 191 ),
			'user_agent'                => self::nullable_string( isset( $data['user_agent'] ) ? $data['user_agent'] : null, 255 ),
			'error_type'                => self::nullable_string( isset( $data['error_type'] ) ? $data['error_type'] : null, 32 ),
			'error_message'             => $error_message,
			// Reservado para F4. Nunca se rellena aquí.
			'validation_result'         => null,
			'yith_presets'              => null,
			'diagnostics'               => $diagnostics,
		);

		$formats = array(
			'%d', // queue_id.
			'%s', // url_hash.
			'%s', // url.
			'%s', // session_id.
			'%d', // attempt.
			'%s', // requested_at.
			'%d', // http_status.
			'%d', // duration_ms.
			'%d', // bytes.
			'%s', // x_litespeed_cache.
			'%s', // x_litespeed_cache_control.
			'%s', // vary.
			'%s', // content_type.
			'%s', // user_agent.
			'%s', // error_type.
			'%s', // error_message.
			'%s', // validation_result.
			'%s', // yith_presets.
			'%s', // diagnostics.
		);

		$inserted = $wpdb->insert( self::table(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Recupera un run por ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'runs' ) ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Recupera el run más reciente asociado a una fila de la cola.
	 *
	 * Pensado para que el Worker y los tests puedan comprobar qué se ha
	 * registrado tras procesar una URL concreta.
	 *
	 * @param int $queue_id ID de la fila de scw_queue.
	 * @return array|null
	 */
	public static function find_latest_by_queue_id( $queue_id ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'runs' ) ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE queue_id = %d ORDER BY id DESC LIMIT 1',
				(int) $queue_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Elimina los runs de una sesión concreta.
	 *
	 * Único método de borrado de esta fase. Lo usan los tests de F3 para
	 * limpiar sus propias filas sin tocar el resto de la tabla.
	 *
	 * @param string $session_id Sesión.
	 * @return int Filas eliminadas.
	 */
	public static function delete_by_session( $session_id ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'runs' ) ) {
			return 0;
		}

		$deleted = $wpdb->delete( self::table(), array( 'session_id' => (string) $session_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Normaliza un valor de header a cadena truncada, o null si viene vacío.
	 *
	 * @param mixed $value Valor.
	 * @param int   $max   Longitud máxima.
	 * @return string|null
	 */
	private static function nullable_string( $value, $max ) {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : substr( $value, 0, $max );
	}

	/**
	 * Momento actual en UTC y formato MySQL, igual que el resto del plugin.
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}
}
