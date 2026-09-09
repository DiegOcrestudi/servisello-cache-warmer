<?php
/**
 * Desinstalación.
 *
 * Por seguridad, las tablas y los ajustes SÓLO se borran si el ajuste
 * delete_data_on_uninstall está activo (por defecto está desactivado). Así,
 * desinstalar el plugin durante el desarrollo no destruye la cola ni el
 * historial de diagnóstico.
 *
 * El estado de ejecución y los eventos de cron sí se limpian siempre.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-scw-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scw-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scw-state.php';

wp_clear_scheduled_hook( 'scw_tick' );
wp_clear_scheduled_hook( 'scw_watchdog' );
delete_transient( 'scw_tick_lock' );

$scw_settings = get_option( SCW_Settings::OPTION, array() );
$scw_purge    = is_array( $scw_settings ) && ! empty( $scw_settings['delete_data_on_uninstall'] );

if ( $scw_purge ) {
	SCW_Schema::drop_all();
	SCW_Settings::delete();
	SCW_State::delete();
	delete_option( SCW_Schema::OPTION_DB_VERSION );
} else {
	// Sin borrado de datos: al menos se deja el estado en reposo.
	SCW_State::delete();
}
