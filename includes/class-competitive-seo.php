<?php
/**
 * CKG_Competitive_SEO
 * Análisis SEO competitivo real.
 *
 * Proceso:
 *  1. Construye URL de búsqueda Google para la keyword objetivo
 *  2. Descarga los resultados (usando user-agent de navegador real)
 *  3. Extrae top 5-8 resultados orgánicos: title, description, URL, dominio
 *  4. Carga cada página y extrae: word_count, h2_count, faq_count, spec_count,
 *     price, images_count, schema_types
 *  5. Compara con los datos del formulario propio
 *  6. Genera puntuación relativa y recomendaciones con Ollama
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Competitive_SEO {

    const TIMEOUT = 15;

    /* ═══════════════════════════════════════════════════════════════
       PUNTO DE ENTRADA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * @param string $keyword        Keyword principal del producto
     * @param array  $our_data       Datos propios del formulario
     * @return array|\WP_Error
     */
    public static function analyze( string $keyword, array $our_data ) {
        if ( empty( $keyword ) ) {
            return new WP_Error( 'no_keyword', 'Introduce la keyword principal en la pestaña SEO.' );
        }

        // ── 1. Obtener resultados Google ────────────────────────────
        $serp = self::fetch_serp( $keyword );
        if ( is_wp_error( $serp ) ) return $serp;
        if ( empty( $serp ) ) {
            return new WP_Error( 'no_results', 'No se pudieron obtener resultados de Google. Puede que haya un bloqueo temporal. Inténtalo de nuevo en unos minutos.' );
        }

        // ── 2. Analizar cada página competidora ─────────────────────
        $competitors = [];
        foreach ( array_slice( $serp, 0, 6 ) as $result ) {
            $page_data = self::analyze_page( $result['url'] );
            $competitors[] = array_merge( $result, $page_data );
        }

        // ── 3. Calcular métricas propias ────────────────────────────
        $our_metrics = self::calculate_our_metrics( $our_data );

        // ── 4. Scoring relativo ─────────────────────────────────────
        $scores = self::calculate_scores( $our_metrics, $competitors );

        // ── 5. Síntesis con Ollama ──────────────────────────────────
        $recommendations = self::generate_recommendations( $scores, $our_metrics, $competitors, $keyword );

        return [
            'keyword'         => $keyword,
            'competitors'     => $competitors,
            'our_metrics'     => $our_metrics,
            'scores'          => $scores,
            'recommendations' => $recommendations,
        ];
    }

    /* ── Fetch SERP: Perplexity (primero) → DuckDuckGo (fallback) ── */

    private static function fetch_serp( string $keyword ): array|\WP_Error {
        // Opcion 1: Perplexity API (si esta configurada) — mas fiable
        if ( class_exists( 'CKG_LLM' ) && CKG_LLM::perplexity_available() ) {
            $results = self::fetch_serp_perplexity( $keyword );
            if ( ! is_wp_error( $results ) && ! empty( $results ) ) {
                return $results;
            }
        }

        // Opcion 2: DuckDuckGo HTML (sin API, sin bloqueo agresivo)
        $results = self::fetch_serp_duckduckgo( $keyword );
        if ( ! is_wp_error( $results ) && ! empty( $results ) ) {
            return $results;
        }

        return new WP_Error( 'no_results',
            'No se pudieron obtener resultados. ' .
            ( class_exists('CKG_LLM') && CKG_LLM::perplexity_available()
                ? 'Perplexity no devolvio resultados.'
                : 'Configura la API de Perplexity en Ajustes para analisis SEO fiable.' )
        );
    }

    /* ── SERP via Perplexity sonar ──────────────────────────────── */

    private static function fetch_serp_perplexity( string $keyword ): array|\WP_Error {
        $s     = get_option( 'ckg_settings', [] );
        $key   = trim( $s['llm_key_perplexity'] ?? '' );
        $model = $s['llm_model_perplexity'] ?? 'sonar';

        $prompt = 'Busca en Google.es los 6 primeros resultados organicos para la keyword: "' . $keyword . ' karting". '
                . 'Devuelve SOLO un JSON array con esta estructura exacta: '
                . '[{"url":"https://...","title":"...","description":"...","domain":"..."}]. '
                . 'Solo resultados organicos reales, sin anuncios ni Shopping. Solo el JSON, sin texto adicional.';

        $resp = wp_remote_post( 'https://api.perplexity.ai/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'Responde SOLO con JSON valido, sin texto adicional.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'max_tokens'  => 1000,
                'temperature' => 0.1,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) return $resp;
        if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return new WP_Error( 'perplexity_err', 'Perplexity HTTP ' . wp_remote_retrieve_response_code( $resp ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $text = trim( $body['choices'][0]['message']['content'] ?? '' );
        $text = preg_replace( '/```json|```/', '', $text );
        $text = trim( $text );

        $data = json_decode( $text, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) || empty( $data ) ) {
            // intentar extraer JSON embebido en la respuesta
            if ( preg_match( '/(\[.*\]|\{.*\})/s', $text, $m ) ) {
                $data = json_decode( $m[0], true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) || empty( $data ) ) {
                    return new WP_Error( 'parse_err', 'Perplexity no devolvio JSON valido.' );
                }
            } else {
                return new WP_Error( 'parse_err', 'Perplexity no devolvio JSON valido.' );
            }
        }

        // Normalizar y validar cada resultado
        $results = [];
        foreach ( $data as $item ) {
            $url = $item['url'] ?? '';
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;
            if ( str_contains( $url, 'google' ) || str_contains( $url, 'youtube' ) ) continue;

            $results[] = [
                'url'         => $url,
                'title'       => $item['title']       ?? '',
                'description' => $item['description'] ?? '',
                'domain'      => $item['domain']       ?? parse_url( $url, PHP_URL_HOST ),
                'position'    => count( $results ) + 1,
                'source'      => 'perplexity',
            ];
            if ( count( $results ) >= 6 ) break;
        }

        return $results;
    }

    /* ── SERP via DuckDuckGo HTML (fallback sin API) ─────────────── */

    private static function fetch_serp_duckduckgo( string $keyword ): array|\WP_Error {
        $q    = urlencode( $keyword . ' karting' );
        $url  = 'https://html.duckduckgo.com/html/?q=' . $q . '&kl=es-es';

        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers'    => [
                'Accept'          => 'text/html',
                'Accept-Language' => 'es-ES,es;q=0.9',
            ],
        ] );

        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) return new WP_Error( 'ddg_err', "DuckDuckGo HTTP $code" );

        $html = wp_remote_retrieve_body( $resp );
        return self::parse_duckduckgo( $html );
    }

    private static function parse_duckduckgo( string $html ): array {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $results = [];

        // DuckDuckGo HTML usa .result__a para los enlaces
        foreach ( $xpath->query( '//a[@class="result__a"]' ) as $a ) {
            $href  = $a->getAttribute( 'href' );
            $title = trim( $a->textContent );

            // DDG usa URLs de redirección — extraer URL real
            if ( preg_match( '/uddg=([^&]+)/', $href, $m ) ) {
                $href = urldecode( $m[1] );
            }

            if ( ! filter_var( $href, FILTER_VALIDATE_URL ) ) continue;
            $domain = parse_url( $href, PHP_URL_HOST ) ?? '';
            if ( str_contains( $domain, 'duckduckgo' ) || str_contains( $domain, 'google' ) ) continue;

            // Descripcion del resultado
            $desc = '';
            $parent = ( $a->parentNode ? $a->parentNode->parentNode : null );
            if ( $parent ) {
                $snippet = $xpath->query( './/*[@class="result__snippet"]', $parent );
                if ( $snippet->length > 0 ) $desc = trim( $snippet->item(0)->textContent );
            }

            $results[] = [
                'url'         => $href,
                'title'       => $title,
                'description' => $desc,
                'domain'      => $domain,
                'position'    => count( $results ) + 1,
                'source'      => 'duckduckgo',
            ];

            if ( count( $results ) >= 6 ) break;
        }

        return $results;
    }

    private static function parse_serp( string $html ): array {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $results = [];

        // Selectores de resultados orgánicos de Google
        $selectors = [
            '//div[@class="g"]',
            '//div[contains(@class,"Gx5Zad")]',
            '//div[contains(@jscontroller,"SC7lYd")]',
        ];

        foreach ( $selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes->length >= 3 ) {
                foreach ( $nodes as $node ) {
                    $result = self::extract_serp_result( $xpath, $node );
                    if ( $result && ! empty( $result['url'] ) ) {
                        // Filtrar dominios propios y no-relevantes
                        $domain = parse_url( $result['url'], PHP_URL_HOST ) ?? '';
                        if ( str_contains( $domain, 'google' ) ) continue;
                        if ( str_contains( $domain, 'youtube' ) ) continue;
                        if ( str_contains( $domain, 'wikipedia' ) ) continue;
                        $results[] = $result;
                    }
                    if ( count( $results ) >= 8 ) break;
                }
                if ( count( $results ) >= 3 ) break;
            }
        }

        // Fallback: buscar links de resultados directamente
        if ( count( $results ) < 3 ) {
            foreach ( $xpath->query( '//a[@href]' ) as $a ) {
                $href = $a->getAttribute( 'href' );
                if ( preg_match( '#^(https?://[^/]+(?:/[^\s"]*)?)\s*$#', $href, $m ) ) {
                    $domain = parse_url( $m[1], PHP_URL_HOST ) ?? '';
                    if ( str_contains( $domain, 'google' ) ) continue;
                    if ( str_contains( $domain, 'youtube' ) ) continue;
                    if ( str_contains( $domain, 'wikipedia' ) ) continue;
                    if ( ! filter_var( $m[1], FILTER_VALIDATE_URL ) ) continue;
                    if ( ! isset( $seen[$m[1]] ) ) {
                        $results[] = [
                            'url'         => $m[1],
                            'title'       => trim( $a->textContent ),
                            'description' => '',
                            'domain'      => $domain,
                            'position'    => count( $results ) + 1,
                        ];
                        $seen[$m[1]] = true;
                    }
                    if ( count( $results ) >= 8 ) break;
                }
            }
        }

        return $results;
    }

    private static function extract_serp_result( DOMXPath $xpath, DOMNode $node ): ?array {
        // Título
        $title_nodes = $xpath->query( './/h3', $node );
        $title = $title_nodes->length > 0 ? trim( $title_nodes->item(0)->textContent ) : '';

        // URL
        $link_nodes = $xpath->query( './/a[@href]', $node );
        $url = '';
        foreach ( $link_nodes as $link ) {
            $href = $link->getAttribute( 'href' );
            if ( filter_var( $href, FILTER_VALIDATE_URL ) && ! str_contains( $href, 'google' ) ) {
                $url = $href;
                break;
            }
        }
        if ( empty( $url ) ) return null;

        // Descripción
        $desc = '';
        foreach ( $xpath->query( './/span | .//div[@class]', $node ) as $el ) {
            $text = trim( $el->textContent );
            if ( strlen( $text ) > 80 && strlen( $text ) < 350 ) {
                $desc = $text;
                break;
            }
        }

        return [
            'url'         => $url,
            'title'       => $title,
            'description' => $desc,
            'domain'      => parse_url( $url, PHP_URL_HOST ) ?? '',
            'position'    => 0,
        ];
    }

    /* ── Analizar página competidora ─────────────────────────────── */

    private static function analyze_page( string $url ): array {
        $defaults = [
            'word_count'   => 0,
            'h2_count'     => 0,
            'h3_count'     => 0,
            'faq_count'    => 0,
            'spec_count'   => 0,
            'image_count'  => 0,
            'has_schema'   => false,
            'schema_types' => [],
            'price'        => '',
            'title_len'    => 0,
            'desc_len'     => 0,
            'error'        => '',
        ];

        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (compatible; CMSKartBot/1.0)',
        ] );

        if ( is_wp_error( $resp ) ) {
            $defaults['error'] = $resp->get_error_message();
            return $defaults;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) {
            $defaults['error'] = "HTTP $code";
            return $defaults;
        }

        $html = wp_remote_retrieve_body( $resp );

        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        // Palabras en el contenido principal
        $body_text = '';
        foreach ( $xpath->query( '//main | //article | //div[@class and contains(@class,"product")]' ) as $el ) {
            $body_text .= ' ' . $el->textContent;
            if ( str_word_count( $body_text ) > 50 ) break;
        }
        if ( ! $body_text ) $body_text = $dom->documentElement ? $dom->documentElement->textContent : '';
        $word_count = str_word_count( strip_tags( $body_text ) );

        // H2 / H3
        $h2_count = $xpath->query( '//h2' )->length;
        $h3_count = $xpath->query( '//h3' )->length;

        // FAQ
        $faq_count = max(
            $xpath->query( '//*[contains(@class,"faq") or contains(@class,"accordion")]' )->length,
            $xpath->query( '//h3[contains(text(),"?")]' )->length
        );

        // Especificaciones
        $spec_count = max(
            $xpath->query( '//table[contains(@class,"spec") or contains(@class,"techni")]' )->length * 5,
            $xpath->query( '//table//tr' )->length
        );
        $spec_count = min( $spec_count, 30 );

        // Imágenes del producto
        $image_count = $xpath->query( '//div[contains(@class,"product") or contains(@class,"gallery")]//img' )->length;

        // Precio
        $price = '';
        foreach ( $xpath->query( '//*[contains(@class,"price") or @itemprop="price"]' ) as $el ) {
            $text = trim( $el->textContent );
            if ( preg_match( '/[\d]+[.,]\d{0,2}\s*€/', $text, $m ) ) {
                $price = $m[0];
                break;
            }
        }

        // Schema.org
        $schema_types = [];
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
            if ( is_array( $decoded ) ) {
                $type = $decoded['@type'] ?? ( $decoded[0]['@type'] ?? '' );
                if ( $type ) $schema_types[] = $type;
            }
        }

        // Title y description
        $title_el = $xpath->query( '//title' );
        $title_len = $title_el->length > 0 ? strlen( trim( $title_el->item(0)->textContent ) ) : 0;
        $meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
        $desc_len  = $meta_desc->length > 0 ? strlen( trim( $meta_desc->item(0)->nodeValue ) ) : 0;

        return [
            'word_count'   => $word_count,
            'h2_count'     => $h2_count,
            'h3_count'     => $h3_count,
            'faq_count'    => $faq_count,
            'spec_count'   => $spec_count,
            'image_count'  => $image_count,
            'has_schema'   => ! empty( $schema_types ),
            'schema_types' => $schema_types,
            'price'        => $price,
            'title_len'    => $title_len,
            'desc_len'     => $desc_len,
            'error'        => '',
        ];
    }

    /* ── Métricas propias ─────────────────────────────────────────── */

    private static function calculate_our_metrics( array $data ): array {
        $content = wp_strip_all_tags(
            ( $data['intro']      ?? '' ) . ' ' .
            ( $data['conclusion'] ?? '' ) . ' ' .
            implode( ' ', array_column( $data['caracteristicas'] ?? [], 'texto' ) ) . ' ' .
            implode( ' ', array_column( $data['faq']            ?? [], 'respuesta' ) )
        );

        return [
            'word_count'   => str_word_count( $content ),
            'h2_count'     => count( $data['caracteristicas'] ?? [] ) + 3, // intro+specs+conclusion
            'h3_count'     => count( $data['caracteristicas'] ?? [] ) + count( $data['faq'] ?? [] ),
            'faq_count'    => count( $data['faq']   ?? [] ),
            'spec_count'   => count( $data['specs'] ?? [] ),
            'image_count'  => count( array_filter( $data['images'] ?? [] ) ),
            'has_schema'   => true, // el plugin siempre genera schema
            'schema_types' => [ 'Product', 'FAQPage' ],
            'title_len'    => strlen( $data['seo_title']       ?? '' ),
            'desc_len'     => strlen( $data['seo_description'] ?? '' ),
            'has_homos'    => ! empty( $data['homologacion'] ),
            'homo_count'   => count( $data['homologacion'] ?? [] ),
            'has_brand'    => ! empty( $data['brand'] ),
            'has_sku'      => ! empty( $data['sku'] ),
            'product_name' => $data['product_name'] ?? '',
            'brand'        => $data['brand']        ?? '',
            'keyword'      => $data['seo_keywords'] ?? '',
        ];
    }

    /* ── Scoring relativo ─────────────────────────────────────────── */

    private static function calculate_scores( array $our, array $competitors ): array {
        // Calcular medias de competidores (ignorar errores)
        $valid = array_filter( $competitors, fn( $c ) => empty( $c['error'] ) );
        if ( empty( $valid ) ) {
            return [ 'overall' => 50, 'details' => [], 'message' => 'Sin datos suficientes de competidores.' ];
        }

        $avg = [];
        foreach ( [ 'word_count', 'h2_count', 'faq_count', 'spec_count', 'image_count', 'title_len', 'desc_len' ] as $key ) {
            $vals = array_filter( array_column( $valid, $key ), fn($v) => $v > 0 );
            $avg[ $key ] = $vals ? array_sum( $vals ) / count( $vals ) : 0;
        }

        $details = [];
        $total   = 0;
        $max     = 0;

        $checks = [
            [ 'key' => 'word_count', 'label' => 'Palabras de contenido', 'weight' => 20,
              'tip'  => 'Más contenido = más autoridad SEO',
              'format' => fn($v) => $v . ' palabras' ],
            [ 'key' => 'faq_count',  'label' => 'Preguntas FAQ',          'weight' => 15,
              'tip'  => 'Los FAQ generan featured snippets en Google',
              'format' => fn($v) => $v . ' preguntas' ],
            [ 'key' => 'spec_count', 'label' => 'Especificaciones técnicas', 'weight' => 15,
              'tip'  => 'Las especificaciones reducen dudas y aumentan conversión',
              'format' => fn($v) => $v . ' specs' ],
            [ 'key' => 'image_count','label' => 'Imágenes del producto',  'weight' => 10,
              'tip'  => 'Más imágenes = más tiempo en página = mejor SEO',
              'format' => fn($v) => $v . ' imágenes' ],
            [ 'key' => 'h2_count',   'label' => 'Estructura H2/H3',        'weight' => 10,
              'tip'  => 'Los encabezados ayudan a Google a entender la estructura',
              'format' => fn($v) => $v . ' encabezados' ],
            [ 'key' => 'title_len',  'label' => 'SEO Title',               'weight' => 15,
              'tip'  => 'Óptimo: 50-60 caracteres',
              'format' => fn($v) => $v . ' chars' ],
            [ 'key' => 'desc_len',   'label' => 'Meta description',        'weight' => 15,
              'tip'  => 'Óptimo: 140-155 caracteres',
              'format' => fn($v) => $v . ' chars' ],
        ];

        foreach ( $checks as $check ) {
            $our_val  = (float) ( $our[ $check['key'] ] ?? 0 );
            $avg_val  = (float) ( $avg[ $check['key'] ] ?? 0 );
            $max_pts  = $check['weight'];
            $max     += $max_pts;

            if ( $avg_val === 0.0 ) {
                $pts    = $our_val > 0 ? $max_pts : 0;
                $status = $our_val > 0 ? 'ok' : 'fail';
                $diff   = '';
            } elseif ( $check['key'] === 'title_len' ) {
                // Para title: óptimo es 50-60
                $pts    = ( $our_val >= 50 && $our_val <= 60 ) ? $max_pts
                        : ( $our_val > 0 ? (int) round( $max_pts * 0.6 ) : 0 );
                $status = $pts === $max_pts ? 'ok' : ( $pts > 0 ? 'warn' : 'fail' );
                $diff   = '';
            } elseif ( $check['key'] === 'desc_len' ) {
                $pts    = ( $our_val >= 140 && $our_val <= 155 ) ? $max_pts
                        : ( $our_val > 0 ? (int) round( $max_pts * 0.6 ) : 0 );
                $status = $pts === $max_pts ? 'ok' : ( $pts > 0 ? 'warn' : 'fail' );
                $diff   = '';
            } else {
                $ratio  = $our_val / $avg_val;
                $pts    = (int) round( min( $ratio, 1.5 ) / 1.5 * $max_pts );
                $status = $ratio >= 1.0 ? 'ok' : ( $ratio >= 0.6 ? 'warn' : 'fail' );
                $sign   = $our_val >= $avg_val ? '+' : '';
                $diff   = $sign . round( $our_val - $avg_val, 0 );
            }

            $total += $pts;

            $details[] = [
                'label'    => $check['label'],
                'our_val'  => $our_val,
                'avg_val'  => round( $avg_val, 1 ),
                'pts'      => $pts,
                'max_pts'  => $max_pts,
                'status'   => $status,
                'diff'     => $diff,
                'tip'      => $check['tip'],
                'format'   => ( $check['format'] )( (int) $our_val ),
                'format_avg' => ( $check['format'] )( (int) round( $avg_val ) ),
            ];
        }

        // Bonificaciones especiales para karting
        $bonus = 0;
        if ( $our['has_homos'] )  { $bonus += 5; $details[] = [ 'label' => 'Homologaciones (ventaja karting)', 'our_val' => $our['homo_count'], 'avg_val' => 0, 'pts' => 5, 'max_pts' => 5, 'status' => 'ok', 'diff' => '+' . $our['homo_count'], 'tip' => 'Las homologaciones son un diferenciador único en karting', 'format' => $our['homo_count'] . ' homologaciones', 'format_avg' => '—' ]; }
        if ( $our['has_schema'] ) { $bonus += 5; $details[] = [ 'label' => 'Schema Product + FAQ', 'our_val' => 1, 'avg_val' => 0, 'pts' => 5, 'max_pts' => 5, 'status' => 'ok', 'diff' => '+5', 'tip' => 'El schema estructurado mejora el CTR en Google Shopping', 'format' => 'Activo', 'format_avg' => 'No detectado' ]; }
        if ( $our['has_sku'] )    { $bonus += 2; }

        $overall = min( 100, (int) round( ( $total + $bonus ) / ( $max + 10 ) * 100 ) );

        // Puntuación media de competidores (simplificada)
        $comp_avg_score = 55; // estimado base

        return [
            'overall'        => $overall,
            'comp_avg'       => $comp_avg_score,
            'advantage'      => $overall - $comp_avg_score,
            'details'        => $details,
            'competitors_ok' => count( $valid ),
            'avg_metrics'    => $avg,
        ];
    }

    /* ── Recomendaciones Ollama ───────────────────────────────────── */

    private static function generate_recommendations( array $scores, array $our, array $competitors, string $keyword ): string {
        if ( ! class_exists( 'CKG_Ollama' ) || ! CKG_LLM::ping() ) {
            return self::static_recommendations( $scores );
        }

        $fails = array_filter( $scores['details'], fn($d) => $d['status'] === 'fail' );
        $warns = array_filter( $scores['details'], fn($d) => $d['status'] === 'warn' );

        $gaps = implode( ', ', array_column( array_merge( array_values($fails), array_values($warns) ), 'label' ) );

        $comp_titles = implode( "\n", array_filter(
            array_map( fn($c) => $c['domain'] . ': ' . $c['title'], array_slice( $competitors, 0, 3 ) )
        ) );

        $prompt = <<<PROMPT
Eres un experto SEO especializado en karting de competición para CMSKart.es.

Keyword objetivo: "$keyword"
Puntuación SEO propia: {$scores['overall']}/100
Áreas a mejorar: $gaps

Top 3 competidores en Google:
$comp_titles

Genera 4-5 recomendaciones específicas y accionables para superar a estos competidores en Google.
Cada recomendación debe ser concreta, técnica y específica para el nicho de karting.
Formato: una recomendación por línea, comenzando con un emoji relevante.
Responde SOLO las recomendaciones, sin encabezados ni explicaciones adicionales.
PROMPT;

        $result = CKG_LLM::improve_raw( $prompt, [ 'product_name' => $our['product_name'] ] );
        return is_wp_error( $result ) ? self::static_recommendations( $scores ) : $result;
    }

    private static function static_recommendations( array $scores ): string {
        $recs = [];
        foreach ( $scores['details'] as $d ) {
            if ( $d['status'] === 'fail' ) {
                $recs[] = '🔴 Crítico — ' . $d['label'] . ': ' . $d['tip'];
            } elseif ( $d['status'] === 'warn' ) {
                $recs[] = '🟡 Mejorar — ' . $d['label'] . ': actualmente ' . $d['format'] . ', competidores tienen ' . $d['format_avg'];
            }
        }
        return implode( "\n", array_slice( $recs, 0, 5 ) ) ?: '✅ Tu contenido está por encima de la media de los competidores.';
    }
}
