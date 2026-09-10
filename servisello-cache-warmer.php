<?php
/**
 * Plugin Name:       Servisello Cache Warmer
 * Plugin URI:        https://www.servisello.es/
 * Description:       Crawler conservador de calentamiento y validación de caché para Servisello.es. Fase F2.1: base de F1 más la cola persistente. No realiza todavía ninguna petición de calentamiento.
 * Version:           1.1.0-f2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Servisello
 * License:           GPL-2.0-or-later
 * Text Domain:       servisello-cache-warmer
 * Domain Path:       /languages
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

define( 'SCW_VERSION', '1.1.0-f2.1' );
define( 'SCW_PLUGIN_FILE', __FILE__ );
define( 'SCW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Capability requerida para toda la administración del plugin.
 * Decisión cerrada en la revisión de arquitectura: manage_options.
 */
if ( ! defined( 'SCW_CAPABILITY' ) ) {
	define( 'SCW_CAPABILITY', 'manage_options' );
}

require_once SCW_PLUGIN_DIR . 'includes/class-scw-schema.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-settings.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-state.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-logger.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-queue.php';
require_once SCW_PLUGIN_DIR . 'includes/runner/class-scw-scheduler.php';
require_once SCW_PLUGIN_DIR . 'includes/admin/class-scw-admin.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-activator.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-deactivator.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-plugin.php';

register_activation_hook( __FILE__, array( 'SCW_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCW_Deactivator', 'deactivate' ) );

/**
 * Los hooks se registran en el momento de cargar el fichero, no en plugins_loaded,
 * para que el filtro cron_schedules ya exista cuando WordPress ejecuta el hook de
 * activación (en ese momento plugins_loaded ya ha pasado).
 */
SCW_Plugin::instance()->run();
