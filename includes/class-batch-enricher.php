<?php
/**
 * CKG_Batch_Enricher
 * Enriquece productos WooCommerce existentes con Ollama en segundo plano.
 *
 * Flujo:
 *   1. El admin selecciona productos (por categoria, marca, estado de enrichment)
 *   2. Los añade a la cola (wp_options: ckg_enrichment_queue)
 *   3. Cada peticion AJAX procesa un producto — titulo, specs basicas, SEO
 *   4. El JS llama repetidamente hasta vaciar la cola mostrando progreso
 *
 * Campos que rellena por cada producto basico:
 *   - short_description (si esta vacia)
 *   - description completa con ContentBuilder
 *   - seo title, description, keywords (RankMath)
 *   - _ckg_generated = 1 (marca como procesado)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Batch_Enricher {

    const QUEUE_OPTION  = 'ckg_enrichment_queue';
    const STATUS_OPTION = 'ckg_enrichment_status';

    /* ── Estado de la cola ───────────────────────────────────────────── */

    public static function get_queue(): array {
        return (array) get_option( self::QUEUE_OPTION, [] );
    }

    public static function get_status(): array {
        return (array) get_option( self::STATUS_OPTION, [
            'total'     => 0,
            'processed' => 0,
            'failed'    => 0,
            'running'   => false,
            'last_id'   => 0,
            'last_name' => '',
            'started_at'=> '',
            'errors'    => [],
        ] );
    }

    public static function is_running(): bool {
        return (bool) ( self::get_status()['running'] ?? false );
    }

    /* ── Construir cola de productos a enriquecer ────────────────────── */

    /**
     * Obtiene IDs de productos pendientes de enriquecer.
     *
     * @param array $filters  ['category_id' => int, 'only_empty' => bool, 'limit' => int]
     * @return int[]
     */
    public static function get_pending_products( array $filters = [] ): array {
        $args = [
            'status'  => 'publish',
            'limit'   => (int) ( $filters['limit'] ?? 200 ),
            'return'  => 'ids',
            'type'    => [ 'simple', 'variable' ],
        ];

        if ( ! empty( $filters['category_id'] ) ) {
            $args['category'] = [ ( ( $term = get_term( $filters['category_id'], 'product_cat' ) ) && ! is_wp_error( $term ) ? $term->slug : '' ) ];
        }

        $ids = wc_get_products( $args );

        // Filtrar por score SEO si se especifica un máximo
        if ( $max_score < 100 && ! empty( $ids ) ) {
            $ids = array_filter( $ids, function( $id ) use ( $max_score ) {
                $score = (int) get_post_meta( $id, '_ckg_seo_score', true );
                return $score <= $max_score; // incluir los que tienen score 0 (sin score)
            } );
        }

        // Filtrar: solo los que NO han sido procesados por el plugin
        if ( ! empty( $filters['only_empty'] ) ) {
            // Obtener los ya procesados en una sola query SQL
            global $wpdb;
            $processed_ids = $wpdb->get_col(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ckg_generated' AND meta_value = '1'"
            );
            $processed_ids = array_map( 'intval', $processed_ids );

            $ids = array_filter( $ids, function( $id ) use ( $processed_ids ) {
                return ! in_array( $id, $processed_ids );
            } );
        }

        return array_values( $ids );
    }

    /**
     * Re-enriquecimiento inteligente:
     * Productos ya enriquecidos pero con score bajo o enriquecidos hace mucho.
     */
    public static function get_stale_products( int $min_days = 30, int $max_score = 70, int $limit = 50 ): array {
        global $wpdb;

        $cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$min_days} days" ) );

        // Productos enriquecidos hace más de $min_days días
        $stale_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_ckg_enriched_at'
             AND pm.meta_value < %s
             AND p.post_type = 'product'
             AND p.post_status = 'publish'
             LIMIT %d",
            $cutoff_date, $limit * 2
        ) );

        if ( empty( $stale_ids ) ) return [];

        // De esos, filtrar los que tienen score bajo
        $result = [];
        foreach ( $stale_ids as $id ) {
            $score = (int) get_post_meta( $id, '_ckg_seo_score', true );
            if ( $score <= $max_score ) {
                // Resetear el flag para que pueda re-enriquecerse
                delete_post_meta( $id, '_ckg_generated' );
                $result[] = (int) $id;
                if ( count( $result ) >= $limit ) break;
            }
        }

        return $result;
    }

    /**
     * Añade productos a la cola.
     */
    public static function add_to_queue( array $product_ids ): int {
        $queue    = self::get_queue();
        $existing = array_column( $queue, 'id' );
        $added    = 0;

        foreach ( $product_ids as $id ) {
            if ( in_array( $id, $existing ) ) continue;
            $queue[] = [
                'id'     => (int) $id,
                'name'   => get_the_title( $id ),
                'status' => 'pending',
            ];
            $added++;
        }

        update_option( self::QUEUE_OPTION, $queue );

        $status             = self::get_status();
        $status['total']    = count( $queue );
        $status['running']  = false;
        $status['started_at'] = current_time( 'mysql' );
        update_option( self::STATUS_OPTION, $status );

        return $added;
    }

    /**
     * Limpia la cola completamente.
     */
    public static function clear_queue(): void {
        delete_option( self::QUEUE_OPTION );
        delete_option( self::STATUS_OPTION );
    }

    /* ── Procesar UN producto de la cola ─────────────────────────────── */

    /**
     * Coge el siguiente pendiente de la cola y lo enriquece.
     * Devuelve el estado actual después de procesar.
     */
    public static function process_next(): array {
        $queue = self::get_queue();

        // Encontrar el siguiente pendiente
        $next_idx = null;
        foreach ( $queue as $i => $item ) {
            if ( $item['status'] === 'pending' ) { $next_idx = $i; break; }
        }

        if ( $next_idx === null ) {
            // Cola vacía — marcar como completado
            $status            = self::get_status();
            $status['running'] = false;
            update_option( self::STATUS_OPTION, $status );
            return [ 'done' => true, 'status' => $status ];
        }

        $item       = $queue[ $next_idx ];
        $product_id = $item['id'];

        // Marcar como procesando
        $status             = self::get_status();
        $status['running']  = true;
        $status['last_id']  = $product_id;
        $status['last_name']= $item['name'];
        update_option( self::STATUS_OPTION, $status );

        // ── Enriquecer el producto ────────────────────────────────────
        $result = self::enrich_product( $product_id );

        // Actualizar estado en la cola
        $queue[ $next_idx ]['status'] = $result['success'] ? 'done' : 'failed';
        if ( ! $result['success'] ) {
            $queue[ $next_idx ]['error'] = $result['error'] ?? 'Error desconocido';
        }
        update_option( self::QUEUE_OPTION, $queue );

        // Actualizar contadores
        $status = self::get_status();
        if ( $result['success'] ) {
            $status['processed'] = ( $status['processed'] ?? 0 ) + 1;
        } else {
            $status['failed']  = ( $status['failed'] ?? 0 ) + 1;
            $status['errors'][] = "ID $product_id: " . ( $result['error'] ?? '' );
            $status['errors']  = array_slice( $status['errors'], -20 ); // max 20 errores
        }

        // Calcular pendientes
        $pending = count( array_filter( $queue, fn( $q ) => $q['status'] === 'pending' ) );
        $status['pending'] = $pending;
        $status['running'] = $pending > 0;
        update_option( self::STATUS_OPTION, $status );

        return [
            'done'       => $pending === 0,
            'status'     => $status,
            'last_result'=> $result,
            'pending'    => $pending,
        ];
    }

    /* ── Enriquecimiento de un producto ──────────────────────────────── */

    public static function enrich_product( int $product_id ): array {
        try {
            $product = wc_get_product( $product_id );
            if ( ! $product ) return [
                'success'    => false,
                'error'      => 'Producto no encontrado (ID: ' . $product_id . ')',
                'product_id' => $product_id,
                'name'       => 'ID ' . $product_id,
            ];

            $name   = $product->get_name();
            $brand  = self::get_product_brand( $product_id );
            $sku    = $product->get_sku();
            $cats   = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
            $cat    = ! is_wp_error( $cats ) && $cats ? $cats[0] : '';

            // Recoger TODO lo que ya tiene el producto
            $existing_short = wp_strip_all_tags( $product->get_short_description() );
            $existing_desc  = wp_strip_all_tags( $product->get_description() );
            $existing_specs = self::get_specs_from_attributes( $product );
            $existing_homos = json_decode( get_post_meta( $product_id, '_ckg_homologaciones', true ) ?: '[]', true );
            $existing_faq   = json_decode( get_post_meta( $product_id, '_ckg_faq', true ) ?: '[]', true );

            // Construir $data compatible con CKG_Content_Builder
            // partiendo de lo que ya existe — Ollama lo mejora, no lo borra
            $data = [
                'product_name'    => $name,
                'brand'           => $brand,
                'sku'             => $sku,
                'categories'      => ! is_wp_error( $cats ) ? $cats : [],
                'short_desc'      => $existing_short,
                'intro'           => strlen( $existing_desc ) >= 200 ? $existing_desc : '',
                'specs'           => $existing_specs,
                'faq'             => $existing_faq ?: [],
                'homologacion'    => $existing_homos ?: [],
                'caracteristicas' => [],
                'categorias_uso'  => [],
                'accessories'     => [],
                'compatibilidad'  => [],
                'conclusion'      => '',
                'seo_title'       => '',
                'seo_description' => '',
                'seo_keywords'    => '',
            ];

            // ── Enriquecer con competidores si nombre es generico ────
            // Un nombre es generico si: no tiene marca, es muy corto, o el SKU no aparece en el nombre
            $is_generic = ( empty( $brand ) || str_word_count( $name ) <= 3 || strlen( $name ) < 20 );
            if ( $is_generic && class_exists( 'CKG_Competitor_Scraper' ) ) {
                $search_query = trim( "$brand $name $sku" );
                $comp_urls    = self::find_competitor_urls( $search_query );
                if ( ! empty( $comp_urls ) ) {
                    $comp_data = CKG_Competitor_Scraper::analyze( $comp_urls, $name, 'specs' );
                    if ( ! is_wp_error( $comp_data ) ) {
                        $syn = $comp_data['synthesis'] ?? [];

                        // Fusionar specs del competidor
                        if ( ! empty( $syn['specs'] ) ) {
                            $existing_keys = array_map( fn($s) => strtolower($s['key']), $data['specs'] );
                            foreach ( $syn['specs'] as $s ) {
                                if ( ! in_array( strtolower($s['key']), $existing_keys ) ) {
                                    $data['specs'][] = $s;
                                }
                            }
                        }

                        // Guardar precios de competidores
                        if ( ! empty( $syn['prices_found'] ) ) {
                            $our_price  = (float) $product->get_price();
                            $comp_prices = [];
                            foreach ( $syn['prices_found'] as $price_str ) {
                                preg_match( '/[\d]+[.,]?\d*/', str_replace('.', '', $price_str), $pm );
                                if ( $pm ) $comp_prices[] = (float) str_replace( ',', '.', $pm[0] );
                            }
                            if ( $comp_prices ) {
                                $min_comp = min( $comp_prices );
                                update_post_meta( $product_id, '_ckg_comp_price_min',  $min_comp );
                                update_post_meta( $product_id, '_ckg_comp_price_urls', wp_json_encode( $comp_urls ) );
                                update_post_meta( $product_id, '_ckg_comp_price_date', current_time( 'mysql' ) );
                                // Alerta si el competidor es mas barato
                                if ( $our_price > 0 && $min_comp < $our_price ) {
                                    update_post_meta( $product_id, '_ckg_price_alert',
                                        "Competidor: {$min_comp}€ | Tu precio: {$our_price}€ | Diferencia: " . round( $our_price - $min_comp, 2 ) . "€"
                                    );
                                } else {
                                    delete_post_meta( $product_id, '_ckg_price_alert' );
                                }
                            }
                        }

                        // Usar descripcion del competidor como contexto
                        if ( ! empty( $syn['intro'] ) && empty( $existing_desc ) ) {
                            $existing_desc = $syn['intro'];
                        }
                    }
                }
            }

            // ── Generar con Ollama ────────────────────────────────────
            if ( class_exists( 'CKG_Ollama' ) && CKG_Ollama::ping() ) {
                $prompt = self::build_enrich_prompt( $name, $brand, $cat, $sku, $data['specs'], $existing_desc, $existing_short );
                $response = CKG_Ollama::improve_raw( $prompt );

                if ( ! is_wp_error( $response ) && $response ) {
                    $parsed = self::parse_ollama_response( $response );
                    if ( ! empty( $parsed['intro'] ) )            $data['intro']           = $parsed['intro'];
                    if ( ! empty( $parsed['short_desc'] ) )       $data['short_desc']      = $parsed['short_desc'];
                    if ( ! empty( $parsed['conclusion'] ) )       $data['conclusion']       = $parsed['conclusion'];
                    if ( ! empty( $parsed['faq'] ) )              $data['faq']             = $parsed['faq'];
                    if ( ! empty( $parsed['seo_title'] ) )        $data['seo_title']       = $parsed['seo_title'];
                    if ( ! empty( $parsed['seo_description'] ) )  $data['seo_description'] = $parsed['seo_description'];
                    if ( ! empty( $parsed['seo_keywords'] ) )     $data['seo_keywords']    = $parsed['seo_keywords'];
                    // Traducciones y schema
                    if ( ! empty( $parsed['desc_en'] ) )          $data['desc_en']         = $parsed['desc_en'];
                    if ( ! empty( $parsed['desc_it'] ) )          $data['desc_it']         = $parsed['desc_it'];
                    if ( ! empty( $parsed['schema_name'] ) )      $data['schema_name']     = $parsed['schema_name'];
                    if ( ! empty( $parsed['schema_description'] ) ) $data['schema_description'] = $parsed['schema_description'];
                }
            }

            // ── Si Ollama no responde, generar textos minimos ─────────
            if ( empty( $data['short_desc'] ) ) {
                $data['short_desc'] = "$name" . ( $brand ? " de $brand" : '' )
                    . ( $cat ? " para $cat" : '' ) . '. Disponible en CMSKart.es.';
            }
            if ( empty( $data['intro'] ) ) {
                $data['intro'] = $data['short_desc'];
            }
            if ( empty( $data['seo_title'] ) ) {
                $data['seo_title'] = "$name" . ( $brand ? " | $brand" : '' ) . ' | CMSKart';
            }
            if ( empty( $data['seo_description'] ) ) {
                $data['seo_description'] = $data['short_desc'];
            }
            if ( empty( $data['seo_keywords'] ) ) {
                $data['seo_keywords'] = strtolower( $name ) . ( $brand ? ', ' . strtolower( $brand ) : '' ) . ', karting';
            }

            // ── Guardar revision ANTES de cualquier cambio ───────────────
            $revision_id = 0;
            if ( class_exists( 'CKG_Revisions' ) ) {
                $revision_id = CKG_Revisions::save(
                    $product_id,
                    $product->get_description(),
                    $product->get_short_description(),
                    '', // desc_despues: se actualiza al final
                    '',
                    'enriquecimiento'
                );
            }
            // Backup legacy en postmeta (compatibilidad)
            if ( ! get_post_meta( $product_id, '_ckg_backup_desc', true ) ) {
                update_post_meta( $product_id, '_ckg_backup_desc',  $product->get_description() );
                update_post_meta( $product_id, '_ckg_backup_short', $product->get_short_description() );
                update_post_meta( $product_id, '_ckg_backup_date',  current_time( 'mysql' ) );
            }

            // ── Guardar en WooCommerce ────────────────────────────────
            // ── Extraer y preservar traducciones del CSV antes de sobreescribir ──
            $raw_desc  = wp_strip_all_tags( $product->get_description() );
            $raw_short = wp_strip_all_tags( $product->get_short_description() );

            // Si tiene formato CSV con traducciones, extraerlas y guardarlas
            $base_from_csv = self::extract_and_preserve_translations( $product_id, $raw_desc );
            if ( $base_from_csv !== $raw_desc && empty( $data['intro'] ) ) {
                // Usar la descripción española del CSV como base para Ollama
                $data['intro'] = $base_from_csv;
                $existing_desc = $base_from_csv;
            }

            // Proteger contenido generado con el plugin (tiene estructura HTML compleja)
            $word_count = str_word_count( $raw_desc );
            $has_plugin_content = $word_count > 80 || strpos( $product->get_description(), '<h2' ) !== false || strpos( $product->get_description(), '<h3' ) !== false;

            // Descripcion corta — sobreescribir si vacia, muy corta o baja calidad
            $existing_short = $raw_short;
            if ( self::is_low_quality( $existing_short ) && ! empty( $data['short_desc'] ) ) {
                $product->set_short_description( wp_kses_post( $data['short_desc'] ) );
            }

            // Descripcion larga — solo si es baja calidad Y no tiene contenido elaborado
            $existing_desc = $raw_desc;
            if ( self::is_low_quality( $existing_desc ) && ! $has_plugin_content ) {
                if ( class_exists( 'CKG_Content_Builder' ) ) {
                    $product->set_description( CKG_Content_Builder::build( $data ) );
                } else {
                    $long = '<p>' . wp_kses_post( $data['intro'] ) . '</p>';
                    if ( ! empty( $data['faq'] ) ) {
                        $long .= '<h3>Preguntas frecuentes</h3>';
                        foreach ( $data['faq'] as $faq_item ) {
                            $long .= '<h4>' . esc_html( $faq_item['pregunta'] ?? '' ) . '</h4>';
                            $long .= '<p>' . wp_kses_post( $faq_item['respuesta'] ?? '' ) . '</p>';
                        }
                    }
                    if ( ! empty( $data['conclusion'] ) ) {
                        $long .= '<p>' . wp_kses_post( $data['conclusion'] ) . '</p>';
                    }
                    $product->set_description( $long );
                }
            }

            // -- Enriquecer descripcion con FAQ, conclusion, traducciones y schema
            $current_desc = $product->get_description();
            $extra_html   = '';

            // FAQ -- anadir si no existe
            $existing_faq_meta = get_post_meta( $product_id, '_ckg_faq', true );
            if ( empty( $existing_faq_meta ) && ! empty( $data['faq'] ) ) {
                update_post_meta( $product_id, '_ckg_faq', wp_json_encode( $data['faq'] ) );
                if ( strpos( $current_desc, 'Preguntas frecuentes' ) === false ) {
                    $extra_html .= '<h3>Preguntas frecuentes</h3>';
                    foreach ( $data['faq'] as $faq_item ) {
                        $extra_html .= '<h4>' . esc_html( $faq_item['pregunta'] ?? '' ) . '</h4>';
                        $extra_html .= '<p>'  . wp_kses_post( $faq_item['respuesta'] ?? '' ) . '</p>';
                    }
                }
            }

            // Conclusion -- anadir si no existe en el texto actual
            if ( ! empty( $data['conclusion'] ) && strpos( $current_desc, substr( $data['conclusion'], 0, 20 ) ) === false ) {
                $extra_html .= '<p><strong>' . wp_kses_post( $data['conclusion'] ) . '</strong></p>';
            }

            // Traducciones EN e IT
            if ( ! empty( $data['desc_en'] ) ) {
                update_post_meta( $product_id, '_ckg_desc_en', $data['desc_en'] );
                $extra_html .= '<p><em><strong>EN:</strong> ' . wp_kses_post( $data['desc_en'] ) . '</em></p>';
            }
            if ( ! empty( $data['desc_it'] ) ) {
                update_post_meta( $product_id, '_ckg_desc_it', $data['desc_it'] );
                $extra_html .= '<p><em><strong>IT:</strong> ' . wp_kses_post( $data['desc_it'] ) . '</em></p>';
            }

            // Aplicar al producto
            if ( $extra_html ) {
                $product->set_description( $current_desc . "\n" . $extra_html );
            }

            // Schema JSON-LD
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $data['schema_name'] ?? $name,
                'description' => $data['schema_description'] ?? wp_strip_all_tags( $product->get_short_description() ),
                'sku'         => $sku,
                'brand'       => [ '@type' => 'Brand', 'name' => $brand ?: 'CMSKart' ],
                'offers'      => [
                    '@type'         => 'Offer',
                    'priceCurrency' => 'EUR',
                    'price'         => $product->get_price(),
                    'availability'  => $product->is_in_stock()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'url'           => get_permalink( $product_id ),
                ],
            ];
            if ( ! empty( $data['faq'] ) ) {
                $schema['mainEntity'] = array_map( function( $f ) {
                    return [
                        '@type'          => 'Question',
                        'name'           => $f['pregunta']  ?? '',
                        'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $f['respuesta'] ?? '' ],
                    ];
                }, $data['faq'] );
            }
            update_post_meta( $product_id, '_ckg_schema', wp_json_encode( $schema ) );

            $product->save();

            // Actualizar revision con el contenido generado
            if ( $revision_id && class_exists( 'CKG_Revisions' ) ) {
                CKG_Revisions::update_after(
                    $revision_id,
                    $product->get_description(),
                    $product->get_short_description()
                );
            }

            // SEO — RankMath
            if ( class_exists( 'CKG_RankMath' ) ) {
                CKG_RankMath::apply( $product_id, $data );
            }

            // Detectar canibalización de keywords con ChromaDB
            if ( class_exists( 'CKG_ChromaDB' ) && ! empty( $data['seo_keywords'] ) ) {
                $keyword_main = explode( ',', $data['seo_keywords'] )[0];
                $keyword_main = trim( $keyword_main );
                if ( $keyword_main ) {
                    $conflicts = CKG_ChromaDB::check_keyword_cannibalization( $keyword_main, $product_id );
                    if ( ! empty( $conflicts ) ) {
                        $alert_parts = [];
                        foreach ( array_slice( $conflicts, 0, 3 ) as $c ) {
                            $alert_parts[] = $c['product_name'] . ' (' . $c['similarity'] . '%)';
                        }
                        update_post_meta( $product_id, '_ckg_keyword_conflict',
                            'Keyword "' . $keyword_main . '" puede canibalizar: ' . implode( ', ', $alert_parts )
                        );
                    } else {
                        delete_post_meta( $product_id, '_ckg_keyword_conflict' );
                    }
                    // Indexar este producto en ChromaDB para futuros checks
                    CKG_ChromaDB::upsert(
                        CKG_ChromaDB::COL_KEYWORDS,
                        'kw_' . $product_id,
                        $keyword_main,
                        [ 'product_id' => $product_id, 'product_name' => $name, 'keyword' => $keyword_main ]
                    );
                }
            }

            // Marcar como procesado
            update_post_meta( $product_id, '_ckg_generated', 1 );
            update_post_meta( $product_id, '_ckg_enriched_at', current_time( 'mysql' ) );

            // Aplicar campos sociales (Facebook, Google, Pinterest)
            if ( class_exists( 'CKG_SocialMeta' ) ) {
                CKG_SocialMeta::apply( $product_id, $data );
            }

            return [ 'success' => true, 'product_id' => $product_id, 'name' => $name ];

        } catch ( \Throwable $e ) {
            error_log( "CKG_Batch_Enricher::enrich_product($product_id): " . $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private static function get_product_brand( int $id ): string {
        foreach ( [ 'pwb-brand', 'pa_marca', 'pa_brand', 'product_brand' ] as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) continue;
            $terms = wp_get_object_terms( $id, $tax, [ 'fields' => 'names' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) return $terms[0];
        }
        return '';
    }

    private static function get_specs_from_attributes( WC_Product $product ): array {
        $specs = [];
        foreach ( $product->get_attributes() as $attr ) {
            if ( $attr->get_variation() ) continue; // saltar atributos de variacion
            $name = wc_attribute_label( $attr->get_name() );
            $vals = $attr->is_taxonomy()
                ? wp_list_pluck( $attr->get_terms(), 'name' )
                : $attr->get_options();
            if ( $name && $vals ) {
                $specs[] = [ 'key' => $name, 'value' => implode( ', ', $vals ) ];
            }
        }
        return $specs;
    }

    /**
     * Busca URLs de competidores para un producto generico.
     * Usa DuckDuckGo para encontrar el producto en tiendas de karting.
     */
    /**
     * Extrae traducciones y descripción española del formato CSV de importación.
     * Formato: DESCRIPTION IN SPANISH: xxx / DESCRIPTION IN ENGLISH: xxx / ITALIAN DESCRIPTIONS: xxx
     * Guarda EN e IT en meta fields para no perderlas, devuelve la española como base.
     */
    private static function extract_and_preserve_translations( int $product_id, string $text ): string {
        if ( strpos( $text, 'DESCRIPTION IN SPANISH' ) === false ) return $text;

        // Extraer cada idioma
        $es = $en = $it = '';

        if ( preg_match( '/DESCRIPTION IN SPANISH[:\s]+([^\n]+(?:\n(?!DESCRIPTION|CATEGORY|ITALIAN)[^\n]+)*)/i', $text, $m ) ) {
            $es = trim( $m[1] );
        }
        if ( preg_match( '/DESCRIPTION IN ENGLISH[:\s]+([^\n]+(?:\n(?!DESCRIPTION|CATEGORY|ITALIAN)[^\n]+)*)/i', $text, $m ) ) {
            $en = trim( $m[1] );
        }
        if ( preg_match( '/ITALIAN DESCRIPTIONS?[:\s]+([^\n]+(?:\n(?!DESCRIPTION|CATEGORY)[^\n]+)*)/i', $text, $m ) ) {
            $it = trim( $m[1] );
        }

        // Guardar traducciones como meta para no perderlas
        if ( $en ) update_post_meta( $product_id, '_ckg_desc_en', $en );
        if ( $it ) update_post_meta( $product_id, '_ckg_desc_it', $it );

        // Devolver la española como base para Ollama
        return $es ?: $text;
    }

    /**
     * Detecta si un texto es contenido de baja calidad (importación automática).
     * Devuelve true si hay que sobreescribir.
     */
    private static function is_low_quality( string $text ): bool {
        if ( strlen( $text ) < 200 ) return true;

        // Patrones típicos de importaciones masivas sin trabajar
        $bad_patterns = [
            'PRODUCT CATEGORY',
            'DESCRIPTION IN SPANISH',
            'DESCRIPTION IN ENGLISH',
            'ITALIAN DESCRIPTIONS',
            'CATEGORY:',
            'Consigue ya',
            'en www. CMSKART',
            'Discos de freno de karting ventilados', // texto genérico que se repite
            'DESCRIPTION IN',
            'karting ventilados',
        ];

        $text_upper = strtoupper( $text );
        foreach ( $bad_patterns as $pattern ) {
            if ( strpos( $text_upper, strtoupper( $pattern ) ) !== false ) {
                return true;
            }
        }

        return false;
    }

    private static function find_competitor_urls( string $query ): array {
        if ( empty( $query ) ) return [];

        $sites  = [ 'kpsracing.es', 'gt2i.es', 'kartshop.es', 'mondokart.com', 'kartsport.es' ];
        $urls   = [];
        $query_enc = urlencode( $query );

        foreach ( $sites as $site ) {
            // Buscar en DuckDuckGo: site:dominio + query
            $search_url = "https://html.duckduckgo.com/html/?q=site:{$site}+{$query_enc}";
            $resp = wp_remote_get( $search_url, [
                'timeout'    => 10,
                'sslverify'  => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ] );

            if ( is_wp_error( $resp ) ) continue;

            $body = wp_remote_retrieve_body( $resp );
            // Extraer primer resultado de la busqueda
            if ( preg_match( '/href="(https?:\/\/' . preg_quote($site, '/') . '[^"]+)"/', $body, $m ) ) {
                $urls[] = $m[1];
            }

            if ( count( $urls ) >= 2 ) break; // Max 2 competidores para no tardar mucho
        }

        return $urls;
    }

    private static function build_enrich_prompt( string $name, string $brand, string $cat, string $sku, array $specs, string $existing_desc = '', string $existing_short = '' ): string {
        $specs_text = '';
        if ( $specs ) {
            $specs_text = "ESPECIFICACIONES TECNICAS:\n" . implode( "\n", array_map(
                fn( $s ) => "- " . $s['key'] . ': ' . $s['value'], array_slice( $specs, 0, 10 )
            ) );
        }

        $context = '';
        if ( strlen( $existing_desc ) > 50 ) {
            if ( self::is_low_quality( $existing_desc ) ) {
                $context .= "TEXTO DE REFERENCIA (puede contener nombres de otras tiendas, reescribir completamente):\n"
                         . mb_substr( $existing_desc, 0, 800 ) . "\n";
            } else {
                $context .= "DESCRIPCION PROPIA EXISTENTE (mejorar y ampliar, NO reemplazar):\n"
                         . mb_substr( $existing_desc, 0, 800 ) . "\n";
            }
        } elseif ( strlen( $existing_short ) > 20 ) {
            $context .= "DESCRIPCION CORTA BASE:\n" . $existing_short . "\n";
        }

        return "Actua como un Copywriter SEO tecnico experto en piezas de karting de competicion para TU UNICA TIENDA: CMSKart.es.\n\n"
             . "<datos_del_producto>\n"
             . "Producto: $name\n"
             . ( $brand ? "Marca Fabricante: $brand\n" : '' )
             . ( $cat   ? "Categoria: $cat\n" : '' )
             . ( $sku   ? "Referencia (SKU): $sku\n" : '' )
             . "$specs_text\n\n"
             . "$context\n"
             . "</datos_del_producto>\n\n"
             . "INSTRUCCIONES DE REDACCION:\n"
             . "Genera contenido enriquecido, comercial y altamente optimizado para SEO extrayendo SOLO los datos tecnicos de <datos_del_producto>.\n"
             . "- Tono: Tecnico, directo, persuasivo y en Espanol de Espana.\n"
             . "- REGLA DE ORO: ESTA TOTALMENTE PROHIBIDO mencionar nombres de otras tiendas (ej: 'Francis', 'Mondokart', 'KPS', 'gt2i', 'kartshop').\n"
             . "- Escribe como si el producto fuera vendido UNICA Y EXCLUSIVAMENTE por CMSKart.es.\n\n"
             . "REGLAS ESTRICTAS DE FORMATO (CRITICO PARA EL SISTEMA):\n"
             . "1. Devuelve UNICA Y EXCLUSIVAMENTE un objeto JSON valido.\n"
             . "2. NO envuelvas la respuesta en bloques de codigo markdown (PROHIBIDO usar ```json o ```).\n"
             . "3. NO incluyas saludos, explicaciones, ni texto de introduccion.\n"
             . "4. El JSON debe contener EXACTAMENTE estas claves respetando el formato:\n\n"
             . "{\n"
             . "  \"short_desc\": \"Descripcion corta de 1-2 frases destacando el beneficio principal en espanol.\",\n"
             . "  \"intro\": \"Introduccion tecnica de 100-150 palabras en espanol integrando los datos tecnicos y ventajas. Atribuido siempre a CMSKart.es.\",\n"
             . "  \"conclusion\": \"Llamada a la accion (CTA) de 2-3 frases en espanol animando a comprar en CMSKart.es.\",\n"
             . "  \"desc_en\": \"Traduccion fiel de la short_desc al ingles (50-80 palabras).\",\n"
             . "  \"desc_it\": \"Traduccion fiel de la short_desc al italiano (50-80 parole).\",\n"
             . "  \"faq\": [\n"
             . "    {\"pregunta\": \"Pregunta frecuente tecnica util 1\", \"respuesta\": \"Respuesta directa 1\"},\n"
             . "    {\"pregunta\": \"Pregunta frecuente tecnica util 2\", \"respuesta\": \"Respuesta directa 2\"}\n"
             . "  ],\n"
             . "  \"schema_name\": \"Nombre limpio y optimizado del producto para schema.org.\",\n"
             . "  \"schema_description\": \"Breve descripcion en ingles para el schema Product.\",\n"
             . "  \"seo_title\": \"Titulo SEO persuasivo (maximo 60 caracteres).\",\n"
             . "  \"seo_description\": \"Meta descripcion comercial (140-155 caracteres).\",\n"
             . "  \"seo_keywords\": \"keyword principal, keyword secundaria 1, keyword secundaria 2\"\n"
             . "}";
    }
    private static function parse_ollama_response( string $response ): array {
        // Extraer JSON de la respuesta
        $response = preg_replace( '/```json|```/', '', $response );
        $response = trim( $response );

        // Intentar decodificar directamente
        $data = @json_decode( $response, true );
        if ( is_array( $data ) ) return $data;

        // Intentar extraer el JSON del texto
        if ( preg_match( '/\{.*\}/s', $response, $m ) ) {
            $data = @json_decode( $m[0], true );
            if ( is_array( $data ) ) return $data;
        }

        return [];
    }

    /* ── Estadísticas ────────────────────────────────────────────────── */

    public static function get_enrichment_stats(): array {
        global $wpdb;

        // Contar total de productos publicados con una sola query SQL
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        // Contar enriquecidos con _ckg_generated = 1
        $enriched = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_ckg_generated'
             AND pm.meta_value = '1'
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );

        $queue  = self::get_queue();
        $status = self::get_status();

        return [
            'total_products' => $total,
            'enriched'       => $enriched,
            'pending_queue'  => count( array_filter( $queue, fn( $q ) => $q['status'] === 'pending' ) ),
            'done_queue'     => count( array_filter( $queue, fn( $q ) => $q['status'] === 'done' ) ),
            'failed_queue'   => count( array_filter( $queue, fn( $q ) => $q['status'] === 'failed' ) ),
            'queue_total'    => count( $queue ),
            'is_running'     => $status['running'] ?? false,
            'last_name'      => $status['last_name'] ?? '',
        ];
    }
}
