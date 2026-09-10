<?php
/**
 * Cola persistente.
 *
 * Esta clase se ocupa EXCLUSIVAMENTE de la persistencia y del ciclo de vida de
 * los elementos de la cola. No sabe nada de sitemaps, de normalización de URLs,
 * de exclusiones, de HTTP ni de validación.
 *
 * Contrato de entrada: recibe URLs YA NORMALIZADAS. Aquí sólo se calcula el
 * hash de forma consistente y se comprueba el duplicado contra el índice único.
 * Ninguna regla de normalización de query strings vive en este fichero.
 *
 * La creación de la tabla pertenece a SCW_Schema. Esta clase nunca crea ni
 * altera tablas.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Queue {

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_SUCCESS    = 'success';
	const STATUS_SKIPPED    = 'skipped';
	const STATUS_SUSPICIOUS = 'suspicious';
	const STATUS_FAILED     = 'failed';

	/** Segundos de lease por defecto si no hay ajuste configurado. */
	const DEFAULT_LEASE_SECONDS = 120;

	/**
	 * Intentos máximos por defecto de una entrada de la cola.
	 *
	 * Es el número de veces que una entrada puede llegar a reclamarse para su
	 * procesamiento. NO tiene relación con los reintentos HTTP de una petición
	 * concreta, que serán un ajuste distinto en una fase posterior. El valor
	 * coincide con el DEFAULT de la columna max_attempts en el esquema.
	 *
	 * En F2.1 este valor sólo se persiste: ninguna lógica lo consulta todavía
	 * para bloquear ni para reintentar.
	 */
	const DEFAULT_MAX_ATTEMPTS = 2;

	/** Intentos máximos de reclamación atómica antes de rendirse en un tick. */
	const MAX_CLAIM_ATTEMPTS = 5;

	/**
	 * Todos los estados soportados.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_SUCCESS,
			self::STATUS_SKIPPED,
			self::STATUS_SUSPICIOUS,
			self::STATUS_FAILED,
		);
	}

	/**
	 * Estados terminales a los que puede llevar complete().
	 *
	 * @return string[]
	 */
	public static function terminal_statuses() {
		return array(
			self::STATUS_SUCCESS,
			self::STATUS_SKIPPED,
			self::STATUS_SUSPICIOUS,
			self::STATUS_FAILED,
		);
	}

	/**
	 * Nombre real de la tabla.
	 *
	 * @return string
	 */
	public static function table() {
		return SCW_Schema::table( 'queue' );
	}

	/**
	 * Hash estable de una URL ya normalizada.
	 *
	 * Se usa sha1 porque encaja exactamente en el char(40) del índice único y
	 * evita indexar un varchar(2048).
	 *
	 * El hash se calcula sobre la cadena EXACTA que se recibe, sin recortarla ni
	 * transformarla de ninguna manera. Si dos cadenas distintas deben
	 * considerarse la misma URL, eso lo decide el normalizador en una fase
	 * posterior, nunca esta clase.
	 *
	 * @param string $url URL normalizada.
	 * @return string
	 */
	public static function hash_url( $url ) {
		return sha1( (string) $url );
	}

	/**
	 * Segundos de lease vigentes.
	 *
	 * Se lee de los ajustes si la clave existe, con filtro para poder ajustarlo
	 * desde código sin tocar la base de datos.
	 *
	 * @param int|null $override Valor explícito.
	 * @return int
	 */
	public static function lease_seconds( $override = null ) {
		if ( null !== $override ) {
			return max( 1, (int) $override );
		}

		$seconds = (int) SCW_Settings::get( 'queue_lease_seconds', self::DEFAULT_LEASE_SECONDS );

		if ( $seconds < 1 ) {
			$seconds = self::DEFAULT_LEASE_SECONDS;
		}

		$seconds = (int) apply_filters( 'scw_queue_lease_seconds', $seconds );

		// El filtro también puede devolver un valor inservible: se acota igual.
		return max( 1, $seconds );
	}

	/**
	 * Inserta una URL en la cola.
	 *
	 * @param string $url  URL ya normalizada.
	 * @param array  $args type, source, priority, max_attempts, available_at, status.
	 * @return array {
	 *     @type bool        $inserted  True si se creó una fila nueva.
	 *     @type bool        $duplicate True si la URL ya estaba en la cola.
	 *     @type int|null    $id        ID de la fila nueva, o de la existente si era duplicado.
	 *     @type string|null $url_hash  Hash calculado.
	 *     @type string|null $error     Motivo del fallo, si lo hubo.
	 * }
	 */
	public static function insert( $url, $args = array() ) {
		global $wpdb;

		$result = array(
			'inserted'  => false,
			'duplicate' => false,
			'id'        => null,
			'url_hash'  => null,
			'error'     => null,
		);

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			$result['error'] = 'missing_table';
			return $result;
		}

		// La URL se conserva EXACTAMENTE como llega. trim() se usa sólo para
		// detectar cadenas vacías o en blanco, nunca para modificar el valor
		// almacenado: normalizar no es competencia de esta clase.
		$url = is_string( $url ) ? $url : '';

		if ( '' === trim( $url ) ) {
			$result['error'] = 'empty_url';
			return $result;
		}

		if ( strlen( $url ) > 2048 ) {
			$result['error'] = 'url_too_long';
			return $result;
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			$result['error'] = 'invalid_url';
			return $result;
		}

		$hash               = self::hash_url( $url );
		$result['url_hash'] = $hash;

		// Duplicado conocido: se devuelve el ID existente sin tocar la fila.
		$existing = self::find_by_hash( $hash );

		if ( $existing ) {
			$result['duplicate'] = true;
			$result['id']        = (int) $existing['id'];
			return $result;
		}

		$defaults = array(
			'type'         => 'unknown',
			'source'       => '',
			'priority'     => null,
			'max_attempts' => null,
			'available_at' => null,
			'status'       => self::STATUS_PENDING,
		);

		$args = array_merge( $defaults, is_array( $args ) ? $args : array() );

		$type   = substr( sanitize_key( (string) $args['type'] ), 0, 32 );
		$type   = '' === $type ? 'unknown' : $type;
		$source = substr( sanitize_text_field( (string) $args['source'] ), 0, 191 );
		$status = in_array( $args['status'], self::statuses(), true ) ? $args['status'] : self::STATUS_PENDING;

		$priority     = null === $args['priority'] ? self::default_priority( $type ) : (int) $args['priority'];
		$priority     = max( 0, min( 65535, $priority ) );
		$max_attempts = null === $args['max_attempts'] ? self::default_max_attempts() : (int) $args['max_attempts'];
		$max_attempts = max( 0, min( 255, $max_attempts ) );

		$now          = self::now();
		$available_at = self::to_mysql_datetime( $args['available_at'], $now );
		$table        = self::table();

		// INSERT IGNORE: si otro proceso ganó la carrera, el índice único evita
		// el duplicado y afectará a 0 filas en lugar de lanzar un error.
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$table}
			 (url_hash, url, type, source, priority, status, attempts, max_attempts, available_at, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %d, %s, 0, %d, %s, %s, %s)",
			$hash,
			$url,
			$type,
			$source,
			$priority,
			$status,
			$max_attempts,
			$available_at,
			$now,
			$now
		); // phpcs:ignore WordPress.DB

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB

		if ( false === $affected ) {
			$result['error'] = 'db_error';
			return $result;
		}

		if ( 0 === (int) $affected ) {
			$existing            = self::find_by_hash( $hash );
			$result['duplicate'] = true;
			$result['id']        = $existing ? (int) $existing['id'] : null;
			return $result;
		}

		$result['inserted'] = true;
		$result['id']       = (int) $wpdb->insert_id;

		return $result;
	}

	/**
	 * Inserta varias URLs y devuelve el recuento agregado.
	 *
	 * Inserta de una en una a propósito: con 1.000-2.000 URLs el coste es
	 * irrelevante y a cambio cada URL recibe su propio veredicto.
	 *
	 * @param string[] $urls URLs normalizadas.
	 * @param array    $args Argumentos comunes, ver insert().
	 * @return array inserted, duplicates, errors, ids.
	 */
	public static function insert_many( array $urls, $args = array() ) {
		$summary = array(
			'inserted'   => 0,
			'duplicates' => 0,
			'errors'     => 0,
			'ids'        => array(),
		);

		foreach ( $urls as $url ) {
			$res = self::insert( $url, $args );

			if ( $res['inserted'] ) {
				$summary['inserted']++;
				$summary['ids'][] = $res['id'];
			} elseif ( $res['duplicate'] ) {
				$summary['duplicates']++;
			} else {
				$summary['errors']++;
			}
		}

		return $summary;
	}

	/**
	 * Devuelve la siguiente URL disponible sin reclamarla.
	 *
	 * Criterio: status pending y available_at ya vencido. Orden: prioridad
	 * descendente, luego antigüedad, luego id.
	 *
	 * @return array|null Fila asociativa o null.
	 */
	public static function get_next() {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return null;
		}

		$table = self::table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = %s AND available_at <= %s
				 ORDER BY priority DESC, created_at ASC, id ASC
				 LIMIT 1",
				self::STATUS_PENDING,
				self::now()
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Reclama la siguiente URL disponible para procesarla.
	 *
	 * La exclusión mutua se consigue con un UPDATE condicionado a que la fila
	 * siga en pending: el motor garantiza la atomicidad del UPDATE, de modo que
	 * si dos procesos compiten sólo uno verá una fila afectada. No hace falta
	 * SELECT ... FOR UPDATE ni bloqueos de tabla.
	 *
	 * El vencimiento del lease no se guarda al reclamar: se calcula al liberar,
	 * comparando locked_at contra el lease configurado en ese momento.
	 *
	 * @return array|null Fila reclamada, con lock_token, o null si no hay nada.
	 */
	public static function claim() {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return null;
		}

		$table = self::table();

		for ( $i = 0; $i < self::MAX_CLAIM_ATTEMPTS; $i++ ) {
			$candidate = self::get_next();

			if ( ! $candidate ) {
				return null;
			}

			$token = self::generate_lock_token();
			$now   = self::now();

			$affected = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, lock_token = %s, locked_at = %s, attempts = attempts + 1, updated_at = %s
					 WHERE id = %d AND status = %s",
					self::STATUS_PROCESSING,
					$token,
					$now,
					$now,
					(int) $candidate['id'],
					self::STATUS_PENDING
				)
			);

			if ( 1 === (int) $affected ) {
				return self::get( (int) $candidate['id'] );
			}

			if ( false === $affected ) {
				return null;
			}

			// Otro proceso se la llevó: se prueba con la siguiente.
		}

		return null;
	}

	/**
	 * Cierra un elemento en un estado terminal.
	 *
	 * El lock_token es OBLIGATORIO. Sin él no hay aislamiento entre workers:
	 * un worker cuyo lease caducó, y cuya URL ya fue recuperada y reclamada por
	 * otro, podría cerrar un trabajo que ya no le pertenece. Por eso el UPDATE
	 * exige simultáneamente el id, el estado processing y el token exacto. Si el
	 * token es de un worker antiguo, ninguna fila coincide y la operación falla.
	 *
	 * Aquí no vive ninguna lógica de reintentos ni de max_attempts.
	 *
	 * @param int    $id     ID de la fila.
	 * @param string $status success|skipped|suspicious|failed.
	 * @param array  $args   lock_token (obligatorio), last_result, last_error.
	 * @return bool True si se actualizó exactamente una fila.
	 */
	public static function complete( $id, $status, $args = array() ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return false;
		}

		$id = (int) $id;

		if ( $id <= 0 || ! in_array( $status, self::terminal_statuses(), true ) ) {
			return false;
		}

		$defaults = array(
			'last_result' => null,
			'last_error'  => null,
			'lock_token'  => null,
		);

		$args = array_merge( $defaults, is_array( $args ) ? $args : array() );

		$lock_token = is_string( $args['lock_token'] ) ? $args['lock_token'] : '';

		if ( '' === $lock_token ) {
			return false;
		}

		$last_result = null === $args['last_result'] ? $status : substr( sanitize_text_field( (string) $args['last_result'] ), 0, 32 );
		$last_error  = null === $args['last_error'] ? null : substr( sanitize_text_field( (string) $args['last_error'] ), 0, 255 );

		$table = self::table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, last_result = %s, last_error = %s, lock_token = NULL, locked_at = NULL, updated_at = %s
				 WHERE id = %d AND status = %s AND lock_token = %s",
				$status,
				$last_result,
				$last_error,
				self::now(),
				$id,
				self::STATUS_PROCESSING,
				$lock_token
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Devuelve a pending los elementos cuyo lease ha caducado.
	 *
	 * Cubre el caso de un PHP o un WP-Cron que muere a mitad del procesamiento.
	 * También recupera filas en processing sin locked_at, que sólo pueden
	 * proceder de una anomalía y quedarían bloqueadas para siempre.
	 *
	 * No incrementa attempts: el intento ya se contó al reclamar.
	 *
	 * @param int|null $lease_seconds Lease explícito en segundos.
	 * @return int Número de filas recuperadas.
	 */
	public static function release_expired_locks( $lease_seconds = null ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return 0;
		}

		$seconds = self::lease_seconds( $lease_seconds );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$table   = self::table();
		$now     = self::now();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, lock_token = NULL, locked_at = NULL, updated_at = %s
				 WHERE status = %s AND ( locked_at IS NULL OR locked_at < %s )",
				self::STATUS_PENDING,
				$now,
				self::STATUS_PROCESSING,
				$cutoff
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Devuelve a pending todos los elementos en processing, sin mirar el lease.
	 *
	 * Se usa en la desactivación del plugin para no dejar nada bloqueado.
	 *
	 * @return int Número de filas liberadas.
	 */
	public static function release_all_locks() {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return 0;
		}

		$table = self::table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, lock_token = NULL, locked_at = NULL, updated_at = %s
				 WHERE status = %s",
				self::STATUS_PENDING,
				self::now(),
				self::STATUS_PROCESSING
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Estadísticas básicas de la cola, en una sola consulta agregada.
	 *
	 * @return array total más un contador por estado.
	 */
	public static function stats() {
		global $wpdb;

		$stats = array( 'total' => 0 );

		foreach ( self::statuses() as $status ) {
			$stats[ $status ] = 0;
		}

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return $stats;
		}

		$table = self::table();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! is_array( $rows ) ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$status = (string) $row['status'];
			$count  = (int) $row['total'];

			$stats[ $status ]  = isset( $stats[ $status ] ) ? $stats[ $status ] + $count : $count;
			$stats['total']   += $count;
		}

		return $stats;
	}

	/**
	 * Número de filas en un estado concreto.
	 *
	 * @param string $status Estado.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return 0;
		}

		$table = self::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", (string) $status )
		);
	}

	/**
	 * Recupera una fila por ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return null;
		}

		$table = self::table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Recupera una fila por hash.
	 *
	 * @param string $hash Hash sha1.
	 * @return array|null
	 */
	public static function find_by_hash( $hash ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return null;
		}

		$table = self::table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s LIMIT 1", (string) $hash ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Recupera una fila por URL normalizada.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public static function find_by_url( $url ) {
		return self::find_by_hash( self::hash_url( $url ) );
	}

	/**
	 * Elimina todas las filas cuyo campo source coincida.
	 *
	 * Único método de borrado de esta fase. Lo consume el script de prueba de
	 * F2.1 para limpiar sus propias filas sin vaciar la cola entera. El vaciado
	 * completo ("Limpiar cola") es una acción de administración y llegará con su
	 * interfaz, no antes.
	 *
	 * @param string $source Origen.
	 * @return int Filas eliminadas.
	 */
	public static function delete_by_source( $source ) {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return 0;
		}

		$deleted = $wpdb->delete( self::table(), array( 'source' => (string) $source ), array( '%s' ) ); // phpcs:ignore WordPress.DB

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Prioridad por defecto según el tipo, leída de los ajustes de F1.
	 *
	 * @param string $type Tipo.
	 * @return int
	 */
	private static function default_priority( $type ) {
		$priorities = SCW_Settings::get( 'priorities', array() );

		if ( is_array( $priorities ) && isset( $priorities[ $type ] ) ) {
			return (int) $priorities[ $type ];
		}

		if ( is_array( $priorities ) && isset( $priorities['unknown'] ) ) {
			return (int) $priorities['unknown'];
		}

		return 50;
	}

	/**
	 * Intentos máximos por defecto de una entrada de la cola.
	 *
	 * Valor propio de la cola, deliberadamente independiente de cualquier ajuste
	 * de reintentos HTTP: son dos conceptos distintos y no deben acoplarse.
	 *
	 * @return int
	 */
	private static function default_max_attempts() {
		return self::DEFAULT_MAX_ATTEMPTS;
	}

	/**
	 * Token de lock de 32 caracteres.
	 *
	 * @return string
	 */
	private static function generate_lock_token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Momento actual en UTC y formato MySQL, igual que el resto del plugin.
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Convierte un valor de fecha admisible a datetime MySQL en UTC.
	 *
	 * @param mixed  $value    Timestamp, cadena datetime o null.
	 * @param string $fallback Valor por defecto.
	 * @return string
	 */
	private static function to_mysql_datetime( $value, $fallback ) {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}

		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		$timestamp = strtotime( (string) $value . ' UTC' );

		if ( false === $timestamp ) {
			return $fallback;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
