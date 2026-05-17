<?php
/**
 * CKG_Attribute_Migrator
 * Limpia los atributos basura de WooCommerce.
 *
 * Problema: al importar productos se crearon 50+ atributos globales
 * que en realidad son especificaciones técnicas (AXLE Ø, WHEELBASE,
 * BRAKING SYSTEM...). Esto ralentiza WooCommerce y ensúcia la gestión.
 *
 * Solución:
 *   1. Analizar todos los atributos globales existentes
 *   2. Clasificar: ¿es variación real (talla/color/material) o spec técnica?
 *   3. Las specs técnicas: mover sus valores a la descripción del producto
 *      como filas de la tabla de specs del ContentBuilder
 *   4. Eliminar los atributos taxonomía que no son variaciones reales
 *   5. Conservar: pa_talla, pa_color, pa_material, pa_compuesto, pa_size
 *
 * IMPORTANTE: Proceso reversible — hace backup en wp_options antes de actuar.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Attribute_Migrator {

    const BACKUP_OPTION = 'ckg_attribute_migration_backup';

    // Atributos a CONSERVAR (son variaciones reales con precio)
    const KEEP_SLUGS = [
        'talla', 'size', 'color', 'colour', 'material',
        'compuesto', 'compound', 'categoria', 'category',
    ];

    /* ── Análisis — qué hay en WooCommerce ──────────────────────────── */

    /**
     * Analiza los atributos globales existentes y los clasifica.
     * No modifica nada, solo devuelve el análisis.
     */
    public static function analyze(): array {
        $all_attrs = wc_get_attribute_taxonomies();
        $result    = [
            'keep'    => [],  // atributos a conservar
            'migrate' => [],  // atributos a convertir en specs técnicas
            'total'   => count( $all_attrs ),
        ];

        foreach ( $all_attrs as $attr ) {
            $slug     = strtolower( $attr->attribute_name );
            $label    = $attr->attribute_label;
            $term_count = (int) ( get_terms( [
                'taxonomy'   => 'pa_' . $slug,
                'hide_empty' => false,
                'fields'     => 'count',
            ] ) ?: 0 );

            $is_variation = self::is_variation_attribute( $slug, $label );

            $entry = [
                'id'          => (int) $attr->attribute_id,
                'slug'        => $slug,
                'label'       => $label,
                'tax'         => 'pa_' . $slug,
                'term_count'  => $term_count,
                'is_variation'=> $is_variation,
                'reason'      => $is_variation ? 'Es variacion de precio/selector' : 'Es especificacion tecnica',
            ];

            if ( $is_variation ) {
                $result['keep'][] = $entry;
            } else {
                $result['migrate'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Determina si un atributo es una variación real o una spec técnica.
     */
    private static function is_variation_attribute( string $slug, string $label ): bool {
        // Por slug exacto
        if ( in_array( $slug, self::KEEP_SLUGS ) ) return true;

        // Por contenido del nombre
        $label_lower = strtolower( $label );
        $variation_keywords = [
            'talla', 'size', 'color', 'colour', 'color', 'material',
            'compuesto', 'compound', 'soft', 'medium', 'hard',
        ];
        foreach ( $variation_keywords as $kw ) {
            if ( str_contains( $label_lower, $kw ) ) return true;
        }

        // Si el slug es muy corto (< 6 chars) probablemente es genérico
        // pero no garantiza que sea variación

        // Por defecto: si el label es técnico/largo → spec
        if ( strlen( $label ) > 20 ) return false;
        if ( preg_match( '/[ØÐ°]/', $label ) ) return false; // símbolos técnicos
        if ( preg_match( '/\d+\s*(mm|cm|m|kg|g|cc)/', strtolower($label) ) ) return false; // medidas
        if ( str_contains( strtolower($label), 'mod.' ) ) return false;
        if ( preg_match( '/^(ok|kz|rok|rok\s)/i', $label ) ) return false; // categorías motor

        return false; // por defecto: tratar como spec salvo que coincida arriba
    }

    /* ── Migración — mover specs a post_meta de los productos ────────── */

    /**
     * Ejecuta la migración de UN atributo (para llamadas AJAX por lotes).
     *
     * @param int $attribute_id  ID del atributo a migrar
     * @param bool $dry_run      Si true, solo simula sin modificar nada
     */
    public static function migrate_attribute( int $attribute_id, bool $dry_run = false ): array {
        $attr = self::get_attribute_by_id( $attribute_id );
        if ( ! $attr ) return [ 'success' => false, 'error' => "Atributo $attribute_id no encontrado" ];

        $tax   = 'pa_' . $attr->attribute_name;
        $label = $attr->attribute_label;

        // Obtener todos los productos que tienen este atributo
        $products_with_attr = get_posts( [
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'tax_query'   => [ [ 'taxonomy' => $tax, 'operator' => 'EXISTS' ] ],
        ] );

        $migrated = 0;
        $errors   = [];

        foreach ( $products_with_attr as $product_id ) {
            // Obtener valores del atributo para este producto
            $terms = wp_get_object_terms( $product_id, $tax, [ 'fields' => 'names' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            $value = implode( ', ', $terms );

            if ( ! $dry_run ) {
                // Guardar como spec técnica en post_meta
                $existing_specs = json_decode( get_post_meta( $product_id, '_ckg_migrated_specs', true ) ?: '[]', true );
                $existing_specs[] = [ 'key' => $label, 'value' => $value ];
                update_post_meta( $product_id, '_ckg_migrated_specs', wp_json_encode( $existing_specs ) );

                // Eliminar el término del producto (pero no el atributo global todavía)
                wp_remove_object_terms( $product_id, array_map( fn($t) => $t, wp_get_object_terms( $product_id, $tax, [ 'fields' => 'ids' ] ) ), $tax );
            }

            $migrated++;
        }

        if ( ! $dry_run && empty( $errors ) ) {
            // Eliminar el atributo global
            wc_delete_attribute( $attribute_id );
        }

        return [
            'success'   => true,
            'attribute' => $label,
            'migrated'  => $migrated,
            'dry_run'   => $dry_run,
            'errors'    => $errors,
        ];
    }

    /**
     * Hacer backup completo antes de migrar.
     */
    public static function backup(): bool {
        $all_attrs = wc_get_attribute_taxonomies();
        $backup    = [];

        foreach ( $all_attrs as $attr ) {
            $tax   = 'pa_' . $attr->attribute_name;
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );

            $backup[] = [
                'id'    => $attr->attribute_id,
                'name'  => $attr->attribute_name,
                'label' => $attr->attribute_label,
                'terms' => ! is_wp_error( $terms ) ? wp_list_pluck( $terms, 'name' ) : [],
            ];
        }

        return update_option( self::BACKUP_OPTION, [
            'date'  => current_time( 'mysql' ),
            'attrs' => $backup,
        ] );
    }

    public static function has_backup(): bool {
        return ! empty( get_option( self::BACKUP_OPTION ) );
    }

    public static function get_backup_date(): string {
        $b = get_option( self::BACKUP_OPTION, [] );
        return $b['date'] ?? '';
    }

    private static function get_attribute_by_id( int $id ) {
        foreach ( wc_get_attribute_taxonomies() as $attr ) {
            if ( (int) $attr->attribute_id === $id ) return $attr;
        }
        return null;
    }
}
