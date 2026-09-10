<?php
/**
 * Pipeline de encolado desde sitemap.
 *
 * Encadena las piezas ya existentes y no reimplementa ninguna de sus reglas:
 *
 *   SCW_Sitemap_Parser -> SCW_URL_Normalizer -> SCW_URL_Exclusions -> SCW_Queue
 *
 * Recibe el CONTENIDO XML, no la URL del sitemap. Descargarlo es una petición
 * HTTP y en esta fase no se hace ninguna: el cliente HTTP llega en F3 y será
 * quien alimente este pipeline. Por eso la clase no tiene ni una referencia a
 * wp_remote_get, cURL o sockets.
 *
 * Es instanciable, a diferencia del resto de clases del proyecto, porque
 * necesita conservar estado entre llamadas: el conjunto de hashes ya vistos es
 * lo que permite deduplicar entre sitemaps distintos incluso en dry-run, cuando
 * no hay ninguna fila en la cola contra la que comparar.
 *
 * @package Servisello_Cache_Warmer
 */

defined( 'ABSPATH' ) || exit;

class SCW_Sitemap_Pipeline {

	const DECISION_ENQUEUED        = 'enqueued';
	const DECISION_WOULD_ENQUEUE   = 'would_enqueue';
	const DECISION_DUPLICATE       = 'duplicate';
	const DECISION_INVALID         = 'invalid';
	const DECISION_EXCLUDED        = 'excluded';
	const DECISION_NOT_PROCESSABLE = 'not_processable';
	const DECISION_ERROR           = 'error';

	/** Motivo cuando el documento era un índice de sitemaps. */
	const REASON_SITEMAP_INDEX = 'sitemap_index';

	/** Tope de detalle por ejecución, para no acumular memoria sin límite. */
	const MAX_ITEMS = 5000;

	/** @var array Configuración efectiva. */
	private $config;

	/** @var array Hashes ya vistos en esta ejecución. */
	private $seen = array();

	/** @var array Contadores acumulados. */
	private $totals;

	/**
	 * Constructor.
	 *
	 * @param array $config Sobrescrituras: normalizer, exclusions, sitemap_types,
	 *                      priorities, dry_run, check_queue, source.
	 */
	public function __construct( $config = array() ) {
		$defaults = array(
			// Configuración que se pasa tal cual a cada capa. Vacío significa
			// "usa la configuración real del plugin".
			'normalizer'  => array(),
			'exclusions'  => array(),
			'sources'     => array(),
			// En dry-run no se escribe nada en la cola.
			'dry_run'     => true,
			// En dry-run, consultar la cola para saber si una URL ya estaba.
			'check_queue' => true,
		);

		$this->config = array_merge( $defaults, is_array( $config ) ? $config : array() );
		$this->totals = self::empty_totals();
	}

	/**
	 * Procesa el contenido de un sitemap.
	 *
	 * @param string $xml  Contenido XML ya obtenido.
	 * @param array  $args source, type, priority, sitemap_url, dry_run.
	 * @return array Informe de la ejecución.
	 */
	public function process( $xml, $args = array() ) {
		$args = array_merge(
			array(
				'sitemap_url' => '',
				'source'      => '',
				'type'        => null,
				'priority'    => null,
				'dry_run'     => $this->config['dry_run'],
			),
			is_array( $args ) ? $args : array()
		);

		$sitemap_url = (string) $args['sitemap_url'];
		$source      = '' !== (string) $args['source'] ? (string) $args['source'] : $sitemap_url;
		$dry_run     = (bool) $args['dry_run'];

		$type = null === $args['type']
			? SCW_Sitemap_Sources::type_for_url( $sitemap_url, $this->config['sources'] )
			: SCW_Sitemap_Sources::normalize_type( $args['type'] );

		$priority = null === $args['priority']
			? SCW_Sitemap_Sources::priority_for_type( $type, $this->config['sources'] )
			: (int) $args['priority'];

		$report = array(
			'sitemap_url' => $sitemap_url,
			'source'      => $source,
			'type'        => $type,
			'priority'    => $priority,
			'dry_run'     => $dry_run,
			'is_index'    => false,
			'parsed'      => 0,
			'counts'      => self::empty_totals(),
			'by_reason'   => array(),
			'items'       => array(),
			'child_sitemaps' => array(),
			'error'       => null,
			'error_message' => null,
		);

		$parsed = SCW_Sitemap_Parser::parse( $xml );

		if ( ! $parsed['valid'] ) {
			$report['error']         = $parsed['error'];
			$report['error_message'] = $parsed['error_message'];

			$this->totals['errors']++;

			return $report;
		}

		// Un índice de sitemaps no aporta URLs de contenido: sus <loc> son otros
		// sitemaps. Se devuelven aparte para que quien llame decida, pero NUNCA
		// se encolan ni se tratan como páginas.
		if ( SCW_Sitemap_Parser::is_index( $parsed ) ) {
			$report['is_index']       = true;
			$report['child_sitemaps'] = $parsed['locs'];
			$report['parsed']         = $parsed['count'];
			$report['error']          = self::REASON_SITEMAP_INDEX;
			$report['error_message']  = 'El documento es un índice de sitemaps; sus URLs no son contenido.';

			return $report;
		}

		$report['parsed'] = $parsed['count'];

		foreach ( $parsed['locs'] as $loc ) {
			$item = $this->process_loc( $loc, $type, $priority, $source, $dry_run );

			$report['counts'][ $item['decision'] ]++;
			$this->totals[ $item['decision'] ]++;

			if ( null !== $item['reason'] ) {
				$key                       = $item['decision'] . ':' . $item['reason'];
				$report['by_reason'][ $key ] = isset( $report['by_reason'][ $key ] ) ? $report['by_reason'][ $key ] + 1 : 1;
			}

			if ( count( $report['items'] ) < self::MAX_ITEMS ) {
				$report['items'][] = $item;
			}
		}

		$this->totals['parsed'] += $parsed['count'];

		return $report;
	}

	/**
	 * Procesa una única URL del sitemap.
	 *
	 * @param string $loc      Valor de <loc>.
	 * @param string $type     Tipo.
	 * @param int    $priority Prioridad.
	 * @param string $source   Origen a registrar en la cola.
	 * @param bool   $dry_run  Si true, no se escribe nada.
	 * @return array
	 */
	private function process_loc( $loc, $type, $priority, $source, $dry_run ) {
		$item = array(
			'loc'      => $loc,
			'url'      => null,
			'hash'     => null,
			'decision' => null,
			'reason'   => null,
			'pattern'  => null,
			'queue_id' => null,
		);

		// 1. Normalización (F2.2). No se reimplementa ninguna regla.
		$normalized = SCW_URL_Normalizer::normalize( $loc, $this->config['normalizer'] );

		if ( ! $normalized['valid'] ) {
			$item['decision'] = self::DECISION_INVALID;
			$item['reason']   = $normalized['error'];

			return $item;
		}

		$item['url']  = $normalized['url'];
		$item['hash'] = $normalized['hash'];

		// 2. Exclusiones (F2.3). Tampoco se reimplementa nada.
		$exclusion = SCW_URL_Exclusions::check( $normalized['url'], $this->config['exclusions'] );

		if ( ! $exclusion['processable'] ) {
			$item['decision'] = self::DECISION_NOT_PROCESSABLE;
			$item['reason']   = $exclusion['error'];

			return $item;
		}

		if ( $exclusion['excluded'] ) {
			$item['decision'] = self::DECISION_EXCLUDED;
			$item['reason']   = $exclusion['reason'];
			$item['pattern']  = $exclusion['pattern'];

			return $item;
		}

		// 3. Deduplicación en memoria: cubre el mismo sitemap y también dos
		// sitemaps distintos dentro de la misma ejecución.
		if ( isset( $this->seen[ $normalized['hash'] ] ) ) {
			$item['decision'] = self::DECISION_DUPLICATE;
			$item['reason']   = 'duplicate_in_run';

			return $item;
		}

		$this->seen[ $normalized['hash'] ] = true;

		// 4. Encolado. La unicidad definitiva la garantiza el índice único de
		// url_hash en scw_queue, no esta clase.
		if ( $dry_run ) {
			if ( ! empty( $this->config['check_queue'] ) && SCW_Schema::table_exists( 'queue' ) ) {
				$existing = SCW_Queue::find_by_hash( $normalized['hash'] );

				if ( $existing ) {
					$item['decision'] = self::DECISION_DUPLICATE;
					$item['reason']   = 'already_in_queue';
					$item['queue_id'] = (int) $existing['id'];

					return $item;
				}
			}

			$item['decision'] = self::DECISION_WOULD_ENQUEUE;

			return $item;
		}

		$inserted = SCW_Queue::insert(
			$normalized['url'],
			array(
				'type'     => $type,
				'source'   => $source,
				'priority' => $priority,
			)
		);

		if ( $inserted['inserted'] ) {
			$item['decision'] = self::DECISION_ENQUEUED;
			$item['queue_id'] = $inserted['id'];

			return $item;
		}

		if ( $inserted['duplicate'] ) {
			$item['decision'] = self::DECISION_DUPLICATE;
			$item['reason']   = 'already_in_queue';
			$item['queue_id'] = $inserted['id'];

			return $item;
		}

		$item['decision'] = self::DECISION_ERROR;
		$item['reason']   = $inserted['error'];

		return $item;
	}

	/**
	 * Contadores acumulados de todas las llamadas a process().
	 *
	 * @return array
	 */
	public function totals() {
		return $this->totals;
	}

	/**
	 * Número de URLs únicas vistas en esta ejecución.
	 *
	 * @return int
	 */
	public function seen_count() {
		return count( $this->seen );
	}

	/**
	 * Reinicia el estado acumulado.
	 *
	 * @return void
	 */
	public function reset() {
		$this->seen   = array();
		$this->totals = self::empty_totals();
	}

	/**
	 * Estructura de contadores a cero.
	 *
	 * @return array
	 */
	private static function empty_totals() {
		return array(
			'parsed'                       => 0,
			'errors'                       => 0,
			self::DECISION_ENQUEUED        => 0,
			self::DECISION_WOULD_ENQUEUE   => 0,
			self::DECISION_DUPLICATE       => 0,
			self::DECISION_INVALID         => 0,
			self::DECISION_EXCLUDED        => 0,
			self::DECISION_NOT_PROCESSABLE => 0,
			self::DECISION_ERROR           => 0,
		);
	}
}
