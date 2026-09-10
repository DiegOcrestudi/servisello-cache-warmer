<?php
/**
 * Activación del plugin.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Activator {

	/**
	 * Crea tablas y opciones, programa el watchdog y deja el crawler en STOPPED.
	 *
	 * No arranca ningún calentamiento ni programa ningún tick.
	 *
	 * @return void
	 */
	public static function activate() {
		SCW_Schema::install();
		SCW_Settings::install();
		SCW_State::install();

		// La activación nunca deja el crawler en marcha.
		SCW_State::set(
			array(
				'run_status'    => SCW_State::STATUS_STOPPED,
				'status_reason' => 'Plugin activado. Fase F1: sin worker.',
			)
		);

		SCW_Scheduler::schedule_watchdog();

		SCW_Logger::info(
			SCW_Logger::CODE_PLUGIN_ACTIVATED,
			'Plugin activado.',
			array(
				'version'    => SCW_VERSION,
				'db_version' => SCW_Schema::DB_VERSION,
				'tables'     => array(
					'queue'  => SCW_Schema::table_exists( 'queue' ),
					'runs'   => SCW_Schema::table_exists( 'runs' ),
					'events' => SCW_Schema::table_exists( 'events' ),
				),
			)
		);
	}
}
