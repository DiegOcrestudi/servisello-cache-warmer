<?php
/**
 * Administración.
 *
 * Página única de diagnóstico. Desde F3 incluye, además, el mínimo control de
 * arranque/parada imprescindible para poder ejecutar TEST1 de forma
 * controlada (arrancar el crawler, dejar que el worker procese, pararlo). No
 * es un dashboard: no hay pantalla de ajustes, ni AJAX, ni listado de runs.
 * Eso pertenece a F6.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Admin {

	const MENU_SLUG    = 'servisello-cache-warmer';
	const ACTION_ENV   = 'scw_env_check';
	const NONCE_ENV    = 'scw_env_check_nonce';
	const ACTION_START = 'scw_run_start';
	const NONCE_START  = 'scw_run_start_nonce';
	const ACTION_STOP  = 'scw_run_stop';
	const NONCE_STOP   = 'scw_run_stop_nonce';

	/**
	 * Registra los hooks de administración.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_ENV, array( $this, 'handle_env_check' ) );
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_STOP, array( $this, 'handle_stop' ) );
	}

	/**
	 * Menú de primer nivel.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			'Servisello Cache Warmer',
			'Cache Warmer',
			SCW_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-update',
			76
		);
	}

	/**
	 * Comprobación de entorno bajo demanda.
	 *
	 * Hace una única petición de loopback a wp-cron.php para verificar que
	 * WP-Cron nativo puede dispararse. No calienta ninguna URL del sitio.
	 *
	 * @return void
	 */
	public function handle_env_check() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'servisello-cache-warmer' ), 403 );
		}

		check_admin_referer( self::NONCE_ENV );

		$url = add_query_arg( 'doing_wp_cron', sprintf( '%.22F', microtime( true ) ), site_url( 'wp-cron.php' ) );

		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 10,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'X-SCW-Check' => '1' ),
			)
		);
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			SCW_Logger::error(
				SCW_Logger::CODE_LOOPBACK_BLOCKED,
				'El loopback a wp-cron.php ha fallado: ' . $response->get_error_message(),
				array(
					'duration_ms' => $duration,
					'error_code'  => $response->get_error_code(),
				)
			);
			$result = 'error';
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$ok     = $code >= 200 && $code < 400;
			$result = $ok ? 'ok' : 'warning';

			SCW_Logger::log(
				$ok ? SCW_Logger::CODE_LOOPBACK_OK : SCW_Logger::CODE_LOOPBACK_BLOCKED,
				sprintf( 'Loopback a wp-cron.php: HTTP %d en %d ms.', $code, $duration ),
				array(
					'http_status' => $code,
					'duration_ms' => $duration,
				),
				$ok ? SCW_Logger::LEVEL_INFO : SCW_Logger::LEVEL_WARNING
			);
		}

		SCW_Logger::info(
			SCW_Logger::CODE_ENV_CHECK,
			'Comprobación de entorno ejecutada.',
			array(
				'disable_wp_cron'   => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
				'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) ? (bool) ALTERNATE_WP_CRON : false,
				'next_watchdog'     => SCW_Scheduler::next_watchdog(),
				'next_tick'         => SCW_Scheduler::next_tick(),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'scw_env' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Arranca el crawler de forma manual.
	 *
	 * Mecanismo mínimo para poder ejecutar TEST1 de forma controlada: pone
	 * run_status en RUNNING, abre una sesión nueva y programa el primer
	 * scw_tick si no había ya uno pendiente. No es un sistema de
	 * administración nuevo: reutiliza SCW_State y SCW_Scheduler tal como
	 * existen.
	 *
	 * @return void
	 */
	public function handle_start() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'servisello-cache-warmer' ), 403 );
		}

		check_admin_referer( self::NONCE_START );

		if ( ! SCW_State::is_running() ) {
			$session_id = wp_generate_password( 32, false, false );

			SCW_State::set(
				array(
					'run_status'         => SCW_State::STATUS_RUNNING,
					'status_reason'      => 'Arrancado manualmente desde el panel de administración.',
					'session_id'         => $session_id,
					'session_started_at' => time(),
				)
			);

			SCW_Logger::info(
				SCW_Logger::CODE_RUN_STARTED,
				'Crawler arrancado manualmente desde la administración.',
				array( 'session_id' => $session_id )
			);

			SCW_Scheduler::schedule_next_tick( 0 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'scw_run' => 'started',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Detiene el crawler de forma manual.
	 *
	 * Pone run_status en STOPPED y elimina el scw_tick pendiente, de modo que
	 * la cadena de ticks no continúe. No fuerza la liberación de una fila que
	 * en ese instante estuviera en processing: si la hay, terminará con
	 * normalidad o quedará disponible de nuevo cuando caduque su lease, igual
	 * que ya hace SCW_Queue::release_expired_locks().
	 *
	 * @return void
	 */
	public function handle_stop() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'servisello-cache-warmer' ), 403 );
		}

		check_admin_referer( self::NONCE_STOP );

		wp_clear_scheduled_hook( SCW_Scheduler::HOOK_TICK );

		SCW_State::set(
			array(
				'run_status'    => SCW_State::STATUS_STOPPED,
				'status_reason' => 'Detenido manualmente desde el panel de administración.',
			)
		);

		SCW_Logger::info( SCW_Logger::CODE_RUN_STOPPED, 'Crawler detenido manualmente desde la administración.' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'scw_run' => 'stopped',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Página de diagnóstico.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'servisello-cache-warmer' ), 403 );
		}

		$state       = SCW_State::all();
		$settings    = SCW_Settings::all();
		$notice      = isset( $_GET['scw_env'] ) ? sanitize_key( wp_unslash( $_GET['scw_env'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_notice  = isset( $_GET['scw_run'] ) ? sanitize_key( wp_unslash( $_GET['scw_run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>Servisello Cache Warmer <span style="font-size:13px;color:#666;">' . esc_html( SCW_VERSION ) . '</span></h1>';

		if ( 'ok' === $notice ) {
			echo '<div class="notice notice-success"><p>Loopback a wp-cron.php correcto. WP-Cron nativo puede dispararse.</p></div>';
		} elseif ( 'warning' === $notice || 'error' === $notice ) {
			echo '<div class="notice notice-error"><p>El loopback a wp-cron.php ha fallado. Revisa el registro de eventos: sin loopback, WP-Cron nativo no se disparará de forma fiable.</p></div>';
		}

		if ( 'started' === $run_notice ) {
			echo '<div class="notice notice-success"><p>Crawler arrancado. El primer scw_tick queda programado.</p></div>';
		} elseif ( 'stopped' === $run_notice ) {
			echo '<div class="notice notice-success"><p>Crawler detenido. No se programará ningún scw_tick nuevo.</p></div>';
		}

		echo '<div class="notice notice-info inline"><p><strong>Fase F3.</strong> El worker realiza como máximo una petición de calentamiento por ejecución de scw_tick y registra el resultado en scw_runs. Todavía no valida el contenido HTML ni YITH (F4), y no aplica ritmo adaptativo ni circuit breaker (F5).</p></div>';

		$this->render_tables_panel();
		$this->render_queue_panel();
		$this->render_scheduler_panel( $state );
		$this->render_state_panel( $state );
		$this->render_settings_panel( $settings );
		$this->render_events_panel();

		echo '</div>';
	}

	/**
	 * Panel de estado de la cola.
	 *
	 * Reutiliza SCW_Queue::stats(), sin ninguna consulta propia. Pensado para
	 * seguir visualmente el progreso durante TEST1.
	 *
	 * @return void
	 */
	private function render_queue_panel() {
		$stats = SCW_Queue::stats();

		echo '<h2>Cola de calentamiento</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th style="width:220px">Total</th><td>' . esc_html( number_format_i18n( $stats['total'] ) ) . '</td></tr>';

		foreach ( SCW_Queue::statuses() as $status ) {
			echo '<tr><th>' . esc_html( $status ) . '</th><td>' . esc_html( number_format_i18n( $stats[ $status ] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Panel de tablas.
	 *
	 * @return void
	 */
	private function render_tables_panel() {
		echo '<h2>Base de datos</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Tabla</th><th>Existe</th><th>Filas</th></tr></thead><tbody>';

		foreach ( SCW_Schema::table_names() as $name ) {
			$exists = SCW_Schema::table_exists( $name );
			$count  = $exists ? SCW_Schema::row_count( $name ) : null;

			echo '<tr>';
			echo '<td><code>' . esc_html( SCW_Schema::table( $name ) ) . '</code></td>';
			echo '<td>' . ( $exists ? 'Sí' : '<strong style="color:#b32d2e">No</strong>' ) . '</td>';
			echo '<td>' . esc_html( null === $count ? '—' : number_format_i18n( $count ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">Versión de esquema instalada: <code>' . esc_html( (string) get_option( SCW_Schema::OPTION_DB_VERSION, '—' ) ) . '</code> · esperada: <code>' . esc_html( SCW_Schema::DB_VERSION ) . '</code></p>';
	}

	/**
	 * Panel del planificador.
	 *
	 * @param array $state Estado.
	 * @return void
	 */
	private function render_scheduler_panel( $state ) {
		$rows = array(
			'DISABLE_WP_CRON'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Definida y activa' : 'No definida',
			'ALTERNATE_WP_CRON' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ? 'Definida y activa' : 'No definida',
			'Próximo watchdog'  => $this->format_ts( SCW_Scheduler::next_watchdog() ),
			'Último watchdog'   => $this->format_ts( (int) $state['last_watchdog_at'] ),
			'Próximo tick'      => SCW_Scheduler::next_tick() ? $this->format_ts( SCW_Scheduler::next_tick() ) : 'No programado',
		);

		echo '<h2>Planificador</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ENV ) . '">';
		wp_nonce_field( self::NONCE_ENV );
		submit_button( 'Comprobar entorno (loopback wp-cron)', 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">El watchdog se ejecuta cada 5 minutos y sólo escribe un latido. Si «Último watchdog» avanza solo, WP-Cron nativo funciona.</p>';
	}

	/**
	 * Panel de estado de ejecución.
	 *
	 * @param array $state Estado.
	 * @return void
	 */
	private function render_state_panel( $state ) {
		$is_running = SCW_State::STATUS_RUNNING === $state['run_status'];

		echo '<h2>Estado de ejecución</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th style="width:220px">run_status</th><td><code>' . esc_html( $state['run_status'] ) . '</code></td></tr>';
		echo '<tr><th>Motivo</th><td>' . esc_html( $state['status_reason'] ? $state['status_reason'] : '—' ) . '</td></tr>';
		echo '<tr><th>Sesión</th><td><code>' . esc_html( $state['session_id'] ? $state['session_id'] : '—' ) . '</code></td></tr>';
		echo '<tr><th>Circuit breaker</th><td><code>' . esc_html( $state['breaker_state'] ) . '</code></td></tr>';
		echo '<tr><th>Último tick</th><td>' . esc_html( $this->format_ts( (int) $state['last_tick_at'] ) ) . '</td></tr>';
		echo '<tr><th>Próximo tick</th><td>' . ( SCW_Scheduler::next_tick() ? esc_html( $this->format_ts( SCW_Scheduler::next_tick() ) ) : 'No programado' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_START ) . '">';
		wp_nonce_field( self::NONCE_START );
		submit_button( 'Arrancar', 'primary', 'submit', false, $is_running ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;display:inline-block">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_STOP ) . '">';
		wp_nonce_field( self::NONCE_STOP );
		submit_button( 'Detener', 'secondary', 'submit', false, $is_running ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';
		echo '<p class="description">Arrancar pone el crawler en RUNNING y programa el primer scw_tick. Cada tick procesa como máximo una URL y encadena el siguiente tick mientras siga RUNNING. Detener corta la cadena; una URL que ya estuviera en processing no se fuerza, se recupera sola cuando caduque su lease.</p>';
	}

	/**
	 * Resumen de ajustes cargados.
	 *
	 * @param array $settings Ajustes.
	 * @return void
	 */
	private function render_settings_panel( $settings ) {
		echo '<h2>Ajustes cargados</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th style="width:220px">Sitemaps configurados</th><td>' . esc_html( (string) count( $settings['sitemaps'] ) ) . '</td></tr>';
		echo '<tr><th>Host permitido</th><td><code>' . esc_html( $settings['allowed_host'] ) . '</code></td></tr>';
		echo '<tr><th>Presets YITH válidos</th><td><code>' . esc_html( implode( ', ', $settings['yith_presets'] ) ) . '</code></td></tr>';
		echo '<tr><th>Timeout HTTP</th><td>' . esc_html( (string) $settings['http_timeout'] ) . ' s</td></tr>';
		echo '<tr><th>Canary del breaker</th><td><code>' . esc_html( $settings['breaker_canary_url'] ) . '</code></td></tr>';
		echo '<tr><th>Capability</th><td><code>' . esc_html( SCW_CAPABILITY ) . '</code></td></tr>';
		echo '<tr><th>Borrar datos al desinstalar</th><td>' . ( $settings['delete_data_on_uninstall'] ? 'Sí' : 'No' ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">La pantalla de edición de ajustes llega en F6. Mientras tanto, los valores por defecto están persistidos en <code>' . esc_html( SCW_Settings::OPTION ) . '</code> con autoload desactivado.</p>';
	}

	/**
	 * Últimos eventos.
	 *
	 * @return void
	 */
	private function render_events_panel() {
		$events = SCW_Logger::recent( 20 );

		echo '<h2>Últimos eventos</h2>';

		if ( empty( $events ) ) {
			echo '<p>No hay eventos registrados.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th style="width:160px">Fecha (UTC)</th><th style="width:90px">Nivel</th><th style="width:180px">Código</th><th>Mensaje</th></tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( $event['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $event['level'] ) . '</td>';
			echo '<td><code>' . esc_html( $event['code'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $event['message'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Formatea un timestamp UTC en la zona horaria del sitio.
	 *
	 * @param int $timestamp Epoch.
	 * @return string
	 */
	private function format_ts( $timestamp ) {
		$timestamp = (int) $timestamp;

		if ( $timestamp <= 0 ) {
			return 'Nunca';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp ) . ' (' . human_time_diff( $timestamp, time() ) . ')';
	}
}
