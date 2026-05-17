<?php
/**
 * CKG_Enrichment_Queue
 * Cola de enriquecimiento batch para productos WooCommerce existentes.
 *
 * Funciona procesando lotes de N productos en background via AJAX.
 * El JS llama secuencialmente: cola → procesar 1 → procesar 1 → ...
 *
 * Para cada producto genera con Ollama:
 *   - Descripcion corta mejorada
 *   - Introduccion SEO
 *   - Specs extraidas del titulo y descripcion existente
 *   - FAQ (3-5 preguntas)
 *   - SEO title y meta description
 *   - Conclusion
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Enrichment_Queue {

    const OPTION_QUEUE    = 'ckg_enrich_queue';
    const OPTION_PROGRESS = 'ckg_enrich_progress';
    const OPTION_ERRORS   = 'ckg_enrich_errors';
    const OPTION_RUNNING  = 'ckg_enrich_running';

    /* ── Crear la cola ────────────────────────────────────────────── */

    /**
     * Crea una cola con los IDs de productos que necesitan enriquecimiento.
     *
     * @param array  $filters   Filtros WC: category_id, min_price, max_price, empty_desc
     * @param int    $limit     Máximo de productos a encolar (-1 = todos)
     * @return int   Número de productos encolados
     */
    public static function build_queue( array $filters = [], int $limit = -1 ): int {
        $args = [
            'status'  => 'publish',
            'limit'   => $limit > 0 ? $limit : -1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'ids',
            'type'    => [ 'simple', 'variable' ],
        ];

        if ( ! empty( $filters['category_id'] ) ) {
            $args['category'] = (array) $filters['category_id'];
        }

        $ids = wc_get_products( $args );

        // Filtrar: solo los que no tienen descripcion completa del plugin
        if ( ! empty( $filters['only_basic'] ) ) {
            $ids = array_filter( $ids, function ( $id ) {
                // Si tiene el meta _ckg_generated, ya fue procesado
                if ( get_post_meta( $id, '_ckg_generated', true ) ) return false;
                // Si la descripcion es larga (+500 chars con HTML), probablemente ya esta enriquecido
                $desc = get_post_field( 'post_content', $id );
                return strlen( $desc ) < 500;
            } );
        }

        $ids = array_values( $ids );

        update_option( self::OPTION_QUEUE,    $ids,           false );
        update_option( self::OPTION_PROGRESS, [ 'done' => 0, 'total' => count($ids), 'errors' => 0, 'started_at' => time() ], false );
        update_option( self::OPTION_ERRORS,   [],             false );
        update_option( self::OPTION_RUNNING,  false,          false );

        return count( $ids );
    }

    /* ── Procesar siguiente producto de la cola ───────────────────── */

    /**
     * Procesa el siguiente producto de la cola.
     * Devuelve el estado actual de progreso.
     */
    public static function process_next(): array {
        $queue    = get_option( self::OPTION_QUEUE,    [] );
        $progress = get_option( self::OPTION_PROGRESS, [ 'done'=>0,'total'=>0,'errors'=>0 ] );

        if ( empty( $queue ) ) {
            update_option( self::OPTION_RUNNING, false );
            return [
                'status'   => 'finished',
                'done'     => $progress['done'],
                'total'    => $progress['total'],
                'errors'   => $progress['errors'],
                'message'  => 'Cola completada.',
            ];
        }

        // Sacar el primer producto de la cola
        $product_id = array_shift( $queue );
        update_option( self::OPTION_QUEUE, $queue, false );

        // Procesar
        $result = self::enrich_product( $product_id );

        // Actualizar progreso
        $progress['done']++;
        if ( is_wp_error( $result ) ) {
            $progress['errors']++;
            $errors   = get_option( self::OPTION_ERRORS, [] );
            $errors[] = [ 'id' => $product_id, 'error' => $result->get_error_message() ];
            update_option( self::OPTION_ERRORS, $errors, false );
        }
        update_option( self::OPTION_PROGRESS, $progress, false );

        $pct      = $progress['total'] > 0 ? round( $progress['done'] / $progress['total'] * 100 ) : 0;
        $product  = wc_get_product( $product_id );
        $name     = $product ? $product->get_name() : 'ID ' . $product_id;

        return [
            'status'      => 'processing',
            'done'        => $progress['done'],
            'total'       => $progress['total'],
            'errors'      => $progress['errors'],
            'remaining'   => count( $queue ),
            'pct'         => $pct,
            'last_product'=> $name,
            'last_id'     => $product_id,
            'success'     => ! is_wp_error( $result ),
            'message'     => is_wp_error( $result ) ? $result->get_error_message() : 'OK',
        ];
    }

    /* ── Enriquecer un producto individual ────────────────────────── */

    public static function enrich_product( int $product_id ): bool|\WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'not_found', "Producto $product_id no encontrado." );
        }

        $name     = $product->get_name();
        $brand    = '';
        $sku      = $product->get_sku();
        $existing_desc = wp_strip_all_tags( $product->get_description() );
        $existing_short = wp_strip_all_tags( $product->get_short_description() );

        // Detectar marca
        foreach ( [ 'pwb-brand', 'product_brand', 'pa_brand' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $terms = wp_get_object_terms( $product_id, $tax, [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) { $brand = $terms[0]; break; }
            }
        }

        // Homologaciones guardadas
        $homo_json = get_post_meta( $product_id, '_ckg_homologaciones', true );
        $homos     = $homo_json ? json_decode( $homo_json, true ) : [];
        $homo_text = implode( ', ', array_map( fn($h) => trim(($h['tipo']??'').' '.($h['codigo']??'')), $homos ) );

        // Contexto para Ollama
        $context  = "Producto: $name.";
        if ( $brand )          $context .= " Marca: $brand.";
        if ( $sku )            $context .= " Referencia: $sku.";
        if ( $homo_text )      $context .= " Homologaciones: $homo_text.";
        if ( $existing_short ) $context .= " Descripcion actual: $existing_short.";

        // Generar contenido con Ollama/LLM
        $s       = get_option( 'ckg_settings', [] );
        $timeout = (int) ( $s['gpu_timeout'] ?? 90 );

        $fields = [
            'short_desc'      => "Escribe en 2 frases la descripcion de venta de: \"$name\"" . ($brand?" de $brand":'') . ". Menciona: rendimiento, homologaciones si las hay ($homo_text). En espanol. Solo texto, sin markdown.",
            'intro'           => "Escribe un parrafo de introduccion SEO de 100 palabras para el producto de karting: \"$name\"" . ($brand?" de $brand":'') . ". Incluye keywords naturales. En espanol. Solo texto.",
            'conclusion'      => "Escribe un parrafo de conclusion y llamada a la accion para: \"$name\". Menciona CMSKart.es. En espanol. Solo texto.",
            'seo_title'       => "Escribe el SEO title para: \"$name\"" . ($brand?" $brand":'') . ". Maximo 60 caracteres. Solo el titulo.",
            'seo_description' => "Escribe la meta description para: \"$name\"" . ($brand?" $brand":'') . ". Entre 140-155 caracteres. Solo la descripcion.",
        ];

        $results = [];
        foreach ( $fields as $field => $prompt ) {
            $llm_result = CKG_Ollama::generate( $prompt, $s['ollama_model'] ?? 'qwen2.5', $timeout );
            if ( ! is_wp_error( $llm_result ) && ! empty( $llm_result['text'] ) ) {
                $results[ $field ] = trim( $llm_result['text'] );
            }
        }

        // Generar FAQ
        $faq_prompt = "Escribe 4 preguntas frecuentes con respuestas sobre el producto de karting \"$name\"" . ($brand?" de $brand":'') . ". Formato JSON: [{\"pregunta\":\"...\",\"respuesta\":\"...\"}]. Solo JSON.";
        $faq_result = CKG_Ollama::generate( $faq_prompt, $s['ollama_model'] ?? 'qwen2.5', $timeout );
        $faq = [];
        if ( ! is_wp_error( $faq_result ) && ! empty( $faq_result['text'] ) ) {
            $clean = preg_replace( '/```json|```/', '', $faq_result['text'] );
            $decoded = @json_decode( trim($clean), true );
            if ( is_array( $decoded ) ) $faq = $decoded;
        }

        if ( empty( $results ) ) {
            return new WP_Error( 'ollama_failed', "Ollama no respondio para $name." );
        }

        // Construir el data array para ContentBuilder
        $data = [
            'product_name'    => $name,
            'brand'           => $brand,
            'sku'             => $sku,
            'short_desc'      => $results['short_desc']      ?? $existing_short,
            'intro'           => $results['intro']           ?? '',
            'conclusion'      => $results['conclusion']      ?? '',
            'seo_title'       => $results['seo_title']       ?? '',
            'seo_description' => $results['seo_description'] ?? '',
            'specs'           => [],
            'faq'             => $faq,
            'homologacion'    => $homos,
            'caracteristicas' => [],
            'accessories'     => [],
            'categorias_uso'  => [],
            'compatibilidad'  => [],
            'catalog_url'     => '',
            'catalog_label'   => '',
            'video_url'       => '',
            'revision_date'   => '',
            'compat_image'    => '',
            'specs_image'     => '',
            'faq_image'       => '',
        ];

        // Actualizar descripcion
        $new_desc  = CKG_Content_Builder::build( $data );
        $new_short = CKG_Content_Builder::build_short_description( $data );

        wp_update_post( [
            'ID'           => $product_id,
            'post_content' => $new_desc,
            'post_excerpt' => $new_short,
        ] );

        // Actualizar SEO
        if ( ! empty( $results['seo_title'] ) ) {
            update_post_meta( $product_id, 'rank_math_title',       $results['seo_title'] );
            update_post_meta( $product_id, '_yoast_wpseo_title',    $results['seo_title'] );
        }
        if ( ! empty( $results['seo_description'] ) ) {
            update_post_meta( $product_id, 'rank_math_description',   $results['seo_description'] );
            update_post_meta( $product_id, '_yoast_wpseo_metadesc',   $results['seo_description'] );
        }

        // Social meta
        if ( class_exists( 'CKG_SocialMeta' ) ) {
            CKG_SocialMeta::apply( $product_id, $data );
        }

        // Marcar como procesado
        update_post_meta( $product_id, '_ckg_generated',    1 );
        update_post_meta( $product_id, '_ckg_enriched_at', time() );

        // Indexar en ChromaDB
        if ( class_exists( 'CKG_ChromaDB' ) && CKG_ChromaDB::ping() ) {
            CKG_ChromaDB::index_product( $product_id, $data );
        }

        return true;
    }

    /* ── Estado de la cola ────────────────────────────────────────── */

    public static function get_status(): array {
        $queue    = get_option( self::OPTION_QUEUE,    [] );
        $progress = get_option( self::OPTION_PROGRESS, [ 'done'=>0,'total'=>0,'errors'=>0,'started_at'=>0 ] );
        $errors   = get_option( self::OPTION_ERRORS,   [] );

        return [
            'queue_size'  => count( $queue ),
            'done'        => (int) $progress['done'],
            'total'       => (int) $progress['total'],
            'errors'      => (int) $progress['errors'],
            'started_at'  => $progress['started_at'] ?? 0,
            'pct'         => $progress['total'] > 0 ? round( $progress['done'] / $progress['total'] * 100 ) : 0,
            'error_list'  => array_slice( $errors, -5 ),  // ultimos 5 errores
        ];
    }

    /* ── Limpiar la cola ──────────────────────────────────────────── */

    public static function clear(): void {
        delete_option( self::OPTION_QUEUE );
        delete_option( self::OPTION_PROGRESS );
        delete_option( self::OPTION_ERRORS );
        update_option( self::OPTION_RUNNING, false );
    }
}
