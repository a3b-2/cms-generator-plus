<?php
/**
 * CKG_Competitor_Scraper
 * Extrae información de producto de URLs de la competencia y la sintetiza con Ollama.
 *
 * Extrae de cada URL:
 *  - Título del producto
 *  - Descripción (párrafos relevantes)
 *  - Precio
 *  - Especificaciones (tablas, listas dl/dt/dd)
 *  - FAQ (acordeones, secciones de preguntas)
 *  - Homologaciones (palabras clave CIK-FIA, Snell, FIA…)
 *  - Metadatos OG (og:title, og:description)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Competitor_Scraper {

    /* ── Constantes ───────────────────────────────────────────────────── */
    const MAX_URLS    = 5;
    const TIMEOUT     = 20;
    const MAX_CONTENT = 8000; // caracteres máximos enviados a Ollama

    /* ── Entrada principal ────────────────────────────────────────────── */

    /**
     * @param array  $urls          Array de URLs de competidores (máx 5)
     * @param string $product_name  Nombre del producto a buscar
     * @param string $section       Sección a generar: all | intro | specs | faq | conclusion
     * @return array|\WP_Error      ['raw' => datos por URL, 'synthesis' => texto Ollama]
     */
    public static function analyze( array $urls, string $product_name, string $section = 'all' ) {
        $urls    = array_slice( array_filter( array_map( 'esc_url_raw', $urls ) ), 0, self::MAX_URLS );
        if ( empty( $urls ) ) return new WP_Error( 'no_urls', 'No se proporcionaron URLs válidas.' );

        $collected = [];

        foreach ( $urls as $url ) {
            $data = self::scrape_url( $url );
            if ( ! is_wp_error( $data ) && ! empty( $data ) ) {
                $collected[ $url ] = $data;
            }
        }

        if ( empty( $collected ) ) {
            return new WP_Error( 'no_data', 'No se pudo obtener información de ninguna de las URLs proporcionadas.' );
        }

        $synthesis = self::synthesize( $collected, $product_name, $section );

        return [
            'raw'       => $collected,
            'synthesis' => $synthesis,
        ];
    }

    /* ── Scraping de una URL ──────────────────────────────────────────── */

    public static function scrape_url( string $url ): array|\WP_Error {
        $response = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
                'Accept'          => 'text/html,application/xhtml+xml',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            // Reintentar sin headers extra por si hay bloqueo
            $response = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT, 'sslverify' => false ] );
        }
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fetch_error', "No se pudo cargar $url: " . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'http_error', "HTTP $code al cargar $url" );
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) return new WP_Error( 'empty', "Respuesta vacía en $url" );

        return self::parse_html( $html, $url );
    }

    /* ── Parseo HTML con DOMDocument ──────────────────────────────────── */

    private static function parse_html( string $html, string $url ): array {
        $data = [
            'url'            => $url,
            'domain'         => parse_url( $url, PHP_URL_HOST ),
            'title'          => '',
            'og_title'       => '',
            'og_description' => '',
            'og_image'       => '',
            'product_image'  => '',
            'price'          => '',
            'descriptions'   => [],
            'specs'          => [],
            'variations'     => [],
            'faq'            => [],
            'homologaciones' => [],
            'badges'         => [],
        ];

        // Silenciar errores de parsing HTML mal formado
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // ── Título ──────────────────────────────────────────────────────
        $h1 = $xpath->query( '//h1' );
        if ( $h1->length > 0 ) $data['title'] = trim( $h1->item(0)->textContent );

        // ── Texto visible limpio para Ollama (fallback universal) ─────
        // Eliminar scripts, styles y nav antes de extraer texto
        foreach ( $xpath->query( '//script|//style|//nav|//header|//footer|//aside|//form' ) as $node ) {
            $node->parentNode->removeChild( $node );
        }
        $body_node = $xpath->query( '//body' )->item(0);
        $raw_text  = $body_node ? trim( preg_replace( '/\s+/', ' ', $body_node->textContent ) ) : '';
        // Guardar hasta 5000 chars del texto visible
        $data['page_text'] = mb_substr( $raw_text, 0, 5000 );

        // ── OG meta (titulo, descripcion e imagen) ──────────────────
        $base_url = parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST );
        foreach ( $xpath->query( '//meta[@property]' ) as $meta ) {
            $prop = $meta->getAttribute( 'property' );
            $val  = trim( $meta->getAttribute( 'content' ) );
            if ( $prop === 'og:title'       ) $data['og_title']       = $val;
            if ( $prop === 'og:description' ) $data['og_description'] = $val;
            if ( $prop === 'og:image' && $val ) {
                if ( str_starts_with( $val, '//' ) ) $val = 'https:' . $val;
                elseif ( str_starts_with( $val, '/' ) ) $val = $base_url . $val;
                $data['og_image'] = $val;
            }
        }

        // ── Imagen principal del producto ───────────────────────────
        // Validar que og:image es una URL de imagen real (no una URL de share/redirect)
        $og_is_image = false;
        if ( $data['og_image'] ) {
            $path = strtolower( parse_url( $data['og_image'], PHP_URL_PATH ) ?? '' );
            $img_exts = [ '.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp', '.avif' ];
            foreach ( $img_exts as $ext ) {
                if ( str_ends_with( $path, $ext ) || str_contains( $path, $ext . '?' ) ) {
                    $og_is_image = true;
                    break;
                }
            }
            // Si no tiene extensión pero el dominio es conocido (CDN), aceptar
            if ( ! $og_is_image && preg_match( '/\.(cloudfront|cloudinary|imgix|wp-content|uploads)\./', $data['og_image'] ) ) {
                $og_is_image = true;
            }
        }

        if ( $og_is_image ) {
            $data['product_image'] = $data['og_image'];
        } else {
            $img_selectors = [
                '//figure[contains(@class,"woocommerce-product-gallery__image")]//img',
                '//div[contains(@class,"product-gallery")]//img[1]',
                '//img[contains(@class,"wp-post-image")]',
                '//img[@itemprop="image"]',
            ];
            foreach ( $img_selectors as $img_sel ) {
                $img_node = $xpath->query( $img_sel )->item(0);
                if ( $img_node ) {
                    $src = $img_node->getAttribute('data-large_image')
                        ?: $img_node->getAttribute('data-src')
                        ?: $img_node->getAttribute('src');
                    if ( $src && ! str_contains( strtolower($src), 'placeholder' ) ) {
                        if ( str_starts_with( $src, '//' ) ) $src = 'https:' . $src;
                        elseif ( str_starts_with( $src, '/' ) ) $src = $base_url . $src;
                        $data['product_image'] = $src;
                        break;
                    }
                }
            }
        }

        // ── Variaciones / Atributos ─────────────────────────────────
        $variation_data = [];
        // 1. JSON de variaciones de WooCommerce
        $vform = $xpath->query( '//form[contains(@class,"variations_form")]' )->item(0);
        if ( $vform ) {
            $json_raw = $vform->getAttribute( 'data-product_variations' );
            if ( $json_raw ) {
                $vars = @json_decode( html_entity_decode( $json_raw ), true );
                if ( is_array( $vars ) ) {
                    foreach ( $vars as $var ) {
                        foreach ( $var['attributes'] ?? [] as $aname => $aval ) {
                            $aname_clean = preg_replace( '/^attribute_(pa_)?/', '', $aname );
                            $variation_data[] = [
                                'label'     => $aval,
                                'attr_name' => $aname_clean,
                                'price'     => $var['display_price'] ?? 0,
                            ];
                        }
                    }
                }
            }
        }
        // 2. Select de atributos
        if ( empty( $variation_data ) ) {
            foreach ( $xpath->query( '//select[contains(@name,"attribute_")]' ) as $select ) {
                $aname = preg_replace( '/^attribute_(pa_)?/', '', $select->getAttribute('name') );
                foreach ( $xpath->query( './/option[@value!=""]', $select ) as $opt ) {
                    $variation_data[] = [ 'label' => trim( $opt->textContent ), 'attr_name' => $aname, 'price' => 0 ];
                }
                break;
            }
        }
        if ( ! empty( $variation_data ) ) {
            $data['variations'] = array_slice( $variation_data, 0, 20 );
        }


        // ── Precio ────────────────────────────────────────────────────
        $price_selectors = [
            '//*[contains(@class,"price") or contains(@class,"precio") or contains(@class,"woocommerce-Price")]',
            '//*[@itemprop="price"]',
            '//*[contains(@class,"product-price")]',
        ];
        foreach ( $price_selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes->length > 0 ) {
                $text = trim( $nodes->item(0)->textContent );
                if ( preg_match( '/[\d]+[.,]?\d*\s*(?:€|EUR)/', $text, $m ) ) {
                    $data['price'] = $m[0];
                    break;
                }
            }
        }

        // ── Descripción (párrafos del contenido principal) ────────────
        $desc_selectors = [
            '//div[contains(@class,"product-description") or contains(@class,"woocommerce-product-details__short-description")]//p',
            '//div[contains(@class,"descripcion") or contains(@class,"description")]//p',
            '//div[contains(@class,"tab-content") or contains(@class,"product_tabs")]//p',
            '//article//p',
        ];
        $found_desc = false;
        foreach ( $desc_selectors as $sel ) {
            $paras = $xpath->query( $sel );
            if ( $paras->length >= 1 ) {
                foreach ( $paras as $p ) {
                    $text = trim( $p->textContent );
                    if ( strlen( $text ) > 40 && strlen( $text ) < 1500 ) {
                        $data['descriptions'][] = $text;
                    }
                    if ( count( $data['descriptions'] ) >= 6 ) break;
                }
                if ( count( $data['descriptions'] ) >= 2 ) { $found_desc = true; break; }
            }
        }

        // ── Especificaciones (tablas y listas de definicion) ──────────
        // Tablas WooCommerce y genericas
        $spec_table_queries = [
            '//table[contains(@class,"woocommerce-product-attributes")]//tr',
            '//table[contains(@class,"spec")]//tr',
            '//table[contains(@class,"techni")]//tr',
            '//table[contains(@class,"caracteris")]//tr',
            '//table[contains(@class,"product-attr")]//tr',
            '//table[contains(@class,"ficha")]//tr',
            '//table[contains(@class,"datos")]//tr',
            '//div[contains(@class,"product__info")]//table//tr',
        ];
        foreach ( $spec_table_queries as $spec_query ) {
        foreach ( $xpath->query( $spec_query ) as $row ) {
            $cells = $row->getElementsByTagName( 'td' );
            $ths   = $row->getElementsByTagName( 'th' );
            if ( $cells->length >= 2 ) {
                $k = trim( $cells->item(0)->textContent );
                $v = trim( $cells->item(1)->textContent );
                if ( $k && $v ) $data['specs'][] = [ 'key' => $k, 'value' => $v ];
            } elseif ( $ths->length > 0 && $cells->length > 0 ) {
                $k = trim( $ths->item(0)->textContent );
                $v = trim( $cells->item(0)->textContent );
                if ( $k && $v ) $data['specs'][] = [ 'key' => $k, 'value' => $v ];
            }
        }
        } // fin $spec_table_queries loop

        // Si no hay tabla, probar listas dl/dt/dd
        if ( empty( $data['specs'] ) ) {
            $dls = $xpath->query( '//dl[contains(@class,"spec") or contains(@class,"tech") or contains(@class,"data")]' );
            foreach ( $dls as $dl ) {
                $dts = $dl->getElementsByTagName( 'dt' );
                $dds = $dl->getElementsByTagName( 'dd' );
                for ( $i = 0; $i < $dts->length; $i++ ) {
                    $k = trim( $dts->item( $i )->textContent );
                    $v = $dds->item( $i ) ? trim( $dds->item( $i )->textContent ) : '';
                    if ( $k && $v ) $data['specs'][] = [ 'key' => $k, 'value' => $v ];
                }
            }
        }

        // Fallback de descripcion: short-description de WooCommerce
        if ( empty( $data['descriptions'] ) ) {
            $short_desc = $xpath->query( '//div[contains(@class,"woocommerce-product-details__short-description")]' )->item(0);
            if ( ! $short_desc ) $short_desc = $xpath->query( '//div[@class="product-short-description" or @class="short_description"]' )->item(0);
            if ( $short_desc ) {
                $txt = trim( strip_tags( $short_desc->textContent ) );
                if ( strlen( $txt ) > 20 ) $data['descriptions'][] = $txt;
            }
        }
        // Fallback final: og:description
        if ( empty( $data['descriptions'] ) && $data['og_description'] ) {
            $data['descriptions'][] = $data['og_description'];
        }

        // ── FAQ ──────────────────────────────────────────────────────
        // Patrones comunes de acordeón/FAQ
        $faq_q_selectors = [
            '//*[contains(@class,"faq-question") or contains(@class,"accordion-title") or contains(@class,"question")]',
            '//details/summary',
            '//h3[contains(@class,"faq") or contains(text(),"?")]',
            '//h4[contains(text(),"?")]',
        ];
        $faq_questions = [];
        foreach ( $faq_q_selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes->length > 0 ) {
                foreach ( $nodes as $node ) {
                    $q = trim( $node->textContent );
                    if ( strlen( $q ) > 10 && strpos( $q, '?' ) !== false ) {
                        // Intentar obtener la respuesta del siguiente elemento
                        $next = $node->nextSibling;
                        while ( $next && $next->nodeType === XML_TEXT_NODE ) $next = $next->nextSibling;
                        $a = $next ? trim( $next->textContent ) : '';
                        $data['faq'][] = [ 'pregunta' => $q, 'respuesta' => substr( $a, 0, 400 ) ];
                    }
                    if ( count( $data['faq'] ) >= 8 ) break;
                }
                if ( ! empty( $data['faq'] ) ) break;
            }
        }

        // ── Homologaciones (scan de palabras clave) ───────────────────
        $full_text = strtolower( strip_tags( $html ) );
        $homo_patterns = [
            'CIK-FIA'       => '/cik[\s-]?fia/i',
            'Snell SA2025'  => '/snell\s*sa\s*20\s*25/i',
            'Snell SA2020'  => '/snell\s*sa\s*20\s*20/i',
            'FIA 8877-2022' => '/8877[\s-]?2022/i',
            'FIA 8860-2018' => '/8860[\s-]?2018/i',
            'FIA 8870-2018' => '/8870[\s-]?2018/i',
            'CMR 2016'      => '/cmr\s*2016/i',
            'CE EN13594'    => '/en[\s-]?13594/i',
            'ISO 9001'      => '/iso\s*9001/i',
        ];
        foreach ( $homo_patterns as $label => $pattern ) {
            if ( preg_match( $pattern, $html ) ) {
                $data['homologaciones'][] = $label;
            }
        }
        $data['homologaciones'] = array_unique( $data['homologaciones'] );

        // Limitar specs
        $data['specs'] = array_slice( $data['specs'], 0, 20 );

        return $data;
    }

    /* ── Síntesis con Ollama ─────────────────────────────────────────── */

    private static function synthesize( array $collected, string $product_name, string $section ): array {
        $result = [];
        $ctx    = [ 'product_name' => $product_name ];
        $llm_ok = class_exists( 'CKG_LLM' ) && class_exists( 'CKG_Ollama' );

        // ── Specs: SIEMPRE merge de tablas extraidas (no requiere Ollama) ──
        $merged_specs = [];
        $seen_keys    = [];
        foreach ( $collected as $data ) {
            foreach ( ( $data['specs'] ?? [] ) as $spec ) {
                $key_norm = strtolower( trim( $spec['key'] ) );
                if ( ! isset( $seen_keys[ $key_norm ] ) && ! empty( $spec['value'] ) ) {
                    $merged_specs[] = $spec;
                    $seen_keys[ $key_norm ] = true;
                }
            }
        }
        if ( ! empty( $merged_specs ) ) {
            $result['specs'] = $merged_specs;
        }

        // ── Extraer specs adicionales de las descripciones con Ollama ──
        // Si no hay specs de tablas pero sí hay descripciones, Ollama las extrae
        if ( empty( $merged_specs ) && $llm_ok ) {
            $desc_text = '';
            foreach ( $collected as $data ) {
                foreach ( array_slice( $data['descriptions'] ?? [], 0, 3 ) as $d ) {
                    $desc_text .= $d . "

";
                }
            }
            if ( strlen( $desc_text ) > 100 ) {
                $pname_label = $product_name ? 'del producto "' . $product_name . '"' : '';
                $sp = 'Extrae especificaciones tecnicas del texto ' . $pname_label . '. '
                    . 'Responde SOLO un JSON array: [{"key":"nombre","value":"valor"}]. '
                    . 'Incluye todo: dimensiones, pesos, materiales, potencias, referencias.'
                    . "

TEXTO:
" . mb_substr( $desc_text, 0, 2000 );
                $r = CKG_LLM::improve_raw( $sp, $ctx );
                if ( ! is_wp_error( $r ) && $r ) {
                    $clean  = trim( preg_replace( '/```json|```/', '', $r ) );
                    $parsed = @json_decode( $clean, true );
                    if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                        $result['specs'] = array_slice( $parsed, 0, 20 );
                    }
                }
            }
        }

        // ── Si faltan specs o descripciones, usar page_text con Ollama ──────
        if ( $llm_ok ) {
            foreach ( $collected as &$page_data ) {
                $has_enough = count( $page_data['descriptions'] ?? [] ) >= 2
                           || count( $page_data['specs']        ?? [] ) >= 3;

                if ( ! $has_enough && ! empty( $page_data['page_text'] ) ) {
                    $pname = $product_name ?: ( $page_data['og_title'] ?? '' );
                    $ptxt  = mb_substr( $page_data['page_text'], 0, 3000 );
                    $extract_prompt = 'Analiza el siguiente texto de una pagina web de producto'
                        . ( $pname ? ' ("' . $pname . '")' : '' ) . '.'
                        . ' Responde SOLO con JSON con esta estructura exacta:'
                        . ' {"specs":[{"key":"...","value":"..."}],'
                        . '"description":"parrafo descripcion del producto en espanol",'
                        . '"faq":[{"pregunta":"...","respuesta":"..."}]}'
                        . ' Extrae specs tecnicas reales, descripcion util, y FAQs si las hay.'
                        . "

TEXTO:
" . $ptxt;

                    $r = CKG_LLM::improve_raw( $extract_prompt, [ 'product_name' => $pname ] );
                    if ( ! is_wp_error( $r ) && $r ) {
                        $clean   = trim( preg_replace( '/```json|```/', '', $r ) );
                        $decoded = @json_decode( $clean, true );
                        if ( is_array( $decoded ) ) {
                            if ( ! empty( $decoded['specs'] ) && empty( $page_data['specs'] ) ) {
                                $page_data['specs'] = $decoded['specs'];
                            }
                            if ( ! empty( $decoded['description'] ) && empty( $page_data['descriptions'] ) ) {
                                $page_data['descriptions'][] = $decoded['description'];
                            }
                            if ( ! empty( $decoded['faq'] ) && empty( $page_data['faq'] ) ) {
                                $page_data['faq'] = $decoded['faq'];
                            }
                        }
                    }
                }
            }
            unset( $page_data );

            // Re-merge specs después del enriquecimiento con Ollama
            $merged_specs = [];
            $seen_keys    = [];
            foreach ( $collected as $data ) {
                foreach ( ( $data['specs'] ?? [] ) as $spec ) {
                    $key_norm = strtolower( trim( $spec['key'] ?? '' ) );
                    if ( $key_norm && ! isset( $seen_keys[ $key_norm ] ) && ! empty( $spec['value'] ) ) {
                        $merged_specs[] = $spec;
                        $seen_keys[ $key_norm ] = true;
                    }
                }
            }
            if ( ! empty( $merged_specs ) ) $result['specs'] = $merged_specs;
        }

        // Construir resumen para prompts de Ollama
        $summary = self::build_summary( $collected );

        // ── Generar intro/FAQ/conclusion con Ollama si disponible ──
        if ( $llm_ok ) {
        if ( $section === 'all' || $section === 'intro' ) {
            $prompt_intro = self::prompt_intro( $summary, $product_name );
            $r = CKG_LLM::improve_raw( $prompt_intro, $ctx );
            if ( ! is_wp_error( $r ) && $r ) $result['intro'] = $r;
        }

        if ( $section === 'all' || $section === 'specs' ) {
            // Ya procesado arriba
            $merged_specs = []; // variable local limpia
        }

        if ( $section === 'all' || $section === 'faq' ) {
            $faq_all = [];
            foreach ( $collected as $data ) {
                foreach ( $data['faq'] as $item ) {
                    if ( ! empty( $item['respuesta'] ) ) $faq_all[] = $item;
                }
            }
            if ( ! empty( $faq_all ) ) {
                $faq_text = '';
                foreach ( array_slice( $faq_all, 0, 8 ) as $item ) {
                    $faq_text .= "P: {$item['pregunta']}\nR: {$item['respuesta']}\n\n";
                }
                $prompt_faq = self::prompt_faq( $faq_text, $product_name );
                $r = CKG_LLM::improve_raw( $prompt_faq, $ctx );
                if ( ! is_wp_error( $r ) ) $result['faq_text'] = $r;
            }
        }

        if ( $section === 'all' || $section === 'conclusion' ) {
            $prompt_concl = self::prompt_conclusion( $summary, $product_name );
            $r = CKG_LLM::improve_raw( $prompt_concl, $ctx );
            if ( ! is_wp_error( $r ) ) $result['conclusion'] = $r;
        }

        } // fin if($llm_ok)

        // Homologaciones detectadas (merge de todos — SIEMPRE, sin necesidad de Ollama)
        $all_homos = [];
        foreach ( $collected as $data ) {
            $all_homos = array_merge( $all_homos, $data['homologaciones'] );
        }
        if ( ! empty( $all_homos ) ) {
            $result['homologaciones_detected'] = array_values( array_unique( $all_homos ) );
        }

        // Rango de precios
        $prices = [];
        foreach ( $collected as $data ) {
            if ( $data['price'] ) $prices[] = $data['price'];
        }
        if ( ! empty( $prices ) ) $result['prices_found'] = $prices;

        // Imagen del primer competidor con imagen
        foreach ( $collected as $data ) {
            if ( ! empty( $data['product_image'] ) ) {
                $result['product_image'] = $data['product_image'];
                break;
            }
        }

        // Todas las imagenes de competidores
        $all_images = [];
        foreach ( $collected as $data ) {
            if ( ! empty( $data['product_image'] ) ) {
                $all_images[] = $data['product_image'];
            }
        }
        if ( ! empty( $all_images ) ) $result['images_found'] = array_values( array_unique( $all_images ) );

        // Variaciones mergeadas de todos los competidores
        $all_variations = [];
        $seen_labels    = [];
        foreach ( $collected as $data ) {
            foreach ( $data['variations'] ?? [] as $var ) {
                $label = trim( $var['label'] );
                if ( $label && ! in_array( strtolower($label), $seen_labels ) ) {
                    $all_variations[] = $var;
                    $seen_labels[]    = strtolower( $label );
                }
            }
        }
        if ( ! empty( $all_variations ) ) $result['variations_found'] = $all_variations;

        return $result;
    }

    /* ── Helpers de prompts ───────────────────────────────────────────── */

    private static function build_summary( array $collected ): string {
        $text = '';
        foreach ( $collected as $url => $data ) {
            $domain = $data['domain'] ?? parse_url( $url, PHP_URL_HOST );
            $text  .= "=== COMPETIDOR: $domain ===\n";
            if ( $data['title'] )          $text .= "Titulo: {$data['title']}\n";
            if ( $data['price'] )          $text .= "Precio: {$data['price']}\n";
            if ( $data['product_image'] )  $text .= "Imagen: {$data['product_image']}\n";
            if ( $data['homologaciones'] ) $text .= "Homologaciones: " . implode( ', ', $data['homologaciones'] ) . "\n";
            if ( ! empty( $data['variations'] ) ) {
                $text .= "Variaciones: " . implode( ', ', array_column( $data['variations'], 'label' ) ) . "\n";
            }
            if ( $data['descriptions'] )   $text .= "Descripción:\n" . implode( "\n", array_slice( $data['descriptions'], 0, 3 ) ) . "\n";
            if ( $data['specs'] ) {
                $text .= "Especificaciones:\n";
                foreach ( array_slice( $data['specs'], 0, 10 ) as $s ) {
                    $text .= "  - {$s['key']}: {$s['value']}\n";
                }
            }
            $text .= "\n";
        }
        return substr( $text, 0, self::MAX_CONTENT );
    }

    private static function prompt_intro( string $summary, string $name ): string {
        return <<<PROMPT
Eres redactor SEO experto en karting de competición para CMSKart.es.
A continuación tienes información extraída de webs de la competencia sobre el producto "$name".

DATOS DE COMPETIDORES:
$summary

TAREA: Escribe un párrafo de introducción original y mejorado para CMSKart.es (150-200 palabras).
- Usa <strong>$name</strong> al inicio.
- Integra las ventajas técnicas más destacadas.
- Menciona las homologaciones si aparecen.
- Idioma español de España. Tono profesional y directo para pilotos de karting.
- Responde SOLO el texto del párrafo, sin explicaciones ni encabezados.
PROMPT;
    }

    private static function prompt_faq( string $faq_text, string $name ): string {
        return <<<PROMPT
Eres redactor SEO experto en karting de competición para CMSKart.es.
A continuación tienes preguntas frecuentes encontradas en webs de la competencia sobre "$name".

PREGUNTAS DE COMPETIDORES:
$faq_text

TAREA: Selecciona las 5 preguntas más relevantes y escribe respuestas mejoradas, más completas y en español de España.
Formato de respuesta estricto (JSON array):
[
  {"pregunta": "...", "respuesta": "..."},
  {"pregunta": "...", "respuesta": "..."}
]
Responde SOLO el JSON, sin texto adicional.
PROMPT;
    }

    private static function prompt_conclusion( string $summary, string $name ): string {
        return <<<PROMPT
Eres redactor SEO experto en karting de competición para CMSKart.es.
Información de competidores sobre "$name":

$summary

TAREA: Escribe una conclusión persuasiva (120-150 palabras) para la página de producto en CMSKart.es.
- Resume los 3 puntos fuertes más importantes del producto.
- Termina con una llamada a la acción directa y motivadora.
- Idioma: español de España.
- Responde SOLO el texto de la conclusión.
PROMPT;
    }
}
