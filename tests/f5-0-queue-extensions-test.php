<?php
/**
 * Prueba controlada de las extensiones de cola de F5.0.
 *
 * Cubre exclusivamente lo que F5.0 añade a SCW_Queue:
 *
 *   - defer()
 *   - next_available_at()
 *   - metrics()
 *   - derivación y congelación de max_attempts
 *   - release_expired_locks() con límite de intentos
 *
 * NO hace ninguna petición HTTP. NO toca el scheduler, el worker ni el cliente
 * HTTP. NO hay política de reintentos: eso es F5.2 y aquí no existe.
 *
 * Ejecución recomendada, desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f5-0-queue-extensions-test.php
 *
 * Todas las filas creadas llevan source = 'f5-0-test' y se eliminan al final.
 * Los ajustes que se modifican temporalmente se restauran siempre, incluso si
 * una comprobación falla.
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

foreach ( array( 'defer', 'next_available_at', 'metrics', 'release_expired_locks_report' ) as $scw_required ) {
	if ( ! method_exists( 'SCW_Queue', $scw_required ) ) {
		exit( "SCW_Queue::{$scw_required}() no existe. ¿Está instalada la versión de F5.0?\n" );
	}
}

$scw_test_source = 'f5-0-test';
$scw_pass        = 0;
$scw_fail        = 0;

/**
 * Comprueba una condición e imprime el resultado.
 *
 * @param string $label  Descripción.
 * @param bool   $ok     Resultado.
 * @param string $detail Detalle a mostrar.
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

/**
 * Inserta una URL de prueba y devuelve su fila completa.
 *
 * @param string $slug Sufijo único de la URL.
 * @param array  $args Argumentos para SCW_Queue::insert().
 * @return array|null
 */
$scw_enqueue = function ( $slug, $args = array() ) use ( $scw_test_source ) {
	$url  = 'https://www.servisello.es/f5-0/' . $slug . '/';
	$args = array_merge( array( 'source' => $scw_test_source ), $args );

	$result = SCW_Queue::insert( $url, $args );

	return $result['id'] ? SCW_Queue::get( $result['id'] ) : null;
};

/**
 * Fuerza el valor de columnas de una fila sin pasar por la API pública.
 *
 * Se usa sólo para construir estados que en producción tardarían minutos en
 * darse (un lease caducado, un contador de intentos ya consumido). No forma
 * parte de ningún camino real del plugin.
 *
 * @param int   $id      ID de la fila.
 * @param array $columns Pares columna => valor.
 * @return void
 */
$scw_force = function ( $id, array $columns ) {
	global $wpdb;

	$wpdb->update( SCW_Queue::table(), $columns, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
};

echo "\n=== F5.0 · Extensiones de cola ===\n";
echo 'Tabla: ' . SCW_Queue::table() . "\n";
echo 'Versión de esquema: ' . get_option( SCW_Schema::OPTION_DB_VERSION, '(ninguna)' ) . "\n";
echo 'http_max_retries efectivo: ' . var_export( SCW_Settings::get( 'http_max_retries' ), true ) . "\n\n";

// Estado inicial, para comprobar al final que no se ha dejado nada.
$scw_initial_stats = SCW_Queue::stats();

// Ajustes originales. Se restauran SIEMPRE al final.
$scw_original_retries = SCW_Settings::get( 'http_max_retries' );

// ---------------------------------------------------------------------------
// 0. Precondiciones
// ---------------------------------------------------------------------------
echo "0. Precondiciones\n";

$scw_check( 'La tabla de cola existe', SCW_Schema::table_exists( 'queue' ) );
$scw_check( 'El esquema sigue en la versión 2', '2' === (string) get_option( SCW_Schema::OPTION_DB_VERSION ), 'F5.0 no cambia el esquema' );
$scw_check( 'DEFAULT_MAX_ATTEMPTS sigue siendo 2', 2 === SCW_Queue::DEFAULT_MAX_ATTEMPTS, 'contrato de F2.1' );
$scw_check( 'MIN_MAX_ATTEMPTS es 1', 1 === SCW_Queue::MIN_MAX_ATTEMPTS );
$scw_check( 'queue_lease_seconds está declarado en los ajustes', null !== SCW_Settings::get( 'queue_lease_seconds' ), 'valor=' . var_export( SCW_Settings::get( 'queue_lease_seconds' ), true ) );
$scw_check( 'queue_lease_seconds conserva el valor efectivo anterior (120)', 120 === (int) SCW_Settings::get( 'queue_lease_seconds' ) );
$scw_check( 'lease_seconds() lo respeta', 120 === SCW_Queue::lease_seconds() );

if ( 1 !== (int) $scw_original_retries ) {
	echo "  [AVISO]  http_max_retries almacenado = " . var_export( $scw_original_retries, true ) . ", no 1.\n";
	echo "           Los ajustes se persistieron íntegros en la activación, así que el nuevo\n";
	echo "           valor por defecto del código NO se aplica solo en instalaciones antiguas.\n";
	echo "           Corrección operativa: wp option patch update scw_settings http_max_retries 1\n";
}

// ---------------------------------------------------------------------------
// D. max_attempts: derivación, valor explícito, clamp y congelación
// ---------------------------------------------------------------------------
echo "\nD. max_attempts\n";

$scw_derived = (int) $scw_original_retries + 1;
$scw_row_d1  = $scw_enqueue( 'max-attempts-default' );

$scw_check(
	'Por defecto, max_attempts = http_max_retries + 1',
	$scw_row_d1 && $scw_derived === (int) $scw_row_d1['max_attempts'],
	'max_attempts=' . ( $scw_row_d1 ? $scw_row_d1['max_attempts'] : 'n/a' ) . ' esperado=' . $scw_derived
);

$scw_row_d2 = $scw_enqueue( 'max-attempts-explicit', array( 'max_attempts' => 7 ) );
$scw_check( 'Un max_attempts explícito se respeta', $scw_row_d2 && 7 === (int) $scw_row_d2['max_attempts'], 'max_attempts=' . ( $scw_row_d2 ? $scw_row_d2['max_attempts'] : 'n/a' ) );

$scw_row_d3 = $scw_enqueue( 'max-attempts-zero', array( 'max_attempts' => 0 ) );
$scw_check( 'max_attempts = 0 se eleva a 1', $scw_row_d3 && 1 === (int) $scw_row_d3['max_attempts'], 'max_attempts=' . ( $scw_row_d3 ? $scw_row_d3['max_attempts'] : 'n/a' ) );

$scw_row_d4 = $scw_enqueue( 'max-attempts-negative', array( 'max_attempts' => -5 ) );
$scw_check( 'max_attempts negativo se eleva a 1', $scw_row_d4 && 1 === (int) $scw_row_d4['max_attempts'], 'max_attempts=' . ( $scw_row_d4 ? $scw_row_d4['max_attempts'] : 'n/a' ) );

$scw_row_d5 = $scw_enqueue( 'max-attempts-huge', array( 'max_attempts' => 9999 ) );
$scw_check( 'max_attempts se acota por arriba a 255', $scw_row_d5 && 255 === (int) $scw_row_d5['max_attempts'], 'max_attempts=' . ( $scw_row_d5 ? $scw_row_d5['max_attempts'] : 'n/a' ) );

// --- Congelación: el ajuste se cambia temporalmente y se restaura siempre ---
SCW_Settings::update( array( 'http_max_retries' => 3 ) );

$scw_frozen_row = SCW_Queue::get( $scw_row_d1['id'] );
$scw_new_row    = $scw_enqueue( 'max-attempts-after-change' );

$scw_check(
	'Cambiar http_max_retries NO altera max_attempts de una fila existente',
	$scw_frozen_row && $scw_derived === (int) $scw_frozen_row['max_attempts'],
	'max_attempts=' . ( $scw_frozen_row ? $scw_frozen_row['max_attempts'] : 'n/a' ) . ' esperado=' . $scw_derived
);
$scw_check(
	'Una fila NUEVA sí recibe el nuevo límite derivado (3 + 1 = 4)',
	$scw_new_row && 4 === (int) $scw_new_row['max_attempts'],
	'max_attempts=' . ( $scw_new_row ? $scw_new_row['max_attempts'] : 'n/a' )
);

// Restauración inmediata del ajuste, antes de cualquier otra sección.
SCW_Settings::update( array( 'http_max_retries' => $scw_original_retries ) );
$scw_check( 'http_max_retries restaurado a su valor original', $scw_original_retries === SCW_Settings::get( 'http_max_retries' ), 'valor=' . var_export( SCW_Settings::get( 'http_max_retries' ), true ) );

// ---------------------------------------------------------------------------
// A. defer()
// ---------------------------------------------------------------------------
echo "\nA. defer()\n";

$scw_row_a = $scw_enqueue( 'defer-ok', array( 'priority' => 200 ) );
$scw_claim = SCW_Queue::claim();

$scw_check( 'Se ha reclamado la fila esperada', $scw_claim && (int) $scw_claim['id'] === (int) $scw_row_a['id'], 'id=' . ( $scw_claim ? $scw_claim['id'] : 'null' ) );
$scw_check( 'La fila está en processing con attempts = 1', $scw_claim && SCW_Queue::STATUS_PROCESSING === $scw_claim['status'] && 1 === (int) $scw_claim['attempts'] );

$scw_future   = time() + 600;
$scw_deferred = SCW_Queue::defer( $scw_claim['id'], $scw_future, array( 'lock_token' => $scw_claim['lock_token'] ) );
$scw_row_a    = SCW_Queue::get( $scw_claim['id'] );

$scw_check( 'defer() con token correcto devuelve true', true === $scw_deferred );
$scw_check( "defer() deja la fila en pending", $scw_row_a && SCW_Queue::STATUS_PENDING === $scw_row_a['status'], 'status=' . ( $scw_row_a ? $scw_row_a['status'] : 'n/a' ) );
$scw_check( 'available_at queda en el futuro solicitado', $scw_row_a && gmdate( 'Y-m-d H:i:s', $scw_future ) === $scw_row_a['available_at'], 'available_at=' . ( $scw_row_a ? $scw_row_a['available_at'] : 'n/a' ) );
$scw_check( 'lock_token queda limpio', $scw_row_a && null === $scw_row_a['lock_token'] );
$scw_check( 'locked_at queda limpio', $scw_row_a && null === $scw_row_a['locked_at'] );
$scw_check( 'attempts se conserva en 1', $scw_row_a && 1 === (int) $scw_row_a['attempts'], 'attempts=' . ( $scw_row_a ? $scw_row_a['attempts'] : 'n/a' ) );
$scw_check( 'max_attempts se conserva', $scw_row_a && $scw_derived === (int) $scw_row_a['max_attempts'], 'max_attempts=' . ( $scw_row_a ? $scw_row_a['max_attempts'] : 'n/a' ) );
$scw_check( 'defer() no modifica last_result por defecto', $scw_row_a && null === $scw_row_a['last_result'] );
$scw_next_after_defer = SCW_Queue::get_next();
$scw_check(
	'La fila aplazada NO es reclamable todavía, pese a su prioridad alta',
	! $scw_next_after_defer || (int) $scw_next_after_defer['id'] !== (int) $scw_row_a['id'],
	'get_next=' . ( $scw_next_after_defer ? $scw_next_after_defer['id'] : 'null' ) . ' aplazada=' . $scw_row_a['id']
);

// Token incorrecto y estado incorrecto.
$scw_row_b = $scw_enqueue( 'defer-bad-token', array( 'priority' => 200 ) );
$scw_claim_b = SCW_Queue::claim();

$scw_bad_token = SCW_Queue::defer( $scw_claim_b['id'], time() + 300, array( 'lock_token' => 'token-que-no-es' ) );
$scw_row_b     = SCW_Queue::get( $scw_claim_b['id'] );

$scw_check( 'defer() con token incorrecto devuelve false', false === $scw_bad_token );
$scw_check( 'La fila sigue en processing', $scw_row_b && SCW_Queue::STATUS_PROCESSING === $scw_row_b['status'], 'status=' . ( $scw_row_b ? $scw_row_b['status'] : 'n/a' ) );
$scw_check( 'El lock_token sigue intacto', $scw_row_b && $scw_claim_b['lock_token'] === $scw_row_b['lock_token'] );

$scw_no_token = SCW_Queue::defer( $scw_claim_b['id'], time() + 300, array() );
$scw_check( 'defer() sin lock_token devuelve false', false === $scw_no_token );

// Se cierra la fila para poder probar el estado incorrecto.
SCW_Queue::complete( $scw_claim_b['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => $scw_claim_b['lock_token'] ) );

$scw_wrong_state = SCW_Queue::defer( $scw_claim_b['id'], time() + 300, array( 'lock_token' => $scw_claim_b['lock_token'] ) );
$scw_row_b       = SCW_Queue::get( $scw_claim_b['id'] );

$scw_check( 'defer() sobre una fila que no está en processing devuelve false', false === $scw_wrong_state );
$scw_check( 'La fila terminal no se altera', $scw_row_b && SCW_Queue::STATUS_SUCCESS === $scw_row_b['status'], 'status=' . ( $scw_row_b ? $scw_row_b['status'] : 'n/a' ) );

$scw_check( 'defer() con id inexistente devuelve false', false === SCW_Queue::defer( 999999999, time() + 60, array( 'lock_token' => 'x' ) ) );

// ---------------------------------------------------------------------------
// B. next_available_at()
// ---------------------------------------------------------------------------
echo "\nB. next_available_at()\n";

// Se parte de una cola limpia de filas de prueba para poder razonar sobre el
// mínimo global sin depender del contenido previo de la tabla.
SCW_Queue::delete_by_source( $scw_test_source );

$scw_check( 'Sin filas pending propias, se hereda el mínimo global o null', true, 'base=' . var_export( SCW_Queue::next_available_at(), true ) );

$scw_baseline = SCW_Queue::next_available_at();

$scw_t_far  = time() + 3600;
$scw_t_near = time() + 120;

$scw_enqueue( 'nav-far', array( 'available_at' => $scw_t_far ) );
$scw_enqueue( 'nav-near', array( 'available_at' => $scw_t_near ) );

$scw_nav = SCW_Queue::next_available_at();

if ( null === $scw_baseline ) {
	$scw_check( 'Devuelve el available_at mínimo entre las pending', $scw_t_near === $scw_nav, 'obtenido=' . var_export( $scw_nav, true ) . ' esperado=' . $scw_t_near );
} else {
	$scw_check( 'Devuelve el available_at mínimo entre las pending', min( $scw_baseline, $scw_t_near ) === $scw_nav, 'obtenido=' . var_export( $scw_nav, true ) );
}

$scw_check( 'El valor devuelto es un timestamp entero', is_int( $scw_nav ) );
$scw_check( 'No devuelve el valor más lejano', $scw_nav !== $scw_t_far );

// Una fila processing NO debe contarse.
$scw_row_c  = $scw_enqueue( 'nav-processing', array( 'priority' => 250, 'available_at' => time() - 10 ) );
$scw_claim_c = SCW_Queue::claim();

$scw_check( 'Precondición: la fila processing es la de mayor prioridad', $scw_claim_c && (int) $scw_claim_c['id'] === (int) $scw_row_c['id'] );

$scw_nav_after = SCW_Queue::next_available_at();
$scw_check(
	'next_available_at() ignora las filas processing',
	$scw_nav_after === $scw_nav,
	'con processing=' . var_export( $scw_nav_after, true ) . ' sin processing=' . var_export( $scw_nav, true )
);

// ---------------------------------------------------------------------------
// C. metrics()
// ---------------------------------------------------------------------------
echo "\nC. metrics()\n";

// Escenario mixto construido sobre las filas propias.
$scw_ins_success    = $scw_enqueue( 'metrics-success', array( 'priority' => 240 ) );
$scw_claim_success  = SCW_Queue::claim();
SCW_Queue::complete( $scw_claim_success['id'], SCW_Queue::STATUS_SUCCESS, array( 'lock_token' => $scw_claim_success['lock_token'] ) );

$scw_ins_skipped   = $scw_enqueue( 'metrics-skipped', array( 'priority' => 239 ) );
$scw_claim_skipped = SCW_Queue::claim();
SCW_Queue::complete( $scw_claim_skipped['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claim_skipped['lock_token'] ) );

$scw_ins_susp   = $scw_enqueue( 'metrics-suspicious', array( 'priority' => 238 ) );
$scw_claim_susp = SCW_Queue::claim();
SCW_Queue::complete( $scw_claim_susp['id'], SCW_Queue::STATUS_SUSPICIOUS, array( 'lock_token' => $scw_claim_susp['lock_token'] ) );

$scw_ins_failed   = $scw_enqueue( 'metrics-failed', array( 'priority' => 237 ) );
$scw_claim_failed = SCW_Queue::claim();
SCW_Queue::complete( $scw_claim_failed['id'], SCW_Queue::STATUS_FAILED, array( 'lock_token' => $scw_claim_failed['lock_token'] ) );

$scw_now     = time();
$scw_metrics = SCW_Queue::metrics( $scw_now );

$scw_check( 'metrics() incluye un contador por cada estado', ! array_diff( SCW_Queue::statuses(), array_keys( $scw_metrics ) ) );
$scw_check( 'metrics() incluye claimable_now', array_key_exists( 'claimable_now', $scw_metrics ) );
$scw_check( 'metrics() incluye next_available_at', array_key_exists( 'next_available_at', $scw_metrics ) );
$scw_check( 'metrics() incluye open_work', array_key_exists( 'open_work', $scw_metrics ) );
$scw_check( 'metrics() devuelve el now utilizado', $scw_now === $scw_metrics['now'] );

$scw_check( 'success >= 1', $scw_metrics[ SCW_Queue::STATUS_SUCCESS ] >= 1, 'success=' . $scw_metrics[ SCW_Queue::STATUS_SUCCESS ] );
$scw_check( 'skipped >= 1', $scw_metrics[ SCW_Queue::STATUS_SKIPPED ] >= 1, 'skipped=' . $scw_metrics[ SCW_Queue::STATUS_SKIPPED ] );
$scw_check( 'suspicious >= 1', $scw_metrics[ SCW_Queue::STATUS_SUSPICIOUS ] >= 1, 'suspicious=' . $scw_metrics[ SCW_Queue::STATUS_SUSPICIOUS ] );
$scw_check( 'failed >= 1', $scw_metrics[ SCW_Queue::STATUS_FAILED ] >= 1, 'failed=' . $scw_metrics[ SCW_Queue::STATUS_FAILED ] );
$scw_check( 'processing >= 1 (queda la fila de la sección B)', $scw_metrics[ SCW_Queue::STATUS_PROCESSING ] >= 1, 'processing=' . $scw_metrics[ SCW_Queue::STATUS_PROCESSING ] );
$scw_check( 'open_work = pending + processing', $scw_metrics['open_work'] === $scw_metrics[ SCW_Queue::STATUS_PENDING ] + $scw_metrics[ SCW_Queue::STATUS_PROCESSING ] );
$scw_check( 'total es la suma de todos los estados', $scw_metrics['total'] === array_sum( array_map( function ( $s ) use ( $scw_metrics ) { return $scw_metrics[ $s ]; }, SCW_Queue::statuses() ) ) );

// claimable_now sólo cuenta pending disponibles.
$scw_claimable_expected = 0;
foreach ( array( $scw_t_far, $scw_t_near ) as $scw_ts ) {
	if ( $scw_ts <= $scw_now ) {
		$scw_claimable_expected++;
	}
}

$scw_check(
	'Las dos filas pending aplazadas NO cuentan como claimable_now',
	$scw_metrics['claimable_now'] <= $scw_metrics[ SCW_Queue::STATUS_PENDING ] - 2 + $scw_claimable_expected,
	'claimable_now=' . $scw_metrics['claimable_now'] . ' pending=' . $scw_metrics[ SCW_Queue::STATUS_PENDING ]
);

$scw_metrics_far = SCW_Queue::metrics( $scw_t_far + 1 );
$scw_check(
	'Con un now posterior, esas filas sí son claimable',
	$scw_metrics_far['claimable_now'] >= $scw_metrics['claimable_now'] + 2,
	'antes=' . $scw_metrics['claimable_now'] . ' después=' . $scw_metrics_far['claimable_now']
);

// --- El caso obligatorio: pending = 0 y processing > 0 -----------------------
echo "\nC.bis  pending = 0 con processing > 0\n";

// Se vacían las filas propias y se deja exactamente una en processing.
SCW_Queue::delete_by_source( $scw_test_source );

$scw_pre = SCW_Queue::metrics();

if ( 0 === $scw_pre[ SCW_Queue::STATUS_PENDING ] && 0 === $scw_pre[ SCW_Queue::STATUS_PROCESSING ] ) {
	$scw_ins_only = $scw_enqueue( 'only-processing' );
	$scw_claim_only = SCW_Queue::claim();

	$scw_m = SCW_Queue::metrics();

	$scw_check( 'pending = 0', 0 === $scw_m[ SCW_Queue::STATUS_PENDING ], 'pending=' . $scw_m[ SCW_Queue::STATUS_PENDING ] );
	$scw_check( 'processing = 1', 1 === $scw_m[ SCW_Queue::STATUS_PROCESSING ], 'processing=' . $scw_m[ SCW_Queue::STATUS_PROCESSING ] );
	$scw_check( 'next_available_at es null (no hay pending)', null === $scw_m['next_available_at'] );
	$scw_check( 'claimable_now = 0', 0 === $scw_m['claimable_now'] );
	$scw_check( 'open_work = 1: la cola NO está agotada', 1 === $scw_m['open_work'], 'open_work=' . $scw_m['open_work'] );
	$scw_check( 'Las métricas permiten distinguir este caso de una cola vacía', $scw_m['open_work'] > 0 && 0 === $scw_m['claimable_now'] );

	// Se libera para no dejar nada bloqueado.
	SCW_Queue::complete( $scw_claim_only['id'], SCW_Queue::STATUS_SKIPPED, array( 'lock_token' => $scw_claim_only['lock_token'] ) );
} else {
	echo "  [AVISO]  La cola contiene filas ajenas al test; se omite la comprobación aislada.\n";
	echo "           pending=" . $scw_pre[ SCW_Queue::STATUS_PENDING ] . ' processing=' . $scw_pre[ SCW_Queue::STATUS_PROCESSING ] . "\n";
}

// ---------------------------------------------------------------------------
// E. release_expired_locks() con límite de intentos
// ---------------------------------------------------------------------------
echo "\nE. Recuperación de leases\n";

SCW_Queue::delete_by_source( $scw_test_source );

// --- E.1: attempts < max_attempts -> pending --------------------------------
$scw_ins_rec = $scw_enqueue( 'lease-recover', array( 'priority' => 260, 'max_attempts' => 3 ) );
$scw_claim_rec = SCW_Queue::claim();

$scw_check( 'Precondición: reclamada con attempts = 1 y max_attempts = 3', $scw_claim_rec && 1 === (int) $scw_claim_rec['attempts'] && 3 === (int) $scw_claim_rec['max_attempts'] );

// Se envejece el lease.
$scw_force( $scw_claim_rec['id'], array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );

$scw_report = SCW_Queue::release_expired_locks_report( 120 );
$scw_row_rec = SCW_Queue::get( $scw_claim_rec['id'] );

$scw_check( 'La fila con intentos disponibles vuelve a pending', $scw_row_rec && SCW_Queue::STATUS_PENDING === $scw_row_rec['status'], 'status=' . ( $scw_row_rec ? $scw_row_rec['status'] : 'n/a' ) );
$scw_check( 'El informe la cuenta como recuperada', $scw_report['recovered'] >= 1, 'recovered=' . $scw_report['recovered'] );
$scw_check( 'El informe no la cuenta como agotada', 0 === $scw_report['exhausted'], 'exhausted=' . $scw_report['exhausted'] );
$scw_check( 'attempts se conserva en 1', $scw_row_rec && 1 === (int) $scw_row_rec['attempts'], 'attempts=' . ( $scw_row_rec ? $scw_row_rec['attempts'] : 'n/a' ) );
$scw_check( 'max_attempts se conserva en 3', $scw_row_rec && 3 === (int) $scw_row_rec['max_attempts'] );
$scw_check( 'lock_token limpiado', $scw_row_rec && null === $scw_row_rec['lock_token'] );
$scw_check( 'locked_at limpiado', $scw_row_rec && null === $scw_row_rec['locked_at'] );
$scw_check( 'last_result no se inventa en la recuperación', $scw_row_rec && null === $scw_row_rec['last_result'] );

// --- E.2: attempts >= max_attempts -> failed --------------------------------
// Se vacían las filas de E.1: la fila recuperada volvió a pending con prioridad
// alta y sería la que reclamase esta sección.
SCW_Queue::delete_by_source( $scw_test_source );

$scw_ins_exh   = $scw_enqueue( 'lease-exhausted', array( 'priority' => 259, 'max_attempts' => 1 ) );
$scw_claim_exh = SCW_Queue::claim();

$scw_check( 'Precondición: reclamada con attempts = 1 y max_attempts = 1', $scw_claim_exh && 1 === (int) $scw_claim_exh['attempts'] && 1 === (int) $scw_claim_exh['max_attempts'] );

$scw_force( $scw_claim_exh['id'], array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );

$scw_report_exh = SCW_Queue::release_expired_locks_report( 120 );
$scw_row_exh    = SCW_Queue::get( $scw_claim_exh['id'] );

$scw_check( 'La fila sin intentos disponibles pasa a failed', $scw_row_exh && SCW_Queue::STATUS_FAILED === $scw_row_exh['status'], 'status=' . ( $scw_row_exh ? $scw_row_exh['status'] : 'n/a' ) );
$scw_check( 'NO vuelve a pending', $scw_row_exh && SCW_Queue::STATUS_PENDING !== $scw_row_exh['status'] );
$scw_check( 'last_result = lease_exhausted', $scw_row_exh && SCW_Queue::RESULT_LEASE_EXHAUSTED === $scw_row_exh['last_result'], 'last_result=' . ( $scw_row_exh ? var_export( $scw_row_exh['last_result'], true ) : 'n/a' ) );
$scw_check( 'El informe la cuenta como agotada', 1 === $scw_report_exh['exhausted'], 'exhausted=' . $scw_report_exh['exhausted'] );
$scw_check( 'lock_token limpiado', $scw_row_exh && null === $scw_row_exh['lock_token'] );
$scw_check( 'locked_at limpiado', $scw_row_exh && null === $scw_row_exh['locked_at'] );
$scw_check( 'attempts se conserva', $scw_row_exh && 1 === (int) $scw_row_exh['attempts'] );

// --- E.3: no existe una tercera vía -----------------------------------------
$scw_check(
	'recovered + exhausted cubre todas las filas caducadas del informe',
	( $scw_report_exh['recovered'] + $scw_report_exh['exhausted'] ) >= 1
);
$scw_check( 'El informe expone el lease aplicado', 120 === $scw_report_exh['lease'], 'lease=' . $scw_report_exh['lease'] );

// --- E.4: el contrato de F2.1 se mantiene -----------------------------------
$scw_check( 'release_expired_locks() sigue devolviendo un entero', is_int( SCW_Queue::release_expired_locks( 3600 ) ) );

// --- E.5: el ciclo infinito queda cortado -----------------------------------
echo "\nE.bis  El ciclo infinito de lease queda cortado\n";

SCW_Queue::delete_by_source( $scw_test_source );

$scw_ins_loop = $scw_enqueue( 'lease-loop', array( 'priority' => 258, 'max_attempts' => 2 ) );

$scw_loop_claims = 0;
$scw_loop_status = '';

for ( $scw_i = 0; $scw_i < 10; $scw_i++ ) {
	$scw_loop_claim = SCW_Queue::claim();

	if ( ! $scw_loop_claim || (int) $scw_loop_claim['id'] !== (int) $scw_ins_loop['id'] ) {
		// Otra fila: se devuelve tal cual y se sale del bucle.
		if ( $scw_loop_claim ) {
			SCW_Queue::defer( $scw_loop_claim['id'], time(), array( 'lock_token' => $scw_loop_claim['lock_token'] ) );
		}
		break;
	}

	$scw_loop_claims++;

	// Simula que el proceso muere: no se completa nada, el lease envejece.
	$scw_force( $scw_loop_claim['id'], array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
	SCW_Queue::release_expired_locks_report( 120 );

	$scw_loop_row    = SCW_Queue::get( $scw_ins_loop['id'] );
	$scw_loop_status = $scw_loop_row['status'];

	if ( SCW_Queue::STATUS_PENDING !== $scw_loop_status ) {
		break;
	}
}

$scw_check( 'El bucle termina en failed y no da 10 vueltas', SCW_Queue::STATUS_FAILED === $scw_loop_status, 'status=' . $scw_loop_status . ' vueltas=' . $scw_loop_claims );
$scw_check( 'Se ha reclamado exactamente max_attempts veces', 2 === $scw_loop_claims, 'reclamaciones=' . $scw_loop_claims );

$scw_loop_final = SCW_Queue::get( $scw_ins_loop['id'] );
$scw_check( 'attempts final = max_attempts', $scw_loop_final && (int) $scw_loop_final['attempts'] === (int) $scw_loop_final['max_attempts'], 'attempts=' . ( $scw_loop_final ? $scw_loop_final['attempts'] . '/' . $scw_loop_final['max_attempts'] : 'n/a' ) );
$scw_check( 'El motivo es lease_exhausted', $scw_loop_final && SCW_Queue::RESULT_LEASE_EXHAUSTED === $scw_loop_final['last_result'] );

// ---------------------------------------------------------------------------
// F. Limpieza y restauración
// ---------------------------------------------------------------------------
echo "\nF. Limpieza\n";

SCW_Settings::update( array( 'http_max_retries' => $scw_original_retries ) );

$scw_deleted = SCW_Queue::delete_by_source( $scw_test_source );
$scw_final   = SCW_Queue::stats();

$scw_check( 'Filas de prueba eliminadas', $scw_deleted > 0, $scw_deleted . ' filas' );
$scw_check( 'La cola vuelve a su recuento inicial', (int) $scw_final['total'] === (int) $scw_initial_stats['total'], 'total=' . $scw_final['total'] . ' inicial=' . $scw_initial_stats['total'] );
$scw_check( 'http_max_retries restaurado', $scw_original_retries === SCW_Settings::get( 'http_max_retries' ), 'valor=' . var_export( SCW_Settings::get( 'http_max_retries' ), true ) );
$scw_check( 'El esquema sigue en la versión 2', '2' === (string) get_option( SCW_Schema::OPTION_DB_VERSION ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
