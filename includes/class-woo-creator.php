<?php
/**
 * CKG_Woo_Creator
 * Crea el producto WooCommerce para CMSKart.es
 *
 * Compatible con taxonomías de marca (pwb-brand / pa_brand)
 *  - Meta: _ckg_accessories (accesorios relacionados)
 *  - Meta: _ckg_compatibilidad (JSON chasis + categorías)
 *  - Meta: _ckg_homologaciones (JSON array de homologaciones)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Woo_Creator {

    public static function create( array $data ) {
        try {
        // ── Producto base ──────────────────────────────────────────────────
        $product = new WC_Product_Variable();
        $product->set_name(   sanitize_text_field( $data['product_name'] ) );
        $product->set_slug(   sanitize_title( $data['product_slug'] ?: $data['product_name'] ) );
        $product->set_status( $data['draft'] ? 'draft' : 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_sku(    sanitize_text_field( $data['sku'] ?? '' ) );
        $product->set_manage_stock( false );
        $product->set_reviews_allowed( true );

        // ── Descripciones ──────────────────────────────────────────────────
        $product->set_short_description( CKG_Content_Builder::build_short_description( $data ) );
        $product->set_description(       CKG_Content_Builder::build( $data ) );

        // ── Imágenes ───────────────────────────────────────────────────────
        // Limpiar URLs (Windows puede añadir \r en los saltos de línea del textarea)
        $clean_images = array_filter( array_map( 'trim', $data['images'] ?? [] ) );
        $gallery_ids  = self::get_attachment_ids( $clean_images );
        if ( ! empty( $gallery_ids ) ) {
            $product->set_image_id( $gallery_ids[0] );
            if ( count( $gallery_ids ) > 1 ) {
                $product->set_gallery_image_ids( array_slice( $gallery_ids, 1 ) );
            }
        }

        // ── Atributo variaciones ───────────────────────────────────────────
        $attr_name = sanitize_text_field( $data['attribute_name'] ?? 'Talla' );
        $vars      = $data['variations'] ?? [];

        if ( ! empty( $vars ) ) {
            $attr = new WC_Product_Attribute();
            $attr->set_name( $attr_name );
            $attr->set_options( array_column( $vars, 'label' ) );
            $attr->set_visible( true );
            $attr->set_variation( true );
            $product->set_attributes( [ $attr ] );
        }

        // ── Primer guardado ────────────────────────────────────────────────
        $product_id = $product->save();
        if ( ! $product_id ) {
            return new WP_Error( 'ckg_save_failed', 'No se pudo guardar el producto.' );
        }

        // ── Categorías ─────────────────────────────────────────────────────
        if ( ! empty( $data['categories'] ) ) {
            $cat_ids = self::get_or_create_terms( $data['categories'], 'product_cat' );
            wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );
        }

        // ── Marca ──────────────────────────────────────────────────────────
        if ( ! empty( $data['brand'] ) ) {
            $tax = self::detect_brand_taxonomy();
            if ( $tax ) {
                $bid = self::get_or_create_term( $data['brand'], $tax );
                if ( $bid ) wp_set_object_terms( $product_id, [ $bid ], $tax );
            }
        }

        // ── Tags ───────────────────────────────────────────────────────────
        if ( ! empty( $data['tags'] ) ) {
            $tag_ids = self::get_or_create_terms( $data['tags'], 'product_tag' );
            wp_set_object_terms( $product_id, $tag_ids, 'product_tag' );
        }

        // ── Variaciones ────────────────────────────────────────────────────
        if ( ! empty( $vars ) ) {
            self::create_variations( $product_id, $attr_name, $vars, $data );
        }
        // sync() fue eliminado en WooCommerce 8.x — usar la funcion global si existe
        if ( function_exists( 'wc_get_product' ) ) {
            $synced = wc_get_product( $product_id );
            if ( $synced && method_exists( $synced, 'sync_price' ) ) {
                $synced->sync_price( $product_id );
            }
        }

        // ── Meta: Accesorios relacionados ──────────────────────────────────
        if ( ! empty( $data['accessories'] ) ) {
            update_post_meta( $product_id, '_ckg_accessories', wp_json_encode( $data['accessories'] ) );
        }

        // ── Meta: Homologaciones (para mostrar en descripción corta) ───────
        if ( ! empty( $data['homologacion'] ) ) {
            update_post_meta( $product_id, '_ckg_homologaciones', wp_json_encode( $data['homologacion'] ) );
        }

        // ── Meta: Compatibilidad ───────────────────────────────────────────
        if ( ! empty( $data['compatibilidad'] ) ) {
            update_post_meta( $product_id, '_ckg_compatibilidad', wp_json_encode( $data['compatibilidad'] ) );
        }

        // ── Meta: Catálogo PDF ─────────────────────────────────────────────
        if ( ! empty( $data['catalog_url'] ) ) {
            update_post_meta( $product_id, '_ckg_catalog_url', esc_url_raw( $data['catalog_url'] ) );
        }

        // ── SEO — Yoast ────────────────────────────────────────────────────
        self::set_yoast_meta( $product_id, $data );

        // ── SEO — RankMath (completo con schema Product) ───────────────────
        if ( class_exists( 'CKG_RankMath' ) ) {
            CKG_RankMath::apply( $product_id, $data );
        }

        // ── Marcar producto como creado por el plugin ────────────────────────
        update_post_meta( $product_id, '_ckg_generated', 1 );

        // ── Guardar datos temporales para la Web Story ────────────────────────
        // Se usarán cuando el producto pase a 'publish' via el hook de transición
        $story_keys = ['product_name','brand','sku','specs','homologacion','short_desc','intro','variations'];
        $story_data = [];
        foreach ( $story_keys as $key ) {
            if ( isset( $data[ $key ] ) ) $story_data[ $key ] = $data[ $key ];
        }
        update_post_meta( $product_id, '_ckg_story_data_temp', wp_json_encode( $story_data ) );

        return $product_id;

        } catch ( \Throwable $e ) {
            error_log( 'CKG_Woo_Creator::create() error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return new \WP_Error( 'ckg_fatal', 'Error al crear el producto: ' . $e->getMessage() );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Actualiza un producto WooCommerce existente con los datos del formulario.
     * Solo sobreescribe los campos que vienen rellenos — respeta los que no tocó el usuario.
     */
    public static function update( int $product_id, array $data ) {
        try {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return new WP_Error( 'not_found', 'Producto ' . $product_id . ' no encontrado.' );
            }

            // Campos básicos — solo actualizar si vienen rellenos
            if ( ! empty( $data['product_name'] ) ) {
                $product->set_name( sanitize_text_field( $data['product_name'] ) );
            }
            if ( ! empty( $data['product_slug'] ) ) {
                $product->set_slug( sanitize_title( $data['product_slug'] ) );
            }
            if ( ! empty( $data['sku'] ) ) {
                $product->set_sku( sanitize_text_field( $data['sku'] ) );
            }

            // Descripción corta y larga
            $product->set_short_description( CKG_Content_Builder::build_short_description( $data ) );
            $product->set_description( CKG_Content_Builder::build( $data ) );

            // Imágenes — solo actualizar si vienen nuevas
            $clean_images = array_filter( array_map( 'trim', $data['images'] ?? [] ) );
            if ( ! empty( $clean_images ) ) {
                $gallery_ids = self::get_attachment_ids( $clean_images );
                if ( ! empty( $gallery_ids ) ) {
                    $product->set_image_id( $gallery_ids[0] );
                    if ( count( $gallery_ids ) > 1 ) {
                        $product->set_gallery_image_ids( array_slice( $gallery_ids, 1 ) );
                    }
                }
            }

            // Guardar
            $product->save();

            // Categorías
            if ( ! empty( $data['categories'] ) ) {
                $cat_ids = self::get_or_create_terms( $data['categories'], 'product_cat' );
                wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );
            }

            // Tags
            if ( ! empty( $data['tags'] ) ) {
                $tag_ids = self::get_or_create_terms( $data['tags'], 'product_tag' );
                wp_set_object_terms( $product_id, $tag_ids, 'product_tag' );
            }

            // Marca
            if ( ! empty( $data['brand'] ) ) {
                $tax = self::detect_brand_taxonomy();
                if ( $tax ) {
                    $bid = self::get_or_create_term( $data['brand'], $tax );
                    if ( $bid ) wp_set_object_terms( $product_id, [ $bid ], $tax );
                }
            }

            // Variaciones — solo si vienen en el formulario
            if ( ! empty( $data['variations'] ) ) {
                $attr_name = sanitize_text_field( $data['attribute_name'] ?? 'Talla' );
                $attr = new WC_Product_Attribute();
                $attr->set_name( $attr_name );
                $attr->set_options( array_column( $data['variations'], 'label' ) );
                $attr->set_visible( true );
                $attr->set_variation( true );
                $product->set_attributes( [ $attr ] );
                $product->save();
                self::create_variations( $product_id, $attr_name, $data['variations'], $data );
            }

            // Meta
            if ( ! empty( $data['accessories'] ) )   update_post_meta( $product_id, '_ckg_accessories',    wp_json_encode( $data['accessories'] ) );
            if ( ! empty( $data['homologacion'] ) )   update_post_meta( $product_id, '_ckg_homologaciones', wp_json_encode( $data['homologacion'] ) );
            if ( ! empty( $data['compatibilidad'] ) ) update_post_meta( $product_id, '_ckg_compatibilidad', wp_json_encode( $data['compatibilidad'] ) );
            if ( ! empty( $data['catalog_url'] ) )    update_post_meta( $product_id, '_ckg_catalog_url',    esc_url_raw( $data['catalog_url'] ) );

            // SEO
            self::set_yoast_meta( $product_id, $data );
            if ( class_exists( 'CKG_RankMath' ) ) CKG_RankMath::apply( $product_id, $data );

            update_post_meta( $product_id, '_ckg_generated', 1 );

            return $product_id;

        } catch ( \Throwable $e ) {
            error_log( 'CKG_Woo_Creator::update() error: ' . $e->getMessage() );
            return new \WP_Error( 'ckg_update_fatal', 'Error al actualizar: ' . $e->getMessage() );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    private static function create_variations( int $product_id, string $attr_name, array $vars, array $data = [] ): void {
        $base_price = (float) ( $data['base_price'] ?? 0 );
        $sale_price = (float) ( $data['sale_price'] ?? 0 );

        foreach ( $vars as $v ) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_attributes( [ wc_sanitize_taxonomy_name( $attr_name ) => $v['label'] ] );

            // Precio: individual > base_price global > 0
            $price = ! empty( $v['price'] ) ? (float) $v['price'] : $base_price;
            $variation->set_regular_price( (string) $price );

            // Precio rebajado: individual > sale_price global
            $sale = ! empty( $v['price_sale'] ) ? (float) $v['price_sale']
                  : ( ! empty( $v['sale'] )     ? (float) $v['sale'] : $sale_price );
            if ( $sale > 0 && $sale < $price ) {
                $variation->set_sale_price( (string) $sale );
            }

            if ( ! empty( $v['sku'] ) ) $variation->set_sku( sanitize_text_field( $v['sku'] ) );
            $variation->set_status( 'publish' );
            $variation->save();
        }
    }

    private static function get_attachment_ids( array $urls ): array {
        $ids = [];
        foreach ( $urls as $url ) {
            $url = esc_url_raw( trim( $url ) );
            if ( ! $url ) continue;
            $existing = self::find_attachment_by_url( $url );
            if ( $existing ) { $ids[] = $existing; continue; }
            $id = self::import_image( $url );
            if ( $id && ! is_wp_error( $id ) ) $ids[] = $id;
        }
        return $ids;
    }

    private static function find_attachment_by_url( string $url ): int {
        // 1. Búsqueda exacta por guid
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1", $url
        ) );
        if ( $id ) return $id;

        // 2. attachment_url_to_postid (maneja URLs con y sin dominio)
        $id = attachment_url_to_postid( $url );
        if ( $id ) return $id;

        // 3. Buscar por el nombre del archivo en _wp_attached_file
        $filename = basename( parse_url( $url, PHP_URL_PATH ) );
        if ( $filename ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                  WHERE meta_key = '_wp_attached_file'
                  AND meta_value LIKE %s
                  LIMIT 1",
                '%' . $wpdb->esc_like( $filename )
            ) );
        }
        return $id ?: 0;
    }

    private static function import_image( string $url ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        return media_sideload_image( $url, 0, null, 'id' );
    }

    private static function get_or_create_terms( array $names, string $tax ): array {
        return array_filter( array_map( fn( $n ) => self::get_or_create_term( $n, $tax ), $names ) );
    }

    private static function get_or_create_term( string $name, string $tax ): int {
        $term = get_term_by( 'name', $name, $tax );
        if ( $term ) return (int) $term->term_id;
        $r = wp_insert_term( $name, $tax );
        return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
    }

    private static function detect_brand_taxonomy(): string {
        foreach ( [ 'pwb-brand', 'product_brand', 'pa_brand', 'brand' ] as $t ) {
            if ( taxonomy_exists( $t ) ) return $t;
        }
        return '';
    }

    private static function set_yoast_meta( int $id, array $data ): void {
        if ( ! defined( 'WPSEO_VERSION' ) ) return;
        $title = sanitize_text_field( $data['seo_title']       ?? '' );
        $desc  = sanitize_text_field( $data['seo_description'] ?? '' );
        $kw    = sanitize_text_field( $data['seo_keywords']    ?? '' );
        if ( $title ) update_post_meta( $id, '_yoast_wpseo_title',    $title );
        if ( $desc )  update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
        if ( $kw )    update_post_meta( $id, '_yoast_wpseo_focuskw',  $kw );
        // Yoast: og y twitter se generan automáticamente desde los campos de arriba
    }
}
