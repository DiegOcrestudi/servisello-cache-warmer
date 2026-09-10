<?php
/**
 * Fuentes de sitemap.
 *
 * Resuelve, para cada sitemap configurado, qué tipo de contenido trae y con qué
 * prioridad debe encolarse. Es la pieza que mantiene esa asociación en un solo
 * sitio en lugar de repartirla por el pipeline.
 *
 * No descarga nada ni parsea nada.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Sitemap_Sources {

	/** Tipos que el proyecto reconoce hoy. */
	const KNOWN_TYPES = array( 'post', 'page', 'product', 'category', 'product_cat' );

	/** Tipo cuando no se puede deducir del nombre del sitemap. */
	const TYPE_UNKNOWN = 'unknown';

	/**
	 * Prioridad de las categorías del blog.
	 *
	 * Los ajustes de F1 definen product_cat=100, product=80, page=60, post=40,
	 * home=90 y unknown=50, pero no dicen nada de "category", que es el archivo
	 * de categorías del blog y no tiene nada que ver con las categorías de
	 * WooCommerce.
	 *
	 * Decisión, tomada de forma explícita y no silenciosa: 50. Por encima de un
	 * post suelto (40), porque un archivo cubre muchas entradas y calentarlo
	 * rinde más; por debajo de una página (60), porque en este sitio el blog no
	 * es el contenido principal. Se puede cambiar añadiendo la clave 'category'
	 * al ajuste priorities, sin tocar código.
	 */
	const DEFAULT_CATEGORY_PRIORITY = 50;

	/**
	 * Lista de sitemaps configurados, con su tipo y prioridad resueltos.
	 *
	 * @param array $overrides sitemaps, sitemap_types, priorities.
	 * @return array Lista de arrays con url, type y priority.
	 */
	public static function all( $overrides = array() ) {
		$config  = self::config( $overrides );
		$sources = array();

		foreach ( (array) $config['sitemaps'] as $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			$type = self::type_for_url( $url, $config );

			$sources[] = array(
				'url'      => $url,
				'type'     => $type,
				'priority' => self::priority_for_type( $type, $config ),
			);
		}

		return $sources;
	}

	/**
	 * Deduce el tipo de contenido a partir del nombre del sitemap.
	 *
	 * Se apoya en la convención de nombres que ya usa el sitio:
	 *
	 *   post-sitemap.xml        -> post
	 *   page-sitemap.xml        -> page
	 *   product-sitemap3.xml    -> product
	 *   category-sitemap.xml    -> category
	 *   product_cat-sitemap.xml -> product_cat
	 *
	 * Un mapa explícito en el ajuste sitemap_types, indexado por URL de sitemap,
	 * tiene prioridad sobre la deducción. Ese ajuste no existe todavía: si no
	 * está, se usa un array vacío y no se crea ninguna opción nueva.
	 *
	 * @param string $url    URL del sitemap.
	 * @param array  $config Configuración efectiva.
	 * @return string
	 */
	public static function type_for_url( $url, $config = array() ) {
		$config = isset( $config['sitemap_types'] ) ? $config : self::config( $config );

		$url = trim( (string) $url );

		if ( isset( $config['sitemap_types'][ $url ] ) ) {
			$explicit = self::sanitize_type( $config['sitemap_types'][ $url ] );

			if ( '' !== $explicit ) {
				return $explicit;
			}
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $path );

		// Se quita la extensión y el sufijo -sitemap con su posible numeración.
		$filename = preg_replace( '/\.xml(\.gz)?$/i', '', $filename );

		if ( ! preg_match( '/^(.+?)-sitemap\d*$/', (string) $filename, $matches ) ) {
			return self::TYPE_UNKNOWN;
		}

		$type = self::sanitize_type( $matches[1] );

		return in_array( $type, self::KNOWN_TYPES, true ) ? $type : self::TYPE_UNKNOWN;
	}

	/**
	 * Prioridad de un tipo.
	 *
	 * @param string $type   Tipo.
	 * @param array  $config Configuración efectiva.
	 * @return int
	 */
	public static function priority_for_type( $type, $config = array() ) {
		$config     = isset( $config['priorities'] ) ? $config : self::config( $config );
		$priorities = (array) $config['priorities'];

		if ( isset( $priorities[ $type ] ) ) {
			return (int) $priorities[ $type ];
		}

		if ( 'category' === $type ) {
			return self::DEFAULT_CATEGORY_PRIORITY;
		}

		return isset( $priorities[ self::TYPE_UNKNOWN ] ) ? (int) $priorities[ self::TYPE_UNKNOWN ] : 50;
	}

	/**
	 * Configuración efectiva, leída de los ajustes existentes.
	 *
	 * @param array $overrides Claves a sobrescribir.
	 * @return array
	 */
	public static function config( $overrides = array() ) {
		$config = array(
			'sitemaps'      => (array) SCW_Settings::get( 'sitemaps', array() ),
			'sitemap_types' => (array) SCW_Settings::get( 'sitemap_types', array() ),
			'priorities'    => (array) SCW_Settings::get( 'priorities', array() ),
		);

		if ( is_array( $overrides ) ) {
			$config = array_merge( $config, $overrides );
		}

		return $config;
	}

	/**
	 * Normaliza un identificador de tipo para que encaje en la columna type de
	 * la cola. Público porque el pipeline lo necesita cuando el tipo llega
	 * indicado a mano en lugar de deducirse del sitemap.
	 *
	 * @param string $type Tipo.
	 * @return string
	 */
	public static function normalize_type( $type ) {
		$type = self::sanitize_type( $type );

		return '' === $type ? self::TYPE_UNKNOWN : $type;
	}

	/**
	 * Normaliza un identificador de tipo.
	 *
	 * @param string $type Tipo.
	 * @return string
	 */
	private static function sanitize_type( $type ) {
		return substr( sanitize_key( (string) $type ), 0, 32 );
	}
}
