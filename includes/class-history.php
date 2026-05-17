<?php
/**
 * CKG_History
 * Gestión del historial de productos creados con el plugin.
 * También proporciona la funcionalidad de clonar un producto existente.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_History {

    const OPTION_KEY = 'ckg_product_history';
    const MAX_ITEMS  = 50;

    /* ── Registrar producto en historial ──────────────────────────── */
    public static function record( int $product_id, array $data, int $seo_score = 0 ): void {
        $history = get_option( self::OPTION_KEY, [] );

        // Eliminar entrada previa del mismo producto si existe
        $history = array_filter( $history, fn( $h ) => $h['product_id'] !== $product_id );

        array_unshift( $history, [
            'product_id'    => $product_id,
            'product_name'  => sanitize_text_field( $data['product_name'] ?? '' ),
            'brand'         => sanitize_text_field( $data['brand']        ?? '' ),
            'sku'           => sanitize_text_field( $data['sku']          ?? '' ),
            'seo_score'     => $seo_score,
            'keyword'       => sanitize_text_field( $data['seo_keywords'] ?? '' ),
            'template_used' => sanitize_text_field( $data['template_id']  ?? '' ),
            'created_at'    => current_time( 'Y-m-d H:i' ),
            'has_story'     => (bool) CKG_Web_Story::get_story_id( $product_id ),
            'status'        => get_post_status( $product_id ),
            'spec_count'    => count( $data['specs']        ?? [] ),
            'faq_count'     => count( $data['faq']          ?? [] ),
            'homo_count'    => count( $data['homologacion'] ?? [] ),
            'image_count'   => count( array_filter( $data['images'] ?? [] ) ),
        ] );

        $history = array_slice( $history, 0, self::MAX_ITEMS );
        update_option( self::OPTION_KEY, $history );
    }

    /* ── Obtener historial ────────────────────────────────────────── */
    public static function get( int $limit = 20 ): array {
        $history = get_option( self::OPTION_KEY, [] );

        // Enriquecer con datos actuales de WooCommerce
        $enriched = [];
        foreach ( array_slice( $history, 0, $limit ) as $item ) {
            $product = wc_get_product( $item['product_id'] );
            if ( ! $product ) continue; // producto eliminado
            if ( get_post_status( $item['product_id'] ) === 'trash' ) continue; // en papelera

            $item['status']     = get_post_status( $item['product_id'] );
            $item['edit_url']   = get_edit_post_link( $item['product_id'], 'raw' );
            $item['product_url']= get_permalink( $item['product_id'] );
            $item['image_url']  = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
            $item['price_html'] = $product->get_price_html();
            $item['has_story']  = (bool) CKG_Web_Story::get_story_id( $item['product_id'] );

            $story_id = CKG_Web_Story::get_story_id( $item['product_id'] );
            $item['story_url']  = $story_id ? get_permalink( $story_id ) : '';
            $item['story_edit'] = $story_id ? get_edit_post_link( $story_id, 'raw' ) : '';

            $enriched[] = $item;
        }

        return $enriched;
    }

    /* ── Clonar producto existente → datos para el formulario ──────── */
    public static function clone_product( int $product_id ): array|\WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'not_found', 'Producto no encontrado.' );
        }

        // ── Datos básicos ──────────────────────────────────────────
        $name  = $product->get_name();
        $brand = '';

        // Intentar obtener marca
        $brand_taxonomies = [ 'pwb-brand', 'product_brand', 'pa_brand', 'brand' ];
        foreach ( $brand_taxonomies as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $terms = wp_get_object_terms( $product_id, $tax, [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $brand = $terms[0];
                    break;
                }
            }
        }

        // ── Imágenes ───────────────────────────────────────────────
        $images = [];
        $img_id = $product->get_image_id();
        if ( $img_id ) $images[] = wp_get_attachment_url( $img_id );
        foreach ( $product->get_gallery_image_ids() as $gid ) {
            $url = wp_get_attachment_url( $gid );
            if ( $url ) $images[] = $url;
        }

        // ── Categorías ─────────────────────────────────────────────
        $cat_terms = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $categories = is_wp_error( $cat_terms ) ? [] : $cat_terms;

        // ── Tags ───────────────────────────────────────────────────
        $tag_terms = wp_get_object_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
        $tags = is_wp_error( $tag_terms ) ? [] : $tag_terms;

        // ── Variaciones ────────────────────────────────────────────
        $variations     = [];
        $attribute_name = '';

        if ( $product instanceof WC_Product_Variable ) {
            $attributes = $product->get_attributes();
            foreach ( $attributes as $attr ) {
                if ( $attr->get_variation() ) {
                    $attribute_name = $attr->get_name();
                    break;
                }
            }

            foreach ( $product->get_children() as $var_id ) {
                $variation = wc_get_product( $var_id );
                if ( ! $variation ) continue;
                $attrs  = $variation->get_attributes();
                $label  = reset( $attrs ) ?: '';
                $variations[] = [
                    'label'      => $label,
                    'price'      => $variation->get_regular_price(),
                    'price_sale' => $variation->get_sale_price(),
                    'sku'        => $variation->get_sku(),
                ];
            }
        }

        // ── Specs (desde meta _ckg o desde atributos WC) ───────────
        $specs = [];
        foreach ( $product->get_attributes() as $attr ) {
            if ( ! $attr->get_variation() ) {
                $specs[] = [
                    'key'   => $attr->get_name(),
                    'value' => implode( ', ', $attr->get_options() ),
                ];
            }
        }

        // ── Accesorios ─────────────────────────────────────────────
        $acc_json    = get_post_meta( $product_id, '_ckg_accessories', true );
        $accessories = $acc_json ? json_decode( $acc_json, true ) : [];

        // ── Homologaciones ──────────────────────────────────────────
        $homo_json     = get_post_meta( $product_id, '_ckg_homologaciones', true );
        $homologaciones = $homo_json ? json_decode( $homo_json, true ) : [];

        // ── Compatibilidad ──────────────────────────────────────────
        $compat_json  = get_post_meta( $product_id, '_ckg_compatibilidad', true );
        $compatibilidad = $compat_json ? json_decode( $compat_json, true ) : [];

        // ── Descripción (parsear secciones del HTML) ───────────────
        $description = $product->get_description();
        $short_desc  = wp_strip_all_tags( $product->get_short_description() );

        // ── SEO (RankMath o Yoast) ─────────────────────────────────
        $seo_title = get_post_meta( $product_id, 'rank_math_title',       true )
                  ?: get_post_meta( $product_id, '_yoast_wpseo_title',    true );
        $seo_desc  = get_post_meta( $product_id, 'rank_math_description', true )
                  ?: get_post_meta( $product_id, '_yoast_wpseo_metadesc', true );
        $seo_kw    = get_post_meta( $product_id, 'rank_math_focus_keyword', true )
                  ?: get_post_meta( $product_id, '_yoast_wpseo_focuskw',  true );
        $seo_2kw   = get_post_meta( $product_id, 'rank_math_secondary_keywords', true );

        return [
            'cloned_from'    => $product_id,
            'product_name'   => $name . ' (copia)',
            'product_slug'   => '',
            'brand'          => $brand,
            'sku'            => $product->get_sku() . '-COPIA',
            'categories'     => $categories,
            'tags'           => $tags,
            'images'         => $images,
            'short_desc'     => $short_desc,
            'attribute_name' => $attribute_name,
            'variations'     => $variations,
            'specs'          => $specs,
            'homologacion'   => $homologaciones,
            'compatibilidad' => $compatibilidad,
            'accessories'    => $accessories,
            'seo_title'      => $seo_title,
            'seo_description'=> $seo_desc,
            'seo_keywords'   => $seo_kw,
            'seo_secondary_keywords' => $seo_2kw,
            'draft'          => true,
        ];
    }
}
