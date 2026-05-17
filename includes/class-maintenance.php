<?php
/**
 * CKG_Maintenance
 * Dos funciones de mantenimiento:
 *
 * 1. Limpieza de atributos globales WooCommerce que son en realidad
 *    specs tecnicas (no variaciones con precio).
 *    Los migra a post_meta de descripcion para no perder los datos.
 *
 * 2. Revision periodica de homologaciones con Perplexity.
 *    Comprueba si las homologaciones CIK-FIA de los productos siguen
 *    vigentes para la temporada actual y genera alertas.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Maintenance {

    /* ════════════════════════════════════════════════════════════════
       LIMPIEZA DE ATRIBUTOS GLOBALES WooCommerce
    ════════════════════════════════════════════════════════════════ */

    /**
     * Escanea los atributos globales de WooCommerce y clasifica:
     *  - 'keep'     → atributos utiles (talla, color, material, etc.)
     *  - 'migrate'  → specs tecnicas que deben ir a la descripcion
     *  - 'review'   → dudosos, requieren revision manual
     */
    public static function scan_attributes(): array {
        $taxonomies = wc_get_attribute_taxonomies();
        if ( empty( $taxonomies ) ) return [ 'keep'=>[], 'migrate'=>[], 'review'=>[] ];

        // Atributos que SÍ deben quedarse como globales
        $keep_slugs = [ 'talla','color','material','compuesto','size','colour','categoria','marca','brand' ];

        $result = [ 'keep'=>[], 'migrate'=>[], 'review'=>[] ];

        foreach ( $taxonomies as $attr ) {
            $slug  = $attr->attribute_name;
            $label = $attr->attribute_label;
            $terms = get_terms( [ 'taxonomy' => 'pa_' . $slug, 'hide_empty' => false ] );
            $count = is_array($terms) ? count($terms) : 0;

            // Ver cuantos productos usan este atributo
            $products_count = self::count_products_with_attribute( 'pa_' . $slug );

            $entry = [
                'id'             => $attr->attribute_id,
                'slug'           => $slug,
                'label'          => $label,
                'terms_count'    => $count,
                'products_count' => $products_count,
                'terms'          => is_array($terms) ? wp_list_pluck($terms, 'name') : [],
            ];

            // Clasificar
            if ( in_array( strtolower($slug), $keep_slugs ) ) {
                $result['keep'][] = $entry;
            } elseif ( self::looks_like_spec( $label, $terms ) ) {
                $result['migrate'][] = $entry;
            } else {
                $result['review'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Detecta si un atributo parece una spec tecnica en lugar de una variacion.
     */
    private static function looks_like_spec( string $label, $terms ): bool {
        // Si el label contiene medidas, modelos o numeros tecnicos → spec
        $spec_patterns = [
            '/\d+\s*mm/', '/mod\./i', '/Ø/', '/^axle/i', '/^brake/i',
            '/^front/i', '/^rear/i',  '/^ring/i', '/^steering/i',
            '/^tube/i',   '/^wheel/i','/^chain/i','/^pedal/i',
            '/^seat/i',   '/^frame/i','/^hub/i',  '/^caliper/i',
            '/system$/i', '/included/i',
        ];
        foreach ( $spec_patterns as $pat ) {
            if ( preg_match($pat, $label) ) return true;
        }
        // Si tiene un solo término → probablemente spec
        if ( is_array($terms) && count($terms) <= 1 ) return true;
        // Si el primer término es muy largo → spec
        if ( is_array($terms) && !empty($terms) ) {
            $first_name = is_object($terms[0]) ? $terms[0]->name : $terms[0];
            if ( strlen($first_name) > 30 ) return true;
        }
        return false;
    }

    private static function count_products_with_attribute( string $taxonomy ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.taxonomy = %s AND p.post_type = 'product' AND p.post_status = 'publish'",
            $taxonomy
        ) );
    }

    /**
     * Migra un atributo global a post_meta de los productos que lo usan.
     * Los datos se guardan como spec tecnica en _ckg_migrated_specs.
     * El atributo global se puede eliminar de forma segura después.
     *
     * @param int $attr_id  ID del atributo a migrar
     */
    public static function migrate_attribute_to_spec( int $attr_id ): array {
        $attr = wc_get_attribute( $attr_id );
        if ( ! $attr ) return [ 'error' => 'Atributo no encontrado.' ];

        $taxonomy   = 'pa_' . $attr->slug;
        $migrated   = 0;
        $errors     = [];

        // Obtener todos los productos que tienen este atributo
        $products = get_posts( [
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'tax_query'   => [ [ 'taxonomy' => $taxonomy, 'operator' => 'EXISTS' ] ],
        ] );

        foreach ( $products as $product_post ) {
            $terms = wp_get_object_terms( $product_post->ID, $taxonomy, [ 'fields' => 'names' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            // Guardar como spec en post_meta
            $existing_specs = json_decode( get_post_meta( $product_post->ID, '_ckg_migrated_specs', true ) ?: '[]', true );
            $existing_specs[] = [
                'key'   => $attr->name,
                'value' => implode( ' / ', $terms ),
            ];
            update_post_meta( $product_post->ID, '_ckg_migrated_specs', wp_json_encode( $existing_specs ) );

            // Quitar el atributo del producto
            wp_remove_object_terms( $product_post->ID, array_map( 'sanitize_title', $terms ), $taxonomy );
            $migrated++;
        }

        return [
            'attr_name'    => $attr->name,
            'migrated'     => $migrated,
            'errors'       => $errors,
            'safe_to_delete' => $migrated > 0 && empty($errors),
        ];
    }

    /**
     * Elimina un atributo global de WooCommerce.
     * Solo llamar después de migrate_attribute_to_spec().
     */
    public static function delete_attribute( int $attr_id ): bool|\WP_Error {
        $attr = wc_get_attribute( $attr_id );
        if ( ! $attr ) return new WP_Error( 'not_found', 'Atributo no encontrado.' );

        $result = wc_delete_attribute( $attr_id );
        return $result ? true : new WP_Error( 'delete_failed', 'No se pudo eliminar el atributo.' );
    }

    /* ════════════════════════════════════════════════════════════════
       REVISION DE HOMOLOGACIONES CON PERPLEXITY
    ════════════════════════════════════════════════════════════════ */

    /**
     * Revisa las homologaciones de los productos publicados.
     * Usa Perplexity para verificar si siguen vigentes.
     * Se puede llamar manualmente o desde un cron.
     *
     * @param int $limit  Maximo de productos a revisar por ejecucion
     */
    public static function review_homologaciones( int $limit = 20 ): array {
        if ( ! class_exists('CKG_LLM') || ! CKG_LLM::perplexity_available() ) {
            return [ 'error' => 'Perplexity no configurado.' ];
        }

        // Productos con homologaciones guardadas
        $products = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'numberposts'    => $limit,
            'meta_query'     => [ [ 'key' => '_ckg_homologaciones', 'compare' => 'EXISTS' ] ],
            // Priorizar los no revisados recientemente
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_ckg_homologaciones', 'compare' => 'EXISTS' ],
                [
                    'relation' => 'OR',
                    [ 'key' => '_ckg_homos_reviewed_at', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_ckg_homos_reviewed_at', 'value' => strtotime('-30 days'), 'compare' => '<', 'type' => 'NUMERIC' ],
                ],
            ],
        ] );

        $alerts   = [];
        $reviewed = 0;

        foreach ( $products as $p ) {
            $homos = json_decode( get_post_meta( $p->ID, '_ckg_homologaciones', true ) ?: '[]', true );
            if ( empty( $homos ) ) continue;

            $name = $p->post_title;

            foreach ( $homos as &$homo ) {
                $tipo   = $homo['tipo']   ?? '';
                $codigo = $homo['codigo'] ?? '';
                if ( ! $tipo ) continue;

                // Verificar con Perplexity
                $result = CKG_LLM::verify_homologacion( $name, $tipo, $codigo );

                if ( is_wp_error( $result ) ) continue;

                $homo['last_check']     = time();
                $homo['vigente']        = $result['vigente'] ?? null;
                $homo['nota']           = $result['nota']    ?? '';
                $homo['version_actual'] = $result['version_actual'] ?? '';

                if ( $result['vigente'] === false ) {
                    $alerts[] = [
                        'product_id'      => $p->ID,
                        'product_name'    => $name,
                        'tipo'            => $tipo,
                        'codigo'          => $codigo,
                        'nota'            => $result['nota'] ?? '',
                        'version_actual'  => $result['version_actual'] ?? '',
                        'edit_url'        => get_edit_post_link( $p->ID, 'raw' ),
                    ];

                    // Guardar alerta en post_meta
                    update_post_meta( $p->ID, '_ckg_homo_alert', wp_json_encode( [
                        'tipo'   => $tipo,
                        'codigo' => $codigo,
                        'nota'   => $result['nota'] ?? '',
                        'date'   => gmdate('Y-m-d'),
                    ] ) );
                }
            }
            unset( $homo );

            // Actualizar homologaciones con los resultados
            update_post_meta( $p->ID, '_ckg_homologaciones',       wp_json_encode( $homos ) );
            update_post_meta( $p->ID, '_ckg_homos_reviewed_at',    time() );

            $reviewed++;
        }

        // Guardar resumen de alertas
        if ( ! empty( $alerts ) ) {
            $existing_alerts = get_option( 'ckg_homo_alerts', [] );
            $all_alerts      = array_merge( $existing_alerts, $alerts );
            // Mantener solo los ultimos 100
            update_option( 'ckg_homo_alerts', array_slice( $all_alerts, -100 ) );
        }

        return [
            'reviewed' => $reviewed,
            'alerts'   => $alerts,
            'total_alerts' => count( $alerts ),
        ];
    }

    /**
     * Devuelve las alertas activas de homologaciones caducadas.
     */
    public static function get_homo_alerts(): array {
        return get_option( 'ckg_homo_alerts', [] );
    }

    /**
     * Programa la revision diaria automatica (cron).
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'ckg_review_homologaciones_cron' ) ) {
            wp_schedule_event( time(), 'weekly', 'ckg_review_homologaciones_cron' );
        }
    }

    public static function unschedule_cron(): void {
        $ts = wp_next_scheduled( 'ckg_review_homologaciones_cron' );
        if ( $ts ) wp_unschedule_event( $ts, 'ckg_review_homologaciones_cron' );
    }
}
