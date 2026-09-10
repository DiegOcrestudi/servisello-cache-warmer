<?php
/**
 * Ajustes del plugin.
 *
 * Todo vive en una única opción con autoload = no. En F1 no hay pantalla de
 * edición todavía: se definen los valores por defecto acordados en la
 * arquitectura para que las fases siguientes (F2+) los consuman sin cambiar
 * el modelo de datos.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Settings {

	const OPTION = 'scw_settings';

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(

			// --- Origen de URLs ---------------------------------------------
			// Los 9 sitemaps hijos. El sitemap índice NO se procesa, por decisión
			// cerrada, para no duplicar fuentes.
			'sitemaps'                 => array(
				'https://www.servisello.es/post-sitemap.xml',
				'https://www.servisello.es/page-sitemap.xml',
				'https://www.servisello.es/product-sitemap1.xml',
				'https://www.servisello.es/product-sitemap2.xml',
				'https://www.servisello.es/product-sitemap3.xml',
				'https://www.servisello.es/product-sitemap4.xml',
				'https://www.servisello.es/product-sitemap5.xml',
				'https://www.servisello.es/category-sitemap.xml',
				'https://www.servisello.es/product_cat-sitemap.xml',
			),
			'extra_urls'               => array(
				'https://www.servisello.es/',
			),

			// --- Normalización ----------------------------------------------
			'allowed_host'             => 'www.servisello.es',
			'force_scheme'             => 'https',
			'trailing_slash'           => 'add', // add|remove|keep.
			'ignored_params'           => array(
				'yith_wcan',
				'filter_*',
				'utm_source',
				'utm_medium',
				'utm_campaign',
				'utm_term',
				'utm_content',
				'fbclid',
				'gclid',
				'msclkid',
				'_gl',
			),
			'allowed_params'           => array(), // Lista de rescate: lo aquí listado se conserva aunque encaje en ignored_params. No es una lista blanca: los parámetros desconocidos se conservan igualmente.

			// --- Exclusiones -------------------------------------------------
			'exclude_exact'            => array(),
			'exclude_prefix'           => array(
				'/carrito/',
				'/finalizar-compra/',
				'/mi-cuenta/',
				'/wp-admin/',
				'/wp-login.php',
			),
			'exclude_regex'            => array(),

			// --- Prioridades --------------------------------------------------
			'priorities'               => array(
				'product_cat' => 100,
				'product'     => 80,
				'page'        => 60,
				'post'        => 40,
				'home'        => 90,
				'unknown'     => 50,
			),

			// --- HTTP ----------------------------------------------------------
			'http_timeout'             => 30,
			'http_max_retries'         => 2,
			'http_retry_delays'        => array( 30, 90 ),
			'http_max_redirects'       => 2,
			'user_agent'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 ServiselloCacheWarmer/1.0',

			// --- Ritmo adaptativo (pacer) ---------------------------------------
			'pace_base_delay'          => 5,
			'pace_min_delay'           => 3,
			'pace_max_delay'           => 300,
			'pace_duty_factor'         => 2, // delay >= duración * factor.
			'pace_band_multipliers'    => array(
				'under_2s' => 1,
				'2_4s'     => 1.5,
				'4_6s'     => 3,
				'6_10s'    => 6,
			),
			'pace_delay_over_10s'      => 120,
			'pace_delay_timeout'       => 300,
			'pace_delay_429'           => 90,
			'pace_delay_503'           => 120,
			'pace_delay_5xx'           => 60,

			// --- Circuit breaker -------------------------------------------------
			'breaker_consecutive_errors' => 3,
			'breaker_429_in_window'      => 2,
			'breaker_429_window'         => 600,
			'breaker_slow10_consecutive' => 3,
			'breaker_slow6_consecutive'  => 5,
			'breaker_window_size'        => 20,
			'breaker_window_failure_pct' => 40,
			'breaker_cooldown'           => 300,
			'breaker_max_cooldown'       => 1800,
			'breaker_max_openings'       => 5,
			'breaker_canary_url'         => 'https://www.servisello.es/',
			'breaker_probe_max_seconds'  => 6,

			// --- Validación --------------------------------------------------------
			'yith_presets'             => array( 'preset_6432', 'preset_6683', 'preset_13269' ),
			'min_html_bytes'           => 20000,
			'require_closing_tags'     => true,
			'profiles_requires_yith'   => array(
				'/categoria-producto/design-stamp/exlibris/exlibris-anime/',
				'/categoria-producto/design-stamp/exlibris/exlibris-batidogs/',
				'/categoria-producto/design-stamp/exlibris/exlibris-disenos/',
				'/categoria-producto/design-stamp/exlibris/exlibris-portadas-libros/',
				'/categoria-producto/design-stamp/exlibris/exlibris-sushicats/',
				'/categoria-producto/design-stamp/exlibris/exlibris-estandar/',
				'/categoria-producto/design-stamp/eventos/sellos-aniversario/',
				'/categoria-producto/design-stamp/eventos/sellos-bautizos/',
				'/categoria-producto/design-stamp/eventos/sello-bodas/',
				'/categoria-producto/design-stamp/eventos/sellos-comunion/',
				'/categoria-producto/design-stamp/eventos/sello-fiesta/',
				'/categoria-producto/design-stamp/eventos/eventos-estandar/',
				'/categoria-producto/accesorios-para-sellos/tampones/',
			),
			'profiles_no_yith'         => array(
				'/categoria-producto/design-stamp/exlibris/',
				'/categoria-producto/design-stamp/eventos/',
			),

			// --- Retención y limpieza -------------------------------------------------
			'retention_events_days'    => 30,
			'retention_runs_days'      => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Todos los ajustes, mezclados con los valores por defecto.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Un ajuste concreto.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor si no existe.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Actualiza un subconjunto de ajustes conservando autoload = no.
	 *
	 * @param array $values Pares clave/valor.
	 * @return bool
	 */
	public static function update( array $values ) {
		$current = self::all();
		$merged  = array_merge( $current, $values );

		return update_option( self::OPTION, $merged, false );
	}

	/**
	 * Crea la opción en la activación si no existe, siempre con autoload = no.
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Elimina la opción. Sólo desde uninstall.php.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}
}
