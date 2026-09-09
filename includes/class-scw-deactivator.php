<?php
/**
 * Desactivación del plugin.
 *
 * Deja el sitio limpio de eventos cron y el estado en reposo, pero conserva
 * tablas y ajustes. El borrado de datos sólo ocurre en uninstall.php y sólo si
 * el ajuste delete_data_on_uninstall está activo.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Deactivator {

	/**
	 * Limpia cron, libera leases y detiene el estado.
	 *
	 * @return void
	 */
	public static function deactivate() {
		SCW_Scheduler::unschedule_all();

		$released = self::release_leases();

		SCW_State::reset( 'Plugin desactivado.' );

		SCW_Logger::info(
			SCW_Logger::CODE_PLUGIN_DEACTIVATED,
			'Plugin desactivado. Eventos de cron eliminados.',
			array( 'leases_released' => $released )
		);
	}

	/**
	 * Devuelve a pending cualquier elemento que se quedara en processing.
	 *
	 * En F1 la cola siempre está vacía, pero el método ya existe para que la
	 * desactivación sea segura en cuanto F2 empiece a encolar.
	 *
	 * @return int Filas afectadas.
	 */
	private static function release_leases() {
		global $wpdb;

		if ( ! SCW_Schema::table_exists( 'queue' ) ) {
			return 0;
		}

		$table = SCW_Schema::table( 'queue' );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'pending', lease_owner = NULL, lease_expires_at = NULL, updated_at = %s
				 WHERE status = 'processing'",
				current_time( 'mysql', true )
			)
		);
	}
}
