<?php
/**
 * CKG_BatchImporter
 * Importación masiva de productos desde CSV o Excel.
 *
 * Columnas CSV soportadas (la primera fila es cabecera):
 *   Obligatorio:  name
 *   Opcional:     brand, sku, price, price_sale, categories, tags,
 *                 short_desc, attribute_name, variation_labels,
 *                 variation_prices, images_urls, url_fabricante,
 *                 catalog_url, seo_title, seo_description, seo_keywords
 *
 * Separadores: coma o punto y coma auto-detectados.
 * Múltiples valores en un campo: separados por | (pipe)
 * Ejemplo variation_labels: "Talla S|Talla M|Talla L"
 * Ejemplo variation_prices: "150|175|190"
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_BatchImporter {

    const MAX_ROWS = 100;

    /* ── Procesar un archivo subido ──────────────────────────────── */

    /**
     * @param string $filepath   Ruta al archivo CSV o Excel temporal
     * @param string $mime       Tipo MIME del archivo
     * @param array  $options    ['autofill_llm' => bool, 'import_images' => bool, 'status' => 'draft'|'publish']
     * @return array  ['preview' => [], 'errors' => []]  (si preview = true)
     *                ['results' => [], 'errors' => []]  (si se ejecuta realmente)
     */
    public static function process( string $filepath, string $mime, array $options = [] ): array {
        $rows = self::parse_file( $filepath, $mime );

        if ( is_wp_error( $rows ) ) {
            return [ 'error' => $rows->get_error_message() ];
        }

        if ( empty( $rows ) ) {
            return [ 'error' => 'El archivo está vacío o no se pudo leer.' ];
        }

        $rows = array_slice( $rows, 0, self::MAX_ROWS );

        // Si es solo preview, devolver filas parseadas
        if ( $options['preview_only'] ?? false ) {
            return [ 'rows' => $rows, 'count' => count($rows) ];
        }

        // Procesar cada fila
        $results = [];
        $errors  = [];

        foreach ( $rows as $i => $row ) {
            $row_num = $i + 2; // +2 porque fila 1 es cabecera

            try {
                $product_data = self::row_to_product_data( $row, $options );

                // AutoFill con LLM si está activado y hay URL de fabricante
                if ( ! empty( $options['autofill_llm'] ) && ! empty( $row['url_fabricante'] ) ) {
                    $filled = CKG_AutoFill::run(
                        $row['url_fabricante'],
                        $product_data['product_name'],
                        $product_data['brand']
                    );
                    if ( ! is_wp_error( $filled ) ) {
                        // Fusionar: los datos manuales del CSV tienen prioridad sobre el AutoFill
                        foreach ( $filled as $k => $v ) {
                            if ( empty( $product_data[$k] ) ) $product_data[$k] = $v;
                        }
                    }
                }

                // Crear producto
                $product_id = CKG_Woo_Creator::create( $product_data );

                if ( is_wp_error( $product_id ) ) {
                    $errors[] = "Fila $row_num ({$row['name']}): " . $product_id->get_error_message();
                } else {
                    // Registrar en historial
                    if ( class_exists('CKG_History') ) {
                        $seo = class_exists('CKG_SEO_Analyzer') ? CKG_SEO_Analyzer::analyze($product_data) : [];
                        CKG_History::record( $product_id, $product_data, $seo['score'] ?? 0 );
                    }

                    $results[] = [
                        'row'        => $row_num,
                        'name'       => $row['name'],
                        'product_id' => $product_id,
                        'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
                        'view_url'   => get_permalink( $product_id ),
                    ];
                }
            } catch ( \Throwable $e ) {
                $errors[] = "Fila $row_num: Error inesperado — " . $e->getMessage();
            }
        }

        return [
            'results'  => $results,
            'errors'   => $errors,
            'created'  => count( $results ),
            'failed'   => count( $errors ),
            'total'    => count( $rows ),
        ];
    }

    /* ── Parser de archivo ───────────────────────────────────────── */

    private static function parse_file( string $filepath, string $mime ): array|\WP_Error {
        // Excel (.xlsx)
        if ( str_contains( $mime, 'spreadsheet' ) || str_contains( $mime, 'excel' ) || str_ends_with( $filepath, '.xlsx' ) ) {
            return self::parse_excel( $filepath );
        }

        // CSV
        return self::parse_csv( $filepath );
    }

    private static function parse_csv( string $filepath ): array|\WP_Error {
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) return new WP_Error( 'file_open', 'No se pudo abrir el archivo CSV.' );

        // Auto-detectar separador
        $first_line = fgets( $handle );
        rewind( $handle );
        $separator = substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ? ';' : ',';

        $headers = [];
        $rows    = [];

        while ( ( $line = fgetcsv( $handle, 2000, $separator ) ) !== false ) {
            if ( empty( $headers ) ) {
                $headers = array_map( 'strtolower', array_map( 'trim', $line ) );
                continue;
            }
            if ( count( $line ) === 0 || ( count( $line ) === 1 && empty( $line[0] ) ) ) continue;

            $row = [];
            foreach ( $headers as $idx => $header ) {
                $row[ $header ] = trim( $line[ $idx ] ?? '' );
            }

            if ( ! empty( $row['name'] ) ) {
                $rows[] = $row;
            }
        }

        fclose( $handle );

        if ( empty( $rows ) ) {
            return new WP_Error( 'empty_csv', 'El CSV no tiene filas válidas. Asegúrate de que la primera fila es la cabecera con al menos una columna "name".' );
        }

        return $rows;
    }

    private static function parse_excel( string $filepath ): array|\WP_Error {
        // Usar ZipArchive para leer .xlsx (es un ZIP con XML dentro)
        if ( ! class_exists('ZipArchive') ) {
            return new WP_Error( 'no_zip', 'ZipArchive no disponible. Usa formato CSV en su lugar.' );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $filepath ) !== true ) {
            return new WP_Error( 'zip_open', 'No se pudo abrir el archivo Excel.' );
        }

        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared_xml= $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ( ! $sheet_xml ) return new WP_Error( 'no_sheet', 'No se encontró la hoja de cálculo.' );

        // Parsear strings compartidos
        $shared_strings = [];
        if ( $shared_xml ) {
            $sdom = new DOMDocument();
            @$sdom->loadXML( $shared_xml );
            foreach ( $sdom->getElementsByTagName('si') as $si ) {
                $shared_strings[] = $si->textContent;
            }
        }

        // Parsear hoja
        $dom = new DOMDocument();
        @$dom->loadXML( $sheet_xml );
        $xpath = new DOMXPath( $dom );
        $xpath->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

        $matrix = [];
        foreach ( $xpath->query('//s:row') as $row ) {
            $row_data = [];
            foreach ( $xpath->query('.//s:c', $row) as $cell ) {
                $t   = $cell->getAttribute('t');
                $val = ( ( $__node = $xpath->query('.//s:v', $cell)->item(0) ) ? $__node->textContent : '' );
                if ( $t === 's' ) $val = $shared_strings[(int)$val] ?? $val;
                $row_data[] = $val;
            }
            $matrix[] = $row_data;
        }

        if ( empty($matrix) ) return new WP_Error( 'empty_xlsx', 'El Excel está vacío.' );

        // Convertir matrix a array asociativo
        $headers = array_map( 'strtolower', array_map( 'trim', $matrix[0] ) );
        $rows    = [];

        for ( $i = 1; $i < count($matrix); $i++ ) {
            $row = [];
            foreach ( $headers as $idx => $header ) {
                $row[$header] = trim( $matrix[$i][$idx] ?? '' );
            }
            if ( ! empty($row['name']) ) $rows[] = $row;
        }

        return $rows ?: new WP_Error( 'empty_data', 'No se encontraron filas con datos en el Excel.' );
    }

    /* ── Convertir fila CSV a array de datos del producto ────────── */

    private static function row_to_product_data( array $row, array $options ): array {
        // Variaciones: variation_labels y variation_prices separados por |
        $var_labels = array_filter( array_map( 'trim', explode( '|', $row['variation_labels'] ?? '' ) ) );
        $var_prices = array_filter( array_map( 'trim', explode( '|', $row['variation_prices'] ?? '' ) ) );

        $variations = [];
        foreach ( $var_labels as $i => $label ) {
            if ( $label ) {
                $variations[] = [
                    'label' => $label,
                    'price' => floatval( $var_prices[$i] ?? $row['price'] ?? 0 ),
                    'sku'   => '',
                ];
            }
        }

        // Si no hay variaciones pero sí precio, crear una variación "Estándar"
        if ( empty($variations) && ! empty($row['price']) ) {
            $variations[] = [
                'label' => 'Estándar',
                'price' => floatval( $row['price'] ),
                'sku'   => $row['sku'] ?? '',
            ];
        }

        // Imágenes separadas por |
        $images = array_filter( array_map( 'trim', explode( '|', $row['images_urls'] ?? '' ) ) );

        // Categorías separadas por |
        $categories = array_filter( array_map( 'trim', explode( '|', $row['categories'] ?? '' ) ) );

        // Tags separados por |
        $tags = array_filter( array_map( 'trim', explode( '|', $row['tags'] ?? '' ) ) );

        return [
            'product_name'    => sanitize_text_field( $row['name'] ?? '' ),
            'product_slug'    => sanitize_title( $row['name'] ?? '' ),
            'brand'           => sanitize_text_field( $row['brand']           ?? '' ),
            'sku'             => sanitize_text_field( $row['sku']             ?? '' ),
            'categories'      => $categories,
            'tags'            => $tags,
            'short_desc'      => wp_kses_post( $row['short_desc']             ?? '' ),
            'attribute_name'  => sanitize_text_field( $row['attribute_name']  ?? 'Talla' ),
            'variations'      => $variations,
            'images'          => array_values( $images ),
            'intro'           => wp_kses_post( $row['intro']                  ?? '' ),
            'catalog_url'     => esc_url_raw( $row['catalog_url']             ?? '' ),
            'seo_title'       => sanitize_text_field( $row['seo_title']       ?? '' ),
            'seo_description' => sanitize_text_field( $row['seo_description'] ?? '' ),
            'seo_keywords'    => sanitize_text_field( $row['seo_keywords']    ?? '' ),
            'draft'           => ( $options['status'] ?? 'draft' ) === 'draft',
            'badge_envio'     => true,
            'badge_garantia'  => true,
        ];
    }

    /* ── Generar plantilla CSV de ejemplo ────────────────────────── */

    public static function generate_template_csv(): string {
        $headers = [
            'name', 'brand', 'sku', 'price', 'price_sale',
            'attribute_name', 'variation_labels', 'variation_prices',
            'categories', 'tags', 'short_desc',
            'images_urls', 'url_fabricante', 'catalog_url',
            'seo_title', 'seo_description', 'seo_keywords',
        ];

        $example = [
            'Casco Bell RS7K GP', 'Bell', 'BELL-RS7K-GP', '459', '',
            'Talla', 'S|M|L|XL', '459|459|459|459',
            'Cascos Karting|Equipamiento Piloto', 'casco,karting,Bell,CIK-FIA',
            'Casco de karting Bell RS7K GP homologado CIK-FIA y Snell SA2025.',
            'https://cmskart.es/img/bell-rs7k.webp|https://cmskart.es/img/bell-rs7k-2.webp',
            'https://www.bellhelmets.com/products/rs7k-gp', '',
            'Casco Bell RS7K GP | CIK-FIA + Snell SA2025 | CMSKart',
            'Casco Bell RS7K GP homologado CIK-FIA y Snell SA2025. Carbono. Tallas S-XXL. Envío 24-72h.',
            'casco bell rs7k gp karting',
        ];

        $output  = implode( ',', $headers ) . "\n";
        $output .= implode( ',', array_map( fn($v) => '"' . str_replace('"', '""', $v) . '"', $example ) ) . "\n";

        return $output;
    }
}
