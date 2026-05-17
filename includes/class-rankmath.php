<?php
/**
 * CKG_RankMath
 * Rellena TODOS los meta campos de RankMath para el producto creado.
 *
 * Campos cubiertos:
 *   rank_math_title
 *   rank_math_description
 *   rank_math_focus_keyword
 *   rank_math_secondary_keywords
 *   rank_math_robots             (index, follow)
 *   rank_math_canonical_url
 *   rank_math_og_title
 *   rank_math_og_description
 *   rank_math_og_image           (imagen principal del producto)
 *   rank_math_twitter_title
 *   rank_math_twitter_description
 *   rank_math_twitter_image
 *   rank_math_primary_category
 *   rank_math_breadcrumb_title
 *   rank_math_schema_{hash}      → Product schema completo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_RankMath {

    /**
     * Aplica todos los meta de RankMath al post.
     * @param int   $post_id
     * @param array $data   Datos del producto (del formulario)
     */
    public static function apply( int $post_id, array $data ): void {
        if ( ! defined( 'RANK_MATH_VERSION' ) ) return;

        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        // ── Datos base ────────────────────────────────────────────────
        $name        = sanitize_text_field( $data['product_name']    ?? '' );
        $title       = sanitize_text_field( $data['seo_title']       ?? '' );
        $desc        = sanitize_text_field( $data['seo_description'] ?? '' );
        $keyword     = sanitize_text_field( $data['seo_keywords']    ?? '' );
        $sec_kws     = sanitize_text_field( $data['seo_secondary_keywords'] ?? '' );
        $brand       = sanitize_text_field( $data['brand']           ?? '' );
        $sku         = $product->get_sku();
        $price       = $product->get_price();
        $permalink   = get_permalink( $post_id );

        // Fallbacks inteligentes
        if ( ! $title ) {
            $homo = ! empty( $data['homologacion'][0]['codigo'] )
                ? ' | ' . $data['homologacion'][0]['tipo'] . ' ' . $data['homologacion'][0]['codigo']
                : '';
            $title = $name . $homo . ' | CMSKart';
            // Truncar a 60 chars si es muy largo
            if ( strlen( $title ) > 60 ) $title = mb_substr( $name, 0, 45 ) . '… | CMSKart';
        }

        if ( ! $desc ) {
            $desc = $data['short_desc'] ?? '';
            $desc = wp_strip_all_tags( $desc );
            $desc = wp_trim_words( $desc, 22 );
            if ( $brand ) $desc .= " Marca: $brand.";
            if ( $sku )   $desc .= " Ref: $sku.";
            $desc .= ' Envío 24-72h. Compra en CMSKart.';
            if ( strlen( $desc ) > 155 ) $desc = mb_substr( $desc, 0, 152 ) . '…';
        }

        // RankMath espera UNA sola keyword en focus — separar del resto
        if ( $keyword ) {
            $kw_parts = array_map( 'trim', explode( ',', $keyword ) );
            $keyword  = $kw_parts[0]; // keyword principal
            if ( empty( $sec_kws ) && count( $kw_parts ) > 1 ) {
                $sec_kws = implode( ',', array_slice( $kw_parts, 1 ) ); // resto como secundarias
            }
        } else {
            $keyword = strtolower( $name );
            if ( $brand ) $keyword .= ' ' . strtolower( $brand );
        }

        // Imagen principal
        $img_id  = $product->get_image_id();
        $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';

        // ── Meta básicos ──────────────────────────────────────────────
        update_post_meta( $post_id, 'rank_math_title',       $title   );
        update_post_meta( $post_id, 'rank_math_description', $desc    );
        update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );

        // Secondary keywords
        if ( $sec_kws ) {
            update_post_meta( $post_id, 'rank_math_secondary_keywords', $sec_kws );
        }

        // Robots: index, follow por defecto
        update_post_meta( $post_id, 'rank_math_robots', [ 'index', 'follow' ] );

        // Canonical
        update_post_meta( $post_id, 'rank_math_canonical_url', '' ); // vacío = auto

        // Breadcrumb title
        update_post_meta( $post_id, 'rank_math_breadcrumb_title', $name );

        // ── Open Graph ────────────────────────────────────────────────
        update_post_meta( $post_id, 'rank_math_og_title',       $title );
        update_post_meta( $post_id, 'rank_math_og_description', $desc  );
        if ( $img_url ) {
            update_post_meta( $post_id, 'rank_math_og_image',   $img_url );
        }

        // ── Twitter Card ──────────────────────────────────────────────
        update_post_meta( $post_id, 'rank_math_twitter_title',       $title );
        update_post_meta( $post_id, 'rank_math_twitter_description', $desc  );
        if ( $img_url ) {
            update_post_meta( $post_id, 'rank_math_twitter_image',   $img_url );
        }
        update_post_meta( $post_id, 'rank_math_twitter_card_type', 'summary_large_image' );

        // ── Primary category ──────────────────────────────────────────
        $cat_ids = wp_get_object_terms( $post_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $cat_ids ) && ! empty( $cat_ids ) ) {
            update_post_meta( $post_id, 'rank_math_primary_category', (int) $cat_ids[0] );
        }

        // Score se actualiza al siguiente guardado manual del producto

        // ── Schema Product completo ───────────────────────────────────
        self::apply_product_schema( $post_id, $product, $data, $title, $desc, $img_url );
    }

    /* ── Schema Product ──────────────────────────────────────────────── */

    private static function apply_product_schema( int $post_id, WC_Product $product, array $data, string $title, string $desc, string $img_url ): void {
        $name      = sanitize_text_field( $data['product_name'] ?? '' );
        $brand     = sanitize_text_field( $data['brand']        ?? '' );
        $sku       = $product->get_sku();
        $price     = $product->get_price();
        $permalink = get_permalink( $post_id );

        // Construir aggregate offers para producto variable
        $wc_product = wc_get_product( $post_id );
        $min_price  = $wc_product instanceof WC_Product_Variable ? $wc_product->get_variation_price( 'min' ) : $price;
        $max_price  = $wc_product instanceof WC_Product_Variable ? $wc_product->get_variation_price( 'max' ) : $price;

        // Homologaciones como awards / description adicional
        $homo_text = '';
        if ( ! empty( $data['homologacion'] ) ) {
            $homos     = array_map( fn( $h ) => $h['tipo'] . ' ' . $h['codigo'], $data['homologacion'] );
            $homo_text = 'Homologaciones: ' . implode( ', ', $homos ) . '.';
        }

        // Hash para la clave (RankMath usa un hash corto)
        $schema_hash = strtoupper( substr( md5( 'ckg_product_' . $post_id ), 0, 6 ) );

        $schema = [
            'metadata' => [
                'title' => 'Producto',
                'type'  => 'template',
            ],
            '@type'       => 'Product',
            'name'        => $name,
            'description' => wp_strip_all_tags( $data['short_desc'] ?? $desc ),
            'url'         => $permalink,
        ];

        // Imagen
        if ( $img_url ) {
            $schema['image'] = $img_url;
        }

        // SKU
        if ( $sku ) {
            $schema['sku'] = $sku;
        }

        // Brand
        if ( $brand ) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name'  => $brand,
            ];
        }

        // Offer / AggregateOffer
        if ( $min_price && $max_price && $min_price !== $max_price ) {
            $schema['offers'] = [
                '@type'         => 'AggregateOffer',
                'lowPrice'      => (string) $min_price,
                'highPrice'     => (string) $max_price,
                'priceCurrency' => 'EUR',
                'offerCount'    => count( $wc_product instanceof WC_Product_Variable ? $wc_product->get_children() : [] ),
                'availability'  => 'https://schema.org/InStock',
                'url'           => $permalink,
                'seller'        => [
                    '@type' => 'Organization',
                    'name'  => get_bloginfo( 'name' ),
                    'url'   => get_site_url(),
                ],
            ];
        } elseif ( $price ) {
            $schema['offers'] = [
                '@type'           => 'Offer',
                'price'           => (string) $price,
                'priceCurrency'   => 'EUR',
                'availability'    => 'https://schema.org/InStock',
                'priceValidUntil' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
                'url'             => $permalink,
                'seller'          => [
                    '@type' => 'Organization',
                    'name'  => get_bloginfo( 'name' ),
                    'url'   => get_site_url(),
                ],
            ];
        }

        // Specs como additionalProperty
        if ( ! empty( $data['specs'] ) ) {
            $schema['additionalProperty'] = [];
            foreach ( array_slice( $data['specs'], 0, 10 ) as $spec ) {
                $schema['additionalProperty'][] = [
                    '@type' => 'PropertyValue',
                    'name'  => sanitize_text_field( $spec['key'] ),
                    'value' => sanitize_text_field( $spec['value'] ),
                ];
            }
        }

        // Homologaciones como award
        if ( $homo_text ) {
            $schema['award'] = $homo_text;
        }

        update_post_meta( $post_id, 'rank_math_schema_' . $schema_hash, $schema );

        // RankMath también necesita saber que hay schema
        $schemas_meta = get_post_meta( $post_id, 'rank_math_schemas_in_use', true );
        if ( ! is_array( $schemas_meta ) ) $schemas_meta = [];
        $schemas_meta[ $schema_hash ] = 'product';
        update_post_meta( $post_id, 'rank_math_schemas_in_use', $schemas_meta );
    }

    /* ── Generar secondary keywords con Ollama ───────────────────────── */

    public static function generate_lsi_keywords( string $product_name, string $brand, array $homologaciones, array $specs ): array|\WP_Error {
        if ( ! class_exists( 'CKG_Ollama' ) ) return new WP_Error( 'no_ollama', 'Ollama no disponible.' );
        if ( ! CKG_LLM::ping() ) return new WP_Error( 'offline', 'Ollama offline.' );

        $homo_text  = implode( ', ', array_map( fn( $h ) => $h['tipo'] . ' ' . $h['codigo'], $homologaciones ) );
        $specs_text = implode( ', ', array_map( fn( $s ) => $s['key'], array_slice( $specs, 0, 5 ) ) );

        $prompt = <<<PROMPT
Eres un experto SEO en karting de competición para CMSKart.es.

Producto: "$product_name"
Marca: $brand
Homologaciones: $homo_text
Specs: $specs_text

TAREA: Genera exactamente 12 keywords secundarias LSI para este producto de karting.
Criterios:
- Variaciones del nombre principal con modificadores (precio, comprar, homologado, mejor, online…)
- Términos técnicos de karting relacionados
- Long tail con intención de compra
- Incluye términos con la marca si es relevante
- Idioma: español de España

Responde SOLO en formato JSON array de strings, sin ningún texto adicional:
["keyword 1", "keyword 2", ...]
PROMPT;

        $result = CKG_LLM::improve_raw( $prompt, [ 'product_name' => $product_name ] );
        if ( is_wp_error( $result ) ) return $result;

        // Parsear JSON
        $clean = preg_replace( '/```json|```/', '', $result );
        $clean = trim( $clean );
        $decoded = json_decode( $clean, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            // Intentar extraer JSON embebido
            if ( preg_match( '/(\[.*\]|\{.*\})/s', $clean, $m ) ) {
                $decoded = json_decode( $m[0], true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                    $decoded = null;
                }
            } else {
                $decoded = null;
            }
        }

        if ( ! is_array( $decoded ) ) {
            // Intentar extraer con regex simple de strings
            preg_match_all( '/"([^\"]+)"/', $result, $m );
            $decoded = $m[1] ?? [];
        }

        return array_slice( array_filter( array_map( 'sanitize_text_field', $decoded ) ), 0, 15 );
    }
}
