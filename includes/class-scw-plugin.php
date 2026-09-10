<?php
/**
 * Contenedor del plugin: instancia los componentes y registra sus hooks.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Plugin {

	/** @var SCW_Plugin|null */
	private static $instance = null;

	/** @var SCW_Scheduler */
	private $scheduler;

	/** @var SCW_Admin */
	private $admin;

	/**
	 * Instancia única.
	 *
	 * @return SCW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado.
	 */
	private function __construct() {
		$this->scheduler = new SCW_Scheduler();
		$this->admin     = new SCW_Admin();
	}

	/**
	 * Registra todos los hooks.
	 *
	 * @return void
	 */
	public function run() {
		$this->scheduler->register_hooks();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_schema' ) );

		if ( is_admin() ) {
			$this->admin->register_hooks();
		}
	}

	/**
	 * Carga las traducciones.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'servisello-cache-warmer', false, dirname( SCW_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Aplica migraciones de esquema tras una actualización del plugin.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		if ( ! current_user_can( SCW_CAPABILITY ) ) {
			return;
		}

		if ( SCW_Schema::maybe_upgrade() ) {
			SCW_Logger::info(
				SCW_Logger::CODE_SCHEMA_UPGRADED,
				'Esquema de base de datos actualizado.',
				array( 'db_version' => SCW_Schema::DB_VERSION )
			);
		}
	}

	/**
	 * Acceso al scheduler.
	 *
	 * @return SCW_Scheduler
	 */
	public function scheduler() {
		return $this->scheduler;
	}
}
