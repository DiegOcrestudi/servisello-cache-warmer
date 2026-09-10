<?php
/**
 * Parser de sitemaps XML.
 *
 * Recibe el CONTENIDO XML ya obtenido y devuelve una estructura. No sabe nada
 * de HTTP, no descarga nada, no conoce el dominio del proyecto ni las reglas de
 * normalización o exclusión. Su única responsabilidad es interpretar el XML.
 *
 * Distingue explícitamente entre un sitemap de contenido (urlset) y un índice de
 * sitemaps (sitemapindex): los <loc> de un índice apuntan a otros sitemaps, no a
 * páginas, y no deben tratarse como URLs finales.
 *
 * Seguridad: se rechaza cualquier documento con DOCTYPE o ENTITY antes incluso
 * de intentar parsearlo, lo que cierra XXE y expansión de entidades sin depender
 * de libxml_disable_entity_loader, que está obsoleta desde PHP 8. Además se
 * parsea con LIBXML_NONET, de modo que libxml no puede hacer ninguna petición de
 * red por su cuenta.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Sitemap_Parser {

	const TYPE_URLSET = 'urlset';
	const TYPE_INDEX  = 'sitemapindex';

	const ERROR_EMPTY       = 'empty_xml';
	const ERROR_TOO_LARGE   = 'xml_too_large';
	const ERROR_DOCTYPE     = 'doctype_not_allowed';
	const ERROR_MALFORMED   = 'invalid_xml';
	const ERROR_ROOT        = 'unsupported_root_element';

	/** Tamaño máximo admitido, en bytes. Un sitemap normal ronda los cientos de KB. */
	const MAX_BYTES = 20971520;

	/**
	 * Parsea un documento de sitemap.
	 *
	 * @param string $xml Contenido XML.
	 * @return array {
	 *     @type bool        $valid         True si el documento es utilizable.
	 *     @type string|null $type          urlset|sitemapindex.
	 *     @type string[]    $locs          Valores de <loc>, sin normalizar.
	 *     @type int         $count         Número de <loc> encontrados.
	 *     @type string|null $root          Nombre del elemento raíz encontrado.
	 *     @type string|null $error         Código de error.
	 *     @type string|null $error_message Mensaje legible.
	 * }
	 */
	public static function parse( $xml ) {
		$xml = is_string( $xml ) ? $xml : '';

		if ( '' === trim( $xml ) ) {
			return self::failure( self::ERROR_EMPTY, 'El documento está vacío.' );
		}

		if ( strlen( $xml ) > self::MAX_BYTES ) {
			return self::failure( self::ERROR_TOO_LARGE, 'El documento supera el tamaño máximo admitido.' );
		}

		// Ninguna declaración de tipo de documento ni entidades: no las necesita
		// un sitemap y son el vector de XXE y de la bomba de entidades.
		if ( preg_match( '/<!\s*(DOCTYPE|ENTITY)/i', $xml ) ) {
			return self::failure( self::ERROR_DOCTYPE, 'El documento declara DOCTYPE o ENTITY y no se procesa.' );
		}

		$previous_state = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$document = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );

		$libxml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		if ( false === $document ) {
			$detail = empty( $libxml_errors ) ? '' : ' ' . trim( $libxml_errors[0]->message );

			return self::failure( self::ERROR_MALFORMED, 'El XML no se ha podido parsear.' . $detail );
		}

		$root = $document->getName();

		if ( self::TYPE_URLSET !== $root && self::TYPE_INDEX !== $root ) {
			$result          = self::failure( self::ERROR_ROOT, 'Elemento raíz no reconocido: ' . $root . '.' );
			$result['root']  = $root;

			return $result;
		}

		// Los sitemaps declaran un espacio de nombres por defecto, así que hay
		// que pedir los hijos con ese espacio o SimpleXML no devuelve nada.
		$namespaces = $document->getDocNamespaces();
		$children   = isset( $namespaces[''] ) ? $document->children( $namespaces[''] ) : $document->children();

		$container = ( self::TYPE_INDEX === $root ) ? 'sitemap' : 'url';
		$locs      = array();

		foreach ( $children->{$container} as $entry ) {
			$loc = isset( $entry->loc ) ? trim( (string) $entry->loc ) : '';

			if ( '' !== $loc ) {
				$locs[] = $loc;
			}
		}

		return array(
			'valid'         => true,
			'type'          => $root,
			'locs'          => $locs,
			'count'         => count( $locs ),
			'root'          => $root,
			'error'         => null,
			'error_message' => null,
		);
	}

	/**
	 * ¿Es un índice de sitemaps?
	 *
	 * @param array $result Resultado de parse().
	 * @return bool
	 */
	public static function is_index( $result ) {
		return is_array( $result ) && ! empty( $result['valid'] ) && self::TYPE_INDEX === $result['type'];
	}

	/**
	 * Construye un resultado de fallo.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 * @return array
	 */
	private static function failure( $code, $message ) {
		return array(
			'valid'         => false,
			'type'          => null,
			'locs'          => array(),
			'count'         => 0,
			'root'          => null,
			'error'         => $code,
			'error_message' => $message,
		);
	}
}
