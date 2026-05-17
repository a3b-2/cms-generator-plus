<?php
/**
 * CKG_Web_Story
 * Genera automáticamente una Google Web Story cuando se crea un producto WooCommerce.
 *
 * Requiere: Plugin "Web Stories" por Google (post_type: web-story)
 * Estructura de la story (4 slides):
 *   1. Cover      → Imagen principal + nombre del producto + marca
 *   2. Homologaciones → Badges CIK-FIA, Snell, FIA... sobre fondo oscuro
 *   3. Specs      → Tabla de 4-6 especificaciones técnicas clave
 *   4. CTA        → Imagen + precio + botón enlace al producto
 *
 * Compatibilidad: Web Stories v1.36 - v1.42 (schema version 36)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Web_Story {

    /* ── Canvas dimensions (Web Stories plugin standard) ──────────── */
    const W = 440;
    const H = 660;

    /* ── Colores CMSKart ──────────────────────────────────────────── */
    const RED  = '#c0392b';
    const DARK = '#1a1a2e';
    const GOLD = '#f39c12';
    const WHITE= '#ffffff';

    /* ═══════════════════════════════════════════════════════════════
       PUNTO DE ENTRADA PRINCIPAL
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Crea una Web Story para un producto WooCommerce.
     * @param int   $product_id  ID del post WC producto
     * @param array $data        Datos del formulario (product_name, brand, specs, homologacion, etc.)
     * @return int|\WP_Error     ID del post web-story creado
     */
    public static function create_for_product( int $product_id, array $data = [] ) {

        if ( ! post_type_exists( 'web-story' ) ) {
            return new WP_Error( 'no_plugin',
                'El plugin "Web Stories" de Google no está instalado. Instálalo desde Plugins → Añadir nuevo → buscar "Web Stories".'
            );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'no_product', 'Producto no encontrado.' );
        }

        // ── Recopilar datos ─────────────────────────────────────────
        $name      = sanitize_text_field( $data['product_name'] ?? $product->get_name() );
        $brand     = sanitize_text_field( $data['brand']        ?? '' );
        $permalink = get_permalink( $product_id );
        $price     = $product->get_price_html();
        $price_raw = (float) $product->get_price();

        // Imagen principal del producto
        $img_id    = $product->get_image_id();
        $img_url   = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';
        $img_full  = $img_id ? wp_get_attachment_image_url( $img_id, 'full'  ) : '';

        // Galería: usar segunda imagen si existe
        $gallery_ids = $product->get_gallery_image_ids();
        $img2_url    = ! empty( $gallery_ids ) ? wp_get_attachment_image_url( $gallery_ids[0], 'large' ) : $img_url;

        // Logo del sitio para la story
        $logo_url = self::get_site_logo_url();

        // Homologaciones
        $homos = $data['homologacion'] ?? [];
        if ( empty( $homos ) ) {
            $json = get_post_meta( $product_id, '_ckg_homologaciones', true );
            if ( $json ) $homos = json_decode( $json, true ) ?: [];
        }

        // Specs (máximo 5 para la story)
        $specs = array_slice( $data['specs'] ?? [], 0, 5 );

        // Descripción corta (para meta description)
        $short_desc = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 20 );

        // ── Generar story JSON data ─────────────────────────────────
        $story_data = self::build_story_json(
            $name, $brand, $img_url, $img2_url, $img_full,
            $homos, $specs, $price_raw, $price, $permalink
        );

        // ── Generar AMP HTML (post_content) ────────────────────────
        $amp_html = self::build_amp_html(
            $name, $brand, $img_url, $img2_url, $img_full,
            $homos, $specs, $price_raw, $price, $permalink,
            $logo_url, $short_desc
        );

        // ── Crear el post web-story ─────────────────────────────────
        $story_slug = sanitize_title( $name ) . '-karting-story';

        $post_id = wp_insert_post( [
            'post_type'    => 'web-story',
            'post_title'   => $name . ( $brand ? ' — ' . $brand : '' ) . ' | CMSKart',
            'post_name'    => $story_slug,
            'post_content' => $amp_html,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id() ?: 1,
        ], true );

        if ( is_wp_error( $post_id ) ) return $post_id;

        // ── Meta del plugin Web Stories ─────────────────────────────
        update_post_meta( $post_id, 'web_stories_story_data',      $story_data );
        update_post_meta( $post_id, '_web_stories_is_amp',         1 );
        update_post_meta( $post_id, 'web_stories_poster_portrait',  $img_id ?: 0 );
        update_post_meta( $post_id, 'web_stories_poster_landscape', $img_id ?: 0 );
        update_post_meta( $post_id, 'web_stories_poster_square',    $img_id ?: 0 );

        // Imagen destacada del post — necesaria para que el poster aparezca en el editor
        if ( $img_id ) {
            set_post_thumbnail( $post_id, $img_id );
        }

        // ── Etiqueta de producto en la story ─────────────────────────
        // Vincular la story con el producto via taxonomía y meta
        update_post_meta( $post_id, '_ckg_linked_product_id',  $product_id );
        update_post_meta( $post_id, '_ckg_linked_product_url', $permalink );
        update_post_meta( $post_id, '_ckg_linked_product_name', $name );

        // Copiar categorías del producto a la story para relacionarlas
        $product_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $product_cats ) && ! empty( $product_cats ) ) {
            wp_set_post_tags( $post_id, $product_cats, false );
        }

        // ── Enlazar story con el producto ───────────────────────────
        update_post_meta( $product_id, '_ckg_web_story_id',  $post_id );
        update_post_meta( $product_id, '_ckg_web_story_url', get_permalink( $post_id ) );

        // Añadir shortcode de story embebida al producto
        self::embed_story_in_product( $product_id, $post_id );

        return $post_id;
    }

    /* ═══════════════════════════════════════════════════════════════
       STORY JSON DATA (formato Web Stories plugin v36)
    ═══════════════════════════════════════════════════════════════ */

    private static function build_story_json(
        string $name, string $brand,
        string $img1, string $img2, string $img_full,
        array $homos, array $specs,
        float $price_raw, string $price_html, string $product_url
    ): array {

        $pages = [];

        // ── SLIDE 1: Cover ──────────────────────────────────────────
        $pages[] = self::page_cover( $name, $brand, $img1 );

        // ── SLIDE 2: Homologaciones (solo si hay) ───────────────────
        if ( ! empty( $homos ) ) {
            $pages[] = self::page_homologaciones( $homos, $img2 ?: $img1 );
        }

        // ── SLIDE 3: Specs ──────────────────────────────────────────
        if ( ! empty( $specs ) ) {
            $pages[] = self::page_specs( $specs, $name );
        }

        // ── SLIDE 4: CTA ────────────────────────────────────────────
        $pages[] = self::page_cta( $name, $brand, $img_full ?: $img1, $price_raw, $price_html, $product_url );

        return [
            'version'             => 36,
            'pages'               => $pages,
            'currentStoryStyles'  => [ 'colors' => [] ],
            'backgroundAudio'     => [ 'resource' => null, 'loop' => true, 'tracks' => [] ],
            'title'               => $name,
        ];
    }

    /* ── Generadores de páginas (formato JSON) ─────────────────────── */

    private static function page_cover( string $name, string $brand, string $img_url ): array {
        $elems = [];

        // Fondo: imagen del producto
        if ( $img_url ) {
            $elems[] = self::elem_image_bg( 'cover-bg', $img_url );
        }

        // Overlay degradado inferior (shape negro)
        $elems[] = self::elem_shape( 'cover-gradient', 0, 380, self::W, 280,
            [ 'r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.65 ]
        );

        // Marca (pequeño, arriba del nombre)
        if ( $brand ) {
            $elems[] = self::elem_text( 'cover-brand', $brand, 24, 540, self::W - 48, 36,
                self::GOLD, 18, 'Oswald', 700
            );
        }

        // Nombre del producto
        $elems[] = self::elem_text( 'cover-name', strtoupper( $name ), 24, $brand ? 576 : 540, self::W - 48, 80,
            self::WHITE, 28, 'Oswald', 700
        );

        // Label CMSKart
        $elems[] = self::elem_text( 'cover-site', 'cmskart.es', 24, 616, 150, 30,
            'rgba(255,255,255,0.6)', 14, 'Roboto', 400
        );

        return self::build_page( 'cover', $elems, [ 'r' => 26, 'g' => 26, 'b' => 46 ] );
    }

    private static function page_homologaciones( array $homos, string $img_url ): array {
        $elems = [];

        // Fondo oscuro semitransparente con imagen
        if ( $img_url ) {
            $elems[] = array_merge( self::elem_image_bg( 'homo-bg', $img_url ), [ 'opacity' => 30 ] );
        }

        // Título
        $elems[] = self::elem_text( 'homo-title', '🏆 HOMOLOGACIONES', 24, 60, self::W - 48, 50,
            self::GOLD, 22, 'Oswald', 700
        );

        // Un badge por homologación
        $y = 140;
        foreach ( array_slice( $homos, 0, 5 ) as $i => $homo ) {
            $tipo   = strtoupper( $homo['tipo']   ?? '' );
            $codigo = $homo['codigo']              ?? '';
            $text   = $tipo . ( $codigo ? "\n" . $codigo : '' );

            // Badge background
            $elems[] = self::elem_shape( "homo-bg-$i", 24, $y - 8, self::W - 48, 66,
                [ 'r' => 192, 'g' => 57, 'b' => 43, 'a' => 0.9 ]
            );

            $elems[] = self::elem_text( "homo-$i", $tipo . ( $codigo ? '  ·  ' . $codigo : '' ),
                32, $y + 12, self::W - 80, 44,
                self::WHITE, 20, 'Oswald', 600
            );

            $y += 86;
        }

        return self::build_page( 'homologaciones', $elems, [ 'r' => 26, 'g' => 26, 'b' => 46 ] );
    }

    private static function page_specs( array $specs, string $product_name ): array {
        $elems = [];

        // Título
        $elems[] = self::elem_text( 'specs-title', '⚙️ ESPECIFICACIONES', 24, 50, self::W - 48, 46,
            self::GOLD, 22, 'Oswald', 700
        );

        $y = 120;
        foreach ( array_slice( $specs, 0, 5 ) as $i => $spec ) {
            $bg_color = $i % 2 === 0
                ? [ 'r' => 44, 'g' => 62, 'b' => 80, 'a' => 0.9 ]
                : [ 'r' => 26, 'g' => 26, 'b' => 46, 'a' => 0.9 ];

            $elems[] = self::elem_shape( "spec-bg-$i", 24, $y, self::W - 48, 76, $bg_color );

            $elems[] = self::elem_text( "spec-key-$i",
                strtoupper( $spec['key'] ?? '' ),
                32, $y + 6, self::W - 80, 28,
                self::GOLD, 13, 'Oswald', 600
            );

            $elems[] = self::elem_text( "spec-val-$i",
                $spec['value'] ?? '',
                32, $y + 34, self::W - 80, 34,
                self::WHITE, 17, 'Roboto', 400
            );

            $y += 86;
        }

        return self::build_page( 'specs', $elems, [ 'r' => 26, 'g' => 26, 'b' => 46 ] );
    }

    private static function page_cta(
        string $name, string $brand, string $img_url,
        float $price_raw, string $price_html, string $product_url
    ): array {
        $elems = [];

        // Imagen de fondo
        if ( $img_url ) {
            $elems[] = array_merge( self::elem_image_bg( 'cta-bg', $img_url ), [ 'opacity' => 50 ] );
        }

        // Overlay degradado inferior
        $elems[] = self::elem_shape( 'cta-overlay', 0, 300, self::W, 360,
            [ 'r' => 26, 'g' => 26, 'b' => 46, 'a' => 0.92 ]
        );

        // Nombre
        $elems[] = self::elem_text( 'cta-name', strtoupper( $name ), 24, 320, self::W - 48, 80,
            self::WHITE, 24, 'Oswald', 700
        );

        // Precio (si existe)
        if ( $price_raw > 0 ) {
            $price_text = number_format( $price_raw, 2, ',', '.' ) . ' €';
            $elems[] = self::elem_text( 'cta-price', $price_text, 24, 408, 200, 54,
                self::GOLD, 36, 'Oswald', 800
            );
        }

        // Botón CTA (enlace externo — Web Stories permite 1 link por story)
        $elems[] = [
            'id'             => 'cta-btn',
            'type'           => 'text',
            'x'              => (self::W / 2) - 150,
            'y'              => 565,
            'width'          => 300,
            'height'         => 56,
            'opacity'        => 100,
            'rotationAngle'  => 0,
            'lockAspectRatio'=> false,
            'flip'           => [ 'vertical' => false, 'horizontal' => false ],
            'content'        => '<span style="font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:1px;">Ver en CMSKart →</span>',
            'padding'        => [ 'horizontal' => 0, 'vertical' => 0, 'locked' => true ],
            'backgroundColor'=> [ 'color' => [ 'r' => 192, 'g' => 57, 'b' => 43 ] ],
            'fontSize'        => 16,
            'font'            => [ 'family' => 'Oswald', 'service' => 'fonts.google.com', 'metrics' => [] ],
            'textAlign'       => 'center',
            'lineHeight'      => 1.2,
            'link'            => [ 'url' => $product_url, 'desc' => 'Ver producto en CMSKart', 'needsProxy' => false ],
            'borderRadius'    => [ 'topLeft' => 6, 'topRight' => 6, 'bottomRight' => 6, 'bottomLeft' => 6, 'locked' => true ],
        ];

        return self::build_page( 'cta', $elems, [ 'r' => 26, 'g' => 26, 'b' => 46 ] );
    }

    /* ── Builders de elementos ─────────────────────────────────────── */

    private static function elem_image_bg( string $id, string $src ): array {
        return [
            'id'             => $id,
            'type'           => 'image',
            'x'              => 0,
            'y'              => 0,
            'width'          => self::W,
            'height'         => self::H,
            'isBackground'   => true,
            'opacity'        => 100,
            'rotationAngle'  => 0,
            'lockAspectRatio'=> true,
            'flip'           => [ 'vertical' => false, 'horizontal' => false ],
            'scale'          => 100,
            'focalX'         => 50,
            'focalY'         => 50,
            'resource'       => [
                'type'          => 'image',
                'mimeType'      => 'image/webp',
                'id'            => 0,
                'src'           => $src,
                'width'         => self::W,
                'height'        => self::H,
                'alt'           => '',
                'isExternal'    => true,
                'isPlaceholder' => false,
            ],
        ];
    }

    private static function elem_shape( string $id, int $x, int $y, int $w, int $h, array $color ): array {
        return [
            'id'              => $id,
            'type'            => 'shape',
            'x'               => $x,
            'y'               => $y,
            'width'           => $w,
            'height'          => $h,
            'opacity'         => 100,
            'rotationAngle'   => 0,
            'lockAspectRatio' => false,
            'flip'            => [ 'vertical' => false, 'horizontal' => false ],
            'mask'            => [ 'type' => 'rectangle' ],
            'backgroundColor' => [ 'color' => $color ],
        ];
    }

    private static function elem_text(
        string $id, string $content, int $x, int $y, int $w, int $h,
        string $color, int $font_size, string $font_family = 'Oswald', int $font_weight = 400,
        string $text_align = 'left'
    ): array {
        $r = hexdec( substr( ltrim( $color, '#' ), 0, 2 ) );
        $g = hexdec( substr( ltrim( $color, '#' ), 2, 2 ) );
        $b = hexdec( substr( ltrim( $color, '#' ), 4, 2 ) );
        $style = "font-weight:{$font_weight};color:rgb({$r},{$g},{$b});";

        return [
            'id'              => $id,
            'type'            => 'text',
            'x'               => $x,
            'y'               => $y,
            'width'           => $w,
            'height'          => $h,
            'opacity'         => 100,
            'rotationAngle'   => 0,
            'lockAspectRatio' => false,
            'flip'            => [ 'vertical' => false, 'horizontal' => false ],
            'content'         => "<span style=\"{$style}\">" . esc_html( $content ) . '</span>',
            'padding'         => [ 'horizontal' => 0, 'vertical' => 0, 'locked' => true ],
            'backgroundColor' => null,
            'fontSize'        => $font_size,
            'font'            => [ 'family' => $font_family, 'service' => 'fonts.google.com', 'metrics' => [] ],
            'textAlign'       => $text_align,
            'lineHeight'      => 1.3,
        ];
    }

    private static function build_page( string $id, array $elements, array $bg_color ): array {
        return [
            'id'              => $id,
            'animations'      => [],
            'elements'        => $elements,
            'backgroundColor' => [ 'color' => $bg_color ],
            'pageSize'        => [ 'width' => self::W, 'height' => self::H ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
       AMP HTML (post_content — lo que Google indexa)
    ═══════════════════════════════════════════════════════════════ */

    private static function build_amp_html(
        string $name, string $brand,
        string $img1, string $img2, string $img_full,
        array $homos, array $specs,
        float $price_raw, string $price_html,
        string $product_url, string $logo_url, string $short_desc
    ): string {

        $title    = esc_attr( $name . ( $brand ? ' — ' . $brand : '' ) );
        $poster   = esc_url( $img1 ?: '' );
        $logo     = esc_url( $logo_url );
        $pub      = esc_attr( get_bloginfo( 'name' ) ?: 'CMSKart' );
        $amp_boilerplate = 'body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}';

        $html  = '<!DOCTYPE html><html amp lang="es"><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<script async src="https://cdn.ampproject.org/v0.js"></script>';
        $html .= '<script async custom-element="amp-story" src="https://cdn.ampproject.org/v0/amp-story-1.0.js"></script>';
        $html .= '<style amp-boilerplate>' . $amp_boilerplate . '</style>';
        $html .= '<noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>';
        $html .= '<meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">';
        $html .= '<title>' . $title . ' | CMSKart</title>';
        $html .= '<meta name="description" content="' . esc_attr( $short_desc ) . '">';
        $html .= '</head><body>';
        $html .= '<amp-story standalone title="' . $title . '" publisher="' . $pub . '" publisher-logo-src="' . $logo . '" poster-portrait-src="' . $poster . '">';

        // ── Slide 1: Cover ────────────────────────────────────────
        $html .= '<amp-story-page id="cover">';
        $html .= '<amp-story-grid-layer template="fill">';
        if ( $img1 ) $html .= '<amp-img layout="fill" src="' . esc_url( $img1 ) . '" alt="' . esc_attr( $name ) . '"></amp-img>';
        $html .= '</amp-story-grid-layer>';
        $html .= '<amp-story-grid-layer template="vertical" style="padding:0;">';
        $html .= '<div style="position:absolute;bottom:0;left:0;right:0;padding:24px;background:linear-gradient(transparent,rgba(26,26,46,0.95));min-height:200px;display:flex;flex-direction:column;justify-content:flex-end;">';
        if ( $brand ) $html .= '<p style="margin:0 0 4px;color:' . self::GOLD . ';font-family:Oswald,sans-serif;font-size:14px;text-transform:uppercase;letter-spacing:2px;">' . esc_html( $brand ) . '</p>';
        $html .= '<h1 style="margin:0 0 8px;color:#fff;font-family:Oswald,sans-serif;font-size:26px;text-transform:uppercase;line-height:1.2;">' . esc_html( $name ) . '</h1>';
        $html .= '<p style="margin:0;color:rgba(255,255,255,0.5);font-size:12px;font-family:Roboto,sans-serif;">cmskart.es</p>';
        $html .= '</div></amp-story-grid-layer>';
        $html .= '</amp-story-page>';

        // ── Slide 2: Homologaciones ───────────────────────────────
        if ( ! empty( $homos ) ) {
            $html .= '<amp-story-page id="homologaciones">';
            $html .= '<amp-story-grid-layer template="fill" style="background:' . self::DARK . ';">';
            if ( $img2 ) $html .= '<amp-img layout="fill" src="' . esc_url( $img2 ) . '" alt="" style="opacity:0.2;"></amp-img>';
            $html .= '</amp-story-grid-layer>';
            $html .= '<amp-story-grid-layer template="vertical" style="padding:32px 24px;">';
            $html .= '<p style="color:' . self::GOLD . ';font-family:Oswald,sans-serif;font-size:20px;text-transform:uppercase;margin:0 0 20px;letter-spacing:1px;">🏆 Homologaciones</p>';
            foreach ( array_slice( $homos, 0, 5 ) as $homo ) {
                $tipo   = strtoupper( $homo['tipo']   ?? '' );
                $codigo = $homo['codigo']              ?? '';
                $html .= '<div style="background:' . self::RED . ';border-radius:6px;padding:12px 16px;margin-bottom:10px;">';
                $html .= '<p style="margin:0;color:#fff;font-family:Oswald,sans-serif;font-size:18px;font-weight:700;">' . esc_html( $tipo ) . ( $codigo ? ' <span style="opacity:.85;font-size:14px;">· ' . esc_html( $codigo ) . '</span>' : '' ) . '</p>';
                $html .= '</div>';
            }
            $html .= '</amp-story-grid-layer>';
            $html .= '</amp-story-page>';
        }

        // ── Slide 3: Specs ────────────────────────────────────────
        if ( ! empty( $specs ) ) {
            $html .= '<amp-story-page id="specs">';
            $html .= '<amp-story-grid-layer template="fill" style="background:' . self::DARK . ';"></amp-story-grid-layer>';
            $html .= '<amp-story-grid-layer template="vertical" style="padding:32px 24px;">';
            $html .= '<p style="color:' . self::GOLD . ';font-family:Oswald,sans-serif;font-size:20px;text-transform:uppercase;margin:0 0 16px;letter-spacing:1px;">⚙️ Especificaciones</p>';
            foreach ( array_slice( $specs, 0, 5 ) as $i => $spec ) {
                $bg = $i % 2 === 0 ? 'rgba(44,62,80,0.9)' : 'rgba(52,73,94,0.9)';
                $html .= '<div style="background:' . $bg . ';border-radius:4px;padding:10px 14px;margin-bottom:8px;">';
                $html .= '<p style="margin:0 0 2px;color:' . self::GOLD . ';font-family:Oswald,sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.5px;">' . esc_html( $spec['key'] ) . '</p>';
                $html .= '<p style="margin:0;color:#fff;font-family:Roboto,sans-serif;font-size:16px;font-weight:600;">' . esc_html( $spec['value'] ) . '</p>';
                $html .= '</div>';
            }
            $html .= '</amp-story-grid-layer>';
            $html .= '</amp-story-page>';
        }

        // ── Slide 4: CTA ──────────────────────────────────────────
        $html .= '<amp-story-page id="cta">';
        $html .= '<amp-story-grid-layer template="fill">';
        $img_cta = $img_full ?: $img1;
        if ( $img_cta ) $html .= '<amp-img layout="fill" src="' . esc_url( $img_cta ) . '" alt="' . esc_attr( $name ) . '" style="opacity:0.45;"></amp-img>';
        $html .= '</amp-story-grid-layer>';
        $html .= '<amp-story-grid-layer template="vertical" style="padding:0;">';
        $html .= '<div style="position:absolute;bottom:0;left:0;right:0;padding:28px 24px;background:linear-gradient(transparent,rgba(26,26,46,0.98));">';
        $html .= '<h2 style="margin:0 0 8px;color:#fff;font-family:Oswald,sans-serif;font-size:22px;text-transform:uppercase;">' . esc_html( $name ) . '</h2>';
        if ( $price_raw > 0 ) {
            $price_text = number_format( $price_raw, 2, ',', '.' ) . ' €';
            $html .= '<p style="margin:0 0 16px;color:' . self::GOLD . ';font-family:Oswald,sans-serif;font-size:32px;font-weight:800;">' . esc_html( $price_text ) . '</p>';
        }
        $html .= '</div>';
        $html .= '</amp-story-grid-layer>';
        // CTA Button (amp-story-cta-layer — solo permitido en la última página)
        $html .= '<amp-story-cta-layer>';
        $html .= '<a href="' . esc_url( $product_url ) . '" style="display:block;background:' . self::RED . ';color:#fff;text-align:center;padding:14px 24px;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;text-decoration:none;border-radius:4px;margin:0 24px 24px;">Ver producto en CMSKart →</a>';
        $html .= '</amp-story-cta-layer>';
        $html .= '</amp-story-page>';

        // Page attachment — swipe up para ir al producto
        if ( ! empty( $permalink ) ) {
            $html .= '<amp-story-page-attachment layout="nodisplay" href="' . esc_url( $permalink ) . '" cta-text="Ver producto en CMSKart"></amp-story-page-attachment>';
        }
        $html .= '</amp-story></body></html>';

        return $html;
    }

    /* ═══════════════════════════════════════════════════════════════
       EMBED STORY EN EL PRODUCTO (shortcode web stories)
    ═══════════════════════════════════════════════════════════════ */

    private static function embed_story_in_product( int $product_id, int $story_id ): void {
        // El plugin Web Stories proporciona el shortcode [web_stories_embed]
        $story_url = get_permalink( $story_id );
        if ( ! $story_url ) return;

        $story_title = get_the_title( $story_id );

        // Guardamos en meta para que el admin pueda mostrar el embed o el link
        update_post_meta( $product_id, '_ckg_web_story_embed_url', $story_url );
        update_post_meta( $product_id, '_ckg_web_story_title',     $story_title );
    }

    /* ═══════════════════════════════════════════════════════════════
       UTILIDADES
    ═══════════════════════════════════════════════════════════════ */

    private static function get_site_logo_url(): string {
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $url = wp_get_attachment_image_url( $logo_id, 'full' );
            if ( $url ) return $url;
        }
        // Fallback: favicon
        $icon = get_site_icon_url( 64 );
        return $icon ?: get_site_url() . '/favicon.ico';
    }

    /**
     * Comprueba si el plugin Web Stories está activo.
     */
    public static function plugin_active(): bool {
        return post_type_exists( 'web-story' );
    }

    /**
     * Obtiene el ID de la story asociada a un producto.
     */
    public static function get_story_id( int $product_id ): int {
        return (int) get_post_meta( $product_id, '_ckg_web_story_id', true );
    }
}
