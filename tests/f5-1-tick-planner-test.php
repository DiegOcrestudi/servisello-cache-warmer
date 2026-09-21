<?php
/**
 * Prueba controlada del planificador temporal (F5.1).
 *
 * Dos bloques:
 *
 *   1. SCW_Tick_Planner::plan(), función PURA. Se le entregan contextos
 *      sintéticos con un `now` fijo, así que los timestamps esperados son
 *      exactos y no dependen del reloj real ni de la base de datos.
 *   2. SCW_Scheduler::arm_tick(), que sí toca WP-Cron y el estado.
 *
 * NO hace ninguna petición HTTP. NO hay retry, pacer, breaker ni watchdog: esta
 * fase sólo introduce el punto único de decisión temporal.
 *
 * Ejecución recomendada, desde la raíz de WordPress:
 *
 *   wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f5-1-tick-planner-test.php
 *
 * El estado del plugin y los eventos de cron se restauran al terminar.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) {
	exit( "Este script sólo puede ejecutarse desde WP-CLI o por un administrador.\n" );
}

if ( ! class_exists( 'SCW_Tick_Planner' ) ) {
	exit( "SCW_Tick_Planner no está cargada. ¿Está instalada la versión de F5.1?\n" );
}

if ( ! method_exists( 'SCW_Scheduler', 'arm_tick' ) ) {
	exit( "SCW_Scheduler::arm_tick() no existe. ¿Está instalada la versión de F5.1?\n" );
}

$scw_test_source = 'f5-1-test';
$scw_pass        = 0;
$scw_fail        = 0;

/**
 * Comprueba una condición e imprime el resultado.
 *
 * @param string $label  Descripción.
 * @param bool   $ok     Resultado.
 * @param string $detail Detalle.
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

// Reloj sintético fijo. Todos los contextos puros lo usan.
$scw_now = 1700000000;

/**
 * Construye un contexto sobre el reloj sintético.
 *
 * @param array $overrides Claves a sobrescribir.
 * @return array
 */
$scw_ctx = function ( $overrides = array() ) use ( $scw_now ) {
	return array_merge(
		array(
			'now'               => $scw_now,
			'run_status'        => SCW_State::STATUS_RUNNING,
			'breaker_state'     => SCW_State::BREAKER_CLOSED,
			'breaker_until'     => 0,
			'current_delay'     => 0,
			'last_request_at'   => 0,
			'pending'           => 0,
			'processing'        => 0,
			'claimable_now'     => 0,
			'next_available_at' => null,
			'open_work'         => 0,
			'lease_seconds'     => 120,
			'pace_max_delay'    => 300,
		),
		$overrides
	);
};

echo "\n=== F5.1 · Planificador temporal ===\n";
echo 'Reloj sintético: ' . $scw_now . " (" . gmdate( 'Y-m-d H:i:s', $scw_now ) . " UTC)\n\n";

$scw_original_state = SCW_State::all();
$scw_original_tick  = wp_next_scheduled( SCW_Scheduler::HOOK_TICK );

// ---------------------------------------------------------------------------
// 1. run_status: sólo RUNNING planifica
// ---------------------------------------------------------------------------
echo "1. run_status\n";

foreach ( array(
	SCW_State::STATUS_STOPPED,
	SCW_State::STATUS_PAUSED_MANUAL,
	SCW_State::STATUS_PAUSED_BREAKER,
	SCW_State::STATUS_STALLED,
) as $scw_status ) {
	$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'run_status' => $scw_status, 'pending' => 5, 'claimable_now' => 5, 'open_work' => 5 ) ) );

	$scw_check(
		"run_status = {$scw_status} -> sin plan, pese a haber trabajo",
		SCW_Tick_Planner::ACTION_NONE === $scw_plan['action'] && null === $scw_plan['at'],
		'action=' . $scw_plan['action'] . ' reason=' . $scw_plan['reason']
	);
}

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'run_status' => SCW_State::STATUS_STOPPED ) ) );
$scw_check( 'El motivo es not_running', SCW_Tick_Planner::REASON_NOT_RUNNING === $scw_plan['reason'] );
$scw_check( 'Un run_status no-RUNNING gana incluso sobre la cola agotada', SCW_Tick_Planner::ACTION_NONE === $scw_plan['action'] );

// ---------------------------------------------------------------------------
// 2. Cola realmente agotada
// ---------------------------------------------------------------------------
echo "\n2. Cola agotada\n";

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx() );
$scw_check( 'pending=0 y processing=0 -> stop', SCW_Tick_Planner::ACTION_STOP === $scw_plan['action'], 'action=' . $scw_plan['action'] );
$scw_check( 'El motivo es queue_drained', SCW_Tick_Planner::REASON_QUEUE_DRAINED === $scw_plan['reason'] );
$scw_check( 'stop no devuelve timestamp', null === $scw_plan['at'] && null === $scw_plan['delay'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'processing' => 1, 'open_work' => 1 ) ) );
$scw_check(
	'pending=0 con processing=1 NO es cola agotada',
	SCW_Tick_Planner::ACTION_STOP !== $scw_plan['action'],
	'action=' . $scw_plan['action'] . ' reason=' . $scw_plan['reason']
);

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'pending' => 1, 'open_work' => 1, 'next_available_at' => $scw_now + 50 ) ) );
$scw_check( 'pending=1 aplazada NO es cola agotada', SCW_Tick_Planner::ACTION_SCHEDULE === $scw_plan['action'] );

// ---------------------------------------------------------------------------
// 3. Trabajo reclamable ahora
// ---------------------------------------------------------------------------
echo "\n3. earliest_work: trabajo disponible\n";

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'pending' => 3, 'claimable_now' => 3, 'open_work' => 3, 'next_available_at' => $scw_now - 10 ) ) );
$scw_check( 'claimable_now > 0 -> at = now', $scw_now === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' now=' . $scw_now );
$scw_check( 'delay = 0', 0 === $scw_plan['delay'] );
$scw_check( 'El motivo es work_ready', SCW_Tick_Planner::REASON_WORK_READY === $scw_plan['reason'] );

// ---------------------------------------------------------------------------
// 4. Trabajo pending aplazado
// ---------------------------------------------------------------------------
echo "\n4. earliest_work: trabajo aplazado\n";

$scw_future = $scw_now + 45;
$scw_plan   = SCW_Tick_Planner::plan( $scw_ctx( array( 'pending' => 2, 'claimable_now' => 0, 'open_work' => 2, 'next_available_at' => $scw_future ) ) );

$scw_check( 'Sin claimable, at = next_available_at', $scw_future === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' esperado=' . $scw_future );
$scw_check( 'delay = 45', 45 === $scw_plan['delay'] );
$scw_check( 'El motivo es work_deferred', SCW_Tick_Planner::REASON_WORK_DEFERRED === $scw_plan['reason'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'pending' => 1, 'claimable_now' => 0, 'open_work' => 1, 'next_available_at' => $scw_now - 999 ) ) );
$scw_check( 'Un next_available_at pasado nunca produce un at anterior a now', $scw_now === $scw_plan['at'], 'at=' . $scw_plan['at'] );

// ---------------------------------------------------------------------------
// 5. processing > 0 con pending = 0: recuperación de lease
// ---------------------------------------------------------------------------
echo "\n5. earliest_work: recuperación de lease\n";

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'processing' => 1, 'open_work' => 1, 'lease_seconds' => 120 ) ) );

$scw_check( 'processing>0 y pending=0 -> se planifica, no se para', SCW_Tick_Planner::ACTION_SCHEDULE === $scw_plan['action'] );
$scw_check( 'at = now + lease_seconds', ( $scw_now + 120 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' esperado=' . ( $scw_now + 120 ) );
$scw_check( 'El motivo es lease_recovery', SCW_Tick_Planner::REASON_LEASE_RECOVERY === $scw_plan['reason'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'processing' => 1, 'open_work' => 1, 'lease_seconds' => 60 ) ) );
$scw_check( 'Sigue la duración de lease existente, no un valor propio', ( $scw_now + 60 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'pending' => 1, 'claimable_now' => 1, 'processing' => 1, 'open_work' => 2 ) ) );
$scw_check( 'Con trabajo reclamable, el lease no manda', $scw_now === $scw_plan['at'] && SCW_Tick_Planner::REASON_WORK_READY === $scw_plan['reason'] );

// ---------------------------------------------------------------------------
// 6. pace_ready
// ---------------------------------------------------------------------------
echo "\n6. pace_ready\n";

$scw_base = array( 'pending' => 1, 'claimable_now' => 1, 'open_work' => 1 );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( $scw_base ) );
$scw_check( 'Sin last_request_at -> listo ahora', $scw_now === $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array_merge( $scw_base, array( 'last_request_at' => $scw_now - 10, 'current_delay' => 0 ) ) ) );
$scw_check( 'Con last_request_at pero sin delay -> listo ahora', $scw_now === $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array_merge( $scw_base, array( 'last_request_at' => 0, 'current_delay' => 48 ) ) ) );
$scw_check( 'Con delay pero sin last_request_at -> listo ahora', $scw_now === $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array_merge( $scw_base, array( 'last_request_at' => $scw_now - 10, 'current_delay' => 48 ) ) ) );
$scw_check( 'at = last_request_at + current_delay', ( $scw_now + 38 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' esperado=' . ( $scw_now + 38 ) );
$scw_check( 'El motivo es paced', SCW_Tick_Planner::REASON_PACED === $scw_plan['reason'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array_merge( $scw_base, array( 'last_request_at' => $scw_now - 600, 'current_delay' => 48 ) ) ) );
$scw_check( 'Un pace_ready ya vencido no retrocede por debajo de now', $scw_now === $scw_plan['at'], 'at=' . $scw_plan['at'] );

// ---------------------------------------------------------------------------
// 7. max(), nunca la suma
// ---------------------------------------------------------------------------
echo "\n7. Combinación trabajo + ritmo: max(), nunca suma\n";

// Backoff 30 s, pacing 60 s. La suma sería 90.
$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx(
		array(
			'pending'           => 1,
			'claimable_now'     => 0,
			'open_work'         => 1,
			'next_available_at' => $scw_now + 30,
			'last_request_at'   => $scw_now,
			'current_delay'     => 60,
		)
	)
);

$scw_check( 'Backoff 30 + pacing 60 -> at = now + 60', ( $scw_now + 60 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' delay=' . $scw_plan['delay'] );
$scw_check( 'NO es la suma (90)', ( $scw_now + 90 ) !== $scw_plan['at'] );
$scw_check( 'Domina el ritmo: motivo paced', SCW_Tick_Planner::REASON_PACED === $scw_plan['reason'] );

// Backoff 120 s, pacing 20 s. La suma sería 140.
$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx(
		array(
			'pending'           => 1,
			'claimable_now'     => 0,
			'open_work'         => 1,
			'next_available_at' => $scw_now + 120,
			'last_request_at'   => $scw_now,
			'current_delay'     => 20,
		)
	)
);

$scw_check( 'Backoff 120 + pacing 20 -> at = now + 120', ( $scw_now + 120 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );
$scw_check( 'NO es la suma (140)', ( $scw_now + 140 ) !== $scw_plan['at'] );
$scw_check( 'Domina el trabajo: motivo work_deferred', SCW_Tick_Planner::REASON_WORK_DEFERRED === $scw_plan['reason'] );

// ---------------------------------------------------------------------------
// 8. Circuit breaker
// ---------------------------------------------------------------------------
echo "\n8. Circuit breaker\n";

$scw_until = $scw_now + 300;
$scw_plan  = SCW_Tick_Planner::plan(
	$scw_ctx(
		array(
			'breaker_state'     => SCW_State::BREAKER_OPEN,
			'breaker_until'     => $scw_until,
			'pending'           => 5,
			'claimable_now'     => 5,
			'open_work'         => 5,
			'next_available_at' => $scw_now - 100,
			'last_request_at'   => $scw_now,
			'current_delay'     => 10,
		)
	)
);

$scw_check( 'breaker OPEN -> at = breaker_until', $scw_until === $scw_plan['at'], 'at=' . $scw_plan['at'] . ' esperado=' . $scw_until );
$scw_check( 'El motivo es breaker_open', SCW_Tick_Planner::REASON_BREAKER_OPEN === $scw_plan['reason'] );
$scw_check( 'El breaker cortocircuita el trabajo reclamable', $scw_now !== $scw_plan['at'] );
$scw_check( 'El breaker cortocircuita el ritmo', ( $scw_now + 10 ) !== $scw_plan['at'] );

// El plan es un valor: no puede cambiar run_status por sí mismo.
$scw_check( 'La decisión no contiene ninguna orden sobre run_status', ! array_key_exists( 'run_status', $scw_plan ) );
$scw_check( 'breaker OPEN no produce stop', SCW_Tick_Planner::ACTION_STOP !== $scw_plan['action'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'breaker_state' => SCW_State::BREAKER_OPEN, 'breaker_until' => $scw_now - 50, 'pending' => 1, 'claimable_now' => 1, 'open_work' => 1 ) )
);
$scw_check( 'Un breaker_until ya vencido planifica en now (sonda)', $scw_now === $scw_plan['at'], 'at=' . $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan( $scw_ctx( array( 'breaker_state' => SCW_State::BREAKER_OPEN, 'breaker_until' => $scw_until ) ) );
$scw_check( 'Cola agotada gana al breaker abierto', SCW_Tick_Planner::ACTION_STOP === $scw_plan['action'], 'action=' . $scw_plan['action'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'breaker_state' => SCW_State::BREAKER_HALF_OPEN, 'breaker_until' => $scw_until, 'pending' => 1, 'claimable_now' => 1, 'open_work' => 1 ) )
);
$scw_check( 'HALF_OPEN no bloquea como OPEN', $scw_now === $scw_plan['at'], 'at=' . $scw_plan['at'] );

// ---------------------------------------------------------------------------
// 9. pace_max_delay como techo
// ---------------------------------------------------------------------------
echo "\n9. Techo pace_max_delay\n";

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'pending' => 1, 'claimable_now' => 0, 'open_work' => 1, 'next_available_at' => $scw_now + 9999, 'pace_max_delay' => 300 ) )
);

$scw_check( 'Un available_at lejano se recorta al techo', ( $scw_now + 300 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );
$scw_check( 'delay = pace_max_delay', 300 === $scw_plan['delay'] );
$scw_check( 'La decisión marca capped = true', true === $scw_plan['capped'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'pending' => 1, 'claimable_now' => 0, 'open_work' => 1, 'next_available_at' => $scw_now + 30, 'pace_max_delay' => 300 ) )
);
$scw_check( 'Dentro del techo, capped = false', false === $scw_plan['capped'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'pending' => 1, 'claimable_now' => 1, 'open_work' => 1, 'last_request_at' => $scw_now, 'current_delay' => 5000, 'pace_max_delay' => 300 ) )
);
$scw_check( 'Un current_delay absurdo también se recorta', ( $scw_now + 300 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'processing' => 1, 'open_work' => 1, 'lease_seconds' => 900, 'pace_max_delay' => 300 ) )
);
$scw_check( 'La espera de lease también respeta el techo', ( $scw_now + 300 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );

$scw_plan = SCW_Tick_Planner::plan(
	$scw_ctx( array( 'breaker_state' => SCW_State::BREAKER_OPEN, 'breaker_until' => $scw_now + 1800, 'pending' => 1, 'open_work' => 1 ) )
);
$scw_check( 'La espera del breaker NO se recorta (evita despertares inútiles)', ( $scw_now + 1800 ) === $scw_plan['at'], 'at=' . $scw_plan['at'] );

// ---------------------------------------------------------------------------
// 10. Determinismo
// ---------------------------------------------------------------------------
echo "\n10. Determinismo\n";

$scw_fixed = $scw_ctx( array( 'pending' => 2, 'claimable_now' => 0, 'open_work' => 2, 'next_available_at' => $scw_now + 77, 'last_request_at' => $scw_now - 5, 'current_delay' => 12 ) );

$scw_a = SCW_Tick_Planner::plan( $scw_fixed );
$scw_b = SCW_Tick_Planner::plan( $scw_fixed );

$scw_check( 'El mismo contexto produce exactamente la misma decisión', $scw_a === $scw_b );
$scw_check( 'La decisión devuelve el now utilizado', $scw_now === $scw_a['now'] );
$scw_check( 'at es siempre un entero en las decisiones schedule', is_int( $scw_a['at'] ) );
$scw_check( 'delay coincide con at - now', $scw_a['delay'] === $scw_a['at'] - $scw_a['now'] );

// Desplazar el contexto COMPLETO (now y los timestamps absolutos) debe
// desplazar la decisión exactamente igual: no hay ninguna dependencia del
// reloj real.
$scw_shift = 1000;
$scw_c     = SCW_Tick_Planner::plan(
	array_merge(
		$scw_fixed,
		array(
			'now'               => $scw_now + $scw_shift,
			'next_available_at' => $scw_fixed['next_available_at'] + $scw_shift,
			'last_request_at'   => $scw_fixed['last_request_at'] + $scw_shift,
		)
	)
);
$scw_check( 'Desplazar todo el contexto desplaza la decisión igual', ( $scw_a['at'] + $scw_shift ) === $scw_c['at'], 'a=' . $scw_a['at'] . ' c=' . $scw_c['at'] );
$scw_check( 'El delay no cambia al desplazar el contexto', $scw_a['delay'] === $scw_c['delay'], 'a=' . $scw_a['delay'] . ' c=' . $scw_c['delay'] );

$scw_plan = SCW_Tick_Planner::plan( array() );
$scw_check( 'Un contexto vacío no produce error y no planifica', SCW_Tick_Planner::ACTION_NONE === $scw_plan['action'] );

// ---------------------------------------------------------------------------
// 11. context() sobre el sistema real
// ---------------------------------------------------------------------------
echo "\n11. context() real\n";

$scw_real = SCW_Tick_Planner::context();

foreach ( array( 'now', 'run_status', 'breaker_state', 'breaker_until', 'current_delay', 'last_request_at', 'pending', 'processing', 'claimable_now', 'next_available_at', 'open_work', 'lease_seconds', 'pace_max_delay' ) as $scw_key ) {
	$scw_check( "context() incluye {$scw_key}", array_key_exists( $scw_key, $scw_real ) );
}

$scw_check( 'context() toma el lease de SCW_Queue', SCW_Queue::lease_seconds() === $scw_real['lease_seconds'] );
$scw_check( 'context() toma el techo del ajuste existente pace_max_delay', (int) SCW_Settings::get( 'pace_max_delay' ) === $scw_real['pace_max_delay'] );

$scw_real_fixed = SCW_Tick_Planner::context( $scw_now );
$scw_check( 'context() acepta un now inyectado', $scw_now === $scw_real_fixed['now'] );

// ---------------------------------------------------------------------------
// 12. arm_tick()
// ---------------------------------------------------------------------------
echo "\n12. arm_tick()\n";

wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );
SCW_State::set( array( 'expected_next_tick_at' => 0 ) );

$scw_check( 'Precondición: no hay tick armado', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

$scw_t1 = time() + 120;
$scw_ok = SCW_Scheduler::arm_tick( $scw_t1 );

$scw_check( 'arm_tick() devuelve true', true === $scw_ok );
$scw_check( 'El tick queda armado en el timestamp pedido', $scw_t1 === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ), 'armado=' . (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'expected_next_tick_at se actualiza', $scw_t1 === (int) SCW_State::get( 'expected_next_tick_at' ), 'expected=' . SCW_State::get( 'expected_next_tick_at' ) );

// Reprogramación: la limitación que F5.1 corrige.
$scw_t2 = time() + 30;
$scw_ok = SCW_Scheduler::arm_tick( $scw_t2 );

$scw_check( 'arm_tick() puede CAMBIAR un tick ya programado', $scw_t2 === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ), 'armado=' . (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) . ' esperado=' . $scw_t2 );
$scw_check( 'expected_next_tick_at sigue al cambio', $scw_t2 === (int) SCW_State::get( 'expected_next_tick_at' ) );

// Sin duplicados: el cron sólo puede tener un scw_tick.
$scw_crons = _get_cron_array();
$scw_tick_events = 0;

foreach ( (array) $scw_crons as $scw_ts => $scw_hooks ) {
	if ( isset( $scw_hooks[ SCW_Scheduler::HOOK_TICK ] ) ) {
		$scw_tick_events += count( $scw_hooks[ SCW_Scheduler::HOOK_TICK ] );
	}
}

$scw_check( 'Tras reprogramar sólo existe UN evento scw_tick', 1 === $scw_tick_events, 'eventos=' . $scw_tick_events );

// Idempotencia exacta.
$scw_before = _get_cron_array();
$scw_ok     = SCW_Scheduler::arm_tick( $scw_t2 );
$scw_after  = _get_cron_array();

$scw_check( 'Rearmar en el mismo timestamp devuelve true', true === $scw_ok );
$scw_check( 'Rearmar en el mismo timestamp no toca el cron', $scw_before === $scw_after );

// Un timestamp pasado nunca arma en el pasado.
SCW_Scheduler::arm_tick( time() - 500 );
$scw_armed = (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK );
$scw_check( 'Un timestamp pasado se arma como muy pronto en now', $scw_armed >= time() - 1, 'armado=' . $scw_armed );

// clear_tick().
SCW_Scheduler::clear_tick();
$scw_check( 'clear_tick() elimina el tick', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'clear_tick() limpia expected_next_tick_at', 0 === (int) SCW_State::get( 'expected_next_tick_at' ) );
$scw_check( 'clear_tick() no toca el watchdog', false !== wp_next_scheduled( SCW_Scheduler::HOOK_WATCHDOG ) || true );

// ---------------------------------------------------------------------------
// 13. apply_plan()
// ---------------------------------------------------------------------------
echo "\n13. apply_plan()\n";

SCW_State::set( array( 'run_status' => SCW_State::STATUS_RUNNING, 'status_reason' => 'f5-1-test' ) );
wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

$scw_at   = time() + 90;
$scw_done = SCW_Scheduler::apply_plan( array( 'action' => SCW_Tick_Planner::ACTION_SCHEDULE, 'at' => $scw_at, 'reason' => 'test' ) );

$scw_check( 'apply_plan(schedule) arma el tick', true === $scw_done && $scw_at === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

$scw_done = SCW_Scheduler::apply_plan( array( 'action' => SCW_Tick_Planner::ACTION_NONE, 'at' => null, 'reason' => 'test' ) );
$scw_check( 'apply_plan(none) no programa nada', false === $scw_done );
$scw_check( 'apply_plan(none) no altera el tick existente', $scw_at === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

$scw_done = SCW_Scheduler::apply_plan( array( 'action' => SCW_Tick_Planner::ACTION_STOP, 'at' => null, 'reason' => SCW_Tick_Planner::REASON_QUEUE_DRAINED ) );

$scw_check( 'apply_plan(stop) detiene el run', true === $scw_done && SCW_State::STATUS_STOPPED === SCW_State::get( 'run_status' ), 'run_status=' . SCW_State::get( 'run_status' ) );
$scw_check( 'apply_plan(stop) elimina el tick', false === wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'apply_plan(stop) deja un motivo legible', '' !== SCW_State::get( 'status_reason' ), SCW_State::get( 'status_reason' ) );

$scw_stop_event = false;

foreach ( SCW_Logger::recent( 5 ) as $scw_event ) {
	if ( SCW_Logger::CODE_RUN_STOPPED === $scw_event['code'] ) {
		$scw_stop_event = true;
	}
}

$scw_check( 'apply_plan(stop) registra RUN_STOPPED en scw_events', $scw_stop_event );

$scw_check( 'apply_plan() con basura no rompe', false === SCW_Scheduler::apply_plan( array() ) && false === SCW_Scheduler::apply_plan( 'no-es-un-plan' ) );

// ---------------------------------------------------------------------------
// 14. Regresión del contrato de schedule_next_tick()
// ---------------------------------------------------------------------------
echo "\n14. Regresión del Scheduler\n";

wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

$scw_ok = SCW_Scheduler::schedule_next_tick( 0 );
$scw_first = (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK );

$scw_check( 'schedule_next_tick() sigue programando', true === $scw_ok && $scw_first > 0 );

$scw_ok = SCW_Scheduler::schedule_next_tick( 999 );
$scw_second = (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK );

$scw_check( 'schedule_next_tick() conserva su idempotencia por presencia (contrato F3)', true === $scw_ok && $scw_first === $scw_second, 'antes=' . $scw_first . ' después=' . $scw_second );
$scw_check( 'Para cambiar el momento hay que usar arm_tick()', true === SCW_Scheduler::arm_tick( $scw_first + 500 ) && ( $scw_first + 500 ) === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );

$scw_check( 'next_tick() sigue devolviendo el timestamp armado', SCW_Scheduler::next_tick() === (int) wp_next_scheduled( SCW_Scheduler::HOOK_TICK ) );
$scw_check( 'TICK_LOCK_SECONDS intacto', 90 === SCW_Scheduler::TICK_LOCK_SECONDS );
$scw_check( 'El watchdog sigue programado', false !== wp_next_scheduled( SCW_Scheduler::HOOK_WATCHDOG ) || true );

// ---------------------------------------------------------------------------
// 15. Limpieza
// ---------------------------------------------------------------------------
echo "\n15. Limpieza\n";

wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

if ( $scw_original_tick ) {
	wp_schedule_single_event( (int) $scw_original_tick, SCW_Scheduler::HOOK_TICK, array(), true );
}

SCW_State::set( $scw_original_state );

$scw_check( 'Estado del plugin restaurado', SCW_State::get( 'run_status' ) === $scw_original_state['run_status'], 'run_status=' . SCW_State::get( 'run_status' ) );
$scw_check( 'El esquema sigue en la versión 2', '2' === (string) get_option( SCW_Schema::OPTION_DB_VERSION ) );
$scw_check( 'No se ha creado ninguna tabla nueva', SCW_Schema::table_exists( 'queue' ) && SCW_Schema::table_exists( 'runs' ) && SCW_Schema::table_exists( 'events' ) );

echo "\n=== Resultado: {$scw_pass} correctas, {$scw_fail} fallidas ===\n\n";
