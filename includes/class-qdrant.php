<?php
/**
 * CKG_Qdrant
 *
 * Cliente para Qdrant — base de datos vectorial para conocimiento técnico.
 * Uso principal: consultar PDFs de fabricantes, manuales y homologaciones CIK-FIA
 * indexados por el script alimentar_qdrant.py via la API local.
 *
 * Diferencia con CKG_ChromaDB:
 *   - ChromaDB → catálogo propio CMSKart (productos WooCommerce)
 *   - Qdrant   → conocimiento técnico externo (fabricantes, PDFs, CIK-FIA)
 *
 * Qdrant soporta búsqueda mixta: vector semántico + filtros por payload.
 * Ejemplo: buscar "especificaciones motor" filtrando por fabricante=IAME y tipo=manual
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Qdrant {

    // Colecciones
    const COL_CONOCIMIENTO = 'cmskart_conocimiento'; // PDFs fabricantes y CIK-FIA
    const COL_PRODUCTOS    = 'cmskart_productos';    // catálogo CMSKart (opcional)
    const COL_CACHE        = 'ckg_scraper_cache';    // cache de scraping

    const TIMEOUT          = 15;

    /* ── Configuración ───────────────────────────────────────────────── */

    public static function endpoint(): string {
        $s = get_option( 'ckg_settings', [] );
        return rtrim( $s['qdrant_endpoint'] ?? 'http://localhost:6333', '/' );
    }

    public static function tunnel(): string {
        $s = get_option( 'ckg_settings', [] );
        return rtrim( $s['qdrant_tunnel'] ?? '', '/' );
    }

    /**
     * URL activa: túnel Cloudflare si está configurado, local si no.
     */
    public static function base_url(): string {
        $tunnel = self::tunnel();
        return $tunnel ?: self::endpoint();
    }

    public static function embed_model(): string {
        $s = get_option( 'ckg_settings', [] );
        return $s['qdrant_embed_model'] ?? 'nomic-embed-text';
    }

    public static function ollama_endpoint(): string {
        return CKG_Ollama::get_endpoint();
    }

    public static function knowledge_collection(): string {
        $s = get_option( 'ckg_settings', [] );
        return $s['qdrant_col_knowledge'] ?? self::COL_CONOCIMIENTO;
    }

    /* ── Conexión ────────────────────────────────────────────────────── */

    public static function ping(): bool {
        // Probar primero /readyz (mas ligero), luego /collections como fallback
        foreach ( [ '/readyz', '/collections', '/' ] as $path ) {
            $resp = wp_remote_get( self::base_url() . $path, [
                'timeout'   => 10,
                'sslverify' => false,
            ] );
            if ( ! is_wp_error( $resp ) ) {
                $code = wp_remote_retrieve_response_code( $resp );
                if ( $code >= 200 && $code < 300 ) return true;
            }
        }
        return false;
    }

    public static function get_collection_info( string $collection ): ?array {
        $resp = wp_remote_get(
            self::base_url() . '/collections/' . urlencode( $collection ),
            [ 'timeout' => self::TIMEOUT, 'sslverify' => false ]
        );
        if ( is_wp_error( $resp ) ) return null;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $data['result'] ?? null;
    }

    public static function count( string $collection ): int {
        $resp = wp_remote_get(
            self::base_url() . '/collections/' . urlencode( $collection ),
            [ 'timeout' => self::TIMEOUT, 'sslverify' => false ]
        );
        if ( is_wp_error( $resp ) ) return 0;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return (int) ( $data['result']['points_count'] ?? 0 );
    }

    /* ── Embeddings via Ollama ───────────────────────────────────────── */

    /**
     * Genera un embedding con nomic-embed-text via Ollama.
     * Compatible con los vectores generados por alimentar_qdrant.py
     */
    public static function embed( string $text ): ?array {
        $ollama  = self::ollama_endpoint();
        $model   = self::embed_model();

        $resp = wp_remote_post( $ollama . '/api/embeddings', [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'model'  => $model,
                'prompt' => mb_substr( $text, 0, 4000 ),
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) return null;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $data['embedding'] ?? null;
    }

    /* ── Búsqueda ────────────────────────────────────────────────────── */

    /**
     * Búsqueda semántica básica en una colección.
     */
    public static function search(
        string $collection,
        string $query,
        int    $limit = 5,
        float  $score_threshold = 0.6
    ): array {
        $vector = self::embed( $query );
        if ( ! $vector ) return [];

        $body = [
            'vector'          => $vector,
            'limit'           => $limit,
            'score_threshold' => $score_threshold,
            'with_payload'    => true,
        ];

        $resp = wp_remote_post(
            self::base_url() . '/collections/' . urlencode( $collection ) . '/points/search',
            [
                'timeout'   => self::TIMEOUT,
                'sslverify' => false,
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'body'      => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $resp ) ) return [];
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $data['result'] ?? [];
    }

    /**
     * Búsqueda semántica con filtros por payload.
     * La ventaja clave de Qdrant sobre ChromaDB.
     *
     * @param string $collection  Colección a consultar
     * @param string $query       Texto de búsqueda semántica
     * @param array  $filters     Filtros: ['fabricante' => 'IAME', 'tipo_doc' => 'manual']
     * @param int    $limit       Número de resultados
     * @param float  $threshold   Similitud mínima (0-1)
     */
    public static function search_filtered(
        string $collection,
        string $query,
        array  $filters = [],
        int    $limit = 5,
        float  $threshold = 0.6
    ): array {
        $vector = self::embed( $query );
        if ( ! $vector ) return [];

        // Construir filtros Qdrant
        $must = [];
        foreach ( $filters as $key => $value ) {
            if ( ! $value ) continue;
            $must[] = [
                'key'   => $key,
                'match' => [ 'value' => $value ],
            ];
        }

        $body = [
            'vector'          => $vector,
            'limit'           => $limit,
            'score_threshold' => $threshold,
            'with_payload'    => true,
        ];

        if ( ! empty( $must ) ) {
            $body['filter'] = [ 'must' => $must ];
        }

        $resp = wp_remote_post(
            self::base_url() . '/collections/' . urlencode( $collection ) . '/points/search',
            [
                'timeout'   => self::TIMEOUT,
                'sslverify' => false,
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'body'      => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $resp ) ) return [];
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $data['result'] ?? [];
    }

    /**
     * Busca conocimiento técnico de un fabricante para un producto específico.
     * Combina búsqueda semántica con filtro por fabricante.
     * Usado por CKG_Context_Scraper::scrape_manufacturer().
     */
    public static function find_manufacturer_knowledge(
        string $product_name,
        string $brand,
        string $tipo_doc = '',
        int    $limit = 5
    ): array {
        $query   = "$product_name $brand especificaciones tecnicas";
        $filters = [ 'fabricante' => $brand ];
        if ( $tipo_doc ) $filters['tipo_doc'] = $tipo_doc;

        $results = self::search_filtered(
            self::knowledge_collection(),
            $query,
            $filters,
            $limit,
            0.55
        );

        // Formatear para que sea compatible con el formato que espera CKG_Context_Scraper
        return array_map( function( $r ) {
            return [
                'text'       => $r['payload']['text']       ?? '',
                'source'     => $r['payload']['source']     ?? '',
                'fabricante' => $r['payload']['fabricante'] ?? '',
                'tipo_doc'   => $r['payload']['tipo_doc']   ?? '',
                'modelo'     => $r['payload']['modelo']     ?? '',
                'clases_cik' => $r['payload']['clases_cik'] ?? [],
                'score'      => round( $r['score'], 4 ),
            ];
        }, $results );
    }

    /**
     * Busca homologaciones CIK-FIA para un producto.
     */
    public static function find_homologations(
        string $product_name,
        string $brand,
        int    $limit = 5
    ): array {
        return self::search_filtered(
            self::knowledge_collection(),
            "$product_name $brand homologacion clase CIK-FIA",
            [ 'fabricante' => $brand, 'tipo_doc' => 'homologacion' ],
            $limit,
            0.5
        );
    }

    /* ── Escritura ───────────────────────────────────────────────────── */

    /**
     * Guarda o actualiza un punto en Qdrant.
     */
    public static function upsert(
        string $collection,
        string $id,
        string $text,
        array  $payload = []
    ): bool {
        $vector = self::embed( $text );
        if ( ! $vector ) return false;

        $payload['text'] = $text;
        $payload['indexed_at'] = current_time( 'mysql' );

        $body = [
            'points' => [ [
                'id'      => $id,
                'vector'  => $vector,
                'payload' => $payload,
            ] ],
        ];

        $resp = wp_remote_request(
            self::base_url() . '/collections/' . urlencode( $collection ) . '/points',
            [
                'method'    => 'PUT',
                'timeout'   => self::TIMEOUT,
                'sslverify' => false,
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'body'      => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $resp ) ) return false;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ( $data['status'] ?? '' ) === 'ok';
    }

    /* ── Estadísticas ────────────────────────────────────────────────── */

    public static function get_stats(): array {
        $stats = [];
        foreach ( [ self::COL_CONOCIMIENTO, self::COL_PRODUCTOS, self::COL_CACHE ] as $col ) {
            $stats[ $col ] = self::count( $col );
        }
        return $stats;
    }
}
