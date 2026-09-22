<?php
/**
 * Plugin Name:       Servisello Cache Warmer
 * Plugin URI:        https://www.servisello.es/
 * Description:       Crawler conservador de calentamiento y validación de caché para Servisello.es. Fase F4: además del cliente HTTP, el worker de una URL por tick y la cola/leases reales de F3, valida la integridad del HTML recibido, resuelve la expectativa de contenido de cada URL y comprueba la evidencia del filtro YITH, clasificando cada respuesta como success, suspicious o failed. Todavía no aplica pacing adaptativo ni circuit breaker (F5).
 * Version:           1.6.0-f4
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

define( 'SCW_VERSION', '1.6.0-f4' );
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
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-runs.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-url-normalizer.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-url-exclusions.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-sitemap-parser.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-sitemap-sources.php';
require_once SCW_PLUGIN_DIR . 'includes/crawler/class-scw-sitemap-pipeline.php';
require_once SCW_PLUGIN_DIR . 'includes/http/class-scw-http-client.php';
require_once SCW_PLUGIN_DIR . 'includes/validation/class-scw-html-validator.php';
require_once SCW_PLUGIN_DIR . 'includes/validation/class-scw-page-profile.php';
require_once SCW_PLUGIN_DIR . 'includes/validation/class-scw-yith-validator.php';
require_once SCW_PLUGIN_DIR . 'includes/validation/class-scw-content-validator.php';
require_once SCW_PLUGIN_DIR . 'includes/runner/class-scw-retry-policy.php';
require_once SCW_PLUGIN_DIR . 'includes/runner/class-scw-pacer.php';
require_once SCW_PLUGIN_DIR . 'includes/runner/class-scw-worker.php';
require_once SCW_PLUGIN_DIR . 'includes/runner/class-scw-tick-planner.php';
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
