<?php
/**
 * CKG_Attribute_Extractor
 * Extrae atributos de producto de páginas de competidores y los crea en WooCommerce.
 *
 * Detecta patrones de atributo en:
 *  - Tablas de especificaciones (<table>)
 *  - Listas de definición (<dl><dt><dd>)
 *  - JSON-LD schema.org/Product (additionalProperty)
 *  - Microdata (itemprop)
 *  - Listas <ul> con patrón "Clave: Valor"
 *  - Secciones con clases características de tiendas karting / WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Attribute_Extractor {

    /* ── Normalización de nombres de atributo ─────────────────────────
     * Mapeo: nombre detectado en competidor → etiqueta WooCommerce estándar
     */
    private static array $label_map = [
        // Medidas / tallas
        'talla'           => 'Talla',   'size'    => 'Talla',   'taille'  => 'Talla',
        'medida'          => 'Talla',   'measure' => 'Talla',
        // Color
        'color'           => 'Color',   'colour'  => 'Color',   'colore'  => 'Color',
        // Material
        'material'        => 'Material', 'materials' => 'Material',
        'construcción'    => 'Material', 'construction' => 'Material',
        // Peso
        'peso'            => 'Peso',    'weight'  => 'Peso',    'poids'   => 'Peso',
        // Homologación
        'homologación'    => 'Homologación', 'homologacion' => 'Homologación',
        'certification'   => 'Homologación', 'certifications' => 'Homologación',
        'approval'        => 'Homologación', 'norm'    => 'Homologación',
        // Categoría/uso
        'categoría'       => 'Categoría', 'category' => 'Categoría', 'categories' => 'Categoría',
        // Interior
        'interior'        => 'Interior', 'liner'   => 'Interior', 'padding' => 'Interior',
        // Visera
        'visera'          => 'Visera',  'visor'   => 'Visera',  'shield'  => 'Visera',
        // Género
        'género'          => 'Género',  'genero'  => 'Género',  'gender'  => 'Género',
        'sexo'            => 'Género',
        // Colecciones
        'colección'       => 'Colección', 'collection' => 'Colección', 'serie' => 'Colección',
        // Motor
        'cilindrada'      => 'Cilindrada', 'displacement' => 'Cilindrada',
        'tipo de motor'   => 'Tipo motor', 'engine type'  => 'Tipo motor',
        // Chasis
        'eje'             => 'Eje', 'axle'    => 'Eje',
        'chasis'          => 'Chasis compatible', 'chassis' => 'Chasis compatible',
    ];

    /* ── Blacklist: claves que NO son atributos de producto ───────────── */
    private static array $key_blacklist = [
        'precio', 'price', 'sku', 'ref', 'referencia', 'reference', 'code', 'código',
        'stock', 'disponibilidad', 'availability', 'shipping', 'envío', 'envio',
        'marca', 'brand', 'fabricante', 'manufacturer', 'modelo', 'model',
        'garantía', 'warranty', 'url', 'link', 'descripcion', 'description',
        'nombre', 'name', 'title', 'titulo',
    ];

    /* ── Detección de tipo de variación ──────────────────────────────
     * Determina si un conjunto de valores es:
     *   'talla'     → pa_talla   (XS/S/M/L/XL, números de talla)
     *   'color'     → pa_color   (nombres de colores)
     *   'material'  → pa_material (aluminium, magnesium, carbon...)
     *   'compuesto' → pa_compuesto (soft, medium, hard)
     *   'categoria' → pa_categoria (mini, micro, okj, ok, kz)
     *   'spec'      → spec técnica, NO variación (va a la tabla de specs)
     */
    public static function detect_variation_type( array $labels ): string {
        $joined  = strtolower( implode( ' ', $labels ) );
        $count   = count( $labels );

        // ── Tallas de ropa/equipamiento ─────────────────────────────
        $size_exact = ['xxs','xs','s','m','l','xl','xxl','xxxl'];
        $size_num   = array_filter( $labels, fn($l) => preg_match('/^\d{2,3}$/', trim($l)) );
        $size_match = array_filter( $labels, fn($l) => in_array( strtolower(trim($l)), $size_exact ) );
        if ( count($size_match) >= min(2, $count) || count($size_num) >= min(3, $count) ) {
            return 'talla';
        }

        // ── Colores ─────────────────────────────────────────────────
        $color_words = ['red','blue','black','white','green','yellow','orange','silver',
                        'rojo','azul','negro','blanco','verde','amarillo','naranja','gris','gray',
                        'grey','pink','rosa','morado','purple','carbon','gold','oro'];
        $color_match = array_filter( $labels, fn($l) => in_array( strtolower(trim($l)), $color_words ) );
        if ( count($color_match) >= min(2, $count) ) {
            return 'color';
        }

        // ── Materiales ───────────────────────────────────────────────
        $mat_words = ['aluminium','aluminum','aluminio','magnesium','magnesio',
                      'carbon','carbono','steel','acero','titanium','titanio',
                      'fibre','fiber','fibra','composite'];
        $mat_words_ref = $mat_words;
        $mat_match = array_filter( $labels, function($l) use ($mat_words_ref) {
            $low = strtolower( trim($l) );
            foreach ( $mat_words_ref as $mat ) {
                if ( str_contains( $low, $mat ) ) return true;
            }
            return false;
        });
        if ( count($mat_match) >= min(1, $count) && $count <= 4 ) {
            return 'material';
        }

        // ── Compuestos (neumáticos) ──────────────────────────────────
        $comp_words = ['soft','medium','hard','supersoft','wet','rain','seco','lluvia'];
        $comp_match = array_filter( $labels, fn($l) => in_array( strtolower(trim($l)), $comp_words ) );
        if ( count($comp_match) >= min(2, $count) ) {
            return 'compuesto';
        }

        // ── Categorías de piloto ─────────────────────────────────────
        $cat_words = ['mini','micro','okj','ok','kz','shifter','junior','senior','cadet'];
        $cat_match = array_filter( $labels, fn($l) => in_array( strtolower(trim($l)), $cat_words ) );
        if ( count($cat_match) >= min(2, $count) ) {
            return 'categoria';
        }

        // ── Si los valores son muy técnicos o largos → spec, no variación
        $avg_len = array_sum( array_map( 'strlen', $labels ) ) / max( $count, 1 );
        if ( $avg_len > 25 || $count === 1 ) {
            return 'spec';
        }

        // ── Por defecto: variación genérica (se usará pa_modelo o local)
        return 'variacion';
    }

    /* ── Mapeo tipo → atributo global WooCommerce ─────────────────────
     * Devuelve el slug del atributo global a usar (sin prefijo pa_).
     * Si no existe en WC, lo crea.
     */
    public static function get_or_create_global_attribute( string $type, array $labels ): ?int {
        $type_to_attr = [
            'talla'     => [ 'slug' => 'talla',     'name' => 'Talla'     ],
            'color'     => [ 'slug' => 'color',     'name' => 'Color'     ],
            'material'  => [ 'slug' => 'material',  'name' => 'Material'  ],
            'compuesto' => [ 'slug' => 'compuesto', 'name' => 'Compuesto' ],
            'categoria' => [ 'slug' => 'categoria', 'name' => 'Categoria piloto' ],
        ];

        if ( ! isset( $type_to_attr[ $type ] ) ) return null;

        $cfg = $type_to_attr[ $type ];

        // Buscar atributo global existente
        $existing = wc_get_attribute_taxonomies();
        foreach ( $existing as $attr ) {
            if ( $attr->attribute_name === $cfg['slug'] ) {
                return (int) $attr->attribute_id;
            }
        }

        // Crear el atributo global si no existe
        $attr_id = wc_create_attribute( [
            'name'         => $cfg['name'],
            'slug'         => $cfg['slug'],
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ] );

        return is_wp_error( $attr_id ) ? null : $attr_id;
    }

    /* ═══════════════════════════════════════════════════════════════════
       PUNTO DE ENTRADA — extrae atributos de una lista de URLs
    ═══════════════════════════════════════════════════════════════════ */

    /**
     * @param array $urls  Array de URLs de competidores
     * @return array  ['attributes' => [ 'Talla' => ['S','M','L',...], ... ], 'sources' => [...] ]
     */
    public static function extract_from_urls( array $urls ): array {
        $all_attrs = [];   // label => Set de valores detectados
        $sources   = [];   // url => lista de atributos encontrados

        foreach ( array_slice( $urls, 0, 5 ) as $url ) {
            $url = esc_url_raw( trim( $url ) );
            if ( ! $url ) continue;

            $html = self::fetch( $url );
            if ( is_wp_error( $html ) ) {
                $sources[ $url ] = [ 'error' => $html->get_error_message() ];
                continue;
            }

            $attrs_in_page = self::parse( $html, $url );
            $sources[ $url ] = array_map( fn( $vals ) => count( $vals ) . ' valores', $attrs_in_page );

            foreach ( $attrs_in_page as $label => $values ) {
                if ( ! isset( $all_attrs[ $label ] ) ) $all_attrs[ $label ] = [];
                foreach ( $values as $v ) {
                    if ( ! in_array( $v, $all_attrs[ $label ], true ) ) {
                        $all_attrs[ $label ][] = $v;
                    }
                }
            }
        }

        // Filtrar atributos con 0 valores útiles
        $all_attrs = array_filter( $all_attrs, fn( $vals ) => ! empty( $vals ) );

        return [
            'attributes' => $all_attrs,
            'sources'    => $sources,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       CREAR ATRIBUTOS EN WOOCOMMERCE
    ═══════════════════════════════════════════════════════════════════ */

    /**
     * @param array $to_create  [ 'Talla' => ['S','M','L'], 'Color' => ['Rojo'] ]
     * @return array  [ 'created' => [...], 'existing' => [...], 'errors' => [...] ]
     */
    public static function create_in_woocommerce( array $to_create ): array {
        $created  = [];
        $existing = [];
        $errors   = [];

        foreach ( $to_create as $label => $terms ) {
            $label    = sanitize_text_field( $label );
            $slug     = wc_sanitize_taxonomy_name( sanitize_title( $label ) );
            $taxonomy = wc_attribute_taxonomy_name( $slug );

            // ── Crear o recuperar el atributo global ──────────────────
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $attr_id = wc_create_attribute( [
                    'name'         => $label,
                    'slug'         => $slug,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ] );

                if ( is_wp_error( $attr_id ) ) {
                    $errors[ $label ] = $attr_id->get_error_message();
                    continue;
                }

                // Registrar la taxonomía en la sesión actual
                register_taxonomy( $taxonomy, 'product', [
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ] );

                $created[ $label ] = [ 'attribute_id' => $attr_id, 'terms_added' => [] ];
            } else {
                $existing[ $label ] = [ 'terms_added' => [] ];
            }

            // ── Crear términos (valores del atributo) ─────────────────
            $target = isset( $created[ $label ] ) ? $created : $existing;

            foreach ( (array) $terms as $term_name ) {
                $term_name = sanitize_text_field( trim( $term_name ) );
                if ( empty( $term_name ) ) continue;

                $existing_term = get_term_by( 'name', $term_name, $taxonomy );
                if ( $existing_term ) continue;

                $result = wp_insert_term( $term_name, $taxonomy );
                if ( ! is_wp_error( $result ) ) {
                    if ( isset( $created[ $label ] ) )  $created[ $label ]['terms_added'][]  = $term_name;
                    if ( isset( $existing[ $label ] ) ) $existing[ $label ]['terms_added'][] = $term_name;
                }
            }
        }

        return compact( 'created', 'existing', 'errors' );
    }

    /* ═══════════════════════════════════════════════════════════════════
       PARSEO HTML
    ═══════════════════════════════════════════════════════════════════ */

    private static function parse( string $html, string $url ): array {
        $attrs = [];

        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Estrategia 1: JSON-LD schema.org/Product
        self::parse_json_ld( $html, $attrs );

        // Estrategia 2: Tablas de especificaciones
        self::parse_tables( $xpath, $attrs );

        // Estrategia 3: DL/DT/DD
        self::parse_dl( $xpath, $attrs );

        // Estrategia 4: UL con patrón "Clave: Valor"
        self::parse_ul_kvp( $xpath, $attrs );

        // Estrategia 5: WooCommerce product attributes nativos
        self::parse_wc_attributes( $xpath, $attrs );

        // Normalizar y limpiar
        return self::normalize( $attrs );
    }

    private static function parse_json_ld( string $html, array &$attrs ): void {
        preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches );
        foreach ( $matches[1] as $json_str ) {
            $data = @json_decode( trim( $json_str ), true );
            if ( ! is_array( $data ) ) continue;

            // Aplanar @graph
            $items = isset( $data['@graph'] ) ? $data['@graph'] : [ $data ];
            foreach ( $items as $item ) {
                if ( ( $item['@type'] ?? '' ) !== 'Product' ) continue;

                // additionalProperty
                foreach ( (array) ( $item['additionalProperty'] ?? [] ) as $prop ) {
                    $name  = sanitize_text_field( $prop['name']  ?? '' );
                    $value = sanitize_text_field( $prop['value'] ?? '' );
                    if ( $name && $value ) self::add_attr( $attrs, $name, $value );
                }

                // color, material, size como atributos directos
                $direct = [ 'color' => 'Color', 'material' => 'Material', 'size' => 'Talla' ];
                foreach ( $direct as $key => $label ) {
                    if ( ! empty( $item[ $key ] ) ) {
                        self::add_attr( $attrs, $label, (string) $item[ $key ] );
                    }
                }
            }
        }
    }

    private static function parse_tables( DOMXPath $xpath, array &$attrs ): void {
        $selectors = [
            '//table[contains(@class,"spec") or contains(@class,"techni") or contains(@class,"caracteristic") or contains(@class,"product") or contains(@class,"attribute")]//tr',
            '//table[contains(@id,"spec") or contains(@id,"techni") or contains(@id,"attr")]//tr',
            '//table//tr',
        ];

        $processed = 0;
        foreach ( $selectors as $sel ) {
            foreach ( $xpath->query( $sel ) as $row ) {
                $cells = iterator_to_array( $row->getElementsByTagName( 'td' ) );
                $ths   = iterator_to_array( $row->getElementsByTagName( 'th' ) );

                $key = ''; $val = '';
                if ( count( $ths ) >= 1 && count( $cells ) >= 1 ) {
                    $key = trim( $ths[0]->textContent );
                    $val = trim( $cells[0]->textContent );
                } elseif ( count( $cells ) >= 2 ) {
                    $key = trim( $cells[0]->textContent );
                    $val = trim( $cells[1]->textContent );
                }

                if ( $key && $val && strlen( $val ) < 120 ) {
                    self::add_attr( $attrs, $key, $val );
                    $processed++;
                }

                if ( $processed > 40 ) break;
            }
            if ( $processed > 10 ) break;
        }
    }

    private static function parse_dl( DOMXPath $xpath, array &$attrs ): void {
        foreach ( $xpath->query( '//dl' ) as $dl ) {
            $dts = iterator_to_array( $dl->getElementsByTagName( 'dt' ) );
            $dds = iterator_to_array( $dl->getElementsByTagName( 'dd' ) );
            for ( $i = 0; $i < count( $dts ); $i++ ) {
                $key = trim( $dts[ $i ]->textContent );
                $val = isset( $dds[ $i ] ) ? trim( $dds[ $i ]->textContent ) : '';
                if ( $key && $val && strlen( $val ) < 120 ) {
                    self::add_attr( $attrs, $key, $val );
                }
            }
        }
    }

    private static function parse_ul_kvp( DOMXPath $xpath, array &$attrs ): void {
        foreach ( $xpath->query( '//li' ) as $li ) {
            $text = trim( $li->textContent );
            // Patrón: "Clave: Valor" o "Clave — Valor"
            if ( preg_match( '/^([^:\-–—]{3,40})[:\-–—]\s*(.{2,80})$/', $text, $m ) ) {
                self::add_attr( $attrs, trim( $m[1] ), trim( $m[2] ) );
            }
        }
    }

    private static function parse_wc_attributes( DOMXPath $xpath, array &$attrs ): void {
        // WooCommerce tabla de atributos nativa
        foreach ( $xpath->query( '//table[contains(@class,"woocommerce-product-attributes")]//tr' ) as $row ) {
            $th = $xpath->query( './/th', $row )->item(0);
            $td = $xpath->query( './/td', $row )->item(0);
            if ( $th && $td ) {
                $key = trim( $th->textContent );
                $val = trim( $td->textContent );
                if ( $key && $val ) self::add_attr( $attrs, $key, $val );
            }
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private static function add_attr( array &$attrs, string $key, string $value ): void {
        // Limpiar clave
        $key = preg_replace( '/[*:•\-–—]+$/', '', trim( $key ) );
        $key = trim( $key );
        if ( empty( $key ) || strlen( $key ) > 60 ) return;

        // Blacklist de claves
        $key_lower = strtolower( $key );
        foreach ( self::$key_blacklist as $bl ) {
            if ( str_contains( $key_lower, $bl ) ) return;
        }

        // Limpiar valor
        $value = trim( preg_replace( '/\s+/', ' ', strip_tags( $value ) ) );
        if ( empty( $value ) || strlen( $value ) > 120 ) return;

        if ( ! isset( $attrs[ $key ] ) ) $attrs[ $key ] = [];
        if ( ! in_array( $value, $attrs[ $key ], true ) ) {
            $attrs[ $key ][] = $value;
        }
    }

    private static function normalize( array $attrs ): array {
        $normalized = [];
        foreach ( $attrs as $raw_key => $values ) {
            $key_lower = mb_strtolower( trim( $raw_key ) );
            $label     = self::$label_map[ $key_lower ] ?? ucfirst( trim( $raw_key ) );
            if ( ! isset( $normalized[ $label ] ) ) $normalized[ $label ] = [];
            foreach ( $values as $v ) {
                if ( ! in_array( $v, $normalized[ $label ], true ) ) {
                    $normalized[ $label ][] = $v;
                }
            }
        }
        ksort( $normalized );
        return $normalized;
    }

    private static function fetch( string $url ): string|\WP_Error {
        $resp = wp_remote_get( $url, [
            'timeout'    => 18,
            'user-agent' => 'Mozilla/5.0 (compatible; CMSKartBot/1.0)',
            'headers'    => [ 'Accept-Language' => 'es-ES,es;q=0.9' ],
        ] );
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) return new WP_Error( 'http', "HTTP $code" );
        return wp_remote_retrieve_body( $resp );
    }
}
