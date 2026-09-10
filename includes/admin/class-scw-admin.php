<?php
/**
 * Administración.
 *
 * F1: una única página de diagnóstico. No hay controles de arranque todavía,
 * a propósito: no existe worker que arrancar.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Admin {

	const MENU_SLUG   = 'servisello-cache-warmer';
	const ACTION_ENV  = 'scw_env_check';
	const NONCE_ENV   = 'scw_env_check_nonce';

	/**
	 * Registra los hooks de administración.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_ENV, array( $this, 'handle_env_check' ) );
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
	 * Página de diagnóstico.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'servisello-cache-warmer' ), 403 );
		}

		$state    = SCW_State::all();
		$settings = SCW_Settings::all();
		$notice   = isset( $_GET['scw_env'] ) ? sanitize_key( wp_unslash( $_GET['scw_env'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>Servisello Cache Warmer <span style="font-size:13px;color:#666;">' . esc_html( SCW_VERSION ) . '</span></h1>';

		if ( 'ok' === $notice ) {
			echo '<div class="notice notice-success"><p>Loopback a wp-cron.php correcto. WP-Cron nativo puede dispararse.</p></div>';
		} elseif ( 'warning' === $notice || 'error' === $notice ) {
			echo '<div class="notice notice-error"><p>El loopback a wp-cron.php ha fallado. Revisa el registro de eventos: sin loopback, WP-Cron nativo no se disparará de forma fiable.</p></div>';
		}

		echo '<div class="notice notice-info inline"><p><strong>Fase F1.</strong> El plugin todavía no realiza ninguna petición de calentamiento. Esta página sólo verifica base de datos, ajustes, estado y planificador.</p></div>';

		$this->render_tables_panel();
		$this->render_scheduler_panel( $state );
		$this->render_state_panel( $state );
		$this->render_settings_panel( $settings );
		$this->render_events_panel();

		echo '</div>';
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
			'Próximo tick'      => SCW_Scheduler::next_tick() ? $this->format_ts( SCW_Scheduler::next_tick() ) : 'No programado (correcto en F1)',
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
		echo '<h2>Estado de ejecución</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th style="width:220px">run_status</th><td><code>' . esc_html( $state['run_status'] ) . '</code></td></tr>';
		echo '<tr><th>Motivo</th><td>' . esc_html( $state['status_reason'] ? $state['status_reason'] : '—' ) . '</td></tr>';
		echo '<tr><th>Circuit breaker</th><td><code>' . esc_html( $state['breaker_state'] ) . '</code></td></tr>';
		echo '<tr><th>Último tick</th><td>' . esc_html( $this->format_ts( (int) $state['last_tick_at'] ) ) . '</td></tr>';
		echo '</tbody></table>';
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
		echo '<p class="description">La pantalla de edición de ajustes llega en F2/F6. En F1 los valores por defecto ya están persistidos en <code>' . esc_html( SCW_Settings::OPTION ) . '</code> con autoload desactivado.</p>';
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
