<?php
/**
 * CKG_Context_Scraper
 *
 * Scraper estructurado que sigue el contrato de datos definido en
 * cmskart-scraper-schema.md. Consulta fuentes en orden de prioridad:
 *   1. Fabricante oficial
 *   2. Distribuidores de referencia
 *   3. CIK-FIA (homologaciones)
 *   4. Fallback (Amazon/eBay)
 *
 * El JSON resultante alimenta a la Claude API para generar el HTML
 * completo de la ficha de producto.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Context_Scraper {

    const VERSION        = '1.0';
    const CHROMA_COL_SCRAPER  = 'ckg_scraper_cache';   // JSON de scraping cacheado
    const CHROMA_COL_BRAND    = 'ckg_brand_knowledge';  // conocimiento acumulado por marca
    const CHROMA_COL_HTML     = 'ckg_html_cache';       // HTML generado por Claude API
    const CHROMA_COL_KNOWLEDGE = 'cmskart_conocimiento'; // coleccion local del usuario (default)
    const CHROMA_SIMILARITY   = 0.85;                   // umbral minimo de similitud
    const TIMEOUT        = 20;
    const USER_AGENT     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    const MIN_SPECS      = 5;   // minimo de specs tecnicas para confianza "alta"

    // Fabricantes oficiales — Fuente 1
    const MANUFACTURERS = [
        'iame'    => [ 'base' => 'https://www.iame-engines.com',  'path' => '/engines/' ],
        'tm'      => [ 'base' => 'https://www.tmracing.it',        'path' => '/motori/' ],
        'rotax'   => [ 'base' => 'https://www.rotax.com/en/products', 'path' => '/' ],
        'vortex'  => [ 'base' => 'https://www.vortexkart.com',     'path' => '/engines/' ],
        'parilla' => [ 'base' => 'https://www.parilla.it',         'path' => '/motori/' ],
        'sparco'  => [ 'base' => 'https://www.sparco-official.com', 'path' => '/en/' ],
        'alpinestars' => [ 'base' => 'https://www.alpinestars.com', 'path' => '/collections/' ],
        'arai'    => [ 'base' => 'https://www.arai.com',           'path' => '/kart/' ],
        'bell'    => [ 'base' => 'https://www.bellhelmets.com',    'path' => '/collections/' ],
        'otk'     => [ 'base' => 'https://www.otk-kart.com',       'path' => '/en/products/' ],
    ];

    // Distribuidores de referencia — Fuente 2
    const DISTRIBUTORS = [
        'kpsracing' => 'https://www.kpsracing.es',
        'gt2i'      => 'https://www.gt2i.es',
        'kartshop'  => 'https://www.kartshop.de',
        'mondokart' => 'https://www.mondokart.com',
    ];

    /* ================================================================
       MÉTODO 1 — Orquestador principal
    ================================================================ */

    /**
     * Ejecuta el scraping completo para un producto WooCommerce.
     * Consulta las fuentes en orden de prioridad y devuelve el JSON
     * del contrato validado, listo para enviar a Claude API.
     *
     * @param  int  $product_id  ID del producto WooCommerce
     * @return array{
     *   success: bool,
     *   json: array,          // JSON del contrato (§4 del schema)
     *   confidence: string,   // 'alta' | 'media' | 'baja'
     *   sources_tried: array,
     *   source_used: string,
     *   errors: array
     * }
     */
    public static function scrape( int $product_id ): array {

        $result = [
            'success'       => false,
            'json'          => [],
            'confidence'    => 'baja',
            'sources_tried' => [],
            'source_used'   => '',
            'errors'        => [],
        ];

        // ── Paso 1: datos de WooCommerce ─────────────────────────────
        $wc_data = self::get_wc_data( $product_id );
        if ( is_wp_error( $wc_data ) ) {
            $result['errors'][] = $wc_data->get_error_message();
            self::log_result( $product_id, $result );
            return $result;
        }

        $scraped_data  = [];
        $source_used   = '';

        // ── Paso 1b: ChromaDB — buscar cache de scraping similar ──────
        $chroma_hit = self::chroma_find_similar_scrape( $wc_data );
        if ( $chroma_hit ) {
            // Cache hit: recuperar datos y enriquecer con conocimiento de marca
            $scraped_data = $chroma_hit['scraped_data'];
            $source_used  = 'chroma_cache';
            $result['confidence']    = $chroma_hit['confidence'];
            $result['sources_tried'][] = 'chroma_cache';

            // Enriquecer con conocimiento acumulado de la marca
            $brand_knowledge = self::chroma_get_brand_knowledge( $wc_data['brand'] );
            if ( ! empty( $brand_knowledge ) ) {
                $scraped_data = self::merge_brand_knowledge( $scraped_data, $brand_knowledge );
            }

            // Saltar el scraping web — ir directo a build_json
            goto build_contract;
        }

        // ── Paso 2: Fuente 1 — Fabricante oficial ────────────────────
        $result['sources_tried'][] = 'fabricante_oficial';
        $mfr_data = self::scrape_manufacturer( $wc_data );

        if ( ! is_wp_error( $mfr_data ) && self::has_enough_specs( $mfr_data ) ) {
            $scraped_data = $mfr_data;
            $source_used  = 'fabricante_oficial';
            $result['confidence'] = 'alta';
        }

        // ── Paso 3: Fuente 2 — Distribuidores (si Fuente 1 falló) ────
        if ( empty( $scraped_data ) ) {
            $result['sources_tried'][] = 'distribuidor';
            $dist_data = self::scrape_distributor( $wc_data );

            if ( ! is_wp_error( $dist_data ) && ! empty( $dist_data ) ) {
                $scraped_data = $dist_data;
                $source_used  = 'distribuidor';
                $result['confidence'] = 'media';
            }
        }

        // ── Paso 4: Fuente 3 — CIK-FIA (homologaciones) ──────────────
        // Siempre se intenta para enriquecer con datos oficiales de homologación
        $result['sources_tried'][] = 'cikfia';
        $cik_data = self::scrape_cikfia( $wc_data );
        if ( ! is_wp_error( $cik_data ) && ! empty( $cik_data ) ) {
            // Fusionar homologaciones con los datos ya recogidos
            $scraped_data = array_merge( $scraped_data, $cik_data );
        }

        // ── Paso 5: Fuente 4 — Fallback si todo lo anterior falló ────
        if ( empty( $scraped_data ) ) {
            $result['sources_tried'][] = 'fallback';
            $scraped_data = self::scrape_fallback( $wc_data );
            $source_used  = 'fallback';
            $result['confidence'] = 'baja';
        }

        // ── Paso 5b: Guardar datos en ChromaDB para futuros usos ─────
        if ( ! empty( $scraped_data ) && $source_used !== 'chroma_cache' ) {
            self::chroma_store_scrape( $wc_data, $scraped_data, $result['confidence'] );
            self::chroma_update_brand_knowledge( $wc_data['brand'], $scraped_data );
        }

        // Label para saltar desde cache hit
        build_contract:

        // ── Paso 6: Construir y validar el JSON del contrato ─────────
        $contract_json = self::build_json( $wc_data, $scraped_data, $source_used );
        $validation    = self::validate_json( $contract_json );

        // Usar el JSON corregido por validate_json (puede haber aplicado autocorrecciones)
        $final_json = $validation['json'] ?? $contract_json;

        // Rellenar fuentes_consultadas en el meta del JSON
        $final_json['meta']['fuentes_consultadas'] = $result['sources_tried'];
        $final_json['meta']['confianza_datos']      = $result['confidence'];

        if ( ! $validation['valid'] ) {
            $result['errors'] = array_merge( $result['errors'], $validation['errors'] );
        }
        if ( ! empty( $validation['warnings'] ) ) {
            $result['warnings'] = $validation['warnings'];
        }
        if ( ! empty( $validation['fixes'] ) ) {
            $result['fixes'] = $validation['fixes'];
        }

        // ── Paso 7: Registrar en log ──────────────────────────────────
        $result['success']      = true;
        $result['json']         = $final_json;
        $result['source_used']  = $source_used;

        self::log_result( $product_id, $result );

        return $result;
    }

    /* ================================================================
       MÉTODO 2 — Datos de WooCommerce
    ================================================================ */

    /**
     * Extrae todos los datos disponibles del producto WooCommerce.
     * Estos datos alimentan tanto la búsqueda en fuentes externas
     * como los campos que el schema define en §7 (sin scraping).
     *
     * @param  int  $product_id
     * @return array|WP_Error
     */
    public static function get_wc_data( int $product_id ): array|\WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new \WP_Error( 'not_found', "Producto $product_id no encontrado en WooCommerce." );
        }

        if ( get_post_status( $product_id ) === 'trash' ) {
            return new \WP_Error( 'trashed', "Producto $product_id está en la papelera." );
        }

        // Nombre normalizado para búsqueda: minúsculas, sin tildes, guiones
        $name            = $product->get_name();
        $name_normalized = self::normalize_slug( $name );

        // Fabricante — varias fuentes posibles
        $brand = get_post_meta( $product_id, '_ckg_brand', true )
              ?: get_post_meta( $product_id, 'pa_marca',   true )
              ?: '';

        $brand_normalized = strtolower( self::remove_accents( $brand ) );

        // Categorías como texto
        $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $cats = is_wp_error( $cats ) ? [] : $cats;

        // Tags como texto
        $tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
        $tags = is_wp_error( $tags ) ? [] : $tags;

        // Imágenes
        $images = [];
        $thumb_id = $product->get_image_id();
        if ( $thumb_id ) {
            $images[] = [
                'url'          => wp_get_attachment_url( $thumb_id ),
                'alt_sugerido' => $name . ' vista principal',
                'tipo'         => 'principal',
            ];
        }
        foreach ( $product->get_gallery_image_ids() as $gid ) {
            $images[] = [
                'url'          => wp_get_attachment_url( $gid ),
                'alt_sugerido' => $name . ' detalle',
                'tipo'         => 'detalle',
            ];
        }

        // Specs ya guardadas por el plugin (de enriquecimientos anteriores)
        $existing_specs = json_decode(
            get_post_meta( $product_id, '_ckg_specs', true ) ?: '[]', true
        ) ?: [];

        // Homologaciones ya guardadas
        $existing_homos = json_decode(
            get_post_meta( $product_id, '_ckg_homologaciones', true ) ?: '[]', true
        ) ?: [];

        return [
            // Identificación
            'product_id'        => $product_id,
            'sku'               => $product->get_sku(),
            'name'              => $name,
            'name_normalized'   => $name_normalized,
            'brand'             => $brand,
            'brand_normalized'  => $brand_normalized,
            'permalink'         => get_permalink( $product_id ),

            // Precios y stock
            'price'             => $product->get_price(),
            'price_regular'     => $product->get_regular_price(),
            'currency'          => get_woocommerce_currency(),
            'stock'             => $product->get_stock_quantity(),
            'in_stock'          => $product->is_in_stock(),

            // Taxonomías
            'categories'        => $cats,
            'tags'              => $tags,

            // Contenido existente
            'existing_desc'     => wp_strip_all_tags( $product->get_description() ),
            'existing_short'    => wp_strip_all_tags( $product->get_short_description() ),
            'existing_specs'    => $existing_specs,
            'existing_homos'    => $existing_homos,

            // Imágenes
            'images'            => $images,

            // Keywords ya generadas por el enricher (si existe)
            'focus_keyword'     => get_post_meta( $product_id, 'rank_math_focus_keyword', true ) ?: '',
        ];
    }

    /* ================================================================
       MÉTODOS AUXILIARES (internos)
    ================================================================ */

    /**
     * Normaliza un nombre para usarlo como slug de búsqueda.
     * "Motor IAME X30 125cc" → "motor-iame-x30-125cc"
     */
    private static function normalize_slug( string $text ): string {
        $text = self::remove_accents( $text );
        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9\s-]/', '', $text );
        $text = preg_replace( '/[\s]+/', '-', trim( $text ) );
        return $text;
    }

    /**
     * Elimina tildes y caracteres especiales del español.
     */
    private static function remove_accents( string $text ): string {
        $search  = [ 'á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ' ];
        $replace = [ 'a','e','i','o','u','u','n','A','E','I','O','U','U','N' ];
        return str_replace( $search, $replace, $text );
    }

    /**
     * Verifica si los datos raspados tienen suficientes specs técnicas.
     */
    private static function has_enough_specs( array $data ): bool {
        $specs = $data['especificaciones_tecnicas'] ?? [];
        $count = count( array_filter( $specs, fn( $v ) => $v && $v !== '[DATO NO DISPONIBLE]' ) );
        return $count >= self::MIN_SPECS;
    }

    /**
     * Hace una petición HTTP con el user-agent de Chrome.
     */
    protected static function fetch( string $url ): string|\WP_Error {
        $resp = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'sslverify'  => false,
            'user-agent' => self::USER_AGENT,
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
            ],
        ] );

        if ( is_wp_error( $resp ) ) return $resp;

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 400 ) {
            return new \WP_Error( 'http_error', "HTTP $code en $url" );
        }

        return wp_remote_retrieve_body( $resp );
    }

    /**
     * Parsea HTML con DOMDocument y devuelve un XPath listo para usar.
     */
    protected static function parse_html( string $html ): ?\DOMXPath {
        if ( empty( $html ) ) return null;

        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();

        return new \DOMXPath( $dom );
    }

    /**
     * Métodos pendientes de implementación — se añaden en las siguientes iteraciones.
     * Definidos aquí como stubs para que el archivo sea sintácticamente válido.
     */
    /**
     * Fuente 1 — Fabricante oficial.
     *
     * Estrategia:
     * 1. Detectar fabricante desde $wc_data['brand_normalized']
     * 2. Construir URL candidata: base + path + slug
     * 3. Si HTTP 200 → extraer specs, descripcion, imagenes
     * 4. Si 404 → intentar búsqueda interna: base + ?s=slug
     * 5. Si nada → WP_Error
     */
    protected static function scrape_manufacturer( array $wc_data ): array|\WP_Error {

        $brand = $wc_data['brand_normalized'];
        $slug  = $wc_data['name_normalized'];

        // ── Paso 0a: Consultar Qdrant PRIMERO (PDFs fabricantes) ─────
        if ( class_exists( 'CKG_Qdrant' ) && CKG_Qdrant::ping() ) {
            $qdrant_results = CKG_Qdrant::find_manufacturer_knowledge(
                $wc_data['name'],
                $wc_data['brand'],
                'manual'
            );
            if ( ! empty( $qdrant_results ) ) {
                $texts = array_column( $qdrant_results, 'text' );
                $specs = self::extract_specs_from_chunks( $texts, $wc_data );
                $desc  = $texts[0] ?? '';

                if ( count( $specs ) >= self::MIN_SPECS ) {
                    return [
                        'url_fuente'                => 'qdrant:' . CKG_Qdrant::knowledge_collection(),
                        'descripcion_original'      => $desc,
                        'descripcion_idioma'        => 'es',
                        'especificaciones_tecnicas' => $specs,
                        'imagenes'                  => [],
                        'nombre_fabricante'         => $wc_data['name'],
                    ];
                }
            }
        }

        // ── Paso 0b: Consultar ChromaDB (catálogo propio) ─────────────
        $knowledge_col = get_option( 'ckg_chroma_col_knowledge', self::CHROMA_COL_KNOWLEDGE );

        if ( class_exists( 'CKG_ChromaDB' ) && CKG_ChromaDB::ping() ) {
            $query   = $wc_data['name'] . ' ' . $wc_data['brand'] . ' especificaciones tecnicas';
            $results = CKG_ChromaDB::query( $knowledge_col, $query, 5 );

            if ( ! empty( $results['documents'][0] ) ) {
                $specs = self::extract_specs_from_chunks( $results['documents'][0], $wc_data );
                $desc  = implode( ' ', array_slice( $results['documents'][0], 0, 2 ) );

                if ( count( $specs ) >= self::MIN_SPECS ) {
                    return [
                        'url_fuente'                => 'chroma_local:' . $knowledge_col,
                        'descripcion_original'      => $desc,
                        'descripcion_idioma'        => 'es',
                        'especificaciones_tecnicas' => $specs,
                        'imagenes'                  => [],
                        'nombre_fabricante'         => $wc_data['name'],
                    ];
                }
            }
        }

        // Detectar qué fabricante es por el nombre de marca
        $mfr_key = null;
        foreach ( self::MANUFACTURERS as $key => $cfg ) {
            if ( str_contains( $brand, $key ) || str_contains( $slug, $key ) ) {
                $mfr_key = $key;
                break;
            }
        }

        if ( ! $mfr_key ) {
            return new \WP_Error( 'manufacturer_unknown',
                "Fabricante '$brand' no encontrado en la lista de fabricantes soportados."
            );
        }

        $cfg      = self::MANUFACTURERS[ $mfr_key ];
        $base_url = rtrim( $cfg['base'], '/' );
        $path     = rtrim( $cfg['path'], '/' );

        // Intento 1: URL directa
        $url  = "$base_url$path/$slug";
        $html = self::fetch( $url );

        // Intento 2: búsqueda interna si el directo falla
        if ( is_wp_error( $html ) ) {
            $search_url = "$base_url/?s=" . urlencode( $wc_data['name'] );
            $search_html = self::fetch( $search_url );

            if ( is_wp_error( $search_html ) ) {
                return new \WP_Error( 'manufacturer_not_found',
                    "No se encontró el producto en {$cfg['base']} (directo ni búsqueda)."
                );
            }

            // Extraer primer resultado de búsqueda
            $url  = self::extract_first_search_result( $search_html, $base_url );
            if ( ! $url ) {
                return new \WP_Error( 'manufacturer_no_results',
                    "Búsqueda en {$cfg['base']} no devolvió resultados para '{$wc_data['name']}'."
                );
            }

            $html = self::fetch( $url );
            if ( is_wp_error( $html ) ) return $html;
        }

        // Parsear el HTML obtenido
        $xpath = self::parse_html( $html );
        if ( ! $xpath ) {
            return new \WP_Error( 'parse_error', "No se pudo parsear el HTML de $url" );
        }

        // Extraer datos estructurados
        return [
            'url_fuente'               => $url,
            'descripcion_original'     => self::extract_description( $xpath ),
            'descripcion_idioma'       => self::detect_language( $html ),
            'especificaciones_tecnicas'=> self::extract_specs( $xpath ),
            'imagenes'                 => self::extract_images( $xpath, $base_url ),
            'nombre_fabricante'        => self::extract_title( $xpath ) ?: $wc_data['name'],
        ];
    }

    /**
     * Fuente 2 — Distribuidores de referencia.
     *
     * Estrategia:
     * 1. Buscar por SKU o nombre en cada distribuidor via buscador interno
     * 2. Tomar el primer resultado con coincidencia > 80% en nombre
     * 3. Extraer specs, descripcion e imagenes
     * 4. Devolver datos del primer distribuidor que responda
     */
    protected static function scrape_distributor( array $wc_data ): array|\WP_Error {

        $search_term = $wc_data['sku'] ?: $wc_data['name'];
        $name_words  = explode( ' ', strtolower( $wc_data['name'] ) );

        $search_patterns = [
            '/search?q=%s',
            '/?s=%s',
            '/catalogsearch/result/?q=%s',
            '/buscar?q=%s',
        ];

        foreach ( self::DISTRIBUTORS as $dist_name => $base_url ) {
            $base        = rtrim( $base_url, '/' );
            $product_url = null;

            foreach ( $search_patterns as $pattern ) {
                $search_url = $base . sprintf( $pattern, urlencode( $search_term ) );
                $html       = self::fetch( $search_url );

                if ( is_wp_error( $html ) ) continue;

                $product_url = self::find_best_match( $html, $base, $name_words );
                if ( $product_url ) break;
            }

            if ( ! $product_url ) continue;

            $product_html = self::fetch( $product_url );
            if ( is_wp_error( $product_html ) ) continue;

            $xpath = self::parse_html( $product_html );
            if ( ! $xpath ) continue;

            $specs = self::extract_specs( $xpath );
            $desc  = self::extract_description( $xpath );

            if ( empty( $specs ) && strlen( $desc ) < 50 ) continue;

            return [
                'url_fuente'                => $product_url,
                'distribuidor'              => $dist_name,
                'descripcion_original'      => $desc,
                'descripcion_idioma'        => self::detect_language( $product_html ),
                'especificaciones_tecnicas' => $specs,
                'imagenes'                  => self::extract_images( $xpath, $base ),
                'nombre_fabricante'         => self::extract_title( $xpath ) ?: $wc_data['name'],
                'precio_competidor'         => self::extract_price( $xpath ),
            ];
        }

        return new \WP_Error( 'distributor_not_found',
            "Ningun distribuidor devolvio resultados para '{$wc_data['name']}'."
        );
    }

    /**
     * Fuente 3 — CIK-FIA (homologaciones oficiales).
     *
     * Estrategia:
     * 1. Descargar la pagina de reglamentos CIK-FIA
     * 2. Encontrar el PDF de homologaciones vigentes
     * 3. Descargar el PDF y parsear su texto
     * 4. Buscar el modelo del producto dentro del documento
     * 5. Extraer clase (OK, OKJ, Mini, etc.) y año de homologacion
     *
     * No bloquea el flujo si falla — la homologacion es enriquecimiento opcional.
     */
    protected static function scrape_cikfia( array $wc_data ): array|\WP_Error {

        $name_normalized = $wc_data['name_normalized'];
        $brand           = $wc_data['brand_normalized'];

        // ── Paso 0a: Consultar Qdrant PRIMERO (homologaciones CIK-FIA) ─
        if ( class_exists( 'CKG_Qdrant' ) && CKG_Qdrant::ping() ) {
            $qdrant_results = CKG_Qdrant::find_homologations(
                $wc_data['name'],
                $wc_data['brand']
            );
            if ( ! empty( $qdrant_results ) ) {
                $texts    = array_column( $qdrant_results, 'text' );
                $homo     = self::extract_homologation_from_chunks( $texts, $wc_data );
                $clases   = $qdrant_results[0]['clases_cik'] ?? [];

                if ( ! empty( $homo ) || ! empty( $clases ) ) {
                    return array_merge( $homo ?: [], [
                        'homologacion_found'  => ! empty( $homo ),
                        'homologacion_source' => 'qdrant',
                        'clases_disponibles'  => $clases,
                    ] );
                }
            }
        }

        // ── Paso 0b: Consultar ChromaDB local ─────────────────────────
        $knowledge_col = get_option( 'ckg_chroma_col_knowledge', self::CHROMA_COL_KNOWLEDGE );

        if ( class_exists( 'CKG_ChromaDB' ) && CKG_ChromaDB::ping() ) {
            $query = $wc_data['name'] . ' ' . $wc_data['brand'] . ' homologacion clase';
            $results = CKG_ChromaDB::query( $knowledge_col, $query, 5 );

            if ( ! empty( $results['ids'][0] ) ) {
                // Buscar datos de homologacion en los fragmentos recuperados
                $homo_data = self::extract_homologation_from_chunks(
                    $results['documents'][0] ?? [],
                    $wc_data
                );
                if ( ! empty( $homo_data ) ) {
                    return array_merge( $homo_data, [ 'homologacion_source' => 'chroma_local' ] );
                }

                // Aunque no haya homologacion, extraer specs tecnicas del contexto
                $specs_from_chroma = self::extract_specs_from_chunks(
                    $results['documents'][0] ?? [],
                    $wc_data
                );
                if ( ! empty( $specs_from_chroma ) ) {
                    return [
                        'homologacion_found'        => false,
                        'homologacion_source'       => 'chroma_local',
                        'especificaciones_tecnicas' => $specs_from_chroma,
                        'descripcion_original'      => implode( ' ', array_slice( $results['documents'][0], 0, 2 ) ),
                    ];
                }
            }
        }

        // ── Si ChromaDB no tiene datos, intentar web ──────────────────
        // Primero intentar desde la pagina de reglamentos
        $regs_url  = 'https://www.cikfia.com/regulations/';
        $regs_html = self::fetch( $regs_url );

        $pdf_url = '';

        if ( ! is_wp_error( $regs_html ) ) {
            $pdf_url = self::find_homologation_pdf( $regs_html );
        }

        // Fallback: URL conocida del PDF de homologaciones de motores
        if ( ! $pdf_url ) {
            $pdf_url = 'https://www.cikfia.com/fileadmin/user_upload/CIK/documents/HOMOLOGATIONS/engines_homologation_list.pdf';
        }

        // Intentar tambien la pagina tecnica directamente
        $tech_url  = 'https://www.cikfia.com/regulations/technical-regulations/';
        $tech_html = self::fetch( $tech_url );

        if ( ! is_wp_error( $tech_html ) && ! $pdf_url ) {
            $pdf_url = self::find_homologation_pdf( $tech_html );
        }

        if ( ! $pdf_url ) {
            return new \WP_Error( 'cikfia_no_pdf',
                'No se encontro PDF de homologaciones en CIK-FIA.'
            );
        }

        // Descargar el PDF
        $pdf_content = self::fetch( $pdf_url );
        if ( is_wp_error( $pdf_content ) ) {
            return new \WP_Error( 'cikfia_pdf_error',
                'No se pudo descargar el PDF de homologaciones: ' . $pdf_content->get_error_message()
            );
        }

        // Parsear el PDF como texto plano usando pdftotext si esta disponible,
        // o extraer texto del binario PDF buscando strings legibles
        $pdf_text = self::extract_pdf_text( $pdf_content );

        if ( empty( $pdf_text ) ) {
            return new \WP_Error( 'cikfia_parse_error',
                'No se pudo extraer texto del PDF de homologaciones.'
            );
        }

        // Buscar el producto en el texto del PDF
        $homologation = self::find_in_homologation_list( $pdf_text, $wc_data );

        if ( empty( $homologation ) ) {
            // No encontrado en CIK-FIA no es un error critico — muchos recambios no tienen homologacion
            return [
                'homologacion_clase'    => '',
                'homologacion_ano'      => '',
                'homologacion_found'    => false,
                'homologacion_source'   => 'cikfia',
            ];
        }

        return [
            'homologacion_clase'    => $homologation['clase'],
            'homologacion_ano'      => $homologation['ano'],
            'homologacion_numero'   => $homologation['numero'] ?? '',
            'homologacion_found'    => true,
            'homologacion_source'   => 'cikfia',
            'homologacion_pdf_url'  => $pdf_url,
        ];
    }

    /**
     * Encuentra la URL del PDF de homologaciones en el HTML de CIK-FIA.
     */
    private static function find_homologation_pdf( string $html ): string {
        $xpath = self::parse_html( $html );
        if ( ! $xpath ) return '';

        // Buscar links que contengan palabras clave de homologacion
        $keywords = [ 'homologation', 'homologacion', 'engine', 'motor', 'approved' ];

        foreach ( $xpath->query( '//a[@href]' ) as $link ) {
            $href = $link->getAttribute('href');
            $text = strtolower( $link->textContent . ' ' . $href );

            // Debe ser un PDF y contener keyword de homologacion
            if ( ! str_contains( strtolower( $href ), '.pdf' ) ) continue;

            foreach ( $keywords as $kw ) {
                if ( str_contains( $text, $kw ) ) {
                    if ( str_starts_with( $href, '//' ) ) return 'https:' . $href;
                    if ( str_starts_with( $href, '/' ) ) return 'https://www.cikfia.com' . $href;
                    return $href;
                }
            }
        }
        return '';
    }

    /**
     * Extrae texto legible de un PDF binario.
     * Intenta primero con shell pdftotext, luego extrae strings del binario.
     */
    private static function extract_pdf_text( string $pdf_binary ): string {
        // Opcion 1: pdftotext disponible en el servidor
        if ( function_exists( 'shell_exec' ) ) {
            $tmp = sys_get_temp_dir() . '/ckg_homologation_' . uniqid() . '.pdf';
            file_put_contents( $tmp, $pdf_binary );
            $text = @shell_exec( "pdftotext '$tmp' - 2>/dev/null" );
            @unlink( $tmp );
            if ( $text && strlen( $text ) > 100 ) return $text;
        }

        // Opcion 2: extraer strings legibles del binario PDF (fallback)
        // Los PDFs contienen texto entre parentesis: (texto)
        $text = '';
        preg_match_all( '/\(([^\)]{3,})\)/', $pdf_binary, $matches );
        if ( ! empty( $matches[1] ) ) {
            $text = implode( ' ', array_filter( $matches[1], function( $s ) {
                // Solo strings con caracteres alfanumericos reales
                return preg_match( '/[a-zA-Z0-9]{3,}/', $s );
            } ) );
        }

        return $text;
    }

    /**
     * Busca el producto en el texto del PDF de homologaciones.
     * Devuelve clase y año si lo encuentra, array vacio si no.
     */
    private static function find_in_homologation_list( string $pdf_text, array $wc_data ): array {
        $lines  = explode( "
", $pdf_text );
        $name   = strtolower( $wc_data['name'] );
        $brand  = strtolower( $wc_data['brand'] );
        $sku    = strtolower( $wc_data['sku'] );

        // Clases CIK-FIA conocidas
        $classes = [ 'OK', 'OKJ', 'OK-N', 'Mini', 'KZ', 'KZ2', 'KF', 'ROK', 'Shifter' ];

        foreach ( $lines as $line ) {
            $line_lower = strtolower( $line );

            // Verificar si la linea menciona el producto
            $name_match  = $name  && str_contains( $line_lower, $name );
            $brand_match = $brand && str_contains( $line_lower, $brand );
            $sku_match   = $sku   && str_contains( $line_lower, $sku );

            if ( ! $name_match && ! ( $brand_match && $sku_match ) ) continue;

            // Buscar la clase en la linea
            foreach ( $classes as $class ) {
                if ( str_contains( $line, $class ) ) {
                    // Buscar el año (4 digitos)
                    preg_match( '/(20\d{2})/', $line, $year_match );
                    // Buscar numero de homologacion (ej: 01/2023)
                    preg_match( '/(\d{2}\/\d{4})/', $line, $num_match );

                    return [
                        'clase'  => $class,
                        'ano'    => $year_match[1]  ?? '',
                        'numero' => $num_match[1]   ?? '',
                        'linea'  => trim( $line ),
                    ];
                }
            }
        }

        return [];
    }

    protected static function scrape_fallback( array $wc_data ): array {
        return [];
    }

    /**
     * Metodo 6 — Construye el JSON del contrato (schema §4).
     *
     * Fusiona datos de WooCommerce + scraping en el formato exacto
     * que consume la Claude API. Marca campos vacios con [DATO NO DISPONIBLE].
     */
    protected static function build_json( array $wc_data, array $scraped, string $source ): array {

        $now = current_time( 'c' ); // ISO 8601

        // ── Especificaciones: fusionar WC + scraping ──────────────────
        $specs_raw    = array_merge(
            $wc_data['existing_specs'] ?? [],
            $scraped['especificaciones_tecnicas'] ?? []
        );

        // Normalizar al formato {'campo' => 'valor'}
        $specs_flat = [];
        foreach ( $specs_raw as $k => $v ) {
            if ( is_array( $v ) && isset( $v['key'] ) ) {
                $specs_flat[ $v['key'] ] = $v['value'] ?? '[DATO NO DISPONIBLE]';
            } elseif ( is_string( $k ) ) {
                $specs_flat[ $k ] = $v;
            }
        }

        // Mapear al formato tecnico canonico del schema
        $specs_canonical = self::map_specs_to_canonical( $specs_flat );

        // ── Homologaciones: WC existentes + CIK-FIA ──────────────────
        $homo_existing = $wc_data['existing_homos'] ?? [];
        $homo_cik      = [];
        if ( ! empty( $scraped['homologacion_found'] ) && $scraped['homologacion_found'] ) {
            $homo_cik[] = $scraped['homologacion_clase'] ?? '';
        }
        // Tambien buscar en homologaciones ya guardadas en WC
        $homo_all = array_unique( array_filter(
            array_merge( $homo_existing, $homo_cik )
        ) );

        // ── Imagenes: WC + scraping, sin duplicados ───────────────────
        $images_wc      = $wc_data['images'] ?? [];
        $images_scraped = $scraped['imagenes'] ?? [];
        $images_all     = $images_wc;
        $seen_urls      = array_column( $images_wc, 'url' );
        foreach ( $images_scraped as $img ) {
            if ( ! in_array( $img['url'], $seen_urls ) ) {
                $images_all[] = $img;
                $seen_urls[]  = $img['url'];
            }
        }

        // ── Descripcion: prioridad fabricante > distribuidor > WC ─────
        $desc_original = $scraped['descripcion_original'] ?? '';
        $desc_idioma   = $scraped['descripcion_idioma']   ?? 'es';
        if ( empty( $desc_original ) ) {
            $desc_original = $wc_data['existing_desc'] ?? '';
            $desc_idioma   = 'es';
        }

        // ── Keywords SEO ──────────────────────────────────────────────
        $keywords_focus = [];
        if ( $wc_data['focus_keyword'] ) {
            $keywords_focus[] = $wc_data['focus_keyword'];
        }
        // Añadir variantes del nombre como keywords base
        $name_lower = strtolower( $wc_data['name'] );
        if ( $wc_data['brand'] ) {
            $keywords_focus[] = $name_lower;
            $keywords_focus[] = strtolower( $wc_data['brand'] ) . ' karting';
        }
        $keywords_focus = array_unique( array_filter( $keywords_focus ) );

        // ── Campos vacios ─────────────────────────────────────────────
        $campos_vacios = [];
        if ( empty( $specs_canonical['cilindrada_cc'] ) )  $campos_vacios[] = 'cilindrada_cc';
        if ( empty( $specs_canonical['potencia_cv'] ) )    $campos_vacios[] = 'potencia_cv';
        if ( empty( $specs_canonical['peso_kg'] ) )        $campos_vacios[] = 'peso_kg';
        if ( empty( $specs_canonical['homologacion'] ) )   $campos_vacios[] = 'homologacion_clase';
        if ( empty( $desc_original ) )                     $campos_vacios[] = 'descripcion_original_texto';

        // ── Construir el JSON del contrato ────────────────────────────
        return [
            'meta' => [
                'scraper_version'       => self::VERSION,
                'fecha_scraping'        => $now,
                'producto_wc_id'        => $wc_data['product_id'],
                'sku_wc'                => $wc_data['sku'],
                'fuentes_consultadas'   => [],  // se rellena en scrape()
                'fuente_primaria_usada' => $source,
                'confianza_datos'       => 'media', // se actualiza en scrape()
            ],

            'producto' => [
                'nombre_original'           => $wc_data['name'],
                'nombre_normalizado'        => $scraped['nombre_fabricante'] ?? $wc_data['name'],
                'fabricante'                => $wc_data['brand'],
                'modelo'                    => self::extract_model( $wc_data['name'], $wc_data['brand'] ),
                'referencia_fabricante'     => $wc_data['sku'],
                'gtin13'                    => '',
                'url_fuente_principal'      => $scraped['url_fuente'] ?? '',
                'descripcion_original_idioma' => $desc_idioma,
                'descripcion_original_texto'  => $desc_original,
            ],

            'especificaciones_tecnicas' => array_map(
                fn( $v ) => $v ?: '[DATO NO DISPONIBLE]',
                $specs_canonical
            ),

            'campos_vacios' => $campos_vacios,

            'homologaciones' => $homo_all,

            'imagenes' => $images_all,

            'seo' => [
                'keywords_focus'              => array_values( $keywords_focus ),
                'keywords_lsi'                => self::generate_lsi_keywords( $wc_data ),
                'categoria_producto'          => implode( ', ', $wc_data['categories'] ),
                'publico_objetivo'            => self::infer_audience( $wc_data ),
                'competidores_serp_detectados'=> array_keys( self::DISTRIBUTORS ),
            ],

            'woocommerce' => [
                'precio_actual'      => $wc_data['price'],
                'precio_competidor'  => $scraped['precio_competidor'] ?? '',
                'stock_actual'       => $wc_data['stock'],
                'url_producto_wc'    => $wc_data['permalink'],
                'categorias_wc'      => $wc_data['categories'],
                'tags_wc'            => $wc_data['tags'],
                'en_stock'           => $wc_data['in_stock'],
            ],
        ];
    }

    /**
     * Mapea specs en bruto al formato canonico del schema.
     * Intenta encontrar cada campo por alias comunes en ES/EN/IT.
     */
    private static function map_specs_to_canonical( array $specs ): array {
        // Mapa: campo_canonico => [alias posibles en ES/EN/IT]
        $field_map = [
            'tipo_motor'        => ['tipo','type','motore','motor type','tipo de motor'],
            'cilindrada_cc'     => ['cilindrada','displacement','cc','cilindrata','cubic capacity'],
            'bore_mm'           => ['diametro','bore','alesaggio','diameter','alveo'],
            'stroke_mm'         => ['carrera','stroke','corsa','alzada'],
            'lubricacion'       => ['lubricacion','lubrication','lubrificazione','mezcla'],
            'encendido'         => ['encendido','ignition','accensione','ignicion'],
            'transmision'       => ['transmision','transmission','trasmissione','cadena'],
            'refrigeracion'     => ['refrigeracion','cooling','raffreddamento','water'],
            'arranque'          => ['arranque','starter','avviamento','start'],
            'carburador'        => ['carburador','carburettor','carburatore','carb'],
            'potencia_cv'       => ['potencia','power','potenza','hp','cv','kw','bhp'],
            'torque_nm'         => ['torque','torcia','par motor','nm','par maximo'],
            'rpm_limite'        => ['rpm','regim','rev','revoluciones','giri'],
            'peso_kg'           => ['peso','weight','poids','kg','mass'],
            'homologacion'      => ['homologacion','homologation','omologazione','clase','class','categoria'],
            'ano_homologacion'  => ['ano homologacion','year','anno','homologation year'],
            'victorias'         => ['victorias','victories','vittorie','wins','palmarés'],
        ];

        $result = array_fill_keys( array_keys( $field_map ), '' );

        foreach ( $specs as $key => $value ) {
            $key_lower = strtolower( trim( $key ) );
            foreach ( $field_map as $canonical => $aliases ) {
                if ( $result[ $canonical ] ) continue; // ya encontrado
                foreach ( $aliases as $alias ) {
                    if ( str_contains( $key_lower, $alias ) ) {
                        $result[ $canonical ] = $value;
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extrae el nombre del modelo desde el nombre completo del producto.
     * "Motor IAME X30 125cc" → "X30"
     */
    private static function extract_model( string $name, string $brand ): string {
        if ( $brand ) {
            $model = trim( str_ireplace( $brand, '', $name ) );
            $model = preg_replace( '/\s+/', ' ', $model );
            return trim( $model );
        }
        return $name;
    }

    /**
     * Genera keywords LSI (semanticas) basadas en el producto.
     */
    private static function generate_lsi_keywords( array $wc_data ): array {
        $lsi    = [];
        $name   = strtolower( $wc_data['name'] );
        $brand  = strtolower( $wc_data['brand'] );
        $cats   = array_map( 'strtolower', $wc_data['categories'] );

        // Keywords basadas en la categoria
        foreach ( $cats as $cat ) {
            $lsi[] = $cat . ' karting';
            $lsi[] = $cat . ' competicion';
            $lsi[] = $cat . ' kart';
        }

        // Keywords basadas en la marca
        if ( $brand ) {
            $lsi[] = $brand . ' kart';
            $lsi[] = $brand . ' original';
            $lsi[] = 'recambio ' . $brand;
            $lsi[] = 'pieza ' . $brand;
        }

        // Keywords genericas de compra
        $lsi[] = 'comprar ' . $name;
        $lsi[] = $name . ' precio';
        $lsi[] = $name . ' online';

        return array_unique( array_filter( $lsi ) );
    }

    /**
     * Infiere el publico objetivo del producto.
     */
    private static function infer_audience( array $wc_data ): string {
        $name = strtolower( $wc_data['name'] );
        $cats = array_map( 'strtolower', $wc_data['categories'] );
        $all  = $name . ' ' . implode( ' ', $cats );

        if ( str_contains( $all, 'junior' ) || str_contains( $all, 'mini' ) ) {
            return 'Pilotos junior y equipos de karting categoria Mini/Junior';
        }
        if ( str_contains( $all, 'kz' ) || str_contains( $all, 'shifter' ) ) {
            return 'Pilotos y equipos de karting categoria KZ/Shifter';
        }
        if ( str_contains( $all, 'ok' ) || str_contains( $all, 'senior' ) ) {
            return 'Pilotos y equipos de karting categoria OK/Senior';
        }
        return 'Pilotos y equipos de karting de competicion';
    }

    /**
     * Metodo 7 — Valida el JSON del contrato segun las reglas del schema §5.
     *
     * Reglas obligatorias:
     * R1. nombre_normalizado no vacio
     * R2. Al menos 5 especificaciones tecnicas presentes
     * R3. url_producto_wc presente y valida
     * R4. Al menos 1 keyword focus
     * R5. Al menos 1 imagen
     * R6. campos_vacios siempre documentado (aunque sea [])
     *
     * Devuelve ['valid' => bool, 'errors' => [], 'warnings' => [], 'fixes' => []]
     * Los warnings no bloquean — los errors si (se registran en el log).
     */
    protected static function validate_json( array $json ): array {
        $errors   = [];
        $warnings = [];
        $fixes    = []; // correcciones aplicadas automaticamente

        // ── R1: Nombre no vacio ───────────────────────────────────────
        $nombre = $json['producto']['nombre_normalizado'] ?? '';
        if ( empty( $nombre ) ) {
            // Autocorreccion: usar nombre_original de WooCommerce
            $nombre_original = $json['producto']['nombre_original'] ?? '';
            if ( $nombre_original ) {
                $json['producto']['nombre_normalizado'] = $nombre_original;
                $fixes[] = 'R1: nombre_normalizado rellenado con nombre_original';
            } else {
                $errors[] = 'R1: nombre_normalizado y nombre_original estan vacios.';
            }
        }

        // ── R2: Al menos 5 especificaciones tecnicas ──────────────────
        $specs = $json['especificaciones_tecnicas'] ?? [];
        $specs_con_valor = array_filter(
            $specs,
            fn( $v ) => $v && $v !== '[DATO NO DISPONIBLE]'
        );

        if ( count( $specs_con_valor ) < self::MIN_SPECS ) {
            $warnings[] = sprintf(
                'R2: Solo %d especificaciones con datos reales (minimo recomendado: %d). '
                . 'Los campos vacios se marcaran como [DATO NO DISPONIBLE].',
                count( $specs_con_valor ),
                self::MIN_SPECS
            );
            // No es error bloqueante — Claude API trabajara con lo que haya
        }

        // ── R3: URL del producto WC ───────────────────────────────────
        $url_wc = $json['woocommerce']['url_producto_wc'] ?? '';
        if ( empty( $url_wc ) ) {
            // Autocorreccion: construir desde product_id
            $product_id = $json['meta']['producto_wc_id'] ?? 0;
            if ( $product_id ) {
                $permalink = get_permalink( $product_id );
                if ( $permalink ) {
                    $json['woocommerce']['url_producto_wc'] = $permalink;
                    $fixes[] = 'R3: url_producto_wc construida desde get_permalink()';
                } else {
                    $errors[] = 'R3: url_producto_wc vacia y no se pudo construir desde product_id.';
                }
            } else {
                $errors[] = 'R3: url_producto_wc vacia y product_id no disponible.';
            }
        } elseif ( ! filter_var( $url_wc, FILTER_VALIDATE_URL ) ) {
            $errors[] = "R3: url_producto_wc '$url_wc' no es una URL valida.";
        }

        // ── R4: Al menos 1 keyword focus ──────────────────────────────
        $keywords = $json['seo']['keywords_focus'] ?? [];
        if ( empty( $keywords ) ) {
            // Autocorreccion: generar keyword desde el nombre del producto
            $nombre = $json['producto']['nombre_normalizado']
                   ?? $json['producto']['nombre_original']
                   ?? '';
            if ( $nombre ) {
                $json['seo']['keywords_focus'] = [ strtolower( $nombre ) ];
                $fixes[] = 'R4: keywords_focus generada automaticamente desde el nombre.';
            } else {
                $errors[] = 'R4: keywords_focus vacia y no se pudo generar automaticamente.';
            }
        }

        // ── R5: Al menos 1 imagen ─────────────────────────────────────
        $imagenes = $json['imagenes'] ?? [];
        if ( empty( $imagenes ) ) {
            // Autocorreccion: usar imagen destacada de WooCommerce
            $product_id = $json['meta']['producto_wc_id'] ?? 0;
            if ( $product_id ) {
                $thumb = get_the_post_thumbnail_url( $product_id, 'full' );
                if ( $thumb ) {
                    $json['imagenes'][] = [
                        'url'          => $thumb,
                        'alt_sugerido' => $json['producto']['nombre_normalizado'] ?? '',
                        'tipo'         => 'principal',
                    ];
                    $fixes[] = 'R5: imagen principal obtenida de get_the_post_thumbnail_url().';
                } else {
                    $warnings[] = 'R5: No hay imagenes disponibles. El HTML generado no tendra imagenes de producto.';
                }
            }
        }

        // ── R6: campos_vacios siempre documentado ─────────────────────
        if ( ! isset( $json['campos_vacios'] ) ) {
            $json['campos_vacios'] = [];
            $fixes[] = 'R6: campos_vacios inicializado como array vacio.';
        }

        // ── Validaciones adicionales de calidad ───────────────────────

        // Precio presente
        if ( empty( $json['woocommerce']['precio_actual'] ) ) {
            $warnings[] = 'CALIDAD: precio_actual vacio. El schema Offer de Google no podra mostrar precio.';
        }

        // Descripcion original presente
        if ( empty( $json['producto']['descripcion_original_texto'] ) ) {
            $warnings[] = 'CALIDAD: descripcion_original_texto vacia. Claude API generara contenido solo con specs.';
        }

        // Fabricante identificado
        if ( empty( $json['producto']['fabricante'] ) ) {
            $warnings[] = 'CALIDAD: fabricante no identificado. Puede afectar al Brand schema.';
        }

        return [
            'valid'    => empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
            'fixes'    => $fixes,
            'json'     => $json, // JSON posiblemente corregido por autocorrecciones
        ];
    }

    /**
     * Metodo 8 — Registra el resultado del scraping en la tabla custom.
     * Tabla: {prefix}ckg_scraper_log (schema §8)
     */
    /**
     * Extrae especificaciones tecnicas de fragmentos de texto de ChromaDB.
     * Los fragmentos vienen de PDFs de fabricantes procesados por alimentar_chroma.py
     */
    private static function extract_specs_from_chunks( array $chunks, array $wc_data ): array {
        $specs = [];
        $combined_text = implode( "
", $chunks );

        // Patron: "Campo: Valor" o "Campo – Valor" tipico en PDFs tecnicos
        $patterns = [
            '/(?:Displacement|Cilindrada|CC|cm3)[:\s–-]+([0-9.,]+\s*(?:cc|cm3)?)/i'
                => 'cilindrada_cc',
            '/(?:Bore|Diametro|Alveo)[:\s–-]+([0-9.,]+\s*mm)/i'
                => 'bore_mm',
            '/(?:Stroke|Carrera|Alzada)[:\s–-]+([0-9.,]+\s*mm)/i'
                => 'stroke_mm',
            '/(?:Power|Potencia|Puissance)[:\s–-]+([0-9.,]+\s*(?:hp|cv|kw))/i'
                => 'potencia_cv',
            '/(?:Weight|Peso|Poids)[:\s–-]+([0-9.,]+\s*kg)/i'
                => 'peso_kg',
            '/(?:Max RPM|RPM|Regime)[:\s–-]+([0-9.,]+)/i'
                => 'rpm_limite',
            '/(?:Carburetor|Carburador|Carburateur)[:\s–-]+([A-Za-z0-9\s.]+)/i'
                => 'carburador',
            '/(?:Ignition|Encendido|Allumage)[:\s–-]+([A-Za-z0-9\s]+)/i'
                => 'encendido',
            '/(?:Cooling|Refrigeracion|Refroidissement)[:\s–-]+([A-Za-z\s]+)/i'
                => 'refrigeracion',
            '/(?:Lubrication|Lubricacion|Lubrification)[:\s–-]+([A-Za-z0-9\s:]+)/i'
                => 'lubricacion',
        ];

        foreach ( $patterns as $pattern => $field ) {
            if ( preg_match( $pattern, $combined_text, $m ) ) {
                $specs[ $field ] = trim( $m[1] );
            }
        }

        return $specs;
    }

    /**
     * Extrae datos de homologacion de fragmentos de texto de ChromaDB.
     * Busca clase (OK, OKJ, KZ, Mini) y año en el texto de PDFs CIK-FIA.
     */
    private static function extract_homologation_from_chunks( array $chunks, array $wc_data ): array {
        $combined = implode( "
", $chunks );
        $classes  = [ 'KZ2', 'KZ', 'OKJ', 'OK-N', 'OK', 'Mini', 'ROK', 'Shifter' ];
        $name     = strtolower( $wc_data['name'] );
        $brand    = strtolower( $wc_data['brand'] );

        foreach ( explode( "
", $combined ) as $line ) {
            $line_lower = strtolower( $line );
            if ( ! str_contains( $line_lower, $name ) && ! str_contains( $line_lower, $brand ) ) {
                continue;
            }
            foreach ( $classes as $class ) {
                if ( str_contains( $line, $class ) ) {
                    preg_match( '/(20\d{2})/', $line, $year_m );
                    preg_match( '/(\d{2}\/\d{4})/', $line, $num_m );
                    return [
                        'homologacion_clase'   => $class,
                        'homologacion_ano'     => $year_m[1]  ?? '',
                        'homologacion_numero'  => $num_m[1]   ?? '',
                        'homologacion_found'   => true,
                        'linea_fuente'         => trim( $line ),
                    ];
                }
            }
        }
        return [];
    }

    protected static function log_result( int $product_id, array $result ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ckg_scraper_log';

        $wpdb->insert( $table, [
            'producto_id'        => $product_id,
            'fecha'              => current_time( 'mysql' ),
            'fuentes_intentadas' => wp_json_encode( $result['sources_tried'] ?? [] ),
            'fuente_exitosa'     => $result['source_used'] ?? '',
            'campos_vacios'      => wp_json_encode( $result['json']['campos_vacios'] ?? [] ),
            'confianza'          => $result['confidence'] ?? 'baja',
            'html_generado'      => 0, // se actualiza cuando Claude API devuelve el HTML
            'error_mensaje'      => ! empty( $result['errors'] )
                                    ? implode( ' | ', $result['errors'] )
                                    : null,
        ], [ '%d','%s','%s','%s','%s','%s','%d','%s' ] );
    }

    /**
     * Actualiza el log cuando Claude API devuelve el HTML.
     */
    public static function mark_html_generated( int $product_id, bool $success ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ckg_scraper_log';

        $wpdb->update(
            $table,
            [ 'html_generado' => $success ? 1 : 0 ],
            [ 'producto_id'   => $product_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Crea la tabla ckg_scraper_log si no existe.
     * Llamado desde register_activation_hook del plugin.
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'ckg_scraper_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_id         BIGINT UNSIGNED NOT NULL,
            fecha               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fuentes_intentadas  TEXT,
            fuente_exitosa      VARCHAR(100)    NOT NULL DEFAULT '',
            campos_vacios       TEXT,
            confianza           ENUM('alta','media','baja') NOT NULL DEFAULT 'baja',
            html_generado       TINYINT(1)      NOT NULL DEFAULT 0,
            error_mensaje       TEXT,
            PRIMARY KEY (id),
            KEY producto_id (producto_id),
            KEY fecha (fecha),
            KEY confianza (confianza)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Devuelve el log de scraping para un producto o los ultimos N registros.
     */
    public static function get_log( int $product_id = 0, int $limit = 20 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ckg_scraper_log';

        if ( $product_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE producto_id = %d ORDER BY fecha DESC LIMIT %d",
                $product_id, $limit
            ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY fecha DESC LIMIT %d", $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Busca el resultado mas relevante en una pagina de busqueda.
     * Calcula coincidencia por palabras del nombre del producto.
     * Devuelve la URL del mejor resultado o empty string.
     */
    private static function find_best_match( string $html, string $base_url, array $name_words ): string {
        $xpath = self::parse_html( $html );
        if ( ! $xpath ) return '';

        // Selectores de links de resultados tipicos en tiendas
        $link_selectors = [
            '//ul[contains(@class,"products")]//a[contains(@class,"product")]/@href',
            '//div[contains(@class,"product-item")]//a/@href',
            '//article[contains(@class,"product")]//a/@href',
            '//li[contains(@class,"product")]//a/@href',
            '//div[contains(@class,"search-result")]//a/@href',
            '//div[contains(@class,"result")]//h2//a/@href',
            '//h3[contains(@class,"product")]//a/@href',
        ];

        $best_url   = '';
        $best_score = 0;

        foreach ( $link_selectors as $sel ) {
            foreach ( $xpath->query( $sel ) as $node ) {
                $href  = trim( $node->nodeValue );
                if ( ! $href ) continue;

                // Normalizar URL
                if ( str_starts_with( $href, '//' ) ) $href = 'https:' . $href;
                elseif ( str_starts_with( $href, '/' ) ) $href = $base_url . $href;
                if ( ! filter_var( $href, FILTER_VALIDATE_URL ) ) continue;

                // Calcular score de coincidencia por palabras en la URL
                $url_lower = strtolower( $href );
                $score     = 0;
                foreach ( $name_words as $word ) {
                    if ( strlen( $word ) > 2 && str_contains( $url_lower, $word ) ) {
                        $score++;
                    }
                }

                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best_url   = $href;
                }
            }
        }

        // Umbral minimo: al menos 2 palabras coinciden (evitar falsos positivos)
        return $best_score >= 2 ? $best_url : '';
    }

    /**
     * Extrae el precio del producto desde el HTML.
     * Soporta WooCommerce, PrestaShop y meta OpenGraph.
     */
    private static function extract_price( \DOMXPath $xpath ): string {
        // Meta OpenGraph (mas fiable)
        $meta = $xpath->query( '//meta[@property="product:price:amount"]' )->item(0);
        if ( $meta ) return trim( $meta->getAttribute('content') );

        $selectors = [
            '//span[contains(@class,"woocommerce-Price-amount")]',
            '//span[contains(@class,"price")]',
            '//div[contains(@class,"product-price")]',
            '//p[contains(@class,"price")]',
            '//*[@itemprop="price"]',
        ];

        foreach ( $selectors as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $text = trim( preg_replace( '/[^\d.,]/', '', $node->textContent ) );
                if ( $text ) return $text;
            }
        }
        return '';
    }

    /* ================================================================
       MÉTODOS ChromaDB — cache semántico y conocimiento de marca
    ================================================================ */

    /**
     * Busca en ChromaDB un scraping previo similar al producto actual.
     * Usa el nombre normalizado como texto de búsqueda semántica.
     * Devuelve los datos cacheados si la similitud supera el umbral.
     */
    private static function chroma_find_similar_scrape( array $wc_data ): ?array {
        if ( ! class_exists( 'CKG_ChromaDB' ) ) return null;
        if ( ! CKG_ChromaDB::ping() ) return null;

        $query_text = $wc_data['name'] . ' ' . $wc_data['brand'];
        $results    = CKG_ChromaDB::query( self::CHROMA_COL_SCRAPER, $query_text, 3 );

        if ( empty( $results['ids'][0] ) ) return null;

        foreach ( $results['ids'][0] as $i => $id ) {
            $distance   = $results['distances'][0][$i] ?? 1;
            $similarity = max( 0, 1 - $distance );

            if ( $similarity < self::CHROMA_SIMILARITY ) continue;

            $meta = $results['metadatas'][0][$i] ?? [];
            $json = isset( $meta['scraped_json'] )
                ? json_decode( $meta['scraped_json'], true )
                : null;

            if ( ! $json ) continue;

            return [
                'scraped_data' => $json,
                'similarity'   => round( $similarity * 100, 1 ),
                'source_id'    => $id,
                'confidence'   => $similarity >= 0.95 ? 'alta' : 'media',
            ];
        }

        return null;
    }

    /**
     * Guarda el resultado del scraping en ChromaDB para futuras consultas.
     * El texto de indexación combina nombre + brand + specs para búsqueda semántica.
     */
    private static function chroma_store_scrape( array $wc_data, array $scraped, string $confidence ): void {
        if ( ! class_exists( 'CKG_ChromaDB' ) || ! CKG_ChromaDB::ping() ) return;

        // Texto representativo para el embedding
        $specs_text = '';
        foreach ( $scraped['especificaciones_tecnicas'] ?? [] as $k => $v ) {
            if ( $v && $v !== '[DATO NO DISPONIBLE]' ) {
                $specs_text .= "$k: $v. ";
            }
        }

        $index_text = implode( ' ', array_filter( [
            $wc_data['name'],
            $wc_data['brand'],
            $scraped['descripcion_original'] ?? '',
            $specs_text,
        ] ) );

        $doc_id = 'scrape_' . $wc_data['product_id'];

        CKG_ChromaDB::upsert(
            self::CHROMA_COL_SCRAPER,
            $doc_id,
            $index_text,
            [
                'product_id'   => $wc_data['product_id'],
                'product_name' => $wc_data['name'],
                'brand'        => $wc_data['brand'],
                'sku'          => $wc_data['sku'],
                'confidence'   => $confidence,
                'scraped_json' => wp_json_encode( $scraped ),
                'indexed_at'   => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Recupera el conocimiento acumulado de una marca desde ChromaDB.
     * Devuelve specs comunes detectadas en productos anteriores de esa marca.
     */
    private static function chroma_get_brand_knowledge( string $brand ): array {
        if ( ! $brand ) return [];
        if ( ! class_exists( 'CKG_ChromaDB' ) || ! CKG_ChromaDB::ping() ) return [];

        $results = CKG_ChromaDB::query(
            self::CHROMA_COL_BRAND,
            $brand . ' especificaciones tecnicas',
            5
        );

        if ( empty( $results['ids'][0] ) ) return [];

        $knowledge = [];
        foreach ( $results['metadatas'][0] ?? [] as $meta ) {
            if ( isset( $meta['specs_json'] ) ) {
                $specs = json_decode( $meta['specs_json'], true );
                if ( is_array( $specs ) ) {
                    // Fusionar specs — no sobreescribir valores ya conocidos
                    foreach ( $specs as $k => $v ) {
                        if ( ! isset( $knowledge[ $k ] ) ) {
                            $knowledge[ $k ] = $v;
                        }
                    }
                }
            }
        }

        return $knowledge;
    }

    /**
     * Actualiza el conocimiento de marca en ChromaDB con las specs
     * del producto recien scrapeado.
     */
    private static function chroma_update_brand_knowledge( string $brand, array $scraped ): void {
        if ( ! $brand ) return;
        if ( ! class_exists( 'CKG_ChromaDB' ) || ! CKG_ChromaDB::ping() ) return;

        $specs = $scraped['especificaciones_tecnicas'] ?? [];
        if ( empty( $specs ) ) return;

        // Filtrar solo specs con valor real
        $specs_real = array_filter(
            $specs,
            fn( $v ) => $v && $v !== '[DATO NO DISPONIBLE]'
        );
        if ( empty( $specs_real ) ) return;

        $doc_id     = 'brand_' . sanitize_key( $brand ) . '_' . uniqid();
        $index_text = $brand . ' ' . implode( ' ', array_keys( $specs_real ) );

        CKG_ChromaDB::upsert(
            self::CHROMA_COL_BRAND,
            $doc_id,
            $index_text,
            [
                'brand'      => $brand,
                'specs_json' => wp_json_encode( $specs_real ),
                'updated_at' => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Guarda el HTML generado por Claude API en ChromaDB.
     * Permite recuperar HTML similar para productos parecidos como referencia.
     */
    public static function chroma_store_html( int $product_id, string $html, array $wc_data ): void {
        if ( ! class_exists( 'CKG_ChromaDB' ) || ! CKG_ChromaDB::ping() ) return;

        $index_text = $wc_data['name'] . ' ' . $wc_data['brand'] . ' HTML generado';
        $doc_id     = 'html_' . $product_id;

        // Guardar solo los primeros 2000 chars del HTML como referencia
        // (ChromaDB no es para almacenar HTML completo, sino para busqueda)
        CKG_ChromaDB::upsert(
            self::CHROMA_COL_HTML,
            $doc_id,
            $index_text,
            [
                'product_id'   => $product_id,
                'product_name' => $wc_data['name'],
                'brand'        => $wc_data['brand'],
                'html_preview' => mb_substr( strip_tags( $html ), 0, 500 ),
                'generated_at' => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Fusiona el conocimiento de marca con los datos scrapeados.
     * Solo rellena campos que estan vacios — no sobreescribe datos reales.
     */
    private static function merge_brand_knowledge( array $scraped, array $brand_knowledge ): array {
        $existing_specs = $scraped['especificaciones_tecnicas'] ?? [];

        foreach ( $brand_knowledge as $field => $value ) {
            // Solo añadir si el campo no existe o esta vacio/placeholder
            $current = $existing_specs[ $field ] ?? '';
            if ( empty( $current ) || $current === '[DATO NO DISPONIBLE]' ) {
                $existing_specs[ $field ] = $value;
            }
        }

        $scraped['especificaciones_tecnicas'] = $existing_specs;
        return $scraped;
    }

    /* ================================================================
       EXTRACTORES INTERNOS — usados por scrape_manufacturer y otros
    ================================================================ */

    /**
     * Extrae la descripción principal del producto desde el HTML.
     * Prueba selectores en orden de especificidad decreciente.
     */
    private static function extract_description( \DOMXPath $xpath ): string {
        $selectors = [
            '//div[contains(@class,"product-description")]',
            '//div[contains(@class,"product__description")]',
            '//div[contains(@class,"product-detail")]//p',
            '//section[contains(@class,"description")]',
            '//div[@id="product-description"]',
            '//meta[@property="og:description"]/@content',
            '//div[contains(@class,"entry-content")]//p[1]',
        ];

        foreach ( $selectors as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $text = trim( $node->textContent ?? $node->nodeValue ?? '' );
                if ( strlen( $text ) > 50 ) return $text;
            }
        }
        return '';
    }

    /**
     * Extrae especificaciones técnicas desde tablas, listas dl/dt/dd
     * y meta tags de producto.
     * Devuelve un array plano ['campo' => 'valor'].
     */
    private static function extract_specs( \DOMXPath $xpath ): array {
        $specs = [];

        // Estrategia 1: tablas de especificaciones
        $table_selectors = [
            '//table[contains(@class,"spec")]//tr',
            '//table[contains(@class,"technical")]//tr',
            '//table[contains(@class,"product-attr")]//tr',
            '//table//tr[td]',
        ];
        foreach ( $table_selectors as $sel ) {
            foreach ( $xpath->query( $sel ) as $row ) {
                $cells = $xpath->query( './/td|.//th', $row );
                if ( $cells->length >= 2 ) {
                    $key = trim( $cells->item(0)->textContent );
                    $val = trim( $cells->item(1)->textContent );
                    if ( $key && $val && strlen( $key ) < 80 ) {
                        $specs[ $key ] = $val;
                    }
                }
            }
            if ( count( $specs ) >= self::MIN_SPECS ) break;
        }

        // Estrategia 2: listas de definición dl/dt/dd
        if ( count( $specs ) < 3 ) {
            $dts = $xpath->query( '//dl//dt' );
            for ( $i = 0; $i < $dts->length; $i++ ) {
                $dt = $dts->item( $i );
                $dd = $xpath->query( 'following-sibling::dd[1]', $dt )->item(0);
                if ( $dt && $dd ) {
                    $key = trim( $dt->textContent );
                    $val = trim( $dd->textContent );
                    if ( $key && $val ) $specs[ $key ] = $val;
                }
            }
        }

        // Estrategia 3: meta product tags (OpenGraph / Schema)
        foreach ( $xpath->query( '//meta[starts-with(@property,"product:")]' ) as $meta ) {
            $prop = str_replace( 'product:', '', $meta->getAttribute('property') );
            $val  = trim( $meta->getAttribute('content') );
            if ( $prop && $val && ! in_array( $prop, ['url','image','price'] ) ) {
                $specs[ ucfirst( $prop ) ] = $val;
            }
        }

        return $specs;
    }

    /**
     * Extrae URLs de imágenes del producto.
     * Maneja lazy loading (data-src) y srcset.
     */
    private static function extract_images( \DOMXPath $xpath, string $base_url ): array {
        $images   = [];
        $seen     = [];

        $img_selectors = [
            '//figure[contains(@class,"product")]//img',
            '//div[contains(@class,"product-gallery")]//img',
            '//div[contains(@class,"product-images")]//img',
            '//img[@itemprop="image"]',
            '//div[contains(@class,"slick-slide")]//img',
        ];

        foreach ( $img_selectors as $sel ) {
            foreach ( $xpath->query( $sel ) as $img ) {
                $src = $img->getAttribute('data-large_image')
                    ?: $img->getAttribute('data-src')
                    ?: $img->getAttribute('data-zoom-image')
                    ?: $img->getAttribute('src');

                if ( ! $src
                    || str_contains( $src, 'data:image' )
                    || str_contains( $src, 'placeholder' )
                    || isset( $seen[ $src ] )
                ) continue;

                if ( str_starts_with( $src, '//' ) ) $src = 'https:' . $src;
                elseif ( str_starts_with( $src, '/' ) ) $src = $base_url . $src;

                $seen[ $src ] = true;
                $images[] = [
                    'url'          => $src,
                    'alt_sugerido' => trim( $img->getAttribute('alt') ?: '' ),
                    'tipo'         => empty( $images ) ? 'principal' : 'detalle',
                ];

                if ( count( $images ) >= 5 ) break 2;
            }
        }

        // Fallback: og:image si no encontramos imágenes de galería
        if ( empty( $images ) ) {
            $og = $xpath->query( '//meta[@property="og:image"]' )->item(0);
            if ( $og ) {
                $images[] = [
                    'url'          => $og->getAttribute('content'),
                    'alt_sugerido' => '',
                    'tipo'         => 'principal',
                ];
            }
        }

        return $images;
    }

    /**
     * Extrae el título del producto desde el HTML.
     */
    private static function extract_title( \DOMXPath $xpath ): string {
        $selectors = [
            '//h1[contains(@class,"product")]',
            '//h1[@itemprop="name"]',
            '//meta[@property="og:title"]/@content',
            '//h1',
        ];
        foreach ( $selectors as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $text = trim( $node->textContent ?? $node->nodeValue ?? '' );
                if ( $text ) return $text;
            }
        }
        return '';
    }

    /**
     * Detecta el idioma del HTML por el atributo lang o el meta.
     */
    private static function detect_language( string $html ): string {
        if ( preg_match( '/<html[^>]+lang=["\']([a-z]{2})/i', $html, $m ) ) {
            return $m[1];
        }
        return 'en'; // asumir inglés para fabricantes internacionales
    }

    /**
     * Extrae la URL del primer resultado de una búsqueda interna.
     */
    private static function extract_first_search_result( string $html, string $base_url ): string {
        $xpath = self::parse_html( $html );
        if ( ! $xpath ) return '';

        $selectors = [
            '//div[contains(@class,"search-result")]//a/@href',
            '//article[contains(@class,"product")]//a/@href',
            '//ul[contains(@class,"products")]//a/@href',
            '//div[contains(@class,"result")]//h2//a/@href',
            '//li[contains(@class,"product")]//a/@href',
        ];

        foreach ( $selectors as $sel ) {
            $node = $xpath->query( $sel )->item(0);
            if ( $node ) {
                $href = trim( $node->nodeValue );
                if ( str_starts_with( $href, '/' ) ) $href = $base_url . $href;
                if ( filter_var( $href, FILTER_VALIDATE_URL ) ) return $href;
            }
        }
        return '';
    }
}
