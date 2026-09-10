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
	 * Desde F2.1 la sentencia vive en SCW_Queue, que es la propietaria de la
	 * tabla. El comportamiento observable es idéntico al de F1.
	 *
	 * @return int Filas afectadas.
	 */
	private static function release_leases() {
		return SCW_Queue::release_all_locks();
	}
}
