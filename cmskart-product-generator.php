<?php
/**
 * Plugin Name: CMSKart Product Generator
 * Plugin URI:  https://cmskart.es
 * Description: Generador de productos WooCommerce para CMSKart.es — karting de competición. Homologaciones, compatibilidad, specs, FAQ, complementos, mejora con Ollama, análisis de competidores e importación de imágenes del fabricante.
 * Version:     2.8.0
 * Author:      CMSKart
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * Text Domain: ckg
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CKG_VERSION', '2.8.0' );
define( 'CKG_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CKG_URL',     plugin_dir_url( __FILE__ ) );
define( 'CKG_SLUG',    'ckg-generator' );


if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( string $haystack, string $needle ): bool {
        return $needle === '' || strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }
}
if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( string $haystack, string $needle ): bool {
        return $needle === '' || substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}
// array_is_list — PHP 8.1+
if ( ! function_exists( 'array_is_list' ) ) {
    function array_is_list( array $arr ): bool {
        if ( $arr === [] ) return true;
        return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
    }
}

require_once CKG_DIR . 'includes/class-content-builder.php';
require_once CKG_DIR . 'includes/class-woo-creator.php';
require_once CKG_DIR . 'includes/shortcodes.php';
require_once CKG_DIR . 'includes/class-ollama.php';
require_once CKG_DIR . 'includes/class-llm-provider.php';
require_once CKG_DIR . 'includes/class-autofill.php';
require_once CKG_DIR . 'includes/class-batch-importer.php';
require_once CKG_DIR . 'includes/templates.php';
require_once CKG_DIR . 'includes/class-competitor-scraper.php';
require_once CKG_DIR . 'includes/class-image-importer.php';
require_once CKG_DIR . 'includes/class-attribute-extractor.php';
require_once CKG_DIR . 'includes/class-rankmath.php';
require_once CKG_DIR . 'includes/class-seo-analyzer.php';
require_once CKG_DIR . 'includes/class-web-story.php';
require_once CKG_DIR . 'includes/class-social-meta.php';
require_once CKG_DIR . 'includes/class-exif-writer.php';
require_once CKG_DIR . 'includes/class-chromadb.php';
require_once CKG_DIR . 'includes/class-qdrant.php';
require_once CKG_DIR . 'includes/class-enrichment-queue.php';
require_once CKG_DIR . 'includes/class-maintenance.php';
require_once CKG_DIR . 'includes/class-batch-enricher.php';
require_once CKG_DIR . 'includes/class-checkout-fields.php';
require_once CKG_DIR . 'includes/class-attribute-migrator.php';
require_once CKG_DIR . 'includes/class-competitive-seo.php';
require_once CKG_DIR . 'includes/class-history.php';
require_once CKG_DIR . 'includes/class-revisions.php';
require_once CKG_DIR . 'includes/class-scheduler.php';
require_once CKG_DIR . 'includes/class-context-scraper.php';
require_once CKG_DIR . 'includes/class-prompt-manager.php';

if ( is_admin() ) require_once CKG_DIR . 'admin/admin-page.php';

add_action( 'plugins_loaded', function () {
    if ( class_exists( 'CKG_Checkout_Fields' ) ) { CKG_Checkout_Fields::init(); }
    if ( class_exists( 'CKG_Scheduler' ) )       { CKG_Scheduler::init(); }
} );

register_deactivation_hook( __FILE__, function () {
    if ( class_exists( 'CKG_Scheduler' ) ) {
        CKG_Scheduler::deactivate();
    }
} );

register_activation_hook( __FILE__, function () {
    CKG_Revisions::create_table();
    CKG_Context_Scraper::create_table();
    CKG_Prompt_Manager::create_table();
    add_option( 'ckg_settings', [
        'envio_texto'      => 'Envío en 24-72h hábiles',
        'garantia_texto'   => 'Garantía de Fabricante',
        'recogida_texto'   => 'Consulta recogida en tu karting',
        'contact_phone'    => '',
        'contact_email'    => 'info@cmskart.es',
        'ollama_endpoint'  => 'http://localhost:11434',
        'ollama_model'     => 'llama3.2',
    ] );
} );

/* ── Frontend ───────────────────────────────────────────────────────────── */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_product() ) return;
    wp_enqueue_style(  'ckg-styles',   CKG_URL . 'assets/css/styles.css',   [], CKG_VERSION );
    wp_enqueue_script( 'ckg-frontend', CKG_URL . 'assets/js/frontend.js', ['jquery'], CKG_VERSION, true );
    wp_localize_script( 'ckg-frontend', 'CKG', [
        'currency' => get_woocommerce_currency_symbol(),
        'nonce'    => wp_create_nonce( 'ckg_nonce' ),
    ] );
} );

/* ── Admin scripts ──────────────────────────────────────────────────────── */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, CKG_SLUG ) === false ) return;
    wp_enqueue_style(  'ckg-admin', CKG_URL . 'assets/css/admin.css', [], CKG_VERSION );
    wp_enqueue_script( 'ckg-admin', CKG_URL . 'assets/js/admin.js', ['jquery'], CKG_VERSION, true );
    wp_enqueue_media();
    // Leer datos pendientes del Modo Express (guardados en transient)
    $user_id       = get_current_user_id();
    $pending_key   = 'ckg_autofill_pending_' . $user_id;
    $pending_data  = get_transient( $pending_key );

    wp_localize_script( 'ckg-admin', 'CKG_ADMIN', [
        'nonce'           => wp_create_nonce( 'ckg_admin_nonce' ),
        'ajaxurl'         => admin_url( 'admin-ajax.php' ),
        'templates'       => CKG_Templates::list(),
        'homos_brands'    => CKG_Templates::homologaciones_by_brand(),
        'pending_autofill'     => $pending_data ? $pending_data : null,
        'perplexity_available' => class_exists('CKG_LLM') && CKG_LLM::perplexity_available(),
        'post_id'              => get_the_ID() ?: 0,
        'provider_label'       => class_exists('CKG_LLM') ? CKG_LLM::active_provider()['label'] : 'Ollama',
        'chroma_available'     => class_exists('CKG_ChromaDB'), // ping se hace al pulsar el boton
    ] );

    // Borrar el transient tras entregarlo (una sola vez)
    if ( $pending_data ) {
        delete_transient( $pending_key );
    }
} );

/* ── Accesorios frontend ────────────────────────────────────────────────── */
add_action( 'woocommerce_single_product_summary', function () {
    global $product;
    if ( ! $product ) return;
    $json = get_post_meta( $product->get_id(), '_ckg_accessories', true );
    if ( empty( $json ) ) return;
    $acc = json_decode( $json, true );
    if ( ! is_array( $acc ) || empty( $acc ) ) return;
    CKG_Shortcodes::render_accessories( $acc );
}, 28 );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX — helpers internos
══════════════════════════════════════════════════════════════════════════ */
function ckg_check_ajax(): void {
    check_ajax_referer( 'ckg_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'No autorizado.' ] );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Atributos WooCommerce
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_get_attributes', function () {
    ckg_check_ajax();
    $taxes  = wc_get_attribute_taxonomies();
    $result = [];
    foreach ( $taxes as $tax ) {
        $taxonomy   = wc_attribute_taxonomy_name( $tax->attribute_name );
        $terms      = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        $result[] = [
            'name'  => $tax->attribute_name,
            'label' => $tax->attribute_label,
            'terms' => is_wp_error( $terms ) ? [] : wp_list_pluck( $terms, 'name' ),
        ];
    }
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Categorías de productos
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_get_categories', function () {
    ckg_check_ajax();
    $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
    if ( is_wp_error( $terms ) ) wp_send_json_error( $terms->get_error_message() );
    $result = [];
    foreach ( $terms as $t ) {
        if ( in_array( strtolower( $t->name ), [ 'uncategorized', 'sin categoría' ] ) ) continue;
        $result[] = [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count ];
    }
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Buscar productos WooCommerce (para accesorios)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_search_products', function () {
    ckg_check_ajax();
    $q      = sanitize_text_field( $_POST['query']       ?? '' );
    $cat_id = absint( $_POST['category_id']              ?? 0  );
    $limit  = min( absint( $_POST['per_page']            ?? 24 ), 50 );

    $args = [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'title', 'order' => 'ASC' ];
    if ( $q )      $args['s'] = $q;
    if ( $cat_id ) $args['tax_query'] = [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id, 'include_children' => true ] ];

    $result = [];
    foreach ( get_posts( $args ) as $p ) {
        $wc = wc_get_product( $p->ID );
        if ( ! $wc ) continue;
        $img_id = $wc->get_image_id();
        // Recoger galería de imágenes también
        $gallery_ids  = $wc->get_gallery_image_ids();
        $gallery_urls = array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'thumbnail' ), array_slice( $gallery_ids, 0, 4 ) );
        $result[] = [
            'id'          => $p->ID,
            'name'        => $p->post_title,
            'price'       => (float) $wc->get_price(),
            'image'       => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
            'image_full'  => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_single' ) : '',
            'gallery'     => array_values( array_filter( $gallery_urls ) ),
            'sku'         => $wc->get_sku(),
            'description' => wp_trim_words( $wc->get_short_description() ?: $wc->get_description(), 15 ),
            'permalink'   => get_permalink( $p->ID ),
        ];
    }
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Marcas
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_get_brands', function () {
    ckg_check_ajax();
    $tax = '';
    foreach ( [ 'pwb-brand', 'product_brand', 'pa_brand', 'brand' ] as $c ) {
        if ( taxonomy_exists( $c ) ) { $tax = $c; break; }
    }
    if ( ! $tax ) { wp_send_json_success( [] ); return; }
    $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name' ] );
    if ( is_wp_error( $terms ) ) { wp_send_json_success( [] ); return; }
    wp_send_json_success( array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name ], $terms ) );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Ollama — mejorar campo individual
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_ollama_improve', function () {
    ckg_check_ajax();

    $section      = sanitize_key( $_POST['section']      ?? '' );
    $content      = sanitize_textarea_field( $_POST['content'] ?? '' );
    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $sku          = sanitize_text_field( $_POST['sku']          ?? '' );
    $homos_raw    = $_POST['homologaciones'] ?? [];
    $homologaciones = is_array( $homos_raw ) ? array_map( 'sanitize_text_field', $homos_raw ) : [];

    if ( empty( $content ) ) {
        wp_send_json_error( [ 'message' => 'El campo está vacío. Escribe algo primero.' ] );
    }

    $ctx = compact( 'product_name', 'brand', 'sku', 'homologaciones' );
    $result = CKG_LLM::improve( $section, $content, $ctx );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [ 'improved' => $result ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Ollama — mejorar todo el formulario de golpe
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_ollama_improve_all', function () {
    ckg_check_ajax();
    set_time_limit( 300 ); // hasta 5 min para mejorar todo

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $sku          = sanitize_text_field( $_POST['sku']          ?? '' );
    $fields_raw   = $_POST['fields'] ?? [];

    if ( ! is_array( $fields_raw ) || empty( $fields_raw ) ) {
        wp_send_json_error( [ 'message' => 'No se enviaron campos para mejorar.' ] );
    }

    $homos_raw = $_POST['homologaciones'] ?? [];
    $ctx       = [
        'product_name'  => $product_name,
        'brand'         => $brand,
        'sku'           => $sku,
        'homologaciones'=> is_array( $homos_raw ) ? array_map( 'sanitize_text_field', $homos_raw ) : [],
    ];

    $results = [];
    $errors  = [];

    $allowed_sections = [ 'short_desc', 'intro', 'caracteristica', 'conclusion', 'compat_nota', 'seo_title', 'seo_description' ];

    foreach ( $fields_raw as $section => $content ) {
        $section = sanitize_key( $section );
        $content = sanitize_textarea_field( $content );

        if ( ! in_array( $section, $allowed_sections ) ) continue;
        if ( empty( trim( $content ) ) ) continue;

        $r = CKG_LLM::improve( $section, $content, $ctx );
        if ( is_wp_error( $r ) ) {
            $errors[ $section ] = $r->get_error_message();
        } else {
            $results[ $section ] = $r;
        }
    }

    wp_send_json_success( [ 'results' => $results, 'errors' => $errors ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Test de conexión con Ollama
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_ollama_ping', function () {
    ckg_check_ajax();
    $result = CKG_LLM::ping_with_test();
    wp_send_json_success( [
        'online'   => $result['ok'],
        'models'   => $result['models'],
        'message'  => $result['message'],
        'provider' => CKG_LLM::active_provider(),
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Cargar plantilla predefinida
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_load_template', function () {
    ckg_check_ajax();
    $template_id = sanitize_key( $_POST['template_id'] ?? '' );
    if ( empty( $template_id ) ) wp_send_json_error( [ 'message' => 'ID de plantilla no válido.' ] );

    $data = CKG_Templates::get( $template_id );
    wp_send_json_success( $data );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Homologaciones por marca
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_get_brand_homos', function () {
    ckg_check_ajax();
    $brand = sanitize_text_field( $_POST['brand'] ?? '' );
    $all   = CKG_Templates::homologaciones_by_brand();

    // Búsqueda exacta primero, luego parcial
    if ( isset( $all[ $brand ] ) ) {
        wp_send_json_success( $all[ $brand ] );
        return;
    }

    foreach ( $all as $key => $homos ) {
        if ( stripos( $brand, $key ) !== false || stripos( $key, $brand ) !== false ) {
            wp_send_json_success( $homos );
            return;
        }
    }

    wp_send_json_success( [] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Análisis de competidores
══════════════════════════════════════════════════════════════════════════ */


/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Importar imágenes desde URL del fabricante
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_import_manufacturer_images', function () {
    ckg_check_ajax();
    set_time_limit( 120 );

    $url          = esc_url_raw( sanitize_text_field( $_POST['url']          ?? '' ) );
    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $post_id      = absint( $_POST['post_id'] ?? 0 );

    if ( empty( $url ) ) {
        wp_send_json_error( [ 'message' => 'URL del fabricante vacía.' ] );
    }
    // Si no hay nombre, usar 'producto' como fallback
    if ( empty( $product_name ) ) {
        $product_name = 'producto';
    }

    // Detectar si es URL directa de imagen o URL de página
    $url_path = strtolower( parse_url( $url, PHP_URL_PATH ) ?? '' );
    $img_exts = [ '.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp', '.avif', '.tiff' ];
    $is_direct_image = false;
    foreach ( $img_exts as $ext ) {
        if ( str_ends_with( $url_path, $ext ) ) { $is_direct_image = true; break; }
    }

    if ( $is_direct_image ) {
        // URL directa de imagen — descargar y convertir directamente
        $result = CKG_Image_Importer::import_single_image( $url, $product_name, $post_id );
    } else {
        // URL de página — extraer imágenes del HTML
        $result = CKG_Image_Importer::import_from_url( $url, $product_name, $post_id );
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    // Construir URLs finales para el textarea
    $urls = array_column( $result, 'url' );

    wp_send_json_success( [
        'imported' => $result,
        'urls'     => $urls,
        'count'    => count( $result ),
        'message'  => count( $result ) . ' imagen(es) importada(s) y convertidas a WebP 600×600.',
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Extractor de atributos de competidores
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_extract_attributes', function () {
    ckg_check_ajax();
    set_time_limit( 120 );

    $urls_raw = sanitize_textarea_field( $_POST['urls'] ?? '' );
    $urls     = array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", $urls_raw ) ) ) );

    if ( empty( $urls ) ) {
        wp_send_json_error( [ 'message' => 'Introduce al menos una URL.' ] );
    }

    $result = CKG_Attribute_Extractor::extract_from_urls( $urls );
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Crear atributos en WooCommerce desde los extraídos
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_create_attributes', function () {
    ckg_check_ajax();

    $attrs_raw = $_POST['attributes'] ?? [];
    if ( ! is_array( $attrs_raw ) || empty( $attrs_raw ) ) {
        wp_send_json_error( [ 'message' => 'No se enviaron atributos.' ] );
    }

    // Sanitizar
    $attrs = [];
    foreach ( $attrs_raw as $label => $terms ) {
        $label  = sanitize_text_field( $label );
        $terms  = is_array( $terms )
            ? array_map( 'sanitize_text_field', $terms )
            : [ sanitize_text_field( $terms ) ];
        if ( $label ) $attrs[ $label ] = $terms;
    }

    $result = CKG_Attribute_Extractor::create_in_woocommerce( $attrs );
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Análisis SEO en vivo
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_seo_analyze', function () {
    ckg_check_ajax();

    $data_raw = $_POST['form_data'] ?? '{}';
    $data     = @json_decode( stripslashes( $data_raw ), true );

    if ( ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos del formulario no válidos.' ] );
    }

    // Sanitizar campos básicos para el análisis
    $data['product_name']   = sanitize_text_field( $data['product_name']   ?? '' );
    $data['seo_keywords']   = sanitize_text_field( $data['seo_keywords']   ?? '' );
    $data['seo_title']      = sanitize_text_field( $data['seo_title']      ?? '' );
    $data['seo_description']= sanitize_text_field( $data['seo_description']?? '' );
    $data['brand']          = sanitize_text_field( $data['brand']          ?? '' );
    $data['sku']            = sanitize_text_field( $data['sku']            ?? '' );
    $data['short_desc']     = wp_kses_post(        $data['short_desc']     ?? '' );
    $data['intro']          = wp_kses_post(        $data['intro']          ?? '' );
    $data['conclusion']     = wp_kses_post(        $data['conclusion']     ?? '' );

    $result = CKG_SEO_Analyzer::analyze( $data );
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Generar LSI keywords con Ollama
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_generate_lsi', function () {
    ckg_check_ajax();

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $homos_raw    = $_POST['homologaciones'] ?? [];
    $specs_raw    = $_POST['specs']          ?? [];

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Introduce el nombre del producto primero.' ] );
    }

    $homologaciones = is_array( $homos_raw ) ? array_map( function($h) {
        return [ 'tipo' => sanitize_text_field($h['tipo'] ?? ''), 'codigo' => sanitize_text_field($h['codigo'] ?? '') ];
    }, $homos_raw ) : [];

    $specs = is_array( $specs_raw ) ? array_map( function($s) {
        return [ 'key' => sanitize_text_field($s['key'] ?? ''), 'value' => sanitize_text_field($s['value'] ?? '') ];
    }, $specs_raw ) : [];

    $result = CKG_RankMath::generate_lsi_keywords( $product_name, $brand, $homologaciones, $specs );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [ 'keywords' => $result ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Linking interno — buscar productos relacionados
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_get_related_products', function () {
    ckg_check_ajax();

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $categories   = array_map( 'sanitize_text_field', (array) ( $_POST['categories'] ?? [] ) );
    $tags         = array_map( 'sanitize_text_field', (array) ( $_POST['tags']       ?? [] ) );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 8,
        'orderby'        => 'relevance',
    ];

    // Buscar por nombre parecido
    if ( $product_name ) $args['s'] = implode( ' ', array_slice( explode( ' ', $product_name ), 0, 3 ) );

    // O por mismas categorías
    if ( empty( $args['s'] ) && ! empty( $categories ) ) {
        $cat_ids = [];
        foreach ( $categories as $cat_name ) {
            $term = get_term_by( 'name', $cat_name, 'product_cat' );
            if ( $term ) $cat_ids[] = $term->term_id;
        }
        if ( $cat_ids ) {
            $args['tax_query'] = [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_ids ] ];
            unset( $args['orderby'] );
        }
    }

    $posts  = get_posts( $args );
    $result = [];

    foreach ( array_slice( $posts, 0, 8 ) as $p ) {
        $wc = wc_get_product( $p->ID );
        if ( ! $wc ) continue;
        $result[] = [
            'id'        => $p->ID,
            'name'      => $p->post_title,
            'permalink' => get_permalink( $p->ID ),
            'price'     => $wc->get_price_html(),
            'image'     => wp_get_attachment_image_url( $wc->get_image_id(), 'thumbnail' ),
            'slug'      => $p->post_name,
        ];
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Export formulario como JSON
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_export_json', function () {
    ckg_check_ajax();
    $data_raw = $_POST['form_data'] ?? '{}';
    $data     = @json_decode( stripslashes( $data_raw ), true );
    if ( ! is_array( $data ) ) wp_send_json_error( [ 'message' => 'Error al procesar datos.' ] );

    $export = [
        '_ckg_export_version' => CKG_VERSION,
        '_ckg_export_date'    => current_time( 'Y-m-d H:i' ),
        '_ckg_export_site'    => get_site_url(),
        'data'                => $data,
    ];

    wp_send_json_success( [ 'json' => wp_json_encode( $export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   HOOK: Generar Web Story automáticamente al publicar un producto
   Se activa cuando el producto pasa de draft/pending a 'publish'
══════════════════════════════════════════════════════════════════════════ */
add_action( 'transition_post_status', function ( string $new_status, string $old_status, WP_Post $post ) {
    // Solo productos WooCommerce que se publican por primera vez
    if ( $post->post_type !== 'product' )   return;
    if ( $new_status !== 'publish' )         return;
    if ( $old_status === 'publish' )         return; // Ya estaba publicado
    if ( ! CKG_Web_Story::plugin_active() )  return;

    // Verificar si el producto fue creado por nuestro plugin (meta indicador)
    $created_by_ckg = get_post_meta( $post->ID, '_ckg_generated', true );
    if ( ! $created_by_ckg ) return; // Solo productos creados por el plugin

    // Evitar doble creación
    if ( CKG_Web_Story::get_story_id( $post->ID ) ) return;

    // Recoger datos guardados temporalmente (los guardamos en el meta al crear el producto)
    $story_data_json = get_post_meta( $post->ID, '_ckg_story_data_temp', true );
    $data = $story_data_json ? json_decode( $story_data_json, true ) : [];

    $result = CKG_Web_Story::create_for_product( $post->ID, $data );

    if ( ! is_wp_error( $result ) ) {
        // Limpiar el temporal
        delete_post_meta( $post->ID, '_ckg_story_data_temp' );
    }
}, 10, 3 );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Crear Web Story manualmente desde el admin
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_create_web_story', function () {
    ckg_check_ajax();
    set_time_limit( 60 );

    $product_id = absint( $_POST['product_id'] ?? 0 );

    // Si no hay product_id, los datos son del formulario (producto aún no creado)
    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => 'Primero crea el producto y luego genera la story.' ] );
    }

    if ( ! CKG_Web_Story::plugin_active() ) {
        wp_send_json_error( [
            'message' => 'El plugin "Web Stories" de Google no está instalado. Instálalo desde Plugins → Añadir nuevo → "Web Stories".',
        ] );
    }

    // Evitar duplicados
    $existing = CKG_Web_Story::get_story_id( $product_id );
    if ( $existing && get_post_status( $existing ) === 'publish' ) {
        wp_send_json_success( [
            'story_id'  => $existing,
            'story_url' => get_permalink( $existing ),
            'edit_url'  => get_edit_post_link( $existing, 'raw' ),
            'message'   => 'Ya existe una Web Story para este producto.',
            'existing'  => true,
        ] );
    }

    // Recoger datos adicionales del POST
    $data_raw = $_POST['product_data'] ?? '{}';
    $data     = @json_decode( stripslashes( $data_raw ), true ) ?: [];

    $result = CKG_Web_Story::create_for_product( $product_id, $data );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [
        'story_id'       => $result,
        'story_url'      => get_permalink( $result ),
        'edit_url'       => get_edit_post_link( $result, 'raw' ),
        'preview_url'    => get_permalink( $result ),
        'message'        => '✅ Web Story creada correctamente.',
        'existing'       => false,
        'slides_created' => 4,
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Comprobar si Web Stories plugin está activo
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_check_web_stories_plugin', function () {
    ckg_check_ajax();
    wp_send_json_success( [
        'active'  => CKG_Web_Story::plugin_active(),
        'message' => CKG_Web_Story::plugin_active()
            ? 'Plugin Web Stories activo ✅'
            : 'Plugin Web Stories no encontrado — instálalo desde Plugins → Añadir nuevo.',
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Detectar configuraciones Ollama de otros plugins en wp_options
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_detect_llm_configs', function () {
    ckg_check_ajax();
    global $wpdb;

    $found = [];

    // Patrones a buscar en los valores de wp_options
    $url_patterns = [
        'localhost:11434',
        'trycloudflare.com',
        'cloudflare.com',
        'ngrok',
        '.loca.lt',
        'ollama',
        '/api/generate',
        '/api/chat',
    ];

    // Buscar opciones que contengan cualquier patrón
    $like_clauses = implode( ' OR ', array_map(
        fn($p) => $wpdb->prepare( 'option_value LIKE %s', '%' . $wpdb->esc_like($p) . '%' ),
        $url_patterns
    ) );

    // Excluir opciones del sistema y transients
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE ($like_clauses)
         AND option_name NOT LIKE '\_transient%'
         AND option_name NOT LIKE '\_site_transient%'
         AND option_name NOT LIKE 'ckg\_%'
         AND option_name NOT LIKE 'rank\_math%'
         AND option_name NOT IN ('siteurl','home','blogdescription','blogname')
         LIMIT 40"
    );

    foreach ( $rows as $row ) {
        $val = maybe_unserialize( $row->option_value );
        if ( ! is_array( $val ) ) {
            // Valor directo (string) — podría ser la URL directamente
            if ( is_string( $val ) && filter_var( $val, FILTER_VALIDATE_URL ) ) {
                foreach ( $url_patterns as $pat ) {
                    if ( str_contains( strtolower($val), $pat ) ) {
                        $found[] = [
                            'option_key'    => $row->option_name,
                            'subkey'        => '',
                            'endpoint'      => $val,
                            'model'         => '',
                            'display'       => $row->option_name . ' → ' . $val,
                            'type'          => 'direct',
                        ];
                        break;
                    }
                }
            }
            continue;
        }

        // Array — buscar claves con URL de Ollama
        $ep_found    = '';
        $ep_key      = '';
        $model_found = '';
        $model_key   = '';

        foreach ( $val as $k => $v ) {
            if ( ! is_string( $v ) || empty( $v ) ) continue;
            $k_lower = strtolower( (string) $k );
            $v_lower = strtolower( $v );

            // Detectar endpoint
            if ( ! $ep_found ) {
                $is_url_key = str_contains($k_lower,'endpoint') || str_contains($k_lower,'url') ||
                              str_contains($k_lower,'ollama')   || str_contains($k_lower,'llm') ||
                              str_contains($k_lower,'host');
                $is_url_val = filter_var($v, FILTER_VALIDATE_URL);
                $has_pattern = false;
                foreach ($url_patterns as $pat) {
                    if (str_contains($v_lower, $pat)) { $has_pattern = true; break; }
                }
                if ( $is_url_val && ( $is_url_key || $has_pattern ) ) {
                    $ep_found = $v;
                    $ep_key   = $k;
                }
            }

            // Detectar modelo
            if ( ! $model_found ) {
                $is_model_key = str_contains($k_lower,'model');
                $is_model_val = preg_match('/^(llama|mistral|qwen|deepseek|gemma|phi|vicuna|wizard|solar|orca|codellama)[0-9:.\-]*/i', $v);
                if ( $is_model_key && $is_model_val ) {
                    $model_found = $v;
                    $model_key   = $k;
                }
            }
        }

        if ( $ep_found ) {
            $found[] = [
                'option_key'    => $row->option_name,
                'subkey'        => $ep_key,
                'model_subkey'  => $model_key,
                'endpoint'      => $ep_found,
                'model'         => $model_found,
                'display'       => $row->option_name . '[' . $ep_key . '] → ' . $ep_found . ( $model_found ? ' | Modelo: ' . $model_found : '' ),
                'type'          => 'nested',
            ];
        }
    }

    // Deduplicar por endpoint
    $unique = [];
    $seen   = [];
    foreach ( $found as $item ) {
        if ( ! isset($seen[$item['endpoint']]) ) {
            $unique[] = $item;
            $seen[$item['endpoint']] = true;
        }
    }

    wp_send_json_success( [
        'configs' => $unique,
        'count'   => count($unique),
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Guardar config Ollama compartida (opción seleccionada)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_save_shared_llm', function () {
    ckg_check_ajax();

    $option_key   = sanitize_text_field( $_POST['option_key']   ?? '' );
    $subkey       = sanitize_text_field( $_POST['subkey']       ?? '' );
    $model_subkey = sanitize_text_field( $_POST['model_subkey'] ?? '' );
    $model        = sanitize_text_field( $_POST['model']        ?? '' );

    if ( ! $option_key ) wp_send_json_error( [ 'message' => 'Clave de opción vacía.' ] );

    $s = get_option( 'ckg_settings', [] );
    $s['shared_llm_option_key'] = $option_key;
    $s['shared_llm_subkey_map'] = [
        'ollama_endpoint' => $subkey,
        'ollama_model'    => $model_subkey,
    ];
    // Limpiar config propia para que use la compartida
    $s['ollama_endpoint'] = '';
    if ( $model ) $s['ollama_model'] = $model;

    update_option( 'ckg_settings', $s );

    // Verificar que funciona
    $endpoint_resolved = CKG_Ollama::ping() ? 'online' : 'offline';

    wp_send_json_success( [
        'saved'    => true,
        'status'   => $endpoint_resolved,
        'endpoint' => CKG_Ollama::get_resolved_endpoint(),
        'message'  => $endpoint_resolved === 'online'
            ? '✅ Configuración guardada y Ollama responde correctamente.'
            : '⚠️ Configuración guardada pero Ollama no responde. Comprueba el túnel.',
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Generar respuestas de FAQ vacías con Ollama
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_generate_faq_answers', function () {
    ckg_check_ajax();

    $product_name   = sanitize_text_field( $_POST['product_name']    ?? '' );
    $brand          = sanitize_text_field( $_POST['brand']           ?? '' );
    $questions_raw  = $_POST['questions'] ?? [];

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Introduce el nombre del producto primero (pestaña Básico).' ] );
    }
    if ( empty( $questions_raw ) ) {
        wp_send_json_error( [ 'message' => 'No hay preguntas que responder. Añade primero las preguntas.' ] );
    }

    $questions = array_filter(
        array_map( 'sanitize_text_field', (array) $questions_raw )
    );

    if ( empty( $questions ) ) {
        wp_send_json_error( [ 'message' => 'Las preguntas están vacías.' ] );
    }

    $homos_raw = $_POST['homologaciones'] ?? [];
    $homo_text = '';
    if ( is_array( $homos_raw ) ) {
        $homos = array_filter( array_map( 'sanitize_text_field', $homos_raw ) );
        if ( $homos ) $homo_text = 'Homologaciones: ' . implode( ', ', $homos ) . '.';
    }

    $qs_numbered = implode( "
", array_map(
        fn( $i, $q ) => ( $i + 1 ) . '. ' . $q,
        array_keys( $questions ), $questions
    ) );

    $prompt = <<<PROMPT
Eres un redactor técnico experto en karting de competición para CMSKart.es.

Producto: "$product_name"
Marca: $brand
$homo_text

Responde EXACTAMENTE a estas preguntas sobre el producto. Cada respuesta: 2-4 frases, técnica, directa y útil para un piloto de karting.

$qs_numbered

Responde SOLO en formato JSON array en el mismo orden, sin texto adicional:
[
  "Respuesta a la pregunta 1...",
  "Respuesta a la pregunta 2...",
  ...
]
PROMPT;

    if ( ! CKG_LLM::ping() ) {
        $provider = CKG_LLM::active_provider();
        wp_send_json_error( [ 'message' => 'Proveedor LLM (' . $provider['label'] . ') no disponible. Comprueba la conexión en Ajustes.' ] );
    }

    $ctx    = [ 'product_name' => $product_name, 'brand' => $brand ];
    $result = CKG_LLM::improve_raw( $prompt, $ctx );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    // Parsear JSON
    $clean   = trim( preg_replace( '/```json|```/', '', $result ) );
    $decoded = @json_decode( $clean, true );

    if ( ! is_array( $decoded ) ) {
        // Intentar extraer strings entre comillas
        preg_match_all( '/"((?:[^"\\]|\\.)*)"/s', $result, $m );
        $decoded = $m[1] ?? [];
    }

    $answers = array_map( 'sanitize_textarea_field', array_values( $decoded ) );

    wp_send_json_success( [
        'answers' => $answers,
        'count'   => count( $answers ),
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Generar SEO desde cero (cuando el campo está vacío)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_generate_seo_from_scratch', function () {
    ckg_check_ajax();

    $section      = sanitize_key( $_POST['section']       ?? '' );
    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $sku          = sanitize_text_field( $_POST['sku']          ?? '' );
    $homos_raw    = $_POST['homologaciones'] ?? [];
    $short_desc   = sanitize_textarea_field( $_POST['short_desc'] ?? '' );

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Rellena el nombre del producto en la pestaña Básico primero.' ] );
    }

    $homo_text = '';
    if ( is_array( $homos_raw ) ) {
        $homos = array_filter( array_map( 'sanitize_text_field', $homos_raw ) );
        if ( $homos ) $homo_text = implode( ' · ', $homos );
    }

    $ctx = compact( 'product_name', 'brand', 'sku' );

    if ( $section === 'seo_title' ) {
        $homo_part = $homo_text ? ' | ' . substr( $homo_text, 0, 20 ) : '';
        $base      = $product_name . ( $brand ? ' ' . $brand : '' ) . $homo_part . ' | CMSKart';

        $prompt = <<<PROMPT
Eres un experto SEO en karting para CMSKart.es.
Genera un SEO title para Google (EXACTAMENTE entre 50 y 60 caracteres) para este producto: $base
Formato ideal: [Nombre producto] | [Homologación clave] | CMSKart
Responde SOLO el title, sin comillas ni explicaciones.
PROMPT;

        $result = CKG_LLM::improve_raw( $prompt, $ctx );

    } elseif ( $section === 'seo_description' ) {

        $prompt = <<<PROMPT
Eres un experto SEO en karting para CMSKart.es.
Genera una meta description para Google (EXACTAMENTE entre 140 y 155 caracteres) para este producto:
Producto: $product_name
Marca: $brand
Homologaciones: $homo_text
Descripcion: $short_desc

Incluye: nombre del producto, ventaja principal, homologacion si aplica, y CMSKart.
Responde SOLO la meta description, sin comillas ni explicaciones.
PROMPT;

        $result = CKG_LLM::improve_raw( $prompt, $ctx );

    } else {
        wp_send_json_error( [ 'message' => 'Sección no reconocida.' ] );
        return;
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    $clean = trim( preg_replace( '/^["\']|["\']$/', '', trim( $result ) ) );

    wp_send_json_success( [ 'text' => $clean ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Análisis SEO competitivo vs Google
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_competitive_seo', function () {
    ckg_check_ajax();
    set_time_limit( 120 );

    $keyword  = sanitize_text_field( $_POST['keyword']   ?? '' );
    $data_raw = $_POST['form_data'] ?? '{}';
    $data     = @json_decode( stripslashes( $data_raw ), true ) ?: [];

    if ( empty( $keyword ) ) {
        wp_send_json_error( [ 'message' => 'Introduce la keyword en la pestaña SEO primero.' ] );
    }

    $result = CKG_Competitive_SEO::analyze( $keyword, $data );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Clonar producto existente
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_clone_product', function () {
    ckg_check_ajax();

    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => 'Selecciona un producto.' ] );
    }

    $result = CKG_History::clone_product( $product_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   PÁGINA: Historial de productos
══════════════════════════════════════════════════════════════════════════ */

/* ======================================================================
   PAGINA: Log de cambios y consultas útiles de BD
====================================================================== */
function ckg_log_page(): void {

    // Changelog del plugin
    $changelog = [
        ["2.7.5","2026-05-17","fix",     "Prompts: faltaba usa_qdrant en INSERT. enrich_product privado→público. resetForm no limpiaba qdrant."],
        ["2.7.4","2026-05-17","feature", "Buscador individual en Enriquecer. Enriquecer por nombre/SKU directamente."],
        ["2.7.3","2026-05-17","fix",     "Qdrant ping: timeout 10s, /readyz+/collections. JS badge muestra errores reales."],
        ["2.7.2","2026-05-17","fix",     "Nonce ckg_ajax→ckg_admin_nonce en prompts. calcCompleteness guard."],
        ["2.7.1","2026-05-16","feature", "Panel Qdrant en Ajustes: verificación, stats, buscador prueba, colecciones configurables."],
        ["2.7.0","2026-05-16","feature", "CKG_Qdrant cliente completo. Context Scraper: Qdrant→ChromaDB→web en orden."],
        ["2.6.9","2026-05-16","fix",     "wp_kses_post→wp_unslash en prompts. LLMProvider::generate→improve_raw."],
        ["2.6.8","2026-05-16","feature", "CKG_Prompt_Manager: tabla ckg_prompts, CRUD, 2 prompts predefinidos, {qdrant_context}."],
        ["2.6.7","2026-05-15","feature", "ChromaDB en CKG_Context_Scraper: caché scraping, conocimiento marca, 3 colecciones."],
        ["2.6.6","2026-05-15","feature", "CKG_Context_Scraper completo: fabricante+distribuidor+CIK-FIA+Qdrant, build_json, log."],
        ["2.6.4","2026-05-15","fix",     "Schema duplicado wp_head→solo FAQPage. RankMath gestiona Product schema."],
        ["2.6.3","2026-05-14","fix",     "Web Stories: set_post_thumbnail para poster. amp-story-page-attachment con enlace."],
        ["2.6.2","2026-05-14","fix",     "Image importer restaurado a v1.4.0. EXIF eliminado (corrompía WebP→grises)."],
        ["2.6.1","2026-05-14","fix",     "ChromaDB: find_similar_products y check_keyword_cannibalization. bulk_index loop JS."],
        ["2.5.9","2026-05-13","fix",     "Contador bulk_index con SQL. RankMath focus keyword: separar principal de secundarias."],
        ["2.5.7","2026-05-13","feature", "Precios competidores con alerta. Filtro score SEO. Modo Revisión. Canibalización keywords."],
        ["2.5.0","2026-05-12","feature", "Enriquecimiento batch completo: backup, FAQ, traducciones EN/IT, schema JSON-LD."],
        ["2.4.6","2026-05-10","fix",     "CKG_Ollama::generate()→improve_raw(). Cola enricher limpiada al iniciar."],
        ["2.4.0","2026-05-08","feature", "Scraper PrestaShop: lazy loading, dl/dt/dd, variaciones WooCommerce."],
    ];

    // Queries útiles de BD
    $db_queries = [
        ["Limpiar schema RankMath roto",
         "DELETE FROM wpwq_postmeta WHERE meta_key LIKE 'rank_math_schema_%' AND post_id = ID;",
         "Elimina schema de un producto para que RankMath regenere limpio."],
        ["Ver productos enriquecidos por score",
         "SELECT p.ID, p.post_title, pm.meta_value as score FROM wpwq_posts p LEFT JOIN wpwq_postmeta pm ON pm.post_id=p.ID AND pm.meta_key='_ckg_seo_score' WHERE p.post_type='product' AND p.post_status='publish' AND EXISTS(SELECT 1 FROM wpwq_postmeta WHERE post_id=p.ID AND meta_key='_ckg_generated') ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC LIMIT 50;",
         "Productos enriquecidos ordenados por score SEO ascendente."],
        ["Resetear flag enriquecimiento individual",
         "DELETE FROM wpwq_postmeta WHERE meta_key='_ckg_generated' AND post_id = ID;",
         "Permite re-enriquecer un producto específico."],
        ["Resetear flag masivo por categoría",
         "DELETE pm FROM wpwq_postmeta pm JOIN wpwq_term_relationships tr ON tr.object_id=pm.post_id WHERE pm.meta_key='_ckg_generated' AND tr.term_taxonomy_id = TERM_ID;",
         "Resetea flag de toda una categoría para re-enriquecer."],
        ["Ver alertas precio competidor",
         "SELECT p.post_title, pm.meta_value FROM wpwq_posts p JOIN wpwq_postmeta pm ON pm.post_id=p.ID WHERE pm.meta_key='_ckg_price_alert' AND p.post_status='publish';",
         "Productos donde un competidor tiene mejor precio."],
        ["Ver prompts guardados",
         "SELECT id, nombre, modelo_recomendado, usa_scraper, usa_chroma, usa_qdrant, activo FROM wpwq_ckg_prompts ORDER BY es_predefinido DESC;",
         "Lista todos los prompts del gestor con sus opciones."],
        ["Limpiar log scraper antiguo",
         "DELETE FROM wpwq_ckg_scraper_log WHERE fecha < DATE_SUB(NOW(), INTERVAL 30 DAY);",
         "Elimina logs de scraping con más de 30 días."],
        ["Ver canibalización keywords",
         "SELECT p.post_title, pm.meta_value FROM wpwq_posts p JOIN wpwq_postmeta pm ON pm.post_id=p.ID WHERE pm.meta_key='_ckg_keyword_conflict' AND p.post_status='publish';",
         "Productos con conflicto de keywords SEO detectado."],
        ["Productos sin imagen destacada",
         "SELECT p.ID, p.post_title FROM wpwq_posts p WHERE p.post_type='product' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM wpwq_postmeta WHERE post_id=p.ID AND meta_key='_thumbnail_id');",
         "Productos publicados sin imagen destacada."],
        ["Score SEO promedio por categoría",
         "SELECT t.name, ROUND(AVG(CAST(pm.meta_value AS UNSIGNED)),1) as avg_score, COUNT(*) as total FROM wpwq_term_taxonomy tt JOIN wpwq_terms t ON t.term_id=tt.term_id JOIN wpwq_term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN wpwq_postmeta pm ON pm.post_id=tr.object_id WHERE tt.taxonomy='product_cat' AND pm.meta_key='_ckg_seo_score' AND pm.meta_value > 0 GROUP BY t.name ORDER BY avg_score ASC;",
         "Score SEO medio por categoría, peores primero."],
        ["Recrear tabla prompts",
         "DROP TABLE IF EXISTS wpwq_ckg_prompts;",
         "Borra tabla para recrearla al reactivar el plugin."],
        ["Productos con precio menor que competidor",
         "SELECT p.post_title, wc.meta_value as precio, cp.meta_value as comp FROM wpwq_posts p JOIN wpwq_postmeta wc ON wc.post_id=p.ID AND wc.meta_key='_price' JOIN wpwq_postmeta cp ON cp.post_id=p.ID AND cp.meta_key='_ckg_comp_price_min' WHERE p.post_type='product' AND p.post_status='publish' AND CAST(wc.meta_value AS DECIMAL(10,2)) > CAST(cp.meta_value AS DECIMAL(10,2));",
         "Productos donde somos más caros que la competencia."],
    ];

    $tipo_colors = [ 'fix' => '#e74c3c', 'feature' => '#27ae60', 'improvement' => '#2271b1' ];
    $tipo_labels = [ 'fix' => '🐛 Fix', 'feature' => '✨ Feature', 'improvement' => '⚡ Mejora' ];

    ?>
    <div class="wrap ckg-wrap">
    <h1>📋 Log de cambios — CMSKart Product Generator</h1>

    <div style="display:flex;gap:16px;flex-wrap:wrap">

        <!-- CHANGELOG -->
        <div class="ckg-card" style="flex:2;min-width:300px">
            <h2 style="margin-top:0;font-size:15px">📝 Historial de versiones</h2>
            <p style="color:#50575e;font-size:12px;margin:0 0 12px">
                Registro de bugs corregidos, funciones implementadas y mejoras.
                Actualizado automáticamente con cada versión.
            </p>
            <table class="wp-list-table widefat striped" style="font-size:12px">
                <thead>
                    <tr>
                        <th style="width:60px">Versión</th>
                        <th style="width:90px">Fecha</th>
                        <th style="width:80px">Tipo</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $changelog as $entry ) :
                    $color = $tipo_colors[ $entry[2] ] ?? '#999';
                    $label = $tipo_labels[ $entry[2] ] ?? $entry[2];
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $entry[0] ); ?></strong></td>
                        <td style="color:#777"><?php echo esc_html( $entry[1] ); ?></td>
                        <td>
                            <span style="background:<?php echo $color; ?>;color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;white-space:nowrap">
                                <?php echo $label; ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $entry[3] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- DB QUERIES -->
        <div class="ckg-card" style="flex:1;min-width:280px">
            <h2 style="margin-top:0;font-size:15px">🗄️ Consultas SQL útiles</h2>
            <p style="color:#50575e;font-size:12px;margin:0 0 12px">
                Referencia rápida de queries para el plugin.
                Copia y pega en phpMyAdmin. Sustituye ID/TERM_ID según corresponda.
            </p>
            <div style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ( $db_queries as $q ) : ?>
                <div style="background:#f6f7f7;border-radius:4px;padding:10px">
                    <div style="font-weight:600;font-size:12px;margin-bottom:4px">
                        <?php echo esc_html( $q[0] ); ?>
                    </div>
                    <div style="font-size:11px;color:#50575e;margin-bottom:6px">
                        <?php echo esc_html( $q[2] ); ?>
                    </div>
                    <div style="position:relative">
                        <code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px;border-radius:3px;font-size:10px;white-space:pre-wrap;word-break:break-all;cursor:pointer"
                              onclick="navigator.clipboard.writeText(this.innerText).then(()=>{this.style.background='#1a4a1a';setTimeout(()=>this.style.background='#1e1e1e',800)})"
                              title="Click para copiar"><?php echo esc_html( $q[1] ); ?></code>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

    </div>
    </div>
    <?php
}

function ckg_history_page(): void {
    $history = CKG_History::get( 20 );
    ?>
    <div class="wrap ckg-wrap">
    <h1>📋 Historial de productos creados</h1>
    <p class="ckg-subtitle">Últimos productos generados con CMSKart Product Generator.</p>

    <?php if ( empty( $history ) ) : ?>
        <div class="ckg-card"><p>No hay productos creados todavía. <a href="<?php echo admin_url('admin.php?page=' . CKG_SLUG); ?>">Crea tu primer producto</a>.</p></div>
    <?php else : ?>
    <div class="ckg-card" style="padding:0;overflow:hidden">
    <table class="ckg-history-table">
        <thead>
            <tr>
                <th>Imagen</th>
                <th>Producto</th>
                <th>SEO Score</th>
                <th>Completitud</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $history as $item ) : ?>
        <tr>
            <td class="ckg-hist-img">
                <?php if ( $item['image_url'] ) : ?>
                <img src="<?php echo esc_url( $item['image_url'] ); ?>" width="52" height="52">
                <?php else : ?>
                <div class="ckg-hist-no-img">📦</div>
                <?php endif; ?>
            </td>
            <td class="ckg-hist-name">
                <strong><?php echo esc_html( $item['product_name'] ); ?></strong><br>
                <?php if ( $item['brand'] ) : ?>
                <span class="ckg-hist-brand"><?php echo esc_html( $item['brand'] ); ?></span>
                <?php endif; ?>
                <?php if ( $item['sku'] ) : ?>
                <span class="ckg-hist-sku">REF: <?php echo esc_html( $item['sku'] ); ?></span>
                <?php endif; ?>
                <?php
                $price_clean = wp_strip_all_tags( $item['price_html'] ?? '' );
                if ( $price_clean ) echo '<span style="color:#27ae60;font-weight:600">' . esc_html( $price_clean ) . '</span>';
                ?>
            </td>
            <td class="ckg-hist-score">
                <?php
                $score = (int) $item['seo_score'];
                $color = $score >= 85 ? '#27ae60' : ($score >= 70 ? '#2ecc71' : ($score >= 50 ? '#f39c12' : '#c0392b'));
                ?>
                <div class="ckg-hist-score-circle" style="border-color:<?php echo $color; ?>;color:<?php echo $color; ?>">
                    <?php echo $score; ?>
                </div>
            </td>
            <td class="ckg-hist-meta">
                <span title="Especificaciones">⚙️ <?php echo (int)$item['spec_count']; ?></span>
                <span title="FAQ">❓ <?php echo (int)$item['faq_count']; ?></span>
                <span title="Homologaciones">🏆 <?php echo (int)$item['homo_count']; ?></span>
                <span title="Imágenes">🖼️ <?php echo (int)$item['image_count']; ?></span>
                <?php if ( $item['has_story'] ) : ?>
                <span title="Web Story activa">📖✅</span>
                <?php else : ?>
                <span title="Sin Web Story" style="opacity:.4">📖</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $status_labels = [ 'publish' => '🟢 Publicado', 'draft' => '🟡 Borrador', 'pending' => '🔵 Pendiente', 'trash' => '🔴 Papelera' ];
                echo $status_labels[ $item['status'] ] ?? esc_html( $item['status'] );
                ?>
            </td>
            <td class="ckg-hist-date">
                <?php echo esc_html( $item['created_at'] ); ?>
            </td>
            <td class="ckg-hist-actions">
                <a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="button button-small">✏️ Editar</a>
                <?php if ( $item['product_url'] ) : ?>
                <a href="<?php echo esc_url( $item['product_url'] ); ?>" class="button button-small" target="_blank">👁</a>
                <?php endif; ?>
                <?php if ( $item['story_url'] ) : ?>
                <a href="<?php echo esc_url( $item['story_url'] ); ?>" class="button button-small" target="_blank" title="Ver Web Story">📖</a>
                <?php endif; ?>
                <button type="button" class="button button-small btn-clone-from-history"
                    data-product-id="<?php echo $item['product_id']; ?>"
                    title="Clonar este producto en el formulario">
                    📋 Clonar
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Obtener info del proveedor activo (para la UI)
══════════════════════════════════════════════════════════════════════════ */
/* ckg_get_provider_info - eliminado (obsoleto) */

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: AutoFill — URL → datos completos del producto
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_autofill', function () {
    ckg_check_ajax();
    set_time_limit( 180 );

    $url          = esc_url_raw( sanitize_text_field( $_POST['url']          ?? '' ) );
    $product_name = sanitize_text_field( $_POST['product_name']              ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']                     ?? '' );
    $template_id  = sanitize_key( $_POST['template_id']                      ?? '' );

    if ( ! $url && ! $product_name ) {
        wp_send_json_error( [ 'message' => 'Introduce al menos una URL o el nombre del producto.' ] );
    }

    $result = CKG_AutoFill::run( $url, $product_name, $brand, $template_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [
        'data'    => $result,
        'message' => 'Formulario completado automáticamente.',
        'source'  => $url ? 'url' : 'name',
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Pipeline automático completo (template + LLM + categorías + attrs)
══════════════════════════════════════════════════════════════════════════ */
/* ckg_auto_pipeline - eliminado (obsoleto) */

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Batch import preview (leer CSV/Excel y devolver las filas sin crear)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_batch_preview', function () {
    ckg_check_ajax();

    if ( empty( $_FILES['csv_file'] ) ) {
        wp_send_json_error( [ 'message' => 'No se recibió ningún archivo.' ] );
    }

    $file     = $_FILES['csv_file'];
    $filepath = $file['tmp_name'];
    $mime     = $file['type'];

    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'Error al subir el archivo: código ' . $file['error'] ] );
    }

    $result = CKG_BatchImporter::process( $filepath, $mime, [ 'preview_only' => true ] );

    if ( isset($result['error']) ) {
        wp_send_json_error( [ 'message' => $result['error'] ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Batch import ejecutar
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_batch_execute', function () {
    ckg_check_ajax();
    set_time_limit( 600 ); // hasta 10 min para lotes grandes

    if ( empty( $_FILES['csv_file'] ) ) {
        wp_send_json_error( [ 'message' => 'No se recibió ningún archivo.' ] );
    }

    $file    = $_FILES['csv_file'];
    $options = [
        'autofill_llm'  => ! empty( $_POST['autofill_llm'] ),
        'import_images' => ! empty( $_POST['import_images'] ),
        'status'        => sanitize_key( $_POST['product_status'] ?? 'draft' ),
        'preview_only'  => false,
    ];

    $result = CKG_BatchImporter::process( $file['tmp_name'], $file['type'], $options );

    if ( isset($result['error']) ) {
        wp_send_json_error( [ 'message' => $result['error'] ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Descargar plantilla CSV
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_download_csv_template', function () {
    ckg_check_ajax();
    $csv = CKG_BatchImporter::generate_template_csv();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="ckg-productos-plantilla.csv"' );
    echo $csv;
    exit;
} );

/* ══════════════════════════════════════════════════════════════════════════
   REST API: Crear producto desde n8n o sistemas externos
══════════════════════════════════════════════════════════════════════════ */
add_action( 'rest_api_init', function () {
    register_rest_route( 'ckg/v1', '/create-product', [
        'methods'             => 'POST',
        'callback'            => 'ckg_rest_create_product',
        'permission_callback' => function ( WP_REST_Request $request ) {
            // Autenticación por API Key en header o por usuario WP autenticado
            $api_key    = $request->get_header('X-CKG-Key');
            $stored_key = get_option('ckg_rest_api_key', '');
            if ( $stored_key && $api_key === $stored_key ) return true;
            return current_user_can( 'manage_woocommerce' );
        },
    ] );

    register_rest_route( 'ckg/v1', '/autofill', [
        'methods'             => 'POST',
        'callback'            => 'ckg_rest_autofill',
        'permission_callback' => function ( WP_REST_Request $req ) {
            $key = $req->get_header('X-CKG-Key');
            return ( $key && $key === get_option('ckg_rest_api_key','') ) || current_user_can('manage_woocommerce');
        },
    ] );

    register_rest_route( 'ckg/v1', '/settings', [
        'methods'             => 'POST',
        'callback'            => function ( WP_REST_Request $req ) {
            $s = get_option( 'ckg_settings', [] );
            $body = $req->get_json_params();
            // Solo permite actualizar ollama_endpoint desde externo
            if ( isset($body['ollama_endpoint']) ) {
                $s['ollama_endpoint'] = esc_url_raw( $body['ollama_endpoint'] );
                update_option( 'ckg_settings', $s );
                return new WP_REST_Response( [ 'updated' => true, 'endpoint' => $s['ollama_endpoint'] ], 200 );
            }
            return new WP_REST_Response( [ 'error' => 'Campo no permitido.' ], 400 );
        },
        'permission_callback' => function ( WP_REST_Request $req ) {
            $key = $req->get_header('X-CKG-Key');
            return $key && $key === get_option('ckg_rest_api_key','');
        },
    ] );
} );

function ckg_rest_create_product( WP_REST_Request $request ): WP_REST_Response {
    $body = $request->get_json_params();
    if ( empty($body) ) $body = $request->get_params();

    $data = ckg_parse_form( $body );

    // AutoFill opcional
    if ( ! empty($body['autofill_url']) && class_exists('CKG_AutoFill') ) {
        $filled = CKG_AutoFill::run( $body['autofill_url'], $data['product_name'], $data['brand'] );
        if ( ! is_wp_error($filled) ) {
            foreach ($filled as $k => $v) {
                if ( empty($data[$k]) ) $data[$k] = $v;
            }
        }
    }

    $result = CKG_Woo_Creator::create( $data );

    if ( is_wp_error($result) ) {
        return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
    }

    if ( class_exists('CKG_History') ) CKG_History::record($result, $data);

    return new WP_REST_Response( [
        'product_id'  => $result,
        'edit_url'    => get_edit_post_link($result, 'raw'),
        'product_url' => get_permalink($result),
        'status'      => get_post_status($result),
    ], 201 );
}

function ckg_rest_autofill( WP_REST_Request $request ): WP_REST_Response {
    $body = $request->get_json_params();
    $result = CKG_AutoFill::run(
        esc_url_raw( $body['url']          ?? '' ),
        sanitize_text_field( $body['product_name'] ?? '' ),
        sanitize_text_field( $body['brand']        ?? '' ),
        sanitize_key( $body['template_id']         ?? '' )
    );
    if ( is_wp_error($result) ) {
        return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
    }
    return new WP_REST_Response( $result, 200 );
}

/* ── Página Express ─────────────────────────────────────────────────── */
function ckg_express_page(): void { ?>
    <div class="wrap ckg-wrap">
    <h1>⚡ Modo Express — Producto completo desde una URL</h1>
    <p class="ckg-subtitle">Introduce la URL del fabricante o competidor y el plugin rellena todos los tabs automáticamente.</p>

    <div class="ckg-card">
        <h2>1. Introduce los datos base</h2>
        <table class="form-table">
            <tr>
                <th>URL del producto</th>
                <td>
                    <input type="url" id="express-url" class="large-text" placeholder="https://www.fabricante.com/producto/casco-bell-rs7k-gp">
                    <p class="description">URL del fabricante o competidor. El plugin extraerá automáticamente nombre, imágenes, specs, homologaciones y más.</p>
                </td>
            </tr>
            <tr>
                <th>O solo nombre + marca</th>
                <td>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <input type="text" id="express-name"  class="regular-text" placeholder="Nombre del producto">
                        <input type="text" id="express-brand" class="regular-text" placeholder="Marca">
                    </div>
                    <p class="description">Si no tienes URL, el plugin generará todo el contenido con IA a partir del nombre y la marca.</p>
                </td>
            </tr>
            <tr>
                <th>Tipo de producto</th>
                <td>
                    <select id="express-template" class="regular-text">
                        <option value="">Auto-detectar</option>
                        <?php foreach ( CKG_Templates::list() as $tpl ) : ?>
                        <option value="<?php echo esc_attr($tpl['id']); ?>"><?php echo esc_html($tpl['icon'] . ' ' . $tpl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>

        <div style="margin-top:16px;display:flex;gap:12px;align-items:center">
            <button type="button" class="button button-primary button-hero" id="btn-express-run">
                ⚡ Rellenar formulario automáticamente
            </button>
            <span id="express-status" style="font-size:13px"></span>
        </div>

        <div id="express-progress" style="display:none;margin-top:16px" class="ckg-express-progress">
            <div class="ckg-express-steps">
                <div class="ckg-step" id="step-scrape">🔍 Analizando URL…</div>
                <div class="ckg-step" id="step-template">📋 Aplicando plantilla…</div>
                <div class="ckg-step" id="step-images">🖼️ Importando imágenes…</div>
                <div class="ckg-step" id="step-llm">🤖 Generando contenido con IA…</div>
                <div class="ckg-step" id="step-seo">🔍 Generando SEO…</div>
                <div class="ckg-step" id="step-done">✅ Formulario listo</div>
            </div>
        </div>
    </div>

    <div class="ckg-card" id="express-result-panel" style="display:none">
        <h2>2. Revisa y crea el producto</h2>
        <p>El formulario principal se ha pre-rellenado con los datos extraídos. Pulsa el botón de abajo para ir al formulario, revisar los datos y crear el producto.</p>
        <div id="express-summary"></div>
        <div style="margin-top:16px">
            <a href="<?php echo admin_url('admin.php?page=' . CKG_SLUG); ?>" class="button button-primary button-hero" id="btn-go-to-form">
                🏎️ Ir al formulario y crear el producto
            </a>
        </div>
    </div>
    </div>
<?php }

/* ── Página Importar CSV ─────────────────────────────────────────────── */
function ckg_import_page(): void { ?>
    <div class="wrap ckg-wrap">
    <h1>📊 Importar productos desde CSV / Excel</h1>
    <p class="ckg-subtitle">Crea múltiples productos de una vez subiendo un archivo CSV o Excel.</p>

    <div class="ckg-card">
        <h2>Plantilla CSV</h2>
        <p>Descarga la plantilla, rellénala con tus productos y súbela aquí.</p>
        <a href="<?php echo admin_url('admin-ajax.php?action=ckg_download_csv_template&nonce=' . wp_create_nonce('ckg_admin_nonce')); ?>" class="button">
            📥 Descargar plantilla CSV de ejemplo
        </a>
        <div class="ckg-helper-box" style="margin-top:12px">
            <strong>Columnas principales:</strong> name, brand, sku, price, variation_labels, variation_prices, categories, images_urls, url_fabricante<br>
            <strong>Separador múltiple:</strong> usa <code>|</code> dentro de un campo para múltiples valores. Ej: <code>Talla S|Talla M|Talla L</code><br>
            <strong>Máximo:</strong> 100 productos por importación
        </div>
    </div>

    <div class="ckg-card">
        <h2>Subir archivo</h2>
        <form id="import-form" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th>Archivo CSV / Excel</th>
                    <td>
                        <input type="file" name="csv_file" id="csv-file" accept=".csv,.xlsx,.xls">
                        <p class="description">Formatos: .csv, .xlsx, .xls — Máx 5MB</p>
                    </td>
                </tr>
                <tr>
                    <th>Opciones</th>
                    <td>
                        <label><input type="checkbox" name="autofill_llm" value="1"> 🤖 Generar contenido con IA para campos vacíos (más lento)</label><br>
                        <label><input type="checkbox" name="import_images" value="1" checked> 🖼️ Importar imágenes automáticamente</label><br>
                        <label><input type="radio" name="product_status" value="draft" checked> Crear como borrador</label>&nbsp;&nbsp;
                        <label><input type="radio" name="product_status" value="publish"> Publicar directamente</label>
                    </td>
                </tr>
            </table>
            <div style="display:flex;gap:10px;margin-top:12px">
                <button type="button" class="button" id="btn-preview-import">👁 Vista previa (sin crear)</button>
                <button type="button" class="button button-primary" id="btn-execute-import">🚀 Importar y crear productos</button>
            </div>
        </form>

        <div id="import-preview" style="margin-top:16px;display:none"></div>
        <div id="import-results" style="margin-top:16px;display:none"></div>
    </div>

    <div class="ckg-card">
        <h2>🔌 API REST para n8n / automatizaciones</h2>
        <p>Puedes crear productos desde cualquier sistema externo usando la API REST del plugin.</p>
        <div class="ckg-helper-box">
            <strong>Endpoint crear producto:</strong><br>
            <code>POST <?php echo get_site_url(); ?>/wp-json/ckg/v1/create-product</code><br><br>
            <strong>Endpoint AutoFill (obtener datos de una URL):</strong><br>
            <code>POST <?php echo get_site_url(); ?>/wp-json/ckg/v1/autofill</code><br><br>
            <strong>Autenticación:</strong> Header <code>X-CKG-Key: TU_API_KEY</code><br><br>
            <?php
            $api_key = get_option('ckg_rest_api_key','');
            if ( $api_key ) : ?>
            <strong>Tu API Key:</strong> <code><?php echo esc_html( substr($api_key,0,8) . '...' ); ?></code>
            <button type="button" class="button button-small" id="btn-show-api-key">Mostrar</button>
            <span id="full-api-key" style="display:none"><code><?php echo esc_html($api_key); ?></code></span>
            <?php else : ?>
            <button type="button" class="button button-primary" id="btn-generate-api-key">🔑 Generar API Key</button>
            <div id="new-api-key" style="margin-top:8px"></div>
            <?php endif; ?>
        </div>
    </div>
    </div>
<?php }

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Guardar datos AutoFill en transient (server-side, más fiable que sessionStorage)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_store_autofill', function () {
    ckg_check_ajax();

    $data_raw = $_POST['data'] ?? '';
    $data     = @json_decode( stripslashes( $data_raw ), true );

    if ( ! is_array( $data ) || empty( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
    }

    $user_id   = get_current_user_id();
    $key       = 'ckg_autofill_pending_' . $user_id;

    // Guardar 1 hora — suficiente para que el usuario navegue al formulario
    set_transient( $key, $data, HOUR_IN_SECONDS );

    wp_send_json_success( [
        'stored'    => true,
        'form_url'  => admin_url( 'admin.php?page=' . CKG_SLUG ),
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Búsqueda de producto con Perplexity (tiempo real)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_perplexity_search', function () {
    ckg_check_ajax();
    set_time_limit( 60 );

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $focus        = sanitize_key(        $_POST['focus']        ?? 'full' );

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Introduce el nombre del producto primero.' ] );
    }

    $result = CKG_LLM::search_product( $product_name, $brand, $focus );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [
        'data'    => $result,
        'focus'   => $focus,
        'sources' => $result['_citations'] ?? [],
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Verificar homologación con Perplexity
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_verify_homologacion', function () {
    ckg_check_ajax();

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $tipo         = sanitize_text_field( $_POST['tipo']         ?? '' );
    $codigo       = sanitize_text_field( $_POST['codigo']       ?? '' );

    if ( ! $tipo ) {
        wp_send_json_error( [ 'message' => 'Tipo de homologación requerido.' ] );
    }

    $result = CKG_LLM::verify_homologacion( $product_name, $tipo, $codigo );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Precios de mercado con Perplexity
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_perplexity_prices', function () {
    ckg_check_ajax();

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Nombre del producto requerido.' ] );
    }

    $result = CKG_LLM::search_competitor_prices( $product_name, $brand );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: FAQ real de usuarios con Perplexity
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_perplexity_faq', function () {
    ckg_check_ajax();

    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );

    if ( empty( $product_name ) ) {
        wp_send_json_error( [ 'message' => 'Nombre del producto requerido.' ] );
    }

    $result = CKG_LLM::search_real_faq( $product_name, $brand );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    // Normalizar formato de FAQ
    $faq = $result['faq'] ?? [];
    wp_send_json_success( [ 'faq' => $faq, 'sources' => $result['_citations'] ?? [] ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Detectar plugins sociales/SEO activos
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_detect_social_plugins', function () {
    ckg_check_ajax();
    $active  = CKG_SocialMeta::detect_active_plugins();
    $labels  = [
        'facebook_wc'     => 'Facebook for WooCommerce',
        'google_listings' => 'Google Listings & Ads',
        'rankmath'        => 'RankMath SEO',
        'yoast'           => 'Yoast SEO',
        'yoast_woo'       => 'Yoast WooCommerce SEO',
        'aioseo'          => 'All in One SEO',
        'seopress'        => 'SEOPress',
        'pinterest_wc'    => 'Pinterest for WooCommerce',
        'adtribes_feed'   => 'WooCommerce Product Feed (AdTribes)',
        'ctxfeed'         => 'CTX Feed / WooFeed',
        'yith_feed'       => 'YITH Google Product Feed',
        'wpfm'            => 'WP Product Feed Manager',
        'pixel_cat'       => 'PixelYourSite / Pixel Cat',
    ];
    $result = [];
    foreach ( $labels as $key => $label ) {
        $result[] = [
            'key'    => $key,
            'label'  => $label,
            'active' => ! empty( $active[ $key ] ),
        ];
    }
    wp_send_json_success( [
        'plugins' => $result,
        'active_count' => count( $active ),
        'total'   => count( $labels ),
    ] );
} );

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: Geocodificar una localización (Nominatim/OpenStreetMap)
══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ckg_geocode', function () {
    ckg_check_ajax();
    $place = sanitize_text_field( $_POST['place'] ?? '' );
    if ( empty( $place ) ) wp_send_json_error( [ 'message' => 'Nombre de lugar vacío.' ] );
    $result = CKG_ExifWriter::geocode( $place );
    if ( ! $result ) wp_send_json_error( [ 'message' => 'No se encontraron coordenadas para "' . $place . '".' ] );
    wp_send_json_success( $result );
} );

/* ══════════════════════════════════════════════════════════════════════════
   CHROMADB — Event hooks + AJAX handlers
══════════════════════════════════════════════════════════════════════════ */

// Indexar producto en ChromaDB (evento programado, no bloquea)
add_action( 'ckg_index_product_chroma', function ( int $product_id, array $data ) {
    if ( class_exists( 'CKG_ChromaDB' ) ) {
        CKG_ChromaDB::index_product( $product_id, $data );
    }
}, 10, 2 );

// Eliminar de ChromaDB cuando se borra un producto WooCommerce
add_action( 'before_delete_post', function ( int $post_id ) {
    if ( get_post_type( $post_id ) === 'product' && class_exists( 'CKG_ChromaDB' ) ) {
        CKG_ChromaDB::delete_product( $post_id );
    }
} );

/* ── AJAX: Ping / stats de ChromaDB ────────────────────────────────────── */
add_action( 'wp_ajax_ckg_chroma_ping', function () {
    ckg_check_ajax();
    // Resetear caché para forzar re-detección con el endpoint actual
    CKG_ChromaDB::reset_version_cache();
    $ok      = CKG_ChromaDB::ping();
    $api_ver = CKG_ChromaDB::detect_version();
    $ver     = $ok ? CKG_ChromaDB::version() : '';
    $stats   = $ok ? CKG_ChromaDB::get_stats() : [];
    wp_send_json_success( [
        'ok'          => $ok,
        'version'     => $ver,
        'api_version' => $api_ver,
        'stats'       => $stats,
    ] );
} );

/* ── AJAX: Buscar productos similares (detección de duplicados) ─────────── */
add_action( 'wp_ajax_ckg_chroma_similar', function () {
    ckg_check_ajax();
    $query = sanitize_text_field( $_POST['query'] ?? '' );
    if ( empty( $query ) ) wp_send_json_error( [ 'message' => 'Query vacía.' ] );

    $results = class_exists( 'CKG_ChromaDB' )
        ? CKG_ChromaDB::find_similar_products( $query, 5, 0.80 )
        : [];

    wp_send_json_success( [ 'results' => $results, 'count' => count( $results ) ] );
} );

/* ── AJAX: Sugerir FAQ similares del catálogo ───────────────────────────── */
add_action( 'wp_ajax_ckg_chroma_suggest_faq', function () {
    ckg_check_ajax();
    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $brand        = sanitize_text_field( $_POST['brand']        ?? '' );
    $category     = sanitize_text_field( $_POST['category']     ?? '' );

    if ( empty( $product_name ) ) wp_send_json_error( [ 'message' => 'Nombre del producto requerido.' ] );

    $text    = trim( "$product_name $brand" );
    $results = class_exists( 'CKG_ChromaDB' )
        ? CKG_ChromaDB::suggest_faq( $text, $category, 8 )
        : [];

    wp_send_json_success( [ 'faq' => $results, 'count' => count( $results ) ] );
} );

/* ── AJAX: Conocimiento de marca ────────────────────────────────────────── */
add_action( 'wp_ajax_ckg_chroma_brand', function () {
    ckg_check_ajax();
    $brand = sanitize_text_field( $_POST['brand'] ?? '' );
    if ( empty( $brand ) ) wp_send_json_error( [ 'message' => 'Marca requerida.' ] );

    $result = class_exists( 'CKG_ChromaDB' )
        ? CKG_ChromaDB::get_brand_knowledge( $brand )
        : [];

    wp_send_json_success( $result );
} );

/* ── AJAX: Detectar canibalización de keywords ──────────────────────────── */
add_action( 'wp_ajax_ckg_chroma_cannibalization', function () {
    ckg_check_ajax();
    $keyword    = sanitize_text_field( $_POST['keyword']    ?? '' );
    $product_id = absint( $_POST['product_id'] ?? 0 );

    if ( empty( $keyword ) ) wp_send_json_error( [ 'message' => 'Keyword requerida.' ] );

    $results = class_exists( 'CKG_ChromaDB' )
        ? CKG_ChromaDB::check_keyword_cannibalization( $keyword, $product_id )
        : [];

    wp_send_json_success( [ 'conflicts' => $results, 'count' => count( $results ) ] );
} );

/* ── AJAX: Indexación masiva del catálogo existente ─────────────────────── */
add_action( 'wp_ajax_ckg_chroma_bulk_index', function () {
    ckg_check_ajax();
    set_time_limit( 300 );
    $offset = absint( $_POST['offset'] ?? 0 );
    $batch  = absint( $_POST['batch']  ?? 10 );

    $result = class_exists( 'CKG_ChromaDB' )
        ? CKG_ChromaDB::bulk_index( $offset, $batch )
        : [ 'error' => 'ChromaDB no disponible.' ];

    if ( isset( $result['error'] ) ) {
        wp_send_json_error( [ 'message' => $result['error'] ] );
    }
    wp_send_json_success( $result );
} );

/* ======================================================================
   AJAX: Analizar URLs de competidores + sintetizar con Ollama
====================================================================== */
add_action( 'wp_ajax_ckg_analyze_competitors', function () {
    ckg_check_ajax();
    set_time_limit( 180 );

    $urls_raw     = sanitize_textarea_field( $_POST['urls']         ?? '' );
    $product_name = sanitize_text_field(     $_POST['product_name'] ?? '' );
    $section      = sanitize_key(            $_POST['section']      ?? 'all' );

    // Normalizar saltos de linea (Windows usa \r\n)
    $urls_raw = str_replace( "\r", '', $urls_raw );
    $urls = array_filter( array_map( 'trim', explode( "\n", $urls_raw ) ) );
    $urls = array_filter( $urls, fn( $u ) => filter_var( $u, FILTER_VALIDATE_URL ) );

    if ( empty( $urls ) ) {
        wp_send_json_error( [ 'message' => 'Introduce al menos una URL valida (una por linea).' ] );
    }

    $result = CKG_Competitor_Scraper::analyze( array_values( $urls ), $product_name, $section );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( $result );
} );

/* ======================================================================
   AJAX: Diagnostico de campos sociales de un producto existente
   Escanea el post_meta de un producto y devuelve todos los campos
   que pertenecen a plugins de redes sociales / feeds
====================================================================== */
add_action( 'wp_ajax_ckg_scan_social_meta', function () {
    ckg_check_ajax();

    $product_id = absint( $_POST['product_id'] ?? 0 );

    // Si no viene product_id, usar el ultimo producto publicado
    if ( ! $product_id ) {
        $products = wc_get_products( [
            'status'  => [ 'publish', 'draft' ],
            'limit'   => 1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
        ] );
        $product_id = $products[0] ?? 0;
    }

    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => 'No se encontro ningun producto.' ] );
    }

    $all_meta = get_post_meta( $product_id );

    // Prefijos de plugins sociales que nos interesan
    $prefixes = [
        '_wc_facebook'    => 'Facebook for WooCommerce',
        '_wc_gla'         => 'Google Listings & Ads',
        'gla_'            => 'Google Listings & Ads (alt)',
        '_wc_pinterest'   => 'Pinterest for WooCommerce',
        '_woosea'         => 'AdTribes / WooFeed',
        '_ctx_feed'       => 'CTX Feed',
        '_yith_gf'        => 'YITH Google Feed',
        'wpfm_'           => 'WP Product Feed Manager',
        '_pixelyoursite'  => 'PixelYourSite',
        '_yoast_wpseo_op' => 'Yoast OG',
        '_yoast_wpseo_tw' => 'Yoast Twitter',
        '_aioseo_og'      => 'AIOSEO',
        '_seopress_soci'  => 'SEOPress Social',
        'rank_math'       => 'RankMath',
        '_og_'            => 'Open Graph manual',
    ];

    $found    = [];
    $empty    = [];
    $our_keys = []; // campos que ya rellena CKG_SocialMeta

    foreach ( $all_meta as $key => $values ) {
        $value = $values[0] ?? '';
        foreach ( $prefixes as $prefix => $plugin ) {
            if ( str_starts_with( $key, $prefix ) ) {
                $entry = [
                    'key'    => $key,
                    'plugin' => $plugin,
                    'value'  => strlen( $value ) > 80 ? substr( $value, 0, 80 ) . '...' : $value,
                    'filled' => ! empty( $value ),
                ];
                if ( empty( $value ) ) {
                    $empty[] = $entry;
                } else {
                    $found[] = $entry;
                }
                break;
            }
        }
    }

    // Comprobar que plugins estan activos
    $plugins_active = CKG_SocialMeta::detect_active_plugins();

    wp_send_json_success( [
        'product_id'     => $product_id,
        'product_name'   => get_the_title( $product_id ),
        'filled_fields'  => $found,
        'empty_fields'   => $empty,
        'plugins_active' => $plugins_active,
        'total_meta'     => count( $all_meta ),
    ] );
} );

/* ======================================================================
   AJAX: Buscar campos exactos de Facebook/Google/Pinterest en la BD
   Muestra TODOS los post_meta que contengan palabras clave relevantes
====================================================================== */
add_action( 'wp_ajax_ckg_find_social_fields', function () {
    ckg_check_ajax();
    global $wpdb;

    // Obtener ultimo producto con el plugin
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        $ids = wc_get_products( [ 'status' => ['publish','draft'], 'limit' => 1,
            'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ] );
        $product_id = $ids[0] ?? 0;
    }
    if ( ! $product_id ) wp_send_json_error( [ 'message' => 'Sin productos.' ] );

    // Obtener TODOS los meta del producto sin filtrar
    $all_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d
         AND (
             meta_key LIKE '%%facebook%%'
             OR meta_key LIKE '%%gla%%'
             OR meta_key LIKE '%%pinterest%%'
             OR meta_key LIKE '%%_wc_gtin%%'
             OR meta_key LIKE '%%_wc_mpn%%'
             OR meta_key LIKE '%%_wc_brand%%'
             OR meta_key LIKE '%%fb_%%'
             OR meta_key LIKE '%%google%%'
         )
         ORDER BY meta_key ASC",
        $product_id
    ), ARRAY_A );

    wp_send_json_success( [
        'product_id'   => $product_id,
        'product_name' => get_the_title( $product_id ),
        'fields'       => $all_meta,
        'count'        => count( $all_meta ),
    ] );
} );

/* ======================================================================
   BATCH ENRICHER — Cola de enriquecimiento de productos existentes
====================================================================== */
add_action( 'wp_ajax_ckg_enricher_analyze', function () {
    ckg_check_ajax();
    $mode    = sanitize_text_field( $_POST['mode'] ?? 'normal' );
    $filters = [
        'only_basic'  => ! empty( $_POST['only_basic'] ),
        'category_id' => sanitize_text_field( $_POST['category_id'] ?? '' ),
        'limit'       => absint( $_POST['limit'] ?? 50 ),
        'max_score'   => absint( $_POST['max_score'] ?? 100 ),
    ];
    if ( $mode === 'stale' ) {
        $ids = CKG_Batch_Enricher::get_stale_products(
            absint( $_POST['min_days'] ?? 30 ),
            absint( $_POST['max_score'] ?? 70 ),
            $filters['limit']
        );
    } else {
        $ids = CKG_Batch_Enricher::get_pending_products( $filters );
    }
    wp_send_json_success( [
        'count' => count( $ids ),
        'ids'   => $ids,
        'stats' => CKG_Batch_Enricher::get_enrichment_stats(),
        'mode'  => $mode,
    ] );
} );

add_action( 'wp_ajax_ckg_enricher_start', function () {
    ckg_check_ajax();
    $ids = array_map( 'absint', (array) json_decode( stripslashes( $_POST['ids'] ?? '[]' ), true ) );
    $ids = array_filter( $ids ); // eliminar ceros
    if ( empty( $ids ) ) wp_send_json_error( [ 'message' => 'Sin productos seleccionados.' ] );

    // Limpiar cola anterior para evitar acumulacion de runs rotos
    CKG_Batch_Enricher::clear_queue();

    $added = CKG_Batch_Enricher::add_to_queue( array_values( $ids ) );
    wp_send_json_success( [ 'added' => $added, 'status' => CKG_Batch_Enricher::get_status() ] );
} );

add_action( 'wp_ajax_ckg_enricher_process', function () {
    ckg_check_ajax();
    set_time_limit( 180 );
    if ( function_exists( 'ini_set' ) ) @ini_set( 'memory_limit', '256M' );

    $product_id = absint( $_POST['product_id'] ?? 0 );
    $prompt_id  = absint( $_POST['prompt_id']  ?? 0 );

    try {
        if ( $product_id ) {
            // Modo individual: enriquecer producto especifico
            // Limpiar el flag para forzar re-enriquecimiento
            delete_post_meta( $product_id, '_ckg_generated' );

            // Añadir a la cola y procesar inmediatamente
            CKG_Batch_Enricher::add_to_queue( [ $product_id ] );
            $result = CKG_Batch_Enricher::enrich_product( $product_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message(), 'done' => true ] );
            }

            wp_send_json_success( array_merge(
                is_array( $result ) ? $result : [],
                [ 'done' => true, 'product_id' => $product_id, 'last_result' => [
                    'product_name' => get_the_title( $product_id ),
                    'product_id'   => $product_id,
                ] ]
            ) );
        } else {
            // Modo batch: procesar siguiente en cola
            $result = CKG_Batch_Enricher::process_next();
            wp_send_json_success( $result );
        }
    } catch ( \Throwable $e ) {
        error_log( 'CKG enricher_process: ' . $e->getMessage() );
        wp_send_json_error( [ 'message' => $e->getMessage(), 'done' => false ] );
    }
} );

add_action( 'wp_ajax_ckg_enricher_status', function () {
    ckg_check_ajax();
    wp_send_json_success( [
        'status' => CKG_Batch_Enricher::get_status(),
        'stats'  => CKG_Batch_Enricher::get_enrichment_stats(),
    ] );
} );

add_action( 'wp_ajax_ckg_enricher_clear', function () {
    ckg_check_ajax();
    CKG_Batch_Enricher::clear_queue();
    wp_send_json_success( [ 'message' => 'Cola limpiada.' ] );
} );

/* ======================================================================
   ATTRIBUTE MIGRATOR — Limpieza de atributos basura de WooCommerce
====================================================================== */
add_action( 'wp_ajax_ckg_attr_analyze', function () {
    ckg_check_ajax();
    $analysis = CKG_Attribute_Migrator::analyze();
    wp_send_json_success( $analysis );
} );

add_action( 'wp_ajax_ckg_attr_backup', function () {
    ckg_check_ajax();
    $ok = CKG_Attribute_Migrator::backup();
    wp_send_json_success( [ 'success' => $ok, 'date' => CKG_Attribute_Migrator::get_backup_date() ] );
} );

add_action( 'wp_ajax_ckg_attr_migrate_one', function () {
    ckg_check_ajax();
    $id      = absint( $_POST['attribute_id'] ?? 0 );
    $dry_run = ! empty( $_POST['dry_run'] );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'ID de atributo requerido.' ] );
    $result = CKG_Attribute_Migrator::migrate_attribute( $id, $dry_run );
    is_array( $result ) && $result['success']
        ? wp_send_json_success( $result )
        : wp_send_json_error( $result );
} );

/* ======================================================================
   CHECKOUT FIELDS — Configurar campo de color/config en producto
====================================================================== */
add_action( 'wp_ajax_ckg_set_checkout_field', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    $label      = sanitize_text_field( $_POST['label']   ?? '' );
    $options    = sanitize_text_field( $_POST['options']  ?? '' );
    $required   = ! empty( $_POST['required'] );
    if ( ! $product_id || ! $label ) wp_send_json_error( [ 'message' => 'Producto y etiqueta requeridos.' ] );
    CKG_Checkout_Fields::set_product_field( $product_id, $label, $options, $required );
    wp_send_json_success( [ 'message' => 'Campo configurado correctamente.' ] );
} );

add_action( 'wp_ajax_ckg_remove_checkout_field', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) wp_send_json_error( [ 'message' => 'Producto requerido.' ] );
    CKG_Checkout_Fields::remove_product_field( $product_id );
    wp_send_json_success( [ 'message' => 'Campo eliminado.' ] );
} );

/* ======================================================================
   AJAX: Cola de enriquecimiento batch
====================================================================== */
/* ckg_enrich_build_queue - eliminado (obsoleto) */

/* ckg_enrich_process_next - eliminado (obsoleto) */

/* ckg_enrich_status - eliminado (obsoleto) */

/* ckg_enrich_clear - eliminado (obsoleto) */

/* ======================================================================
   AJAX: Mantenimiento de atributos WooCommerce
====================================================================== */
/* ckg_scan_attributes - eliminado (obsoleto) */

add_action( 'wp_ajax_ckg_migrate_attribute', function () {
    ckg_check_ajax();
    $attr_id = absint( $_POST['attr_id'] ?? 0 );
    if ( ! $attr_id ) wp_send_json_error( [ 'message' => 'ID de atributo requerido.' ] );
    wp_send_json_success( CKG_Maintenance::migrate_attribute_to_spec( $attr_id ) );
} );

add_action( 'wp_ajax_ckg_delete_attribute', function () {
    ckg_check_ajax();
    $attr_id = absint( $_POST['attr_id'] ?? 0 );
    if ( ! $attr_id ) wp_send_json_error( [ 'message' => 'ID requerido.' ] );
    $result = CKG_Maintenance::delete_attribute( $attr_id );
    if ( is_wp_error($result) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    wp_send_json_success( [ 'message' => 'Atributo eliminado.' ] );
} );

/* ======================================================================
   AJAX: Revision de homologaciones
====================================================================== */
add_action( 'wp_ajax_ckg_review_homos', function () {
    ckg_check_ajax();
    set_time_limit( 300 );
    $limit  = absint( $_POST['limit'] ?? 10 );
    $result = CKG_Maintenance::review_homologaciones( $limit );
    wp_send_json_success( $result );
} );

/* ckg_get_homo_alerts - eliminado (obsoleto) */

/* ======================================================================
   AJAX: Campo personalizado de checkout por producto
====================================================================== */
add_action( 'wp_ajax_ckg_save_checkout_field', function () {
    ckg_check_ajax();
    $product_id = absint(              $_POST['product_id']       ?? 0 );
    $label      = sanitize_text_field( $_POST['field_label']      ?? '' );
    $hint       = sanitize_text_field( $_POST['field_hint']       ?? '' );
    $required   = ! empty(             $_POST['field_required'] );
    if ( ! $product_id ) wp_send_json_error( [ 'message' => 'ID de producto requerido.' ] );
    update_post_meta( $product_id, '_ckg_custom_field_label',    $label );
    update_post_meta( $product_id, '_ckg_custom_field_hint',     $hint );
    update_post_meta( $product_id, '_ckg_custom_field_required', $required ? '1' : '' );
    wp_send_json_success( [ 'message' => $label ? "Campo '$label' configurado." : 'Campo desactivado.' ] );
} );

/* ======================================================================
   PAGINA DE MANTENIMIENTO
====================================================================== */
function ckg_maintenance_page(): void { ?>
<div class="wrap ckg-wrap">
    <h1>Mantenimiento -- CMSKart Product Generator</h1>

    <!-- BUSCADOR DE PRODUCTO INDIVIDUAL -->
    <div class="ckg-card" style="margin-bottom:20px;border-left:4px solid #2271b1">
        <h2 style="margin-top:0">🔍 Enriquecer producto específico</h2>
        <p style="color:#50575e">Busca por nombre o SKU y enriquece uno o varios productos directamente.</p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label style="display:block;margin-bottom:4px;font-weight:600">Buscar producto</label>
                <input type="text" id="enrich-search-query"
                    placeholder="Nombre o SKU del producto..."
                    style="width:300px" class="regular-text">
            </div>
            <button type="button" class="button" id="btn-enrich-search">Buscar</button>
        </div>

        <div id="enrich-search-results" style="margin-top:12px;display:none">
            <table class="wp-list-table widefat striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="enrich-search-check-all"></th>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Score SEO</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody id="enrich-search-tbody"></tbody>
            </table>
            <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
                <select id="enrich-search-prompt-id" style="min-width:200px">
                    <?php
                    $available_prompts = class_exists('CKG_Prompt_Manager')
                        ? CKG_Prompt_Manager::get_all() : [];
                    foreach ( $available_prompts as $p ) : ?>
                    <option value="<?php echo (int)$p['id']; ?>">
                        <?php echo esc_html( $p['nombre'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary" id="btn-enrich-selected"
                    style="display:none">
                    ✨ Enriquecer seleccionados
                </button>
                <span id="enrich-search-count" style="color:#50575e;font-size:13px"></span>
            </div>
            <div id="enrich-search-progress" style="margin-top:10px;display:none">
                <div class="ckg-prog-bar" style="margin-bottom:4px">
                    <div class="ckg-prog-fill" id="enrich-search-fill" style="width:0%"></div>
                </div>
                <span id="enrich-search-label" style="font-size:12px;color:#50575e"></span>
            </div>
        </div>
    </div>

    <!-- COLA DE ENRIQUECIMIENTO BATCH -->
    <div class="ckg-card" style="margin-bottom:20px">
        <h2>Enriquecimiento batch de productos existentes</h2>
        <p>Genera con Ollama descripciones, FAQ y SEO para los productos basicos sin contenido.</p>

        <table class="form-table" style="max-width:700px">
            <tr>
                <th>Modo</th>
                <td>
                    <select id="enrich-mode" style="margin-right:8px">
                        <option value="normal">Normal — productos pendientes</option>
                        <option value="stale">Revision — ya enriquecidos (re-procesar)</option>
                    </select>
                    <span id="enrich-stale-opts" style="display:none">
                        | Re-enriquecer si llevan mas de
                        <select id="enrich-min-days" class="small-text">
                            <option value="15">15 dias</option>
                            <option value="30" selected>30 dias</option>
                            <option value="60">60 dias</option>
                        </select>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Prompt a usar</th>
                <td>
                    <select id="enrich-prompt-id">
                        <?php
                        $available_prompts = class_exists('CKG_Prompt_Manager')
                            ? CKG_Prompt_Manager::get_all()
                            : [];
                        foreach ( $available_prompts as $p ) : ?>
                        <option value="<?php echo (int)$p['id']; ?>"
                                data-modelo="<?php echo esc_attr($p['modelo_recomendado']); ?>"
                                data-scraper="<?php echo $p['usa_scraper']; ?>"
                                data-chroma="<?php echo $p['usa_chroma']; ?>"
                                data-qdrant="<?php echo $p['usa_qdrant'] ?? 0; ?>">
                            <?php echo esc_html( $p['nombre'] ); ?>
                            (<?php echo esc_html( $p['modelo_recomendado'] ); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" id="prompt-enrich-info" style="margin:4px 0 0;color:#2271b1"></p>
                </td>
            </tr>
            <tr>
                <th>Solo sin enriquecer</th>
                <td><label><input type="checkbox" id="enrich-only-basic" checked> Solo los que no fueron creados con el plugin</label></td>
            </tr>
            <tr>
                <th>Score SEO maximo</th>
                <td>
                    <select id="enrich-max-score">
                        <option value="100">Todos (sin filtro)</option>
                        <option value="70">Score menor de 70</option>
                        <option value="60">Score menor de 60</option>
                        <option value="50">Score menor de 50</option>
                        <option value="0">Solo sin score</option>
                    </select>
                    <span class="description"> Solo enriquecer los productos con score bajo</span>
                </td>
            </tr>
            <tr>
                <th>Limite</th>
                <td>
                    <input type="number" id="enrich-limit" value="50" class="small-text" min="1" max="900">
                    productos
                    <p class="description">Con Ollama local, cada producto tarda 30-60 segundos. 50 productos ~ 30 minutos.</p>
                </td>
            </tr>
        </table>

        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;align-items:center">
            <button type="button" class="button button-primary" id="btn-enrich-start">
                Iniciar enriquecimiento
            </button>
            <button type="button" class="button" id="btn-enrich-status">Ver estado</button>
            <button type="button" class="button" id="btn-enrich-stop" style="display:none;border-color:#c0392b;color:#c0392b">
                Detener
            </button>
        </div>

        <div id="enrich-progress-wrap" style="display:none;margin-top:16px">
            <div class="ckg-prog-bar" style="margin-bottom:8px;height:12px">
                <div id="enrich-prog-fill" class="ckg-prog-fill" style="width:0%;background:#c0392b"></div>
            </div>
            <div id="enrich-stats" style="font-size:13px;color:#50575e"></div>
            <div id="enrich-last" style="font-size:12px;color:#888;margin-top:4px"></div>
            <div id="enrich-errors" style="font-size:12px;color:#c0392b;margin-top:4px"></div>
        </div>
    </div>

    <!-- LIMPIEZA DE ATRIBUTOS -->
    <div class="ckg-card" style="margin-bottom:20px">
        <h2>Limpieza de atributos WooCommerce</h2>
        <p>Escanea los atributos globales y separa los que son specs tecnicas de los que son variaciones reales.</p>
        <button type="button" class="button button-primary" id="btn-scan-attrs">
            Escanear atributos
        </button>
        <div id="attr-scan-results" style="margin-top:16px"></div>
    </div>

    <!-- REVISION DE HOMOLOGACIONES -->
    <div class="ckg-card" style="margin-bottom:20px">
        <h2>Revision de homologaciones con Perplexity</h2>
        <?php if ( class_exists('CKG_LLM') && CKG_LLM::perplexity_available() ): ?>
        <p>Verifica con Perplexity si las homologaciones CIK-FIA de tus productos siguen vigentes.</p>
        <?php
        $alerts = CKG_Maintenance::get_homo_alerts();
        if ( ! empty($alerts) ):
        ?>
        <div class="notice notice-warning" style="padding:10px 14px;margin-bottom:12px">
            <strong><?php echo count($alerts); ?> alertas activas</strong> -- homologaciones posiblemente caducadas.
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="button" class="button button-primary" id="btn-review-homos">
                Revisar homologaciones ahora
            </button>
            <input type="number" id="homo-review-limit" value="10" class="small-text" min="1" max="100">
            <span style="font-size:12px;color:#888">productos por revision</span>
        </div>
        <div id="homo-review-results" style="margin-top:12px"></div>
        <?php else: ?>
        <div class="notice notice-warning" style="padding:10px 14px">
            Configura la API de Perplexity en <a href="<?php echo admin_url('admin.php?page=' . CKG_SLUG . '-settings'); ?>">Ajustes</a> para usar esta funcion.
        </div>
        <?php endif; ?>
    </div>

    <!-- CAMPO PERSONALIZADO EN CHECKOUT -->
    <div class="ckg-card">
        <h2>Campo de personalizacion en checkout</h2>
        <p>Configura un campo de texto en la ficha de producto para que el cliente especifique color, grabado u otras opciones sin cambiar el precio.</p>
        <table class="form-table" style="max-width:600px">
            <tr>
                <th>ID del producto</th>
                <td><input type="number" id="checkout-field-product-id" class="small-text" placeholder="ID del producto WC"></td>
            </tr>
            <tr>
                <th>Etiqueta del campo</th>
                <td>
                    <input type="text" id="checkout-field-label" class="regular-text" placeholder="Ej: Color deseado, Grabado, Configuracion...">
                    <p class="description">Dejar vacio para desactivar el campo en ese producto.</p>
                </td>
            </tr>
            <tr>
                <th>Texto de ayuda</th>
                <td><input type="text" id="checkout-field-hint" class="regular-text" placeholder="Ej: Escribe el color que prefieres (rojo, azul, negro...)"></td>
            </tr>
            <tr>
                <th>Obligatorio</th>
                <td><label><input type="checkbox" id="checkout-field-required"> El cliente debe rellenarlo para poder comprar</label></td>
            </tr>
        </table>
        <button type="button" class="button button-primary" id="btn-save-checkout-field" style="margin-top:10px">
            Guardar configuracion
        </button>
        <span id="checkout-field-status" style="font-size:13px;margin-left:10px"></span>
    </div>
</div>
<?php }

/* ======================================================================
   AJAX: Parsear texto de fabricante y extraer especificaciones tecnicas
====================================================================== */
add_action( 'wp_ajax_ckg_parse_specs_text', function () {
    ckg_check_ajax();
    set_time_limit( 60 );

    $raw_text     = sanitize_textarea_field( $_POST['text']         ?? '' );
    $product_name = sanitize_text_field(     $_POST['product_name']  ?? '' );

    if ( empty( $raw_text ) ) {
        wp_send_json_error( [ 'message' => 'El texto esta vacio.' ] );
    }

    $text_clean = mb_substr( $raw_text, 0, 4000 );
    $pname_part = $product_name ? ' del producto "' . $product_name . '"' : '';

    $prompt = 'Eres un experto en karting. Analiza el siguiente texto de ficha tecnica de fabricante'
            . $pname_part . ".

"
            . "TEXTO DEL FABRICANTE:
" . $text_clean . "

"
            . "TAREA: Extrae TODAS las especificaciones tecnicas como JSON array.
"
            . "Formato exacto (solo el JSON, sin texto adicional):
"
            . '[{"key":"Nombre parametro","value":"Valor con unidades"}]' . "
"
            . "Incluye dimensiones, pesos, materiales, potencias, homologaciones, referencias.";

    if ( ! class_exists( 'CKG_LLM' ) ) {
        wp_send_json_error( [ 'message' => 'LLM no disponible. Usa el modo manual.' ] );
    }

    $response = CKG_LLM::improve_raw( $prompt, [ 'product_name' => $product_name ] );

    if ( is_wp_error( $response ) || empty( $response ) ) {
        $err = is_wp_error( $response ) ? $response->get_error_message() : 'Sin respuesta';
        wp_send_json_error( [ 'message' => 'Error LLM: ' . $err . '. Usa el modo manual.' ] );
    }

    $clean   = trim( preg_replace( '/```json|```/', '', $response ) );
    $decoded = @json_decode( $clean, true );

    if ( ! is_array( $decoded ) || empty( $decoded ) ) {
        wp_send_json_error( [ 'message' => 'La IA no devolvio JSON valido. Usa el modo manual.' ] );
    }

    $specs_clean = [];
    foreach ( $decoded as $item ) {
        $key = trim( $item['key']   ?? '' );
        $val = trim( $item['value'] ?? '' );
        if ( $key && $val ) {
            $specs_clean[] = [ 'key' => $key, 'value' => $val ];
        }
    }

    wp_send_json_success( [
        'specs'   => $specs_clean,
        'count'   => count( $specs_clean ),
        'message' => count( $specs_clean ) . ' especificaciones extraidas.',
    ] );
} );

add_action( 'wp_ajax_ckg_load_product_for_edit', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
        wp_send_json_error( [ 'message' => 'Producto no encontrado.' ] );
    }
    if ( ! class_exists( 'CKG_History' ) ) {
        wp_send_json_error( [ 'message' => 'CKG_History no disponible.' ] );
    }
    $data = CKG_History::clone_product( $product_id );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( [ 'message' => $data->get_error_message() ] );
    }
    $data['edit_mode']    = true;
    $data['product_id']   = $product_id;
    $data['product_name'] = get_the_title( $product_id );
    $data['sku']          = get_post_meta( $product_id, '_sku', true );
    wp_send_json_success( $data );
} );

/* ======================================================================
   HOOK: Re-aplicar SocialMeta cuando se guarda un producto del plugin
   Por si las imágenes se importan después del apply() inicial
====================================================================== */
add_action( 'woocommerce_update_product', function ( $product_id ) {
    // Solo productos creados por el plugin
    if ( ! get_post_meta( $product_id, '_ckg_generated', true ) ) return;

    // Solo si los campos de Facebook estan vacios (no sobreescribir si ya están)
    $fb_set = get_post_meta( $product_id, '_wc_facebook_visibility', true );
    if ( $fb_set ) return; // Ya aplicado

    if ( class_exists( 'CKG_SocialMeta' ) ) {
        CKG_SocialMeta::apply( $product_id, [] );
    }
}, 20 );

/* ======================================================================
   AJAX: Restaurar backup de descripcion de un producto
====================================================================== */
add_action( 'wp_ajax_ckg_restore_backup', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) wp_send_json_error( [ 'message' => 'ID invalido.' ] );

    $backup_desc  = get_post_meta( $product_id, '_ckg_backup_desc',  true );
    $backup_short = get_post_meta( $product_id, '_ckg_backup_short', true );
    $backup_date  = get_post_meta( $product_id, '_ckg_backup_date',  true );

    if ( $backup_desc === false && $backup_short === false ) {
        wp_send_json_error( [ 'message' => 'No hay backup disponible para este producto.' ] );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) wp_send_json_error( [ 'message' => 'Producto no encontrado.' ] );

    $product->set_description( $backup_desc );
    $product->set_short_description( $backup_short );
    $product->save();

    // Eliminar el flag _ckg_generated para que pueda reenriquecerse
    delete_post_meta( $product_id, '_ckg_generated' );

    wp_send_json_success( [
        'message'     => 'Backup restaurado correctamente. Fecha del backup: ' . $backup_date,
        'product_id'  => $product_id,
    ] );
} );

/* ======================================================================
   HANDLER: Cargar producto para edicion desde URL ?edit_product=ID
====================================================================== */
add_action( 'admin_footer', function () {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cmskart-product-generator' ) return;
    if ( ! isset( $_GET['edit_product'] ) ) return;
    $pid = absint( $_GET['edit_product'] );
    if ( ! $pid ) return;
    // Pasa el ID al JS para que cargue el producto automáticamente
    echo '<script>window._ckg_edit_product_id = ' . $pid . ';</script>' . "
";
} );

/* ======================================================================
   AJAX: Historial de revisiones de contenido
====================================================================== */
add_action( 'wp_ajax_ckg_get_revisions', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        // Sin producto: devolver las ultimas 50 revisiones globales
        $revisions = CKG_Revisions::get_recent( 50 );
    } else {
        $revisions = CKG_Revisions::get_for_product( $product_id );
    }
    wp_send_json_success( [
        'revisions' => $revisions,
        'stats'     => CKG_Revisions::get_stats(),
    ] );
} );

add_action( 'wp_ajax_ckg_restore_revision', function () {
    ckg_check_ajax();
    $revision_id = absint( $_POST['revision_id'] ?? 0 );
    if ( ! $revision_id ) wp_send_json_error( [ 'message' => 'ID de revision invalido.' ] );

    $ok = CKG_Revisions::restore( $revision_id );
    if ( $ok ) {
        wp_send_json_success( [ 'message' => 'Revision restaurada correctamente.' ] );
    } else {
        wp_send_json_error( [ 'message' => 'No se pudo restaurar la revision.' ] );
    }
} );

/* ======================================================================
   PÁGINA: Historial de Revisiones
====================================================================== */
function ckg_revisions_page(): void {
    $stats = class_exists('CKG_Revisions') ? CKG_Revisions::get_stats() : [];
    $recent = class_exists('CKG_Revisions') ? CKG_Revisions::get_recent(50) : [];
    ?>
    <div class="wrap ckg-wrap">
        <h1>Historial de Revisiones de Contenido</h1>

        <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
            <div class="ckg-card" style="min-width:140px;text-align:center">
                <div style="font-size:32px;font-weight:700;color:#2271b1"><?php echo (int)($stats['total'] ?? 0); ?></div>
                <div style="color:#50575e;font-size:13px">Revisiones totales</div>
            </div>
            <div class="ckg-card" style="min-width:140px;text-align:center">
                <div style="font-size:32px;font-weight:700;color:#2271b1"><?php echo (int)($stats['products'] ?? 0); ?></div>
                <div style="color:#50575e;font-size:13px">Productos con historial</div>
            </div>
            <div class="ckg-card" style="min-width:140px;text-align:center">
                <div style="font-size:32px;font-weight:700;color:#27ae60"><?php echo (int)($stats['today'] ?? 0); ?></div>
                <div style="color:#50575e;font-size:13px">Hoy</div>
            </div>
        </div>

        <div class="ckg-card">
            <h3 style="margin-top:0">Últimas revisiones</h3>
            <?php if ( empty( $recent ) ) : ?>
                <p style="color:#50575e">No hay revisiones todavía. Se crean automáticamente al enriquecer productos.</p>
            <?php else : ?>
            <table class="wp-list-table widefat striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Producto</th>
                        <th>Acción</th>
                        <th>Contenido anterior</th>
                        <th>Contenido nuevo</th>
                        <th>Restaurar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $recent as $rev ) : ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo esc_html( $rev['created_at'] ); ?></td>
                        <td>
                            <a href="<?php echo get_edit_post_link( $rev['product_id'] ); ?>" target="_blank">
                                <?php echo esc_html( $rev['product_name'] ); ?>
                            </a>
                            <br><small style="color:#999">ID <?php echo $rev['product_id']; ?></small>
                        </td>
                        <td>
                            <span style="background:<?php echo $rev['accion'] === 'restauracion' ? '#ffeeba' : '#d4edda'; ?>;padding:2px 8px;border-radius:3px;font-size:11px">
                                <?php echo esc_html( $rev['accion'] ); ?>
                            </span>
                        </td>
                        <td style="max-width:200px">
                            <div style="max-height:60px;overflow:hidden;color:#50575e;font-size:12px">
                                <?php echo esc_html( mb_substr( wp_strip_all_tags( $rev['desc_antes'] ?? '' ), 0, 120 ) ); ?>...
                            </div>
                        </td>
                        <td style="max-width:200px">
                            <div style="max-height:60px;overflow:hidden;font-size:12px">
                                <?php echo esc_html( mb_substr( wp_strip_all_tags( $rev['desc_despues'] ?? '' ), 0, 120 ) ); ?>...
                            </div>
                        </td>
                        <td>
                            <button type="button" class="button button-small ckg-restore-revision"
                                data-id="<?php echo (int)$rev['id']; ?>"
                                data-name="<?php echo esc_attr( $rev['product_name'] ); ?>">
                                Restaurar esta
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    jQuery(function($){
        $(document).on('click', '.ckg-restore-revision', function(){
            var id   = $(this).data('id');
            var name = $(this).data('name');
            if (!confirm('Restaurar version anterior de "' + name + '"?')) return;
            var $btn = $(this).text('Restaurando...').prop('disabled', true);
            $.post(ajaxurl, {
                action: 'ckg_restore_revision',
                nonce:  '<?php echo wp_create_nonce("ckg_admin_nonce"); ?>',
                revision_id: id
            }, function(r){
                if (r.success) {
                    $btn.text('Restaurado').css('color','#27ae60');
                    alert(r.data.message);
                    location.reload();
                } else {
                    $btn.text('Error').prop('disabled', false);
                    alert('Error: ' + r.data.message);
                }
            });
        });
    });
    </script>
    <?php
}

/* ======================================================================
   SCHEMA JSON-LD: Solo FAQPage separado (RankMath gestiona Product schema)
   Inyectar Product duplicado causa errores en Search Console
====================================================================== */
add_action( 'wp_head', function () {
    if ( ! is_product() ) return;

    $product_id = get_the_ID();
    $faq_json   = get_post_meta( $product_id, '_ckg_faq', true );
    if ( ! $faq_json ) return;

    $faq_items = json_decode( $faq_json, true );
    if ( empty( $faq_items ) || ! is_array( $faq_items ) ) return;

    // FAQPage schema separado — valido y compatible con Product de RankMath
    $faq_schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array_map( function ( $item ) {
            return [
                '@type'          => 'Question',
                'name'           => $item['pregunta'] ?? '',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $item['respuesta'] ?? '',
                ],
            ];
        }, $faq_items ),
    ];

    echo '<script type="application/ld+json">'
        . wp_json_encode( $faq_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . '</script>' . "\n";
} );

/* ======================================================================
   AJAX: Scheduler de Web Stories
====================================================================== */
add_action( 'wp_ajax_ckg_story_queue_status', function () {
    ckg_check_ajax();
    wp_send_json_success( [
        'queue'    => class_exists( 'CKG_Scheduler' ) ? CKG_Scheduler::get_queue_count() : 0,
        'next_run' => class_exists( 'CKG_Scheduler' ) ? CKG_Scheduler::get_next_run()    : '-',
        'log'      => class_exists( 'CKG_Scheduler' ) ? array_slice( CKG_Scheduler::get_log(), 0, 10 ) : [],
    ] );
} );

add_action( 'wp_ajax_ckg_story_run_now', function () {
    ckg_check_ajax();
    if ( class_exists( 'CKG_Scheduler' ) ) {
        CKG_Scheduler::run_now();
        wp_send_json_success( [ 'message' => 'Procesado el siguiente producto de la cola.' ] );
    } else {
        wp_send_json_error( [ 'message' => 'Scheduler no disponible.' ] );
    }
} );

/* ======================================================================
   AJAX: Context Scraper — scraping estructurado para Claude API
====================================================================== */

// Ejecutar scraping para un producto
add_action( 'wp_ajax_ckg_context_scrape', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) wp_send_json_error( [ 'message' => 'product_id requerido.' ] );

    $result = CKG_Context_Scraper::scrape( $product_id );
    wp_send_json_success( $result );
} );

// Ver log de scraping
add_action( 'wp_ajax_ckg_scraper_log', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    $log        = CKG_Context_Scraper::get_log( $product_id, 20 );
    wp_send_json_success( [ 'log' => $log ] );
} );

/* ======================================================================
   AJAX + PAGE: Gestor de Prompts
====================================================================== */

// Listar prompts
add_action( 'wp_ajax_ckg_prompts_list', function () {
    ckg_check_ajax();
    wp_send_json_success( [
        'prompts' => CKG_Prompt_Manager::get_all(),
        'stats'   => CKG_Prompt_Manager::get_stats(),
    ] );
} );

// Guardar prompt
add_action( 'wp_ajax_ckg_prompt_save', function () {
    ckg_check_ajax();
    $data   = array_map( 'stripslashes', $_POST );
    $result = CKG_Prompt_Manager::save( $data );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }
    wp_send_json_success( [ 'id' => $result, 'message' => 'Prompt guardado correctamente.' ] );
} );

// Borrar prompt
add_action( 'wp_ajax_ckg_prompt_delete', function () {
    ckg_check_ajax();
    $id = absint( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'ID invalido.' ] );
    $ok = CKG_Prompt_Manager::delete( $id );
    if ( ! $ok ) wp_send_json_error( [ 'message' => 'No se pudo borrar (puede ser predefinido).' ] );
    wp_send_json_success( [ 'message' => 'Prompt eliminado.' ] );
} );

// Renderizar prompt con variables sustituidas (preview)
add_action( 'wp_ajax_ckg_prompt_render', function () {
    ckg_check_ajax();
    $prompt_id  = absint( $_POST['prompt_id']  ?? 0 );
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $prompt_id || ! $product_id ) {
        wp_send_json_error( [ 'message' => 'prompt_id y product_id requeridos.' ] );
    }
    $result = CKG_Prompt_Manager::render( $prompt_id, $product_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }
    wp_send_json_success( $result );
} );

// Ejecutar enriquecimiento con prompt especifico
add_action( 'wp_ajax_ckg_enrich_with_prompt', function () {
    ckg_check_ajax();
    $prompt_id  = absint( $_POST['prompt_id']  ?? 0 );
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $prompt_id || ! $product_id ) {
        wp_send_json_error( [ 'message' => 'prompt_id y product_id requeridos.' ] );
    }

    // Renderizar el prompt con datos reales
    $rendered = CKG_Prompt_Manager::render( $prompt_id, $product_id );
    if ( is_wp_error( $rendered ) ) {
        wp_send_json_error( [ 'message' => $rendered->get_error_message() ] );
    }

    // Llamar al proveedor de IA configurado
    // CKG_LLM_Provider::improve_raw() es el metodo correcto (no generate)
    $response = null;
    if ( class_exists( 'CKG_LLM_Provider' ) ) {
        $ctx = [];
        if ( ! empty( $rendered['system_prompt'] ) ) {
            $ctx['system'] = $rendered['system_prompt'];
        }
        $response = CKG_LLM_Provider::improve_raw( $rendered['user_prompt'], $ctx );
    } elseif ( class_exists( 'CKG_Ollama' ) ) {
        $response = CKG_Ollama::improve_raw( $rendered['user_prompt'] );
    }

    if ( ! $response || is_wp_error( $response ) ) {
        $msg = is_wp_error( $response ) ? $response->get_error_message() : 'Sin respuesta del modelo.';
        wp_send_json_error( [ 'message' => $msg ] );
    }

    wp_send_json_success( [
        'response'      => $response,
        'prompt_nombre' => $rendered['prompt_nombre'],
        'product_id'    => $product_id,
    ] );
} );

// Pagina de Prompts
function ckg_prompts_page(): void {
    $prompts = CKG_Prompt_Manager::get_all( false );
    $stats   = CKG_Prompt_Manager::get_stats();
    $nonce   = wp_create_nonce( 'ckg_admin_nonce' );
    ?>
    <div class="wrap ckg-wrap">
        <h1>✍️ Gestor de Prompts</h1>
        <p style="color:#50575e">Define los prompts que usa el enriquecedor. Puedes crear prompts personalizados para cada modelo o situacion.</p>

        <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
            <div class="ckg-card" style="min-width:120px;text-align:center">
                <div style="font-size:28px;font-weight:700;color:#2271b1"><?php echo $stats['total']; ?></div>
                <div style="font-size:12px;color:#50575e">Total</div>
            </div>
            <div class="ckg-card" style="min-width:120px;text-align:center">
                <div style="font-size:28px;font-weight:700;color:#27ae60"><?php echo $stats['activos']; ?></div>
                <div style="font-size:12px;color:#50575e">Activos</div>
            </div>
            <div style="flex:1;display:flex;align-items:center;justify-content:flex-end">
                <button class="button button-primary" id="btn-nuevo-prompt">+ Nuevo prompt</button>
            </div>
        </div>

        <div class="ckg-card" id="form-prompt" style="display:none;margin-bottom:20px">
            <h3 id="form-prompt-title" style="margin-top:0">Nuevo prompt</h3>
            <input type="hidden" id="prompt-id" value="">
            <table class="form-table" style="max-width:900px">
                <tr>
                    <th>Nombre</th>
                    <td><input type="text" id="prompt-nombre" class="regular-text" placeholder="Ej: Claude — Descripcion corta"></td>
                </tr>
                <tr>
                    <th>Descripcion</th>
                    <td><input type="text" id="prompt-descripcion" class="large-text" placeholder="Para que sirve este prompt"></td>
                </tr>
                <tr>
                    <th>Modelo recomendado</th>
                    <td>
                        <select id="prompt-modelo">
                            <option value="ollama">Ollama (local)</option>
                            <option value="anthropic">Claude (Anthropic)</option>
                            <option value="openai">OpenAI</option>
                            <option value="deepseek">DeepSeek</option>
                            <option value="perplexity">Perplexity</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Opciones</th>
                    <td>
                        <label><input type="checkbox" id="prompt-scraper"> Usar scraper de fabricante/distribuidor</label><br>
                        <label><input type="checkbox" id="prompt-chroma"> Usar ChromaDB (catalogó propio — duplicados y keywords)</label><br>
                        <label><input type="checkbox" id="prompt-qdrant"> Usar Qdrant (PDFs fabricantes, manuales, homologaciones CIK-FIA)</label>
                    </td>
                </tr>
                <tr>
                    <th>System Prompt <small style="font-weight:normal">(opcional)</small></th>
                    <td>
                        <textarea id="prompt-system" rows="8" class="large-text" style="font-family:monospace;font-size:12px" placeholder="System prompt para Claude/OpenAI. Vacio para Ollama."></textarea>
                        <p class="description">Solo se usa con modelos que soportan system prompt (Claude, OpenAI).</p>
                    </td>
                </tr>
                <tr>
                    <th>User Prompt <span style="color:#c0392b">*</span></th>
                    <td>
                        <textarea id="prompt-user" rows="16" class="large-text" style="font-family:monospace;font-size:12px" placeholder="El prompt principal. Usa variables: {nombre} {marca} {sku} {categoria} {precio} {specs} {descripcion_actual} {json_scraper} {keywords} {homologaciones} {faq_existente}"></textarea>
                        <p class="description">Variables disponibles: <code>{nombre} {marca} {sku} {categoria} {precio} {specs} {descripcion_actual} {json_scraper} {keywords} {homologaciones} {faq_existente}</code></p>
                    </td>
                </tr>
                <tr>
                    <th>Estado</th>
                    <td><label><input type="checkbox" id="prompt-activo" checked> Activo</label></td>
                </tr>
            </table>
            <div style="margin-top:12px;display:flex;gap:8px">
                <button class="button button-primary" id="btn-guardar-prompt">Guardar</button>
                <button class="button" id="btn-cancelar-prompt">Cancelar</button>
                <span id="prompt-save-msg" style="line-height:32px;margin-left:8px"></span>
            </div>
        </div>

        <div class="ckg-card">
            <table class="wp-list-table widefat striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Modelo</th>
                        <th>Scraper</th>
                        <th>Chroma</th>
                        <th>Qdrant</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="prompts-tbody">
                <?php foreach ( $prompts as $p ) : ?>
                    <tr data-id="<?php echo $p['id']; ?>">
                        <td>
                            <strong><?php echo esc_html( $p['nombre'] ); ?></strong>
                            <?php if ( $p['es_predefinido'] ) : ?><span style="background:#e8f5e9;color:#27ae60;font-size:10px;padding:1px 6px;border-radius:3px;margin-left:6px">Predefinido</span><?php endif; ?>
                            <br><small style="color:#50575e"><?php echo esc_html( $p['descripcion'] ); ?></small>
                        </td>
                        <td><code><?php echo esc_html( $p['modelo_recomendado'] ); ?></code></td>
                        <td><?php echo $p['usa_scraper'] ? '✅' : '—'; ?></td>
                        <td><?php echo $p['usa_chroma']  ? '✅' : '—'; ?></td>
                        <td><?php echo ! empty($p['usa_qdrant']) ? '✅' : '—'; ?></td>
                        <td><?php echo $p['activo'] ? '<span style="color:#27ae60">Activo</span>' : '<span style="color:#999">Inactivo</span>'; ?></td>
                        <td style="white-space:nowrap">
                            <button class="button button-small btn-editar-prompt" data-id="<?php echo $p['id']; ?>">Editar</button>
                            <?php if ( ! $p['es_predefinido'] ) : ?>
                            <button class="button button-small btn-borrar-prompt" data-id="<?php echo $p['id']; ?>" style="color:#c0392b">Borrar</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
    jQuery(function($){
        var ajax  = '<?php echo admin_url("admin-ajax.php"); ?>';
        var nonce = '<?php echo $nonce; ?>';
        var prompts = <?php echo wp_json_encode( array_column( $prompts, null, 'id' ) ); ?>;

        $('#btn-nuevo-prompt').on('click', function(){
            resetForm();
            $('#form-prompt-title').text('Nuevo prompt');
            $('#form-prompt').slideDown();
        });

        $('#btn-cancelar-prompt').on('click', function(){
            $('#form-prompt').slideUp();
        });

        $(document).on('click', '.btn-editar-prompt', function(){
            var id = $(this).data('id');
            var p  = prompts[id];
            if (!p) return;
            $('#prompt-id').val(p.id);
            $('#prompt-nombre').val(p.nombre);
            $('#prompt-descripcion').val(p.descripcion);
            $('#prompt-modelo').val(p.modelo_recomendado);
            $('#prompt-scraper').prop('checked', p.usa_scraper == 1);
            $('#prompt-chroma').prop('checked',  p.usa_chroma  == 1);
            $('#prompt-qdrant').prop('checked',  p.usa_qdrant  == 1);
            $('#prompt-system').val(p.system_prompt);
            $('#prompt-user').val(p.user_prompt);
            $('#prompt-activo').prop('checked', p.activo == 1);
            $('#form-prompt-title').text('Editar: ' + p.nombre);
            $('#form-prompt').slideDown();
            $('html,body').animate({scrollTop: $('#form-prompt').offset().top - 40}, 300);
        });

        $('#btn-guardar-prompt').on('click', function(){
            var $btn = $(this).text('Guardando...').prop('disabled', true);
            $.post(ajax, {
                action:             'ckg_prompt_save',
                nonce:              nonce,
                id:                 $('#prompt-id').val(),
                nombre:             $('#prompt-nombre').val(),
                descripcion:        $('#prompt-descripcion').val(),
                modelo_recomendado: $('#prompt-modelo').val(),
                usa_scraper:        $('#prompt-scraper').is(':checked') ? 1 : 0,
                usa_chroma:         $('#prompt-chroma').is(':checked')  ? 1 : 0,
                usa_qdrant:         $('#prompt-qdrant').is(':checked')  ? 1 : 0,
                system_prompt:      $('#prompt-system').val(),
                user_prompt:        $('#prompt-user').val(),
                activo:             $('#prompt-activo').is(':checked') ? 1 : 0,
            }, function(r){
                $btn.text('Guardar').prop('disabled', false);
                if (r.success) {
                    $('#prompt-save-msg').text('✅ ' + r.data.message).css('color','#27ae60');
                    setTimeout(function(){ location.reload(); }, 1200);
                } else {
                    $('#prompt-save-msg').text('❌ ' + r.data.message).css('color','#c0392b');
                }
            });
        });

        $(document).on('click', '.btn-borrar-prompt', function(){
            var id   = $(this).data('id');
            var name = prompts[id] ? prompts[id].nombre : 'este prompt';
            if (!confirm('Borrar "' + name + '"?')) return;
            var $btn = $(this).text('Borrando...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_prompt_delete', nonce: nonce, id: id }, function(r){
                if (r.success) {
                    $btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
                } else {
                    $btn.text('Borrar').prop('disabled', false);
                    alert(r.data.message);
                }
            });
        });

        function resetForm(){
            $('#prompt-id').val('');
            $('#prompt-nombre, #prompt-descripcion, #prompt-system, #prompt-user').val('');
            $('#prompt-modelo').val('ollama');
            $('#prompt-scraper, #prompt-chroma, #prompt-qdrant').prop('checked', false);
            $('#prompt-activo').prop('checked', true);
            $('#prompt-save-msg').text('');
        }
    });
    </script>
    <?php
}

/* ======================================================================
   AJAX: Qdrant — base de datos vectorial para conocimiento técnico
====================================================================== */
add_action( 'wp_ajax_ckg_qdrant_ping', function () {
    ckg_check_ajax();
    $ok      = class_exists( 'CKG_Qdrant' ) && CKG_Qdrant::ping();
    $stats   = $ok ? CKG_Qdrant::get_stats() : [];
    $version = '';
    if ( $ok ) {
        $resp = wp_remote_get( CKG_Qdrant::base_url() . '/readyz', [ 'timeout' => 3, 'sslverify' => false ] );
        if ( ! is_wp_error( $resp ) ) {
            $version = wp_remote_retrieve_body( $resp );
        }
    }
    wp_send_json_success( [
        'online'  => $ok,
        'stats'   => $stats,
        'url'     => class_exists( 'CKG_Qdrant' ) ? CKG_Qdrant::base_url() : '',
        'version' => trim( $version ),
    ] );
} );

// Búsqueda semántica en Qdrant desde el admin (para probar)
add_action( 'wp_ajax_ckg_qdrant_search', function () {
    ckg_check_ajax();
    $query   = sanitize_text_field( $_POST['query']       ?? '' );
    $brand   = sanitize_text_field( $_POST['brand']       ?? '' );
    $tipo    = sanitize_text_field( $_POST['tipo_doc']    ?? '' );
    $limit   = absint( $_POST['limit'] ?? 5 );
    if ( ! $query ) wp_send_json_error( [ 'message' => 'query requerido.' ] );

    $results = CKG_Qdrant::search_filtered(
        CKG_Qdrant::knowledge_collection(),
        $query,
        array_filter( [ 'fabricante' => $brand, 'tipo_doc' => $tipo ] ),
        $limit,
        0.5
    );
    wp_send_json_success( [ 'results' => $results, 'count' => count( $results ) ] );
} );

/* ======================================================================
   AJAX: Buscar producto para enriquecimiento individual
====================================================================== */
add_action( 'wp_ajax_ckg_enrich_search_product', function () {
    ckg_check_ajax();
    $q = sanitize_text_field( $_POST['query'] ?? '' );
    if ( strlen( $q ) < 2 ) wp_send_json_error( [ 'message' => 'Escribe al menos 2 caracteres.' ] );

    // Buscar por nombre o SKU
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        's'              => $q,
    ];

    // También buscar por SKU exacto
    $by_sku = wc_get_product_id_by_sku( $q );
    $ids    = [];

    if ( $by_sku ) {
        $ids[] = $by_sku;
    }

    $query = new WP_Query( $args );
    foreach ( $query->posts as $post ) {
        $ids[] = $post->ID;
    }

    $ids = array_unique( array_filter( $ids ) );

    $results = [];
    foreach ( array_slice( $ids, 0, 20 ) as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) continue;
        $results[] = [
            'id'        => $id,
            'name'      => $product->get_name(),
            'sku'       => $product->get_sku(),
            'seo_score' => (int) get_post_meta( $id, '_ckg_seo_score', true ),
            'enriched'  => (bool) get_post_meta( $id, '_ckg_generated', true ),
            'edit_url'  => get_edit_post_link( $id, 'url' ),
            'thumb'     => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: '',
        ];
    }

    wp_send_json_success( [ 'results' => $results, 'count' => count( $results ) ] );
} );

/* ======================================================================
   AJAX: Express Auto-rellenar — Pipeline completo URL → Producto
   Flujo: URL → Scraper local (Playwright) → Qdrant + ChromaDB →
          JSON contrato → Prompt → LLM → HTML → Rellena formulario
====================================================================== */
add_action( 'wp_ajax_ckg_express_autofill', function () {
    ckg_check_ajax();
    set_time_limit( 300 );
    if ( function_exists( 'ini_set' ) ) @ini_set( 'memory_limit', '512M' );

    $url        = esc_url_raw( $_POST['url']        ?? '' );
    $product_id = absint(      $_POST['product_id'] ?? 0  );
    $prompt_id  = absint(      $_POST['prompt_id']  ?? 1  );
    $name_hint  = sanitize_text_field( $_POST['name_hint'] ?? '' );
    $brand_hint = sanitize_text_field( $_POST['brand_hint'] ?? '' );

    if ( ! $url ) {
        wp_send_json_error( [ 'message' => 'URL requerida.' ] );
    }

    $log = [];

    // ── PASO 1: Scraping del HTML via API local (Playwright) ──────────
    $scraped_html = '';
    $s            = get_option( 'ckg_settings', [] );
    $api_local    = rtrim( $s['scraper_api_url'] ?? 'http://localhost:8001', '/' );

    $api_resp = wp_remote_post( $api_local . '/scrape-url', [
        'timeout'   => 30,
        'sslverify' => false,
        'headers'   => [ 'Content-Type' => 'application/json' ],
        'body'      => wp_json_encode( [ 'url' => $url, 'wait_ms' => 3000 ] ),
    ] );

    if ( ! is_wp_error( $api_resp ) && wp_remote_retrieve_response_code( $api_resp ) === 200 ) {
        $api_data    = json_decode( wp_remote_retrieve_body( $api_resp ), true );
        $scraped_html = $api_data['html'] ?? '';
        $scraped_text = $api_data['text'] ?? '';
        $log[] = "✅ Scraper local: " . strlen( $scraped_html ) . " chars HTML obtenidos";
    } else {
        // Fallback: scraping básico con wp_remote_get
        $fallback = wp_remote_get( $url, [
            'timeout'    => 20,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ] );
        if ( ! is_wp_error( $fallback ) ) {
            $scraped_html = wp_remote_retrieve_body( $fallback );
            $scraped_text = wp_strip_all_tags( $scraped_html );
            $log[] = "⚠️ Scraper local no disponible — usando fallback básico";
        } else {
            $log[] = "❌ Error scraping: " . $fallback->get_error_message();
        }
    }

    // ── PASO 2: Extraer datos básicos del HTML ─────────────────────────
    $extracted = ckg_extract_product_data_from_html( $scraped_html, $url );

    // Mezclar con hints del formulario
    if ( $name_hint )  $extracted['name']  = $name_hint;
    if ( $brand_hint ) $extracted['brand'] = $brand_hint;

    $log[] = "🔍 Extraído: " . ( $extracted['name'] ?: 'sin nombre' ) . " | Marca: " . ( $extracted['brand'] ?: 'sin marca' );

    // ── PASO 3: Consultar Qdrant (PDFs fabricante) ──────────────────────
    $qdrant_context = '';
    if ( class_exists( 'CKG_Qdrant' ) && CKG_Qdrant::ping() && $extracted['brand'] ) {
        $qdrant_results = CKG_Qdrant::find_manufacturer_knowledge(
            $extracted['name'], $extracted['brand'], '', 5
        );
        if ( ! empty( $qdrant_results ) ) {
            $qdrant_context = implode( "

", array_column( $qdrant_results, 'text' ) );
            $log[] = "✅ Qdrant: " . count( $qdrant_results ) . " fragmentos de conocimiento del fabricante";
        }
    }

    // ── PASO 4: Construir el prompt con todos los datos ─────────────────
    $prompt = ckg_build_express_prompt( $extracted, $scraped_text, $qdrant_context, $url );

    // System prompt: rol de equipo SEO+copywriter+developer
    $system = 'Eres un equipo especializado: experto SEO tecnico, copywriter tecnico-persuasivo '
            . 'en motorsport y e-commerce B2C/B2B, y desarrollador front-end con dominio de Schema.org. '
            . 'Tu unica tienda es CMSKart.es. PROHIBIDO mencionar competidores. '
            . 'No inventes especificaciones tecnicas. Si un dato no consta, usa [DATO NO DISPONIBLE]. '
            . 'Devuelve SOLO un objeto JSON valido, sin markdown, sin explicaciones.';

    // ── PASO 5: Llamar al LLM ──────────────────────────────────────────
    $llm_response = '';
    if ( class_exists( 'CKG_LLM_Provider' ) ) {
        $llm_response = CKG_LLM_Provider::improve_raw( $prompt, [ 'system' => $system ] );
    } elseif ( class_exists( 'CKG_Ollama' ) ) {
        $llm_response = CKG_Ollama::improve_raw( $system . "

" . $prompt );
    }

    if ( ! $llm_response || is_wp_error( $llm_response ) ) {
        $msg = is_wp_error( $llm_response ) ? $llm_response->get_error_message() : 'Sin respuesta del modelo.';
        wp_send_json_error( [ 'message' => $msg, 'log' => $log ] );
    }

    // ── PASO 6: Parsear respuesta JSON del LLM ─────────────────────────
    $parsed = ckg_parse_express_response( $llm_response );

    if ( empty( $parsed ) ) {
        wp_send_json_error( [
            'message' => 'No se pudo parsear la respuesta del modelo.',
            'raw'     => substr( $llm_response, 0, 500 ),
            'log'     => $log,
        ] );
    }

    $log[] = "✅ LLM generó: " . strlen( $llm_response ) . " chars";

    // ── PASO 7: Enriquecer con datos del scraping ──────────────────────
    if ( empty( $parsed['images'] ) && ! empty( $extracted['images'] ) ) {
        $parsed['images'] = $extracted['images'];
    }
    if ( empty( $parsed['product_name'] ) && ! empty( $extracted['name'] ) ) {
        $parsed['product_name'] = $extracted['name'];
    }
    if ( empty( $parsed['brand'] ) && ! empty( $extracted['brand'] ) ) {
        $parsed['brand'] = $extracted['brand'];
    }
    if ( empty( $parsed['price'] ) && ! empty( $extracted['price'] ) ) {
        $parsed['price'] = $extracted['price'];
    }

    wp_send_json_success( [
        'data' => $parsed,
        'log'  => $log,
        'url'  => $url,
    ] );
} );

/**
 * Extrae datos básicos de producto desde HTML crudo.
 */
function ckg_extract_product_data_from_html( string $html, string $url ): array {
    $data = [
        'name'   => '',
        'brand'  => '',
        'price'  => '',
        'sku'    => '',
        'desc'   => '',
        'images' => [],
        'specs'  => [],
    ];

    if ( empty( $html ) ) return $data;

    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
    libxml_clear_errors();
    $xpath = new DOMXPath( $dom );

    // Nombre: og:title → h1 → title
    foreach ( [
        '//meta[@property="og:title"]/@content',
        '//h1',
        '//title',
    ] as $sel ) {
        $node = $xpath->query( $sel )->item(0);
        if ( $node ) {
            $t = trim( $node->textContent ?? $node->nodeValue ?? '' );
            if ( strlen( $t ) > 3 ) { $data['name'] = $t; break; }
        }
    }

    // Marca: meta brand → itemprop brand
    foreach ( [
        '//meta[@property="product:brand"]/@content',
        '//*[@itemprop="brand"]//*[@itemprop="name"]',
        '//*[@itemprop="brand"]',
    ] as $sel ) {
        $node = $xpath->query( $sel )->item(0);
        if ( $node ) {
            $t = trim( $node->textContent ?? $node->nodeValue ?? '' );
            if ( $t ) { $data['brand'] = $t; break; }
        }
    }

    // Precio
    $price_node = $xpath->query( '//meta[@property="product:price:amount"]/@content' )->item(0);
    if ( $price_node ) $data['price'] = trim( $price_node->nodeValue );

    // Descripcion
    $desc_node = $xpath->query( '//meta[@property="og:description"]/@content' )->item(0);
    if ( $desc_node ) $data['desc'] = trim( $desc_node->nodeValue );

    // Imagenes
    $seen = [];
    foreach ( [
        '//meta[@property="og:image"]/@content',
        '//img[@itemprop="image"]/@src',
    ] as $sel ) {
        foreach ( $xpath->query( $sel ) as $node ) {
            $src = trim( $node->nodeValue );
            if ( $src && ! isset( $seen[$src] ) && ! str_contains( $src, 'placeholder' ) ) {
                $seen[$src] = true;
                $data['images'][] = $src;
            }
        }
    }

    return $data;
}

/**
 * Construye el prompt Express con todos los datos disponibles.
 */
function ckg_build_express_prompt( array $extracted, string $page_text, string $qdrant_context, string $url ): string {

    $prompt  = "Crea una ficha de producto WooCommerce completa y optimizada para CMSKart.es.

";
    $prompt .= "<url_fuente>" . $url . "</url_fuente>

";

    if ( $extracted['name'] ) {
        $prompt .= "<producto>
";
        $prompt .= "Nombre: " . $extracted['name'] . "
";
        if ( $extracted['brand'] ) $prompt .= "Marca: " . $extracted['brand'] . "
";
        if ( $extracted['price'] ) $prompt .= "Precio: " . $extracted['price'] . " EUR
";
        if ( $extracted['desc']  ) $prompt .= "Descripcion original: " . $extracted['desc'] . "
";
        $prompt .= "</producto>

";
    }

    if ( $page_text ) {
        $prompt .= "<contenido_pagina>
" . mb_substr( wp_strip_all_tags( $page_text ), 0, 3000 ) . "
</contenido_pagina>

";
    }

    if ( $qdrant_context ) {
        $prompt .= "<conocimiento_fabricante>
" . mb_substr( $qdrant_context, 0, 2000 ) . "
</conocimiento_fabricante>

";
    }

    $prompt .= "INSTRUCCIONES:
";
    $prompt .= "- Tono: tecnico, persuasivo, Espanol de Espana.
";
    $prompt .= "- PROHIBIDO mencionar competidores (KPS, gt2i, kartshop, mondokart, Francis).
";
    $prompt .= "- Todo atribuido a CMSKart.es.
";
    $prompt .= "- No inventar especificaciones que no aparezcan en los datos.

";
    $prompt .= "Genera el siguiente JSON exacto:
";
    $prompt .= "{
";
    $prompt .= '  "product_name": "nombre completo optimizado del producto",' . "
";
    $prompt .= '  "brand": "marca fabricante",' . "
";
    $prompt .= '  "sku": "referencia SKU si aparece",' . "
";
    $prompt .= '  "short_desc": "descripcion corta max 55 palabras, keyword al inicio, CTA suave",' . "
";
    $prompt .= '  "long_desc": "descripcion larga 600-900 palabras HTML con H2/H3, tabla specs, FAQ integrado",' . "
";
    $prompt .= '  "specs": [{"key": "campo", "value": "valor"}],' . "
";
    $prompt .= '  "faq": [{"pregunta": "...", "respuesta": "..."}],' . "
";
    $prompt .= '  "homologaciones": ["CIK-FIA OK", "CIK-FIA OKJ"],' . "
";
    $prompt .= '  "seo_title": "titulo SEO max 60 chars con keyword al inicio",' . "
";
    $prompt .= '  "seo_description": "meta descripcion 140-155 chars con CTA",' . "
";
    $prompt .= '  "seo_keywords": "keyword principal, secundaria 1, secundaria 2",' . "
";
    $prompt .= '  "categories": ["categoria1", "categoria2"],' . "
";
    $prompt .= '  "tags": ["tag1", "tag2"],' . "
";
    $prompt .= '  "price": "precio si se detecta"' . "
";
    $prompt .= "}
";

    return $prompt;
}

/**
 * Parsea la respuesta JSON del LLM con múltiples estrategias de limpieza.
 */
function ckg_parse_express_response( string $raw ): array {
    // Limpiar markdown
    $clean = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
    $clean = preg_replace( '/```\s*$/m', '', $clean );
    $clean = trim( $clean );

    // Intentar parsear directamente
    $data = json_decode( $clean, true );
    if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) return $data;

    // Buscar el JSON entre llaves
    if ( preg_match( '/\{[\s\S]+\}/s', $clean, $m ) ) {
        $data = json_decode( $m[0], true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) return $data;
    }

    return [];
}
