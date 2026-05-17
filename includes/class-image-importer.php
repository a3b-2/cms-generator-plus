<?php
/**
 * CKG_Image_Importer
 * Scraping de imágenes del producto desde URL del fabricante.
 *
 * Proceso:
 *  1. Descarga el HTML de la URL del fabricante
 *  2. Detecta imágenes del producto (filtra logos, iconos, banners)
 *  3. Descarga cada imagen
 *  4. Convierte a WebP con redimensión a 600×600 (con padding para no distorsionar)
 *  5. Genera nombre de archivo SEO: slug-del-producto_N.webp
 *  6. Importa a la Biblioteca de Medios de WordPress
 *  7. Añade: alt text, caption, descripción y título con el nombre del producto
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Image_Importer {

    const MAX_IMAGES    = 12;
    const TARGET_W      = 600;
    const TARGET_H      = 600;
    const TIMEOUT       = 20;
    const MIN_IMG_SIZE  = 8000;    // bytes mínimos — descarta iconos tiny
    const MIN_DIMENSION = 150;     // px mínimo de ancho O alto

    /* ── Punto de entrada ────────────────────────────────────────────── */

    /**
     * @param string $url           URL de la página del fabricante
     * @param string $product_name  Nombre del producto (para alt, caption, filename)
     * @param int    $post_id       ID del post WC al que asociar (0 = sin asociar)
     * @return array|\WP_Error      Lista de attachment IDs importados
     */
    public static function import_from_url( string $url, string $product_name, int $post_id = 0 ) {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'URL no válida.' );
        }

        // ── 1. Cargar HTML ──────────────────────────────────────────────
        $html = self::fetch_html( $url );
        if ( is_wp_error( $html ) ) return $html;

        // ── 2. Extraer URLs de imágenes ─────────────────────────────────
        $image_urls = self::extract_image_urls( $html, $url );
        if ( empty( $image_urls ) ) {
            return new WP_Error( 'no_images', 'No se encontraron imágenes de producto en la URL proporcionada.' );
        }

        // ── 3. Descargar, convertir e importar cada imagen ──────────────
        self::load_wp_media_functions();

        $slug         = sanitize_title( $product_name );
        $imported     = [];
        $counter      = 1;

        foreach ( $image_urls as $img_url ) {
            if ( count( $imported ) >= self::MAX_IMAGES ) break;

            $result = self::process_image( $img_url, $product_name, $slug, $counter, $post_id );
            if ( ! is_wp_error( $result ) && $result > 0 ) {
                $imported[] = [
                    'attachment_id' => $result,
                    'url'           => wp_get_attachment_url( $result ),
                    'thumb'         => wp_get_attachment_image_url( $result, 'thumbnail' ),
                ];
                $counter++;
            }
        }

        if ( empty( $imported ) ) {
            return new WP_Error( 'import_failed', 'No se pudo importar ninguna imagen (puede que sean demasiado pequeñas, estén protegidas o el formato no sea compatible).' );
        }

        return $imported;
    }

    /* ── Fetch HTML ──────────────────────────────────────────────────── */

    private static function fetch_html( string $url ): string|\WP_Error {
        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (compatible; CMSKartBot/1.0; +https://cmskart.es)',
            'headers'    => [ 'Accept-Language' => 'es-ES,es;q=0.9' ],
        ] );
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 400 ) return new WP_Error( 'http', "HTTP $code al cargar la URL." );
        return wp_remote_retrieve_body( $resp );
    }

    /* ── Extracción inteligente de URLs de imágenes ───────────────────── */

    private static function extract_image_urls( string $html, string $base_url ): array {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );
        $base  = self::get_base_url( $base_url );
        $found = [];

        // ── Estrategia 1: Imágenes dentro de galería/slider del producto ─
        $priority_selectors = [
            // WooCommerce
            '//div[contains(@class,"woocommerce-product-gallery")]//img',
            '//figure[contains(@class,"woocommerce-product-gallery__image")]//img',
            // Clases comunes de galerías
            '//div[contains(@class,"product-gallery")]//img',
            '//div[contains(@class,"product-images")]//img',
            '//div[contains(@class,"gallery-thumbs")]//img',
            '//div[contains(@class,"swiper") and contains(@class,"product")]//img',
            '//div[contains(@class,"product-image")]//img',
            '//div[contains(@class,"product__media")]//img',
            '//div[contains(@class,"product-photo")]//img',
            // Schema.org
            '//*[@itemprop="image"]',
        ];

        foreach ( $priority_selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes->length > 0 ) {
                foreach ( $nodes as $img ) {
                    $url = self::resolve_img_url( $img, $base );
                    if ( $url ) $found[] = $url;
                }
                // Si encontramos más de 1 imagen en selectores prioritarios, usarlos
                if ( count( $found ) >= 2 ) break;
            }
        }

        // ── Estrategia 2: Todas las imágenes con atributos data-* ────────
        if ( count( $found ) < 2 ) {
            foreach ( $xpath->query( '//img[@data-src or @data-full-url or @data-large_image]' ) as $img ) {
                $url = $img->getAttribute( 'data-src' )
                    ?: $img->getAttribute( 'data-full-url' )
                    ?: $img->getAttribute( 'data-large_image' );
                if ( $url ) $found[] = self::absolute_url( $url, $base );
            }
        }

        // ── Estrategia 3: Todas las <img> del body si aún insuficiente ───
        if ( count( $found ) < 2 ) {
            foreach ( $xpath->query( '//body//img[@src]' ) as $img ) {
                $url = $img->getAttribute( 'src' );
                if ( $url ) $found[] = self::absolute_url( $url, $base );
            }
        }

        // ── Estrategia 4: JSON-LD (schema Product images) ─────────────
        foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
            $raw = trim( $script->textContent );
            $decoded = json_decode( $raw, true );
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                if ( preg_match( '/(\{.*\}|\[.*\])/s', $raw, $m ) ) {
                    $decoded = json_decode( $m[0], true );
                    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                        $decoded = null;
                    }
                } else {
                    $decoded = null;
                }
            }
            if ( is_array( $decoded ) && isset( $decoded['image'] ) ) {
                $imgs = is_array( $decoded['image'] ) ? $decoded['image'] : [ $decoded['image'] ];
                foreach ( $imgs as $img ) {
                    if ( is_string( $img ) && filter_var( $img, FILTER_VALIDATE_URL ) ) $found[] = $img;
                    if ( is_array( $img ) && ! empty( $img['url'] ) ) $found[] = $img['url'];
                }
            }
        }

        // ── Filtrar y deduplicar ─────────────────────────────────────
        $found = array_unique( array_filter( $found, [ __CLASS__, 'is_valid_image_url' ] ) );

        // ── Ordenar por preferencia: priorizar imágenes grandes (URL hints) ─
        usort( $found, function( $a, $b ) {
            $score_a = self::url_quality_score( $a );
            $score_b = self::url_quality_score( $b );
            return $score_b - $score_a;
        } );

        return array_slice( array_values( $found ), 0, self::MAX_IMAGES );
    }

    /* ── Procesar imagen individual ──────────────────────────────────── */

    private static function process_image( string $img_url, string $product_name, string $slug, int $n, int $post_id ): int|\WP_Error {
        // ── Descargar imagen ─────────────────────────────────────────
        $img_data = self::download_image( $img_url );
        if ( is_wp_error( $img_data ) ) return $img_data;

        // ── Verificar tamaño mínimo ──────────────────────────────────
        if ( strlen( $img_data['body'] ) < self::MIN_IMG_SIZE ) {
            return new WP_Error( 'too_small', "Imagen muy pequeña ($img_url)" );
        }

        // ── Convertir a WebP 600×600 con GD ──────────────────────────
        $webp_result = self::convert_to_webp( $img_data['body'], $img_data['mime'] );
        if ( is_wp_error( $webp_result ) ) return $webp_result;

        [ 'data' => $webp_data, 'width' => $w, 'height' => $h ] = $webp_result;

        // Verificar dimensiones mínimas
        if ( $w < self::MIN_DIMENSION && $h < self::MIN_DIMENSION ) {
            return new WP_Error( 'too_small_dim', "Imagen demasiado pequeña: {$w}×{$h}px" );
        }

        // ── Guardar archivo temporal ──────────────────────────────────
        $upload_dir = wp_upload_dir();
        $suffix     = $n > 1 ? "_$n" : '';
        $filename   = "{$slug}{$suffix}.webp";
        $filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

        // Evitar sobreescribir si ya existe
        $filepath = wp_unique_filename( $upload_dir['path'], $filename );
        $filepath = trailingslashit( $upload_dir['path'] ) . $filepath;
        $filename = basename( $filepath );

        if ( ! file_put_contents( $filepath, $webp_data ) ) {
            return new WP_Error( 'write_failed', "No se pudo escribir el archivo en $filepath" );
        }

        // ── Insertar en biblioteca de medios ──────────────────────────
        $attachment_title = $n === 1
            ? $product_name
            : "$product_name — imagen $n";

        $alt_text = $n === 1
            ? $product_name
            : "$product_name ($n)";

        $caption = "$product_name" . ( $n > 1 ? " — Vista $n" : ' — Vista principal' );

        $attachment = [
            'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
            'post_mime_type' => 'image/webp',
            'post_title'     => $attachment_title,
            'post_content'   => "Imagen de $product_name para CMSKart.es",
            'post_excerpt'   => $caption,
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $filepath );
            return $attachment_id;
        }

        // ── Generar metadatos y thumbnails de WP ─────────────────────
        $metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // ── Alt text, leyenda, descripción ───────────────────────────
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

        return $attachment_id;
    }

    /* ── Conversión a WebP 600×600 con GD ───────────────────────────── */

    private static function convert_to_webp( string $binary, string $mime ): array|\WP_Error {
        if ( ! extension_loaded( 'gd' ) ) {
            return new WP_Error( 'no_gd', 'La extensión GD no está disponible en este servidor.' );
        }

        // Crear imagen fuente desde binario
        $src = null;
        switch ( $mime ) {
            case 'image/jpeg': $src = @imagecreatefromstring( $binary ); break;
            case 'image/png':  $src = @imagecreatefromstring( $binary ); break;
            case 'image/gif':  $src = @imagecreatefromstring( $binary ); break;
            case 'image/webp': $src = @imagecreatefromstring( $binary ); break;
            case 'image/bmp':  $src = @imagecreatefromstring( $binary ); break;
            default:           $src = @imagecreatefromstring( $binary );
        }

        if ( ! $src ) {
            return new WP_Error( 'gd_load', "GD no pudo cargar la imagen (MIME: $mime)." );
        }

        $orig_w = imagesx( $src );
        $orig_h = imagesy( $src );

        if ( $orig_w < self::MIN_DIMENSION && $orig_h < self::MIN_DIMENSION ) {
            imagedestroy( $src );
            return new WP_Error( 'too_small_gd', "Imagen fuente demasiado pequeña: {$orig_w}×{$orig_h}" );
        }

        $target_w = self::TARGET_W;
        $target_h = self::TARGET_H;

        // ── Crear canvas blanco 600×600 ───────────────────────────────
        $canvas = imagecreatetruecolor( $target_w, $target_h );

        // Fondo blanco
        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        imagefill( $canvas, 0, 0, $white );

        // ── Calcular dimensiones con letterbox (sin distorsión) ───────
        $ratio   = min( $target_w / $orig_w, $target_h / $orig_h );
        $dst_w   = (int) round( $orig_w * $ratio );
        $dst_h   = (int) round( $orig_h * $ratio );
        $dst_x   = (int) round( ( $target_w  - $dst_w ) / 2 );
        $dst_y   = (int) round( ( $target_h - $dst_h ) / 2 );

        // Resampling de alta calidad
        imagecopyresampled( $canvas, $src, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $orig_w, $orig_h );
        imagedestroy( $src );

        // ── Exportar a WebP en buffer ─────────────────────────────────
        if ( ! function_exists( 'imagewebp' ) ) {
            imagedestroy( $canvas );
            return new WP_Error( 'no_webp', 'GD no tiene soporte WebP en este servidor. Actualiza PHP o instala libwebp.' );
        }

        ob_start();
        imagewebp( $canvas, null, 82 ); // calidad 82 — buen balance tamaño/nitidez
        $webp_data = ob_get_clean();
        imagedestroy( $canvas );

        if ( empty( $webp_data ) ) {
            return new WP_Error( 'webp_empty', 'La conversión a WebP produjo un archivo vacío.' );
        }

        return [
            'data'   => $webp_data,
            'width'  => $target_w,
            'height' => $target_h,
        ];
    }

    /* ── Descarga de imagen ──────────────────────────────────────────── */

    private static function download_image( string $url ): array|\WP_Error {
        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (compatible; CMSKartBot/1.0)',
        ] );

        if ( is_wp_error( $resp ) ) return $resp;

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'http', "HTTP $code al descargar imagen: $url" );
        }

        $mime = wp_remote_retrieve_header( $resp, 'content-type' );
        $mime = strtok( $mime, ';' );  // quitar ; charset=...
        $mime = trim( $mime );

        // Si el header no dice imagen, intentar deducir de la URL
        if ( ! str_starts_with( $mime, 'image/' ) ) {
            $ext  = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
            $map  = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                      'gif' => 'image/gif',  'webp' => 'image/webp', 'bmp' => 'image/bmp' ];
            $mime = $map[ $ext ] ?? 'image/jpeg';
        }

        return [
            'body' => wp_remote_retrieve_body( $resp ),
            'mime' => $mime,
        ];
    }

    /* ── Utilidades ──────────────────────────────────────────────────── */

    private static function load_wp_media_functions(): void {
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    private static function get_base_url( string $url ): string {
        $parts = parse_url( $url );
        return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
    }

    private static function absolute_url( string $url, string $base ): string {
        if ( str_starts_with( $url, 'http' ) ) return $url;
        if ( str_starts_with( $url, '//' ) )   return 'https:' . $url;
        if ( str_starts_with( $url, '/' ) )    return rtrim( $base, '/' ) . $url;
        return $base . '/' . $url;
    }

    private static function resolve_img_url( DOMElement $img, string $base ): string {
        $src = $img->getAttribute( 'data-large_image' )
            ?: $img->getAttribute( 'data-src' )
            ?: $img->getAttribute( 'data-zoom-image' )
            ?: $img->getAttribute( 'data-full-url' )
            ?: $img->getAttribute( 'src' );
        return $src ? self::absolute_url( $src, $base ) : '';
    }

    private static function is_valid_image_url( string $url ): bool {
        if ( empty( $url ) ) return false;
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) return false;

        $path = strtolower( parse_url( $url, PHP_URL_PATH ) ?? '' );
        $ext  = pathinfo( $path, PATHINFO_EXTENSION );

        // Verificar extensión válida (o sin extensión — puede ser imagen dinámica)
        $valid_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', '' ];
        if ( ! in_array( $ext, $valid_exts ) ) return false;

        // Descartar URLs que claramente no son imágenes de producto
        $blacklist = [ 'logo', 'favicon', 'icon', 'sprite', 'banner', 'badge',
                       'avatar', 'flag', 'star', 'rating', 'payment', 'secure',
                       'shipping', '1x1', 'pixel', 'track', 'blank' ];
        foreach ( $blacklist as $kw ) {
            if ( strpos( $path, $kw ) !== false ) return false;
        }

        return true;
    }

    /**
     * Importa una URL directa de imagen (sin parsear HTML).
     * Para URLs que ya apuntan a un archivo .jpg/.png/.webp etc.
     */
    public static function import_single_image( string $url, string $product_name, int $post_id = 0 ): mixed {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'URL no valida: ' . $url );
        }
        self::load_wp_media_functions();
        $slug   = sanitize_title( $product_name ) ?: 'producto';
        $result = self::process_image( $url, $product_name, $slug, 1, $post_id );
        if ( is_wp_error( $result ) ) return $result;
        return [ [
            'attachment_id' => $result,
            'url'           => wp_get_attachment_url( $result ),
            'thumb'         => wp_get_attachment_image_url( $result, 'thumbnail' ),
        ] ];
    }

    private static function url_quality_score( string $url ): int {
        $score = 0;
        $url_l = strtolower( $url );

        // Indicadores de imagen grande/alta calidad
        if ( strpos( $url_l, '600'  ) !== false ) $score += 5;
        if ( strpos( $url_l, '800'  ) !== false ) $score += 5;
        if ( strpos( $url_l, '1000' ) !== false ) $score += 6;
        if ( strpos( $url_l, '1200' ) !== false ) $score += 7;
        if ( strpos( $url_l, 'large') !== false ) $score += 4;
        if ( strpos( $url_l, 'full' ) !== false ) $score += 4;
        if ( strpos( $url_l, 'zoom' ) !== false ) $score += 3;
        if ( strpos( $url_l, '.webp') !== false ) $score += 2;

        // Indicadores de imagen pequeña/thumbnail — penalizar
        if ( strpos( $url_l, 'thumb' ) !== false ) $score -= 3;
        if ( strpos( $url_l, '-100x' ) !== false ) $score -= 5;
        if ( strpos( $url_l, '-150x' ) !== false ) $score -= 4;
        if ( strpos( $url_l, '-50x'  ) !== false ) $score -= 6;

        return $score;
    }
}
