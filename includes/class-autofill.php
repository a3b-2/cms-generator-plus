<?php
/**
 * CKG_AutoFill
 * Motor principal del "Modo Express".
 *
 * Dado una URL (fabricante o competidor):
 *  1. Descarga el HTML
 *  2. Extrae: nombre, marca, precio, descripción, specs, imágenes,
 *             homologaciones, categorías, variaciones, FAQ
 *  3. Importa imágenes y las convierte a WebP 600×600
 *  4. Genera con LLM el contenido que no se pudo extraer
 *  5. Devuelve el array completo listo para pre-rellenar el formulario
 *
 * También puede trabajar solo con nombre + marca (sin URL).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_AutoFill {

    const TIMEOUT = 18;

    /* ═══════════════════════════════════════════════════════════════
       PUNTO DE ENTRADA PRINCIPAL
    ═══════════════════════════════════════════════════════════════ */

    /**
     * @param string $url           URL del fabricante/competidor (puede estar vacía)
     * @param string $product_name  Nombre del producto (requerido si no hay URL)
     * @param string $brand         Marca (opcional, ayuda a completar)
     * @param string $template_id   ID de plantilla a aplicar como base
     * @return array|\WP_Error
     */
    public static function run(
        string $url          = '',
        string $product_name = '',
        string $brand        = '',
        string $template_id  = ''
    ) {
        $data = [];

        // ── FASE 1: Scraping de la URL (si se proporciona) ──────────
        if ( $url ) {
            $scraped = self::scrape_url( $url );
            if ( ! is_wp_error( $scraped ) ) {
                $data = $scraped;
            }
        }

        // ── Rellenar con datos manuales si el scraping no los consiguió ──
        if ( $product_name && empty( $data['product_name'] ) ) $data['product_name'] = $product_name;
        if ( $brand        && empty( $data['brand'] )        ) $data['brand']        = $brand;

        // Sin nombre de producto no podemos continuar
        if ( empty( $data['product_name'] ) ) {
            return new WP_Error( 'no_name', 'No se pudo detectar el nombre del producto. Introdúcelo manualmente.' );
        }

        // ── FASE 2: Aplicar plantilla base ──────────────────────────
        if ( $template_id && class_exists( 'CKG_Templates' ) ) {
            $tpl = CKG_Templates::get( $template_id );
            // Solo rellenar campos que el scraping dejó vacíos
            foreach ( $tpl as $key => $value ) {
                if ( empty( $data[ $key ] ) ) {
                    $data[ $key ] = $value;
                }
            }
        }

        // ── FASE 3: Auto-detectar plantilla si no se especificó ─────
        if ( ! $template_id ) {
            $detected = self::detect_template( $data['product_name'], $data['brand'] ?? '' );
            if ( $detected && class_exists( 'CKG_Templates' ) ) {
                $tpl = CKG_Templates::get( $detected );
                foreach ( $tpl as $key => $value ) {
                    if ( empty( $data[ $key ] ) ) $data[ $key ] = $value;
                }
                $data['template_detected'] = $detected;
            }
        }

        // ── FASE 4: Homologaciones por marca ────────────────────────
        if ( ! empty( $data['brand'] ) && empty( $data['homologacion'] ) && class_exists( 'CKG_Templates' ) ) {
            $brand_homos = CKG_Templates::homologaciones_by_brand();
            foreach ( $brand_homos as $b => $homos ) {
                if ( stripos( $data['brand'], $b ) !== false || stripos( $b, $data['brand'] ) !== false ) {
                    $data['homologacion'] = $homos;
                    break;
                }
            }
        }

        // ── FASE 5: Cargar atributos WooCommerce si las variaciones están vacías ──
        if ( empty( $data['variations'] ) && class_exists( 'CKG_Templates' ) ) {
            $data['_suggest_load_attributes'] = true;
        }

        // ── FASE 6: Generar contenido con LLM para secciones vacías ──
        $data = self::fill_with_llm( $data );

        // ── FASE 7: Importar imágenes (si hay URLs de imágenes) ──────
        if ( ! empty( $data['_image_urls_to_import'] ) && class_exists( 'CKG_Image_Importer' ) ) {
            $imported = [];
            foreach ( array_slice( $data['_image_urls_to_import'], 0, 8 ) as $img_url ) {
                $result = CKG_Image_Importer::import_from_url( $img_url, $data['product_name'] );
                if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
                    foreach ( $result as $att ) {
                        $imported[] = $att['url'];
                    }
                }
            }
            if ( $imported ) $data['images'] = $imported;
            unset( $data['_image_urls_to_import'] );
        }

        // ── FASE 8: SEO automático ───────────────────────────────────
        if ( empty( $data['seo_title'] ) || empty( $data['seo_description'] ) ) {
            $data = self::fill_seo( $data );
        }

        // ── FASE 9: Generar slug ─────────────────────────────────────
        if ( empty( $data['product_slug'] ) ) {
            $data['product_slug'] = sanitize_title( $data['product_name'] );
        }

        // ── Limpieza final ───────────────────────────────────────────
        unset( $data['_raw_html'], $data['_scraped_url'] );

        return $data;
    }

    /* ═══════════════════════════════════════════════════════════════
       SCRAPING DE URL
    ═══════════════════════════════════════════════════════════════ */

    private static function scrape_url( string $url ): array|\WP_Error {
        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers'    => [ 'Accept-Language' => 'es-ES,es;q=0.9' ],
        ] );

        if ( is_wp_error( $resp ) ) return $resp;

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) return new WP_Error( 'http', "HTTP $code al cargar $url" );

        $html = wp_remote_retrieve_body( $resp );
        return self::parse_product_html( $html, $url );
    }

    private static function parse_product_html( string $html, string $url ): array {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );
        $base  = parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST );

        $data = [ '_scraped_url' => $url ];

        // ── Nombre del producto ──────────────────────────────────────
        $h1 = $xpath->query( '//h1' )->item(0);
        if ( $h1 ) $data['product_name'] = trim( $h1->textContent );

        // Fallback OG title
        if ( empty( $data['product_name'] ) ) {
            $og = $xpath->query( '//meta[@property="og:title"]/@content' )->item(0);
            if ( $og ) $data['product_name'] = trim( $og->nodeValue );
        }

        // ── Marca (schema, meta, breadcrumb) ────────────────────────
        $brand_sources = [
            '//meta[@itemprop="brand"]/@content',
            '//*[@itemprop="brand"]//*[@itemprop="name"]/@content',
            '//*[@itemprop="brand"]',
            '//span[contains(@class,"brand")]',
            '//a[contains(@class,"brand")]',
        ];
        foreach ( $brand_sources as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $val = $node->nodeType === XML_ATTRIBUTE_NODE ? $node->nodeValue : trim( $node->textContent );
                if ( $val && strlen( $val ) < 50 ) { $data['brand'] = $val; break; }
            }
        }

        // ── Precio ───────────────────────────────────────────────────
        foreach ( $xpath->query( '//*[@itemprop="price"] | //*[contains(@class,"price")] | //*[contains(@class,"precio")]' ) as $el ) {
            $text = trim( $el->textContent );
            if ( preg_match( '/(\d+[\.,]\d{0,2})\s*€/', $text, $m ) ) {
                $data['price_found'] = str_replace( ',', '.', $m[1] );
                break;
            }
        }

        // ── Descripción corta ────────────────────────────────────────
        $desc_sources = [
            '//div[contains(@class,"woocommerce-product-details__short-description")]',
            '//div[@itemprop="description"]',
            '//div[contains(@class,"product-description")]',
            '//div[contains(@class,"short-desc")]',
        ];
        foreach ( $desc_sources as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $text = trim( $node->textContent );
                if ( strlen( $text ) > 30 ) { $data['short_desc'] = mb_substr( wp_strip_all_tags( $text ), 0, 300 ); break; }
            }
        }

        // ── Descripción larga (párrafos del contenido principal) ─────
        $paras = [];
        foreach ( [
            '//div[contains(@class,"woocommerce-Tabs-panel")]//p',
            '//div[contains(@class,"product_description")]//p',
            '//article//p',
        ] as $sel ) {
            foreach ( $xpath->query( $sel ) as $p ) {
                $t = trim( $p->textContent );
                if ( strlen( $t ) > 50 && strlen( $t ) < 800 ) $paras[] = $t;
                if ( count( $paras ) >= 4 ) break;
            }
            if ( count( $paras ) >= 2 ) break;
        }
        if ( $paras ) $data['intro'] = implode( "\n\n", array_slice( $paras, 0, 2 ) );

        // ── Especificaciones (tabla + dl) ────────────────────────────
        $specs = [];
        foreach ( $xpath->query(
            '//table[contains(@class,"woocommerce-product-attributes")]//tr |
             //table[contains(@class,"spec")]//tr |
             //table[contains(@class,"techni")]//tr'
        ) as $row ) {
            $th  = $xpath->query( './/th', $row )->item(0);
            $td  = $xpath->query( './/td', $row )->item(0);
            $tds = $xpath->query( './/td', $row );
            if ( $th && $td ) {
                $k = trim( $th->textContent );
                $v = trim( $td->textContent );
                if ( $k && $v && strlen( $v ) < 120 ) $specs[] = [ 'key' => $k, 'value' => $v ];
            } elseif ( $tds->length >= 2 ) {
                $k = trim( $tds->item(0)->textContent );
                $v = trim( $tds->item(1)->textContent );
                if ( $k && $v && strlen( $v ) < 120 ) $specs[] = [ 'key' => $k, 'value' => $v ];
            }
        }
        // DL/DT/DD
        foreach ( $xpath->query( '//dl' ) as $dl ) {
            $dts = iterator_to_array( $dl->getElementsByTagName('dt') );
            $dds = iterator_to_array( $dl->getElementsByTagName('dd') );
            for ( $i = 0; $i < count($dts); $i++ ) {
                $k = trim( $dts[$i]->textContent );
                $v = isset( $dds[$i] ) ? trim( $dds[$i]->textContent ) : '';
                if ( $k && $v ) $specs[] = [ 'key' => $k, 'value' => $v ];
            }
        }
        if ( $specs ) $data['specs'] = array_slice( $specs, 0, 15 );

        // ── Imágenes del producto ────────────────────────────────────
        $img_urls = [];
        foreach ( [
            '//div[contains(@class,"woocommerce-product-gallery")]//img',
            '//div[contains(@class,"product-gallery")]//img',
            '//figure[contains(@class,"product")]//img',
            '//*[@itemprop="image"]',
        ] as $sel ) {
            foreach ( $xpath->query( $sel ) as $img ) {
                $src = $img->getAttribute('data-large_image')
                    ?: $img->getAttribute('data-src')
                    ?: $img->getAttribute('src');
                if ( $src ) {
                    if ( ! str_starts_with( $src, 'http' ) ) $src = rtrim($base,'/') . '/' . ltrim($src,'/');
                    if ( filter_var( $src, FILTER_VALIDATE_URL ) ) $img_urls[] = $src;
                }
            }
            if ( count($img_urls) >= 2 ) break;
        }

        // JSON-LD imágenes
        foreach ( $xpath->query('//script[@type="application/ld+json"]') as $script ) {
            $json = @json_decode( $script->textContent, true );
            if ( $json && isset($json['image']) ) {
                $imgs = is_array($json['image']) ? $json['image'] : [$json['image']];
                foreach ($imgs as $img) {
                    if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) $img_urls[] = $img;
                    if (is_array($img) && !empty($img['url'])) $img_urls[] = $img['url'];
                }
            }
        }

        $img_urls = array_unique( array_filter( $img_urls, function($u) {
            $path = strtolower(parse_url($u, PHP_URL_PATH) ?? '');
            foreach (['logo','favicon','icon','sprite','badge','1x1'] as $bl) {
                if (str_contains($path,$bl)) return false;
            }
            return true;
        }));

        if ( $img_urls ) $data['_image_urls_to_import'] = array_slice(array_values($img_urls), 0, 8);

        // ── Homologaciones (scan de palabras clave) ──────────────────
        $full_text = strtolower( $html );
        $homo_patterns = [
            'CIK-FIA'       => '/cik[\s-]?fia/i',
            'Snell SA2025'  => '/snell\s*sa\s*20\s*25/i',
            'Snell SA2020'  => '/snell\s*sa\s*20\s*20/i',
            'FIA 8877-2022' => '/8877[\s-]?2022/i',
            'FIA 8860-2018' => '/8860[\s-]?2018/i',
            'FIA 8870-2018' => '/8870[\s-]?2018/i',
            'CMR 2016'      => '/cmr\s*2016/i',
        ];
        $homos_detected = [];
        foreach ( $homo_patterns as $label => $pattern ) {
            if ( preg_match( $pattern, $html ) ) {
                $parts = explode( ' ', $label, 2 );
                $homos_detected[] = [
                    'tipo'        => $parts[0],
                    'codigo'      => $parts[1] ?? '',
                    'descripcion' => '',
                ];
            }
        }
        if ( $homos_detected ) $data['homologacion'] = $homos_detected;

        // ── FAQ ───────────────────────────────────────────────────────
        $faq = [];
        foreach ( $xpath->query(
            '//*[contains(@class,"faq-question") or contains(@class,"accordion-header") or contains(@class,"accordion-title")]'
        ) as $q_node ) {
            $q = trim( $q_node->textContent );
            if ( strlen($q) > 10 && str_contains($q,'?') ) {
                $a_node = $q_node->nextSibling;
                while ( $a_node && $a_node->nodeType === XML_TEXT_NODE ) $a_node = $a_node->nextSibling;
                $a = $a_node ? trim($a_node->textContent) : '';
                $faq[] = [ 'pregunta' => $q, 'respuesta' => mb_substr($a, 0, 400) ];
            }
            if ( count($faq) >= 6 ) break;
        }
        if ( $faq ) $data['faq'] = $faq;

        // ── SKU ──────────────────────────────────────────────────────
        foreach ( $xpath->query('//*[@itemprop="sku"] | //*[contains(@class,"sku")]') as $el ) {
            $t = trim($el->textContent);
            if ( $t && strlen($t) < 40 && preg_match('/[A-Z0-9\-]+/i', $t) ) {
                $data['sku'] = $t;
                break;
            }
        }

        // ── Categorías (breadcrumb) ───────────────────────────────────
        $cats = [];
        foreach ( $xpath->query('//nav[contains(@class,"breadcrumb")]//a | //ol[contains(@class,"breadcrumb")]//a') as $a ) {
            $t = trim($a->textContent);
            if ( $t && strlen($t) < 60 && strtolower($t) !== 'inicio' && strtolower($t) !== 'home' ) {
                $cats[] = $t;
            }
        }
        if ( $cats ) $data['categories'] = array_slice( $cats, 0, 3 );

        return $data;
    }

    /* ═══════════════════════════════════════════════════════════════
       DETECCIÓN AUTOMÁTICA DE PLANTILLA
    ═══════════════════════════════════════════════════════════════ */

    private static function detect_template( string $name, string $brand ): string {
        $text = strtolower( $name . ' ' . $brand );

        $rules = [
            'casco'      => ['casco','helmet','casque'],
            'guantes'    => ['guantes','gloves','gants'],
            'botas'      => ['botas','boots','chaussures'],
            'mono'       => ['mono ','overall','karting suit','traje'],
            'proteccion' => ['rib','protector','costillas','protection'],
            'chasis'     => ['chasis','chassis','kart ','racer'],
            'motor'      => ['motor','engine','moteur','tm ','iame ','rotax'],
            'neumatico'  => ['neumático','neumatico','tyre','tire','pneu'],
            'telemetria' => ['mychron','telemetría','aim ','dash','logger'],
            'recambio'   => ['recambio','pieza','part','bearing','rodamiento','eje'],
        ];

        foreach ( $rules as $template => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( str_contains( $text, $kw ) ) return $template;
            }
        }

        return 'accesorio';
    }

    /* ═══════════════════════════════════════════════════════════════
       RELLENAR CON LLM LAS SECCIONES VACÍAS
    ═══════════════════════════════════════════════════════════════ */

    private static function fill_with_llm( array $data ): array {
        if ( ! class_exists('CKG_LLM') || ! CKG_LLM::ping() ) return $data;

        $name  = $data['product_name'] ?? '';
        $brand = $data['brand']        ?? '';
        $ctx   = [
            'product_name'   => $name,
            'brand'          => $brand,
            'homologaciones' => array_map(
                fn($h) => ($h['tipo'] ?? '') . ' ' . ($h['codigo'] ?? ''),
                $data['homologacion'] ?? []
            ),
        ];

        // ── Intro ────────────────────────────────────────────────────
        if ( empty( $data['intro'] ) || strlen( $data['intro'] ) < 80 ) {
            $spec_str = ! empty( $data['specs'] )
                ? implode( ', ', array_map( fn($s) => "{$s['key']}: {$s['value']}", array_slice($data['specs'],0,4) ) )
                : '';
            $homo_str = ! empty( $data['homologacion'] )
                ? implode( ', ', array_map( fn($h) => "{$h['tipo']} {$h['codigo']}", $data['homologacion'] ) )
                : '';

            $result = CKG_LLM::improve( 'intro',
                "Producto: $name" . ($brand?" de $brand":"") . ". $homo_str. $spec_str.",
                $ctx
            );
            if ( ! is_wp_error($result) ) $data['intro'] = $result;
        }

        // ── Descripción corta ────────────────────────────────────────
        if ( empty( $data['short_desc'] ) ) {
            $result = CKG_LLM::improve( 'short_desc',
                "$name" . ($brand?" - $brand":"") . ". Producto de karting de competición.",
                $ctx
            );
            if ( ! is_wp_error($result) ) $data['short_desc'] = $result;
        }

        // ── Conclusión ───────────────────────────────────────────────
        if ( empty( $data['conclusion'] ) ) {
            $result = CKG_LLM::improve( 'conclusion',
                "Resumen de $name" . ($brand?" de $brand":"") . ".",
                $ctx
            );
            if ( ! is_wp_error($result) ) $data['conclusion'] = $result;
        }

        // ── Respuestas FAQ vacías ────────────────────────────────────
        if ( ! empty( $data['faq'] ) ) {
            $empty_qs = array_filter( $data['faq'], fn($f) => empty($f['respuesta']) );
            if ( $empty_qs ) {
                $qs_text = implode("\n", array_map(fn($f,$i) => ($i+1).'. '.$f['pregunta'], $empty_qs, array_keys($empty_qs)));
                $prompt  = "Producto: $name. Responde estas preguntas sobre él:\n$qs_text\nJSON: [{\"pregunta\":\"...\",\"respuesta\":\"...\"}]";
                $result  = CKG_LLM::improve_raw( $prompt, $ctx );

                if ( ! is_wp_error($result) ) {
                    $clean   = trim( preg_replace('/```json|```/', '', $result) );
                    $decoded = @json_decode( $clean, true );
                    if ( is_array($decoded) ) {
                        $answers = array_column($decoded, 'respuesta');
                        $idx     = 0;
                        foreach ( $data['faq'] as &$faq_item ) {
                            if ( empty($faq_item['respuesta']) && isset($answers[$idx]) ) {
                                $faq_item['respuesta'] = $answers[$idx++];
                            }
                        }
                        unset($faq_item);
                    }
                }
            }
        }

        return $data;
    }

    /* ═══════════════════════════════════════════════════════════════
       SEO AUTOMÁTICO
    ═══════════════════════════════════════════════════════════════ */

    private static function fill_seo( array $data ): array {
        if ( ! class_exists('CKG_LLM') || ! CKG_LLM::ping() ) {
            // Fallback sin LLM
            $name  = $data['product_name'] ?? '';
            $brand = $data['brand']        ?? '';
            $homo  = ! empty( $data['homologacion'] )
                ? $data['homologacion'][0]['tipo'] . ' ' . $data['homologacion'][0]['codigo']
                : '';

            if ( empty($data['seo_title']) ) {
                $title = $name . ($homo ? " | $homo" : '') . ' | CMSKart';
                $data['seo_title'] = mb_substr($title, 0, 60);
            }
            if ( empty($data['seo_description']) ) {
                $desc = trim("$name" . ($brand?" $brand":"") . ($homo?". $homo":"") . ". Envío 24-72h. Compra en CMSKart.");
                $data['seo_description'] = mb_substr($desc, 0, 155);
            }
            if ( empty($data['seo_keywords']) ) {
                $data['seo_keywords'] = strtolower($name) . ($brand ? ' ' . strtolower($brand) : '');
            }
            return $data;
        }

        $ctx = [ 'product_name' => $data['product_name'], 'brand' => $data['brand'] ?? '' ];

        if ( empty($data['seo_title']) ) {
            $r = CKG_LLM::improve('seo_title', $data['product_name'] . ' ' . ($data['brand']??''), $ctx);
            if (!is_wp_error($r)) $data['seo_title'] = trim($r);
        }
        if ( empty($data['seo_description']) ) {
            $ref = ($data['short_desc'] ?? '') ?: $data['product_name'];
            $r   = CKG_LLM::improve('seo_description', $ref, $ctx);
            if (!is_wp_error($r)) $data['seo_description'] = trim($r);
        }
        if ( empty($data['seo_keywords']) ) {
            $data['seo_keywords'] = strtolower($data['product_name']) . ($data['brand'] ? ' ' . strtolower($data['brand']) : '');
        }

        return $data;
    }
}
