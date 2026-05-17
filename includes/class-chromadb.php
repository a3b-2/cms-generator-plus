<?php
/**
 * CKG_ChromaDB — Cliente adaptativo
 * v1 (ChromaDB < 1.0): /api/v1/...
 * v2 (ChromaDB >= 1.0): /api/v2/tenants/{t}/databases/{d}/...
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_ChromaDB {

    const DEFAULT_TENANT   = 'default_tenant';
    const DEFAULT_DATABASE = 'default_database';
    const COL_PRODUCTS = 'ckg_products';
    const COL_FAQ      = 'ckg_faq';
    const COL_BRANDS   = 'ckg_brands';
    const COL_KEYWORDS = 'ckg_keywords';

    private static ?string $_api_version = null;

    /* ── Config ──────────────────────────────────────────────────────── */

    private static function ep(): string {
        $s = get_option( 'ckg_settings', [] );
        return rtrim( $s['chroma_endpoint'] ?? 'http://localhost:8000', '/' );
    }

    private static function embed_model(): string {
        $s = get_option( 'ckg_settings', [] );
        return $s['chroma_embed_model'] ?? 'nomic-embed-text';
    }

    /* ── Detección automática de versión ─────────────────────────────── */

    public static function detect_version(): string {
        if ( self::$_api_version !== null ) return self::$_api_version;

        $ep = self::ep();
        $opts = [ 'timeout' => 6, 'sslverify' => false ];

        // Probar v2 primero (ChromaDB >= 1.0)
        $r = wp_remote_get( "$ep/api/v2/heartbeat", $opts );
        if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
            return self::$_api_version = 'v2';
        }

        // Probar v1 (ChromaDB < 1.0)
        $r = wp_remote_get( "$ep/api/v1/heartbeat", $opts );
        if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
            return self::$_api_version = 'v1';
        }

        return self::$_api_version = '';
    }

    public static function ping(): bool { return self::detect_version() !== ''; }

    public static function version(): string {
        $v   = self::detect_version();
        $ep  = self::ep();
        $url = $v === 'v2' ? "$ep/api/v2/version" : "$ep/api/v1/version";
        $r   = wp_remote_get( $url, [ 'timeout' => 5, 'sslverify' => false ] );
        return is_wp_error( $r ) ? '' : trim( wp_remote_retrieve_body( $r ), '"' );
    }

    public static function reset_version_cache(): void { self::$_api_version = null; }

    /* ── URLs según versión ──────────────────────────────────────────── */

    private static function base(): string {
        $ep = self::ep();
        if ( self::detect_version() === 'v2' ) {
            $t = self::DEFAULT_TENANT;
            $d = self::DEFAULT_DATABASE;
            return "$ep/api/v2/tenants/$t/databases/$d";
        }
        return "$ep/api/v1";
    }

    private static function cols_url(): string {
        return self::base() . '/collections';
    }

    private static function col_url( string $col_id ): string {
        return self::base() . '/collections/' . $col_id;
    }

    /* ── Colecciones ─────────────────────────────────────────────────── */

    /**
     * Crea la colección si no existe y devuelve su ID.
     */
    public static function ensure_collection( string $name ): ?string {
        // get_or_create (soportado en v1 y v2)
        $r = wp_remote_post( self::cols_url(), [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'name'          => $name,
                'get_or_create' => true,
                'metadata'      => [ 'hnsw:space' => 'cosine' ],
            ] ),
        ] );

        if ( is_wp_error( $r ) ) return false;
        $code = wp_remote_retrieve_response_code( $r );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );

        if ( in_array( $code, [ 200, 201 ] ) && ( ! empty( $data['id'] ) || ! empty( $data['name'] ) ) ) {
            return $data['id'] ?? $data['name'];
        }

        // Si ya existe (409) o get_or_create no está soportado, obtenerla
        return self::get_col_id( $name );
    }

    private static function get_col_id( string $name ): ?string {
        $r = wp_remote_get( self::cols_url() . '/' . urlencode( $name ), [
            'timeout'   => 10,
            'sslverify' => false,
        ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return false;
        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        return $data['id'] ?? $data['name'] ?? false;
    }

    /* ── Embeddings via Ollama ───────────────────────────────────────── */

    private static function embed( string $text ): array {
        $s   = get_option( 'ckg_settings', [] );
        $ep  = rtrim( $s['ollama_endpoint'] ?? 'http://localhost:11434', '/' );

        $r = wp_remote_post( "$ep/api/embeddings", [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'model'  => self::embed_model(),
                'prompt' => mb_substr( $text, 0, 2000 ),
            ] ),
        ] );

        if ( is_wp_error( $r ) ) return [];
        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        return $data['embedding'] ?? [];
    }

    /* ── CRUD ────────────────────────────────────────────────────────── */

    public static function upsert(
        string $collection,
        string $id,
        string $text,
        array  $metadata = []
    ): bool {
        if ( ! self::ping() ) return false;

        $col_id    = self::ensure_collection( $collection );
        if ( ! $col_id ) return false;

        $embedding = self::embed( $text );
        if ( empty( $embedding ) ) return false;

        $r = wp_remote_post( self::col_url( $col_id ) . '/upsert', [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'ids'        => [ $id ],
                'embeddings' => [ $embedding ],
                'documents'  => [ $text ],
                'metadatas'  => [ ! empty( $metadata ) ? $metadata : (object) [] ],
            ] ),
        ] );

        return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
    }

    public static function query(
        string $collection,
        string $text,
        int    $n = 5,
        ?array $where = null
    ): array {
        if ( ! self::ping() ) return [];

        $col_id = self::get_col_id( $collection );
        if ( ! $col_id ) return [];

        $embedding = self::embed( $text );
        if ( empty( $embedding ) ) return [];

        $n_safe = min( $n, max( 1, self::count( $collection ) ) );

        $body = [
            'query_embeddings' => [ $embedding ],
            'n_results'        => $n_safe,
            'include'          => [ 'metadatas', 'documents', 'distances' ],
        ];
        if ( $where ) $body['where'] = $where;

        $r = wp_remote_post( self::col_url( $col_id ) . '/query', [
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return [];
        return json_decode( wp_remote_retrieve_body( $r ), true ) ?: [];
    }

    public static function count( string $collection ): int {
        $col_id = self::get_col_id( $collection );
        if ( ! $col_id ) return 0;

        $r = wp_remote_get( self::col_url( $col_id ) . '/count', [
            'timeout'   => 10,
            'sslverify' => false,
        ] );

        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return 0;
        return (int) wp_remote_retrieve_body( $r );
    }

    public static function delete_by_id( string $collection, string $id ): bool {
        $col_id = self::get_col_id( $collection );
        if ( ! $col_id ) return false;

        $r = wp_remote_post( self::col_url( $col_id ) . '/delete', [
            'timeout'   => 10,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [ 'ids' => [ $id ] ] ),
        ] );

        return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
    }

    public static function delete_where( string $collection, array $where ): bool {
        $col_id = self::get_col_id( $collection );
        if ( ! $col_id ) return false;

        $r = wp_remote_post( self::col_url( $col_id ) . '/delete', [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [ 'where' => $where ] ),
        ] );

        return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
    }

    /* ── Métodos de alto nivel ───────────────────────────────────────── */

    public static function index_product( int $id, array $data ): bool {
        $text = implode( ' ', array_filter( [
            $data['product_name'] ?? '',
            $data['brand']        ?? '',
            $data['short_desc']   ?? '',
            $data['intro']        ?? '',
            ! empty( $data['specs'] )
                ? implode( ' ', array_column( $data['specs'], 'value' ) )
                : '',
        ] ) );

        if ( empty( trim( $text ) ) ) return false;

        return self::upsert( self::COL_PRODUCTS, 'product_' . $id, $text, [
            'product_id'   => $id,
            'product_name' => $data['product_name'] ?? '',
            'brand'        => $data['brand']        ?? '',
            'sku'          => $data['sku']           ?? '',
        ] );
    }

    public static function find_duplicates( string $product_name, float $threshold = 0.85 ): array {
        $results = self::query( self::COL_PRODUCTS, $product_name, 5 );
        if ( empty( $results['distances'] ) ) return [];

        $duplicates = [];
        foreach ( $results['distances'][0] as $i => $distance ) {
            $similarity = 1 - $distance;
            if ( $similarity >= $threshold ) {
                $meta = $results['metadatas'][0][ $i ] ?? [];
                $duplicates[] = [
                    'product_id'   => $meta['product_id']   ?? 0,
                    'product_name' => $meta['product_name'] ?? '',
                    'similarity'   => round( $similarity * 100 ),
                    'brand'        => $meta['brand']        ?? '',
                ];
            }
        }

        return $duplicates;
    }

    public static function suggest_faq( string $question, int $n = 3 ): array {
        $results = self::query( self::COL_FAQ, $question, $n );
        if ( empty( $results['documents'] ) ) return [];

        $out = [];
        foreach ( $results['documents'][0] as $i => $doc ) {
            $meta  = $results['metadatas'][0][ $i ] ?? [];
            $out[] = [
                'pregunta'  => $meta['pregunta']  ?? '',
                'respuesta' => $meta['respuesta'] ?? $doc,
                'distance'  => $results['distances'][0][ $i ] ?? 1,
            ];
        }
        return $out;
    }

    public static function index_faq( string $id, string $pregunta, string $respuesta, array $meta = [] ): bool {
        return self::upsert( self::COL_FAQ, $id, $pregunta, array_merge( [
            'pregunta'  => $pregunta,
            'respuesta' => $respuesta,
        ], $meta ) );
    }

    public static function get_brand_info( string $brand ): array {
        $results = self::query( self::COL_BRANDS, $brand, 1 );
        return $results['metadatas'][0][0] ?? [];
    }

    public static function check_keyword_cannibalization( string $keyword, int $product_id ): array {
        $results = self::query( self::COL_KEYWORDS, $keyword, 5 );
        if ( empty( $results['metadatas'] ) ) return [];

        $conflicts = [];
        foreach ( $results['metadatas'][0] as $i => $meta ) {
            if ( (int) ( $meta['product_id'] ?? 0 ) === $product_id ) continue;
            $similarity = 1 - ( $results['distances'][0][ $i ] ?? 1 );
            if ( $similarity >= 0.8 ) {
                $conflicts[] = [
                    'product_id'   => $meta['product_id'],
                    'product_name' => $meta['product_name'] ?? '',
                    'keyword'      => $meta['keyword']      ?? '',
                    'similarity'   => round( $similarity * 100 ),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Indexa productos de WooCommerce en lotes.
     * Llamado desde el handler AJAX ckg_chroma_bulk_index.
     */
    /**
     * Busca productos similares al texto dado.
     * Usado para detección de duplicados al crear un producto nuevo.
     */
    public static function find_similar_products( string $text, int $exclude_id = 0, int $limit = 5 ): array {
        $raw = self::query( self::COL_PRODUCTS, $text, $limit + 1 );
        if ( empty( $raw['ids'][0] ) ) return [];

        $results = [];
        foreach ( $raw['ids'][0] as $i => $chroma_id ) {
            $meta       = $raw['metadatas'][0][$i] ?? [];
            $distance   = $raw['distances'][0][$i] ?? 1;
            $similarity = max( 0, round( (1 - $distance) * 100 ) );
            $product_id = (int) ( $meta['product_id'] ?? 0 );

            if ( $product_id === $exclude_id ) continue;
            if ( $similarity < 50 ) continue;

            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            $results[] = [
                'product_id'  => $product_id,
                'name'        => $product->get_name(),
                'similarity'  => $similarity,
                'price'       => (float) $product->get_price(),
                'edit_url'    => get_edit_post_link( $product_id, 'url' ),
                'product_url' => get_permalink( $product_id ),
            ];

            if ( count( $results ) >= $limit ) break;
        }

        return $results;
    }

    /**
     * Comprueba si una keyword ya está siendo usada por otro producto.
     * Usado durante el enriquecimiento para detectar canibalización SEO.
     */
    
    public static function bulk_index( int $offset = 0, int $batch = 10 ): array {
        if ( ! self::ping() ) {
            return [ 'error' => 'ChromaDB no responde.' ];
        }

        $ids = wc_get_products( [
            'status' => 'publish',
            'limit'  => $batch,
            'offset' => $offset,
            'return' => 'ids',
            'type'   => [ 'simple', 'variable' ],
        ] );

        if ( empty( $ids ) ) {
            return [
                'done'      => true,
                'indexed'   => 0,
                'offset'    => $offset,
                'message'   => 'Indexacion completada.',
            ];
        }

        $indexed = 0;
        $errors  = [];

        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            $data = [
                'product_name' => $product->get_name(),
                'brand'        => get_post_meta( $id, '_ckg_brand', true )
                               ?: get_post_meta( $id, 'pa_marca', true )
                               ?: '',
                'sku'          => $product->get_sku(),
                'short_desc'   => wp_strip_all_tags( $product->get_short_description() ),
                'intro'        => wp_strip_all_tags( mb_substr( $product->get_description(), 0, 500 ) ),
            ];

            $ok = self::index_product( $id, $data );
            if ( $ok ) {
                $indexed++;
            } else {
                $errors[] = $id;
            }
        }

        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        return [
            'done'      => count( $ids ) < $batch,
            'indexed'   => $indexed,
            'errors'    => count( $errors ),
            'offset'    => $offset + $batch,
            'total'     => $total,
            'message'   => "Indexados {$indexed} de " . count($ids) . " en este lote.",
        ];
    }

    public static function get_stats(): array {
        $stats = [
            'api_version' => self::detect_version(),
            'chroma_version' => self::version(),
            'available'   => self::ping(),
            'collections' => [],
        ];

        if ( ! $stats['available'] ) return $stats;

        foreach ( [ self::COL_PRODUCTS, self::COL_FAQ, self::COL_BRANDS, self::COL_KEYWORDS ] as $col ) {
            $stats['collections'][ $col ] = self::count( $col );
        }

        return $stats;
    }
}
