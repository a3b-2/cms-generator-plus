<?php
/**
 * CKG_SocialMeta
 * Rellena automáticamente todos los campos de redes sociales,
 * Google Shopping y feeds de producto al crear un producto WooCommerce.
 *
 * Cubre:
 *  ① WooCommerce nativo     → GTIN, MPN, brand, condition, global_unique_id
 *  ② Open Graph manual      → og:title, og:description, og:image, product:*
 *  ③ Facebook for WC        → _wc_facebook_product_image, _description, _commerce_*
 *  ④ Google Listings & Ads  → gla_visibility, gla_brand, gla_gtin, gla_mpn
 *  ⑤ Google Product Feed    → _woosea_* (AdTribes/WooFeed), _yith_gf_*
 *  ⑥ Pinterest for WC       → _wc_pinterest_*
 *  ⑦ Twitter Cards           → ya en RankMath, añadimos fallback Yoast
 *  ⑧ WooCommerce SEO (Yoast) → _yoast_wpseo_og-*, _wpseo_*
 *  ⑨ Schema.org nativo WC   → campos extras para rich snippets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_SocialMeta {

    /**
     * Punto de entrada principal.
     * Llamar después de crear el producto con CKG_Woo_Creator::create()
     */
    public static function apply( int $product_id, array $data ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $name      = sanitize_text_field( $data['product_name']    ?? $product->get_name() );
        $brand     = sanitize_text_field( $data['brand']           ?? '' );
        $sku       = $product->get_sku();
        $price     = $product->get_price();
        $desc_raw  = $data['short_desc'] ?? wp_strip_all_tags( $product->get_short_description() );
        $desc      = wp_strip_all_tags( $desc_raw );
        $desc      = wp_trim_words( $desc, 25 );
        $permalink = get_permalink( $product_id );

        // Imagen principal
        $img_id    = $product->get_image_id();
        $img_url   = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';
        $img_full  = $img_id ? wp_get_attachment_image_url( $img_id, 'full'  ) : '';

        // Galería
        $gallery_urls = array_filter( array_map(
            fn( $id ) => wp_get_attachment_image_url( $id, 'large' ),
            array_slice( $product->get_gallery_image_ids(), 0, 4 )
        ) );

        // Categorías
        $cat_terms = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $cat_name  = ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ? $cat_terms[0] : '';

        // Homologaciones (para descripción enriquecida)
        $homo_text = '';
        if ( ! empty( $data['homologacion'] ) ) {
            $homos     = array_slice( $data['homologacion'], 0, 2 );
            $homo_text = implode( ' · ', array_map(
                fn( $h ) => trim( ( $h['tipo'] ?? '' ) . ' ' . ( $h['codigo'] ?? '' ) ),
                $homos
            ) );
        }

        // Descripción enriquecida para redes (desc + homologaciones)
        $social_desc = $desc . ( $homo_text ? ' · ' . $homo_text : '' );
        if ( strlen( $social_desc ) > 200 ) $social_desc = mb_substr( $social_desc, 0, 197 ) . '…';

        // Título social (más corto, sin "| CMSKart")
        $social_title = $name . ( $brand ? ' — ' . $brand : '' );

        // ── ① WooCommerce nativo (8.x+ GTIN / MPN) ─────────────────────
        self::apply_wc_native( $product_id, $product, $data, $brand, $homo_text );

        // ── ② Open Graph manual (fallback sin plugin SEO) ───────────────
        self::apply_open_graph( $product_id, $social_title, $social_desc, $img_url, $img_full, $price, $brand, $sku, $permalink, $gallery_urls );

        // ── ③ Facebook for WooCommerce (plugin oficial de Meta) ──────────
        self::apply_facebook_for_wc( $product_id, $product, $name, $social_desc, $img_url, $img_full, $brand, $sku );

        // ── ④ Google Listings & Ads (plugin oficial de WooCommerce) ──────
        self::apply_google_listings( $product_id, $product, $brand, $sku, $cat_name, $data );

        // ── ⑤ Plugins de Product Feed ────────────────────────────────────
        self::apply_feed_plugins( $product_id, $product, $data, $brand, $social_desc, $img_full ?: $img_url );

        // ── ⑥ Pinterest for WooCommerce ───────────────────────────────────
        self::apply_pinterest( $product_id, $social_title, $social_desc, $img_url, $brand, $price );

        // ── ⑦ Yoast SEO — campos OG y Twitter adicionales ────────────────
        self::apply_yoast_social( $product_id, $data, $social_title, $social_desc, $img_url );

        // ── ⑧ Schema.org adicional (nativo WC + plugins) ─────────────────
        self::apply_schema_extras( $product_id, $product, $data, $brand );
    }

    /* ── ① WooCommerce nativo ───────────────────────────────────────────── */

    private static function apply_wc_native( int $id, WC_Product $product, array $data, string $brand, string $homo_text ): void {
        $sku   = $product->get_sku();
        $price = (float) $product->get_price();

        // ── WC 8.0+ GTIN nativo ─────────────────────────────────────
        if ( method_exists( $product, 'set_global_unique_id' ) && $sku && ! $product->get_global_unique_id() ) {
            $product->set_global_unique_id( $sku );
            $product->save();
        }

        // ── Homologaciones como campo legible ─────────────────────────
        if ( $homo_text ) {
            update_post_meta( $id, '_ckg_homologaciones_text', $homo_text );
        }
        if ( $price > 0 ) {
            update_post_meta( $id, '_ckg_price_eur', $price );
        }
    }

    /* ── ② Open Graph manual (og: en post meta, leído por muchos plugins) ── */

    private static function apply_open_graph(
        int $id, string $title, string $desc,
        string $img_url, string $img_full,
        string $price, string $brand, string $sku,
        string $url, array $gallery
    ): void {
        // Campos OG estándar
        update_post_meta( $id, '_og_title',       $title );
        update_post_meta( $id, '_og_description', $desc  );
        if ( $img_url ) update_post_meta( $id, '_og_image', $img_url );

        // Facebook Open Graph — product namespace
        update_post_meta( $id, '_og_type',                    'product'  );
        update_post_meta( $id, '_og_product:availability',    'in stock' );
        update_post_meta( $id, '_og_product:condition',       'new'      );
        if ( $price ) {
            update_post_meta( $id, '_og_product:price:amount',   $price );
            update_post_meta( $id, '_og_product:price:currency', 'EUR'  );
        }
        if ( $brand ) update_post_meta( $id, '_og_product:brand',            $brand );
        if ( $sku   ) update_post_meta( $id, '_og_product:retailer_item_id', $sku   );

        // Imágenes de galería para carrusel social
        if ( ! empty( $gallery ) ) {
            update_post_meta( $id, '_og_images', array_values( $gallery ) );
        }
    }

    /* ── ③ Facebook for WooCommerce (plugin oficial de Meta/Facebook) ───── */

    private static function apply_facebook_for_wc(
        int $id, WC_Product $product,
        string $name, string $desc,
        string $img_url, string $img_full,
        string $brand, string $sku
    ): void {
        $sku_val = $product->get_sku() ?: $sku;

        // ── Campos exactos de Facebook for WooCommerce (confirmados en UI) ─

        // Sincronizacion: sync-and-show / sync-and-hide / not-synced
        update_post_meta( $id, '_wc_facebook_visibility', 'sync-and-show' );

        // Descripcion de Facebook
        if ( $desc ) {
            update_post_meta( $id, '_wc_facebook_product_description', $desc );
        }

        // Imagen del producto de Facebook
        $fb_img = $img_full ?: $img_url;
        // Fallback: obtener imagen directamente del producto si no viene en $data
        if ( ! $fb_img ) {
            $img_id_fb = $product->get_image_id();
            if ( $img_id_fb ) {
                $fb_img = wp_get_attachment_image_url( $img_id_fb, 'full' )
                       ?: wp_get_attachment_image_url( $img_id_fb, 'large' );
            }
        }
        if ( $fb_img ) {
            update_post_meta( $id, '_wc_facebook_product_image',        $fb_img );
            update_post_meta( $id, '_wc_facebook_product_image_source', 'other' );
        }

        // Precio de Facebook
        $price = (float) $product->get_price();
        if ( $price > 0 ) {
            update_post_meta( $id, '_wc_facebook_price', (string) $price );
        }

        // MPN — Numero de pieza del fabricante
        if ( $sku_val ) {
            update_post_meta( $id, '_wc_facebook_mpn', $sku_val );
        }

        // Marca
        if ( $brand ) {
            update_post_meta( $id, '_wc_facebook_brand', $brand );
        }

        // Estado: new / refurbished / used
        update_post_meta( $id, '_wc_facebook_product_condition', 'new' );

        // Grupo de edad: adult / all ages / teen / child / toddler / infant / newborn
        update_post_meta( $id, '_wc_facebook_age_group', 'adult' );

        // Genero: female / male / unisex
        update_post_meta( $id, '_wc_facebook_gender', 'unisex' );

        // Categoria de productos de Google (campo compartido en la UI de Facebook)
        // 3315 = Sporting Goods > Motor Sports
        update_post_meta( $id, '_wc_facebook_google_product_category', '3315' );

        // Sync habilitado
        update_post_meta( $id, '_wc_facebook_sync_enabled',    'yes' );
        update_post_meta( $id, '_wc_facebook_sync_enabled_v2', 'yes' );
        update_post_meta( $id, '_wc_facebook_commerce_enabled','yes' );

        // ── Campos fb_* (segundo conjunto que usa Facebook for WooCommerce) ──
        // Confirmados vacios en produccion — hay que rellenarlos tambien
        update_post_meta( $id, 'fb_product_condition',    'new' );
        update_post_meta( $id, 'fb_age_group',            'adult' );
        update_post_meta( $id, 'fb_gender',               'unisex' );
        update_post_meta( $id, 'fb_visibility',           'yes' );

        if ( $desc ) {
            update_post_meta( $id, 'fb_product_description',   $desc );
            update_post_meta( $id, 'fb_rich_text_description', $desc );
        }
        if ( $brand ) {
            update_post_meta( $id, 'fb_brand', $brand );
        }
        if ( $sku_val ) {
            update_post_meta( $id, 'fb_mpn', $sku_val );
        }
        // fb_color, fb_material, fb_size, fb_pattern — se rellenan si vienen en $data
        // por ahora se dejan vacios (no fuerzan valores incorrectos)
    }

    /* ── ④ Google Listings & Ads (plugin oficial WooCommerce/Google) ─────── */

    private static function apply_google_listings(
        int $id, WC_Product $product,
        string $brand, string $sku, string $cat_name,
        array $data = []
    ): void {
        $sku_val = $product->get_sku() ?: $sku;

        // ── Campos exactos de Google for WooCommerce (confirmados en UI) ───

        // GTIN — Global Trade Item Number
        if ( $sku_val ) {
            update_post_meta( $id, '_wc_gla_gtin', $sku_val );
        }

        // MPN — Manufacturer Part Number
        if ( $sku_val ) {
            update_post_meta( $id, '_wc_gla_mpn', $sku_val );
        }

        // Marca
        if ( $brand ) {
            update_post_meta( $id, '_wc_gla_brand', $brand );
        }

        // Condicion: new / refurbished / used
        update_post_meta( $id, '_wc_gla_product_condition', 'new' );

        // Genero: unisex para karting (aplica a todos)
        update_post_meta( $id, '_wc_gla_gender', '' ); // vacio = Por defecto (no forzar genero)

        // Grupo de edad: adult
        update_post_meta( $id, '_wc_gla_age_group', '' ); // vacio = Por defecto

        // Sistema de tallas: EU para Europa
        update_post_meta( $id, '_wc_gla_size_system', 'EU' );

        // Tipo de talla: normal
        update_post_meta( $id, '_wc_gla_size_type', 'regular' );

        // Es lote: no
        update_post_meta( $id, '_wc_gla_is_bundle', 'no' );

        // Contenido para adultos: no
        update_post_meta( $id, '_wc_gla_adult_content', 'no' );

        // Categoria Google Shopping — Deportes > Karting
        // 3315 = Sporting Goods > Individual Sports > Motor Sports
        update_post_meta( $id, '_wc_gla_google_product_category', '3315' );

        // Sincronizacion — mostrar en Shopping
        update_post_meta( $id, '_wc_gla_visibility', 'sync-and-show' );

        // Color y material si vienen en los datos
        if ( ! empty( $data['color'] ?? '' ?? '' ) ) {
            update_post_meta( $id, '_wc_gla_color', $data['color'] ?? '' );
        }
        if ( ! empty( $data['material'] ?? '' ?? '' ) ) {
            update_post_meta( $id, '_wc_gla_material', $data['material'] ?? '' );
        }
    }

    /* ── ⑤ Plugins de Product Feed ──────────────────────────────────────── */

    private static function apply_feed_plugins(
        int $id, WC_Product $product,
        array $data, string $brand,
        string $desc, string $img_url
    ): void {
        $sku   = $product->get_sku();
        $price = (float) $product->get_price();
        $name  = $product->get_name();

        // ── AdTribes / WooCommerce Product Feed ──────────────────────────
        // (plugin: woo-product-feed-pro)
        $woosea = [
            '_woosea_title'        => $name,
            '_woosea_description'  => $desc,
            '_woosea_image'        => $img_url,
            '_woosea_brand'        => $brand,
            '_woosea_condition'    => 'new',
            '_woosea_availability' => 'in stock',
            '_woosea_mpn'          => $sku,
            '_woosea_gtin'         => $sku,
        ];
        foreach ( $woosea as $meta_key => $meta_val ) {
            if ( $meta_val ) update_post_meta( $id, $meta_key, $meta_val );
        }

        // ── CTX Feed / WooFeed (plugin: webappick-product-feed-for-woocommerce) ─
        $ctx_fields = [
            '_ctx_feed_title'          => $name,
            '_ctx_feed_description'    => $desc,
            '_ctx_feed_image'          => $img_url,
            '_ctx_feed_brand'          => $brand,
            '_ctx_feed_condition'      => 'new',
            '_ctx_feed_availability'   => 'in stock',
            '_ctx_feed_mpn'            => $sku,
            '_ctx_feed_gtin'           => $sku,
            '_ctx_feed_identifier_exists' => 'yes',
        ];
        foreach ( $ctx_fields as $k => $v ) {
            if ( $v ) update_post_meta( $id, $k, $v );
        }

        // ── YITH Google Product Feed ──────────────────────────────────────
        $yith_fields = [
            '_yith_gf_product_title'       => $name,
            '_yith_gf_description'         => $desc,
            '_yith_gf_image'               => $img_url,
            '_yith_gf_brand'               => $brand,
            '_yith_gf_condition'           => 'new',
            '_yith_gf_identifier_exists'   => 'yes',
            '_yith_gf_gtin'                => $sku,
            '_yith_gf_mpn'                 => $sku,
            '_yith_gf_availability'        => 'in stock',
            '_yith_gf_google_category'     => 'Sporting Goods > Motor Sports',
        ];
        foreach ( $yith_fields as $k => $v ) {
            if ( $v ) update_post_meta( $id, $k, $v );
        }

        // ── WP Product Feed Manager ───────────────────────────────────────
        update_post_meta( $id, 'wpfm_product_visibility', 'on' );
        if ( $brand ) update_post_meta( $id, 'wpfm_brand', $brand );
        update_post_meta( $id, 'wpfm_condition', 'New' );

        // ── Pixel Cat / PixelYourSite ─────────────────────────────────────
        update_post_meta( $id, '_pixelyoursite_product_brand',       $brand );
        update_post_meta( $id, '_pixelyoursite_product_condition',   'new'  );
        if ( $sku ) update_post_meta( $id, '_pixelyoursite_product_gtin', $sku );
    }

    /* ── ⑥ Pinterest for WooCommerce ────────────────────────────────────── */

    private static function apply_pinterest(
        int $id, string $title, string $desc,
        string $img_url, string $brand, string $price
    ): void {
        // ── Campos exactos de Pinterest for WooCommerce (confirmados en UI) ─

        // Condicion: new / refurbished / used (valor vacio = Predeterminado)
        update_post_meta( $id, '_wc_pinterest_product_condition', 'new' );

        // Categoria de Google (campo compartido con Google/Facebook)
        // 3315 = Sporting Goods > Motor Sports
        update_post_meta( $id, '_wc_pinterest_google_product_category', '3315' );

        // Campos de sincronizacion
        update_post_meta( $id, '_wc_pinterest_sync_enabled', 'yes' );

        // Imagen
        if ( $img_url ) {
            update_post_meta( $id, '_wc_pinterest_product_image', $img_url );
        }
    }

    /* ── ⑦ Yoast SEO campos sociales adicionales ────────────────────────── */

    private static function apply_yoast_social(
        int $id, array $data,
        string $title, string $desc, string $img_url
    ): void {
        if ( ! defined( 'WPSEO_VERSION' ) ) return;

        $img_id = get_post_thumbnail_id( $id ) ?: 0;

        // Open Graph
        update_post_meta( $id, '_yoast_wpseo_opengraph-title',       $title );
        update_post_meta( $id, '_yoast_wpseo_opengraph-description',  $desc  );
        if ( $img_url ) {
            update_post_meta( $id, '_yoast_wpseo_opengraph-image',     $img_url );
            update_post_meta( $id, '_yoast_wpseo_opengraph-image-id',  $img_id  );
        }

        // Twitter Cards
        update_post_meta( $id, '_yoast_wpseo_twitter-title',         $title );
        update_post_meta( $id, '_yoast_wpseo_twitter-description',    $desc  );
        update_post_meta( $id, '_yoast_wpseo_twitter-card',          'summary_large_image' );
        if ( $img_url ) {
            update_post_meta( $id, '_yoast_wpseo_twitter-image',      $img_url );
            update_post_meta( $id, '_yoast_wpseo_twitter-image-id',   $img_id  );
        }

        // Yoast WooCommerce SEO (plugin de pago)
        if ( defined( 'WPSEO_WOO_VERSION' ) ) {
            $brand = $data['brand'] ?? '';
            $sku   = $data['sku']   ?? '';
            if ( $brand ) update_post_meta( $id, '_yoast_wpseo_wc_brand',        $brand );
            if ( $sku )   update_post_meta( $id, '_yoast_wpseo_wc_manufacturer', $brand );
            update_post_meta( $id, '_yoast_wpseo_wc_product_condition', 'https://schema.org/NewCondition' );
        }
    }

    /* ── ⑧ Schema.org extras ────────────────────────────────────────────── */

    private static function apply_schema_extras(
        int $id, WC_Product $product,
        array $data, string $brand
    ): void {
        $sku   = $product->get_sku();
        $price = (float) $product->get_price();

        // Campos leídos por plugins de schema genéricos y WC nativo
        if ( $brand ) {
            update_post_meta( $id, 'schema_brand',  $brand );
            update_post_meta( $id, 'product_brand', $brand );
        }
        if ( $sku ) {
            update_post_meta( $id, 'schema_sku', $sku );
        }
        update_post_meta( $id, 'schema_condition',    'https://schema.org/NewCondition' );
        update_post_meta( $id, 'schema_availability', 'https://schema.org/InStock' );

        if ( $price > 0 ) {
            update_post_meta( $id, 'schema_price',          (string) $price );
            update_post_meta( $id, 'schema_price_currency', 'EUR' );
        }

        // All in One SEO
        if ( class_exists( 'AIOSEO' ) || defined( 'AIOSEO_VERSION' ) ) {
            update_post_meta( $id, '_aioseo_title',         sanitize_text_field( $data['seo_title'] ?? '' ) );
            update_post_meta( $id, '_aioseo_description',   sanitize_text_field( $data['seo_description'] ?? '' ) );
            update_post_meta( $id, '_aioseo_og_title',      sanitize_text_field( $data['seo_title'] ?? '' ) );
            update_post_meta( $id, '_aioseo_og_description',sanitize_text_field( $data['seo_description'] ?? '' ) );
            if ( $brand ) update_post_meta( $id, '_aioseo_og_article_section', $brand );
        }

        // SEOPress
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            update_post_meta( $id, '_seopress_titles_title',     sanitize_text_field( $data['seo_title'] ?? '' ) );
            update_post_meta( $id, '_seopress_titles_desc',      sanitize_text_field( $data['seo_description'] ?? '' ) );
            update_post_meta( $id, '_seopress_social_fb_title',  sanitize_text_field( $data['seo_title'] ?? '' ) );
            update_post_meta( $id, '_seopress_social_fb_desc',   sanitize_text_field( $data['seo_description'] ?? '' ) );
            update_post_meta( $id, '_seopress_social_tw_title',  sanitize_text_field( $data['seo_title'] ?? '' ) );
            update_post_meta( $id, '_seopress_social_tw_desc',   sanitize_text_field( $data['seo_description'] ?? '' ) );
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       DETECCIÓN: qué plugins sociales están activos
    ════════════════════════════════════════════════════════════════════ */

    public static function detect_active_plugins(): array {
        return array_filter( [
            'facebook_wc'      => class_exists( 'WC_Facebookcommerce' ) || defined( 'WC_FACEBOOK_PLUGIN_FILE' ),
            'google_listings'  => class_exists( 'Automattic\WooCommerce\GoogleListingsAndAds\Plugin' ) || defined( 'WC_GLA_VERSION' ),
            'rankmath'         => defined( 'RANK_MATH_VERSION' ),
            'yoast'            => defined( 'WPSEO_VERSION' ),
            'yoast_woo'        => defined( 'WPSEO_WOO_VERSION' ),
            'aioseo'           => defined( 'AIOSEO_VERSION' ),
            'seopress'         => defined( 'SEOPRESS_VERSION' ),
            'pinterest_wc'     => class_exists( 'Pinterest_For_Woocommerce' ) || defined( 'PINTEREST_FOR_WOOCOMMERCE_VERSION' ),
            'adtribes_feed'    => class_exists( 'WooSEA' ) || defined( 'WOOSEA_VERSION' ),
            'ctxfeed'          => defined( 'WFCM_PLUGIN_FILE' ) || class_exists( 'CTXFeed' ),
            'yith_feed'        => defined( 'YITH_GOOGLE_PRODUCT_FEED_VERSION' ),
            'wpfm'             => defined( 'WPFM_PLUGIN_VERSION' ),
            'pixel_cat'        => defined( 'PIXELYOURSITE_VERSION' ),
        ] );
    }
}
