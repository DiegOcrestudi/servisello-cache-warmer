<?php
/**
 * Prueba controlada de la cola persistente (F2.1).
 *
 * NO hace ninguna petición HTTP. Sólo escribe y lee la tabla scw_queue.
 *
 * Ejecución recomendada, desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f2-1-queue-test.php
 *
 * Todas las filas creadas llevan source = 'f2-1-test' y se eliminan al final.
 * Para conservarlas y revisarlas en phpMyAdmin:
 *
 *   wp eval-file ... --skip-plugins=... ; o define SCW_TEST_KEEP antes de ejecutar:
 *   wp eval "define('SCW_TEST_KEEP', true); include 'wp-content/plugins/servisello-cache-warmer/tests/f2-1-queue-test.php';"
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Queue' ) ) {
	exit( "SCW_Queue no está cargada. ¿Está activo el plugin?\n" );
}

$scw_test_source = 'f2-1-test';
$scw_pass        = 0;
$scw_fail        = 0;

/**
 * Comprueba una condición e imprime el resultado.
 *
 * @param string $label   Descripción.
 * @param bool   $ok      Resultado.
 * @param string $detail  Detalle a mostrar.
 * @return void
 */
$scw_check = function ( $label, $ok, $detail = '' ) use ( &$scw_pass, &$scw_fail ) {
	if ( $ok ) {
		$scw_pass++;
		echo "  [ OK ]   {$label}";
	} else {
		$scw_fail++;
		echo "  [FALLO]  {$label}";
	}

	if ( '' !== $detail ) {
		echo "  ->  {$detail}";
	}

	echo "\n";
};

echo "\n=== F2.1 · Prueba de la cola persistente ===\n";
echo 'Tabla: ' . SCW_Queue::table() . "\n";
echo 'Versión de esquema: ' . get_option( SCW_Schema::OPTION_DB_VERSION, '(ninguna)' ) . "\n\n";

// Estado de partida: limpiar restos de ejecuciones anteriores.
SCW_Queue::delete_by_source( $scw_test_source );
$scw_initial = SCW_Queue::stats();
echo 'Filas en la cola antes de empezar: ' . $scw_initial['total'] . "\n\n";

// --- 0. Esquema -------------------------------------------------------------
echo "0. Esquema\n";
$scw_check( 'La tabla de cola existe', SCW_Schema::table_exists( 'queue' ) );
foreach ( array( 'type', 'lock_token', 'locked_at', 'max_attempts', 'last_error' ) as $scw_col ) {
	$scw_check( "Columna {$scw_col} presente", SCW_Schema::column_exists( 'queue', $scw_col ) );
}
foreach ( array( 'object_type', 'lease_owner', 'lease_expires_at' ) as $scw_col ) {
	$scw_check( "Columna antigua {$scw_col} eliminada", ! SCW_Schema::column_exists( 'queue', $scw_col ) );
}
$scw_check( 'queue_is_v2() confirma el esquema v2 completo', SCW_Schema::queue_is_v2() );

// --- 1. Inserción -----------------------------------------------------------
echo "\n1. Inserción\n";
$scw_url_home     = 'https://www.servisello.es/';
$scw_url_contacto = 'https://www.servisello.es/contacto/';
$scw_url_prueba   = 'https://www.servisello.es/prueba/';

$scw_r1 = SCW_Queue::insert(
	$scw_url_home,
	array(
		'type'   => 'home',
		'source' => $scw_test_source,
	)
);
$scw_r2 = SCW_Queue::insert(
	$scw_url_contacto,
	array(
		'type'   => 'page',
		'source' => $scw_test_source,
	)
);
$scw_r3 = SCW_Queue::insert(
	$scw_url_prueba,
	array(
		'type'     => 'product_cat',
		'source'   => $scw_test_source,
		'priority' => 100,
	)
);

$scw_check( 'Insertada la home', $scw_r1['inserted'], 'id=' . var_export( $scw_r1['id'], true ) );
$scw_check( 'Insertada /contacto/', $scw_r2['inserted'], 'id=' . var_export( $scw_r2['id'], true ) );
$scw_check( 'Insertada /prueba/', $scw_r3['inserted'], 'id=' . var_export( $scw_r3['id'], true ) );

$scw_bad = SCW_Queue::insert( 'no-es-una-url', array( 'source' => $scw_test_source ) );
$scw_check( 'URL inválida rechazada', ! $scw_bad['inserted'] && 'invalid_url' === $scw_bad['error'], 'error=' . var_export( $scw_bad['error'], true ) );

// --- 2. Duplicado -----------------------------------------------------------
echo "\n2. Deduplicación\n";
$scw_dup = SCW_Queue::insert( $scw_url_home, array( 'source' => $scw_test_source ) );
$scw_check( 'La misma URL no se inserta dos veces', $scw_dup['duplicate'] && ! $scw_dup['inserted'] );
$scw_check( 'El duplicado devuelve el ID existente', (int) $scw_dup['id'] === (int) $scw_r1['id'], 'id=' . var_export( $scw_dup['id'], true ) );
$scw_check( 'El hash es estable', SCW_Queue::hash_url( $scw_url_home ) === $scw_dup['url_hash'] );
$scw_check( 'Sólo hay 3 filas de prueba', 3 === (int) SCW_Queue::stats()['total'] - (int) $scw_initial['total'] );

// --- 3. Prioridades y orden -------------------------------------------------
echo "\n3. Prioridades y orden de salida\n";
$scw_row_home = SCW_Queue::find_by_url( $scw_url_home );
$scw_row_page = SCW_Queue::find_by_url( $scw_url_contacto );
$scw_row_cat  = SCW_Queue::find_by_url( $scw_url_prueba );

$scw_check( 'Prioridad de home tomada de los ajustes (90)', 90 === (int) $scw_row_home['priority'], 'priority=' . $scw_row_home['priority'] );
$scw_check( 'Prioridad de page tomada de los ajustes (60)', 60 === (int) $scw_row_page['priority'], 'priority=' . $scw_row_page['priority'] );
$scw_check( 'Prioridad explícita respetada (100)', 100 === (int) $scw_row_cat['priority'], 'priority=' . $scw_row_cat['priority'] );
$scw_check( 'max_attempts usa la constante propia de Queue', (int) $scw_row_home['max_attempts'] === SCW_Queue::DEFAULT_MAX_ATTEMPTS, 'max_attempts=' . $scw_row_home['max_attempts'] . ' constante=' . SCW_Queue::DEFAULT_MAX_ATTEMPTS );
$scw_check( 'max_attempts coincide con el DEFAULT del esquema', 2 === SCW_Queue::DEFAULT_MAX_ATTEMPTS );

$scw_next = SCW_Queue::get_next();
$scw_check( 'get_next() devuelve la de mayor prioridad', $scw_next && (int) $scw_next['id'] === (int) $scw_row_cat['id'], 'url=' . ( $scw_next ? $scw_next['url'] : 'null' ) );
$scw_check( 'get_next() no cambia el estado', $scw_next && SCW_Queue::STATUS_PENDING === $scw_next['status'] );

// --- 4. pending -> processing ------------------------------------------------
echo "\n4. Reclamación (pending -> processing)\n";
$scw_claimed = SCW_Queue::claim();
$scw_check( 'claim() devuelve una fila', (bool) $scw_claimed );
$scw_check( 'Es la de mayor prioridad', $scw_claimed && (int) $scw_claimed['id'] === (int) $scw_row_cat['id'] );
$scw_check( 'Estado processing', $scw_claimed && SCW_Queue::STATUS_PROCESSING === $scw_claimed['status'], 'status=' . ( $scw_claimed ? $scw_claimed['status'] : '-' ) );
$scw_check( 'lock_token generado de 32 caracteres', $scw_claimed && 32 === strlen( (string) $scw_claimed['lock_token'] ), 'token=' . ( $scw_claimed ? $scw_claimed['lock_token'] : '-' ) );
$scw_check( 'locked_at establecido', $scw_claimed && ! empty( $scw_claimed['locked_at'] ), 'locked_at=' . ( $scw_claimed ? $scw_claimed['locked_at'] : '-' ) );
$scw_check( 'attempts incrementado a 1', $scw_claimed && 1 === (int) $scw_claimed['attempts'], 'attempts=' . ( $scw_claimed ? $scw_claimed['attempts'] : '-' ) );

$scw_claimed2 = SCW_Queue::claim();
$scw_check( 'Una segunda reclamación coge otra URL distinta', $scw_claimed2 && (int) $scw_claimed2['id'] !== (int) $scw_claimed['id'], 'url=' . ( $scw_claimed2 ? $scw_claimed2['url'] : 'null' ) );

// --- 5. processing -> success ------------------------------------------------
echo "\n5. Cierre en success\n";
$scw_ok = SCW_Queue::complete(
	(int) $scw_claimed['id'],
	SCW_Queue::STATUS_SUCCESS,
	array(
		'last_result' => 'OK',
		'lock_token'  => $scw_claimed['lock_token'],
	)
);
$scw_after = SCW_Queue::get( (int) $scw_claimed['id'] );
$scw_check( 'complete() devuelve true', $scw_ok );
$scw_check( 'Estado success', $scw_after && SCW_Queue::STATUS_SUCCESS === $scw_after['status'] );
$scw_check( 'last_result guardado', $scw_after && 'OK' === $scw_after['last_result'] );
$scw_check( 'lock_token liberado', $scw_after && null === $scw_after['lock_token'] );
$scw_check( 'locked_at liberado', $scw_after && null === $scw_after['locked_at'] );

$scw_reuse = SCW_Queue::complete( (int) $scw_claimed['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => $scw_claimed['lock_token'] ) );
$scw_check( 'Un token ya consumido no vuelve a cerrar la fila', ! $scw_reuse );

// --- 6. processing -> failed -------------------------------------------------
echo "\n6. Cierre en failed\n";
$scw_failed_ok = SCW_Queue::complete(
	(int) $scw_claimed2['id'],
	SCW_Queue::STATUS_FAILED,
	array(
		'last_result' => 'ERROR_TIMEOUT',
		'last_error'  => 'cURL error 28: Operation timed out',
		'lock_token'  => $scw_claimed2['lock_token'],
	)
);
$scw_after2 = SCW_Queue::get( (int) $scw_claimed2['id'] );
$scw_check( 'complete() devuelve true', $scw_failed_ok );
$scw_check( 'Estado failed', $scw_after2 && SCW_Queue::STATUS_FAILED === $scw_after2['status'] );
$scw_check( 'last_error guardado', $scw_after2 && false !== strpos( (string) $scw_after2['last_error'], 'cURL error 28' ), 'last_error=' . ( $scw_after2 ? $scw_after2['last_error'] : '-' ) );

$scw_bad_status = SCW_Queue::complete( (int) $scw_after2['id'], 'processing', array( 'lock_token' => $scw_claimed2['lock_token'] ) );
$scw_check( 'complete() rechaza un estado no terminal', ! $scw_bad_status );

// --- 7. Lease caducado y aislamiento entre workers --------------------------
echo "\n7. Lease caducado y aislamiento entre workers\n";
$scw_claimed3 = SCW_Queue::claim();
$scw_check( 'Queda una URL por reclamar', (bool) $scw_claimed3, 'url=' . ( $scw_claimed3 ? $scw_claimed3['url'] : 'null' ) );

if ( $scw_claimed3 ) {
	global $wpdb;

	$scw_token_a = (string) $scw_claimed3['lock_token'];

	$scw_check( 'Un lock reciente no se recupera', 0 === (int) SCW_Queue::release_expired_locks( 3600 ) );
	$scw_check( 'complete() rechaza un estado no terminal aun con el token correcto', ! SCW_Queue::complete( (int) $scw_claimed3['id'], SCW_Queue::STATUS_PROCESSING, array( 'lock_token' => $scw_token_a ) ) );
	$scw_check( 'complete() sin lock_token falla', ! SCW_Queue::complete( (int) $scw_claimed3['id'], SCW_Queue::STATUS_SUCCESS ) );
	$scw_check( 'complete() con lock_token vacío falla', ! SCW_Queue::complete( (int) $scw_claimed3['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => '' ) ) );
	$scw_check( 'La fila sigue en processing tras los intentos fallidos', SCW_Queue::STATUS_PROCESSING === SCW_Queue::get( (int) $scw_claimed3['id'] )['status'] );

	// Se envejece el lock a mano: el lease se mide contra locked_at.
	$wpdb->update(
		SCW_Queue::table(),
		array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
		array( 'id' => (int) $scw_claimed3['id'] ),
		array( '%s' ),
		array( '%d' )
	);

	$scw_recovered = SCW_Queue::release_expired_locks( 120 );
	$scw_after3    = SCW_Queue::get( (int) $scw_claimed3['id'] );

	$scw_check( 'release_expired_locks() recupera 1 fila', 1 === (int) $scw_recovered, 'recuperadas=' . $scw_recovered );
	$scw_check( 'Vuelve a pending', $scw_after3 && SCW_Queue::STATUS_PENDING === $scw_after3['status'] );
	$scw_check( 'lock_token limpiado', $scw_after3 && null === $scw_after3['lock_token'] );
	$scw_check( 'attempts NO se reduce', $scw_after3 && 1 === (int) $scw_after3['attempts'], 'attempts=' . ( $scw_after3 ? $scw_after3['attempts'] : '-' ) );

	// Segundo worker sobre la misma URL.
	$scw_reclaimed = SCW_Queue::claim();
	$scw_token_b   = $scw_reclaimed ? (string) $scw_reclaimed['lock_token'] : '';

	$scw_check( 'Otro worker reclama la misma URL', $scw_reclaimed && (int) $scw_reclaimed['id'] === (int) $scw_claimed3['id'] );
	$scw_check( 'El token nuevo es distinto del anterior', '' !== $scw_token_b && $scw_token_b !== $scw_token_a );
	$scw_check( 'attempts sube a 2', $scw_reclaimed && 2 === (int) $scw_reclaimed['attempts'], 'attempts=' . ( $scw_reclaimed ? $scw_reclaimed['attempts'] : '-' ) );

	// El caso que motivó el cambio: el worker viejo NO puede cerrar el trabajo del nuevo.
	$scw_stale = SCW_Queue::complete( (int) $scw_claimed3['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => $scw_token_a ) );
	$scw_check( 'El token del worker caducado NO puede completar la fila', ! $scw_stale );
	$scw_check( 'La fila sigue en processing y en manos del worker nuevo', SCW_Queue::STATUS_PROCESSING === SCW_Queue::get( (int) $scw_claimed3['id'] )['status'] );

	$scw_check( 'El token vigente sí completa la fila', SCW_Queue::complete( (int) $scw_claimed3['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => $scw_token_b ) ) );
}

// --- 7b. Liberación total de locks --------------------------------------------
echo "\n7b. release_all_locks()\n";
$scw_extra = SCW_Queue::insert(
	'https://www.servisello.es/prueba-lock/',
	array(
		'type'   => 'page',
		'source' => $scw_test_source,
	)
);
$scw_check( 'Insertada una URL adicional para probar la liberación', $scw_extra['inserted'] );
$scw_locked = SCW_Queue::claim();
$scw_check( 'Reclamada', $scw_locked && SCW_Queue::STATUS_PROCESSING === $scw_locked['status'] );
$scw_check( 'release_all_locks() libera 1 fila', 1 === (int) SCW_Queue::release_all_locks() );
$scw_check( 'Vuelve a pending', $scw_locked && SCW_Queue::STATUS_PENDING === SCW_Queue::get( (int) $scw_locked['id'] )['status'] );

// --- 8. Estadísticas ----------------------------------------------------------
echo "\n8. Estadísticas\n";
$scw_stats = SCW_Queue::stats();
foreach ( array( 'total', 'pending', 'processing', 'success', 'skipped', 'suspicious', 'failed' ) as $scw_key ) {
	echo '  ' . str_pad( $scw_key, 12 ) . $scw_stats[ $scw_key ] . "\n";
}
$scw_check( 'stats() devuelve las 6 claves de estado más el total', 7 === count( array_intersect_key( $scw_stats, array_flip( array( 'total', 'pending', 'processing', 'success', 'skipped', 'suspicious', 'failed' ) ) ) ) );
$scw_check( 'count_by_status(success) coincide con stats()', SCW_Queue::count_by_status( SCW_Queue::STATUS_SUCCESS ) === $scw_stats['success'] );

// --- 9. Volcado de la tabla ----------------------------------------------------
echo "\n9. Filas de prueba en la tabla\n";
global $wpdb;
$scw_table = SCW_Queue::table();
$scw_rows  = $wpdb->get_results(
	$wpdb->prepare( "SELECT id, url, type, priority, status, attempts, max_attempts, locked_at, lock_token, last_result, last_error FROM {$scw_table} WHERE source = %s ORDER BY id ASC", $scw_test_source ),
	ARRAY_A
);

foreach ( $scw_rows as $scw_row ) {
	echo '  #' . $scw_row['id'] . '  ' . str_pad( $scw_row['status'], 11 ) . str_pad( $scw_row['type'], 12 ) . 'prio=' . str_pad( $scw_row['priority'], 5 ) . 'att=' . $scw_row['attempts'] . '/' . $scw_row['max_attempts'] . '  ' . $scw_row['url'] . "\n";
	echo '        locked_at=' . var_export( $scw_row['locked_at'], true ) . '  token=' . var_export( $scw_row['lock_token'], true ) . '  last_result=' . var_export( $scw_row['last_result'], true ) . '  last_error=' . var_export( $scw_row['last_error'], true ) . "\n";
}

// --- Limpieza ------------------------------------------------------------------
if ( defined( 'SCW_TEST_KEEP' ) && SCW_TEST_KEEP ) {
	echo "\nSCW_TEST_KEEP activo: las filas de prueba se conservan para inspección manual.\n";
} else {
	$scw_deleted = SCW_Queue::delete_by_source( $scw_test_source );
	echo "\nLimpieza: {$scw_deleted} filas de prueba eliminadas.\n";
	$scw_final = SCW_Queue::stats();
	$scw_check( 'La cola queda como estaba', (int) $scw_final['total'] === (int) $scw_initial['total'], 'total=' . $scw_final['total'] );
}

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
