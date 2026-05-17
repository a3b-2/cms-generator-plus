<?php
/**
 * Admin Page — CMSKart Product Generator v2.0
 * Novedades:
 *  - Botón media picker en todos los campos URL / imagen
 *  - Multi-select para galería de imágenes
 *  - Importar atributos existentes de WooCommerce con sus términos
 *  - Cargar categorías y marcas desde la BD de WooCommerce
 *  - Buscador de productos para añadir como complementos
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Menú ─────────────────────────────────────────────────────────────── */
add_action( 'admin_menu', function () {
    add_menu_page( 'CMSKart Generator', '🏎️ CKG Generator', 'manage_woocommerce', CKG_SLUG, 'ckg_admin_page', 'dashicons-cart', 58 );
    add_submenu_page( CKG_SLUG, 'Nuevo Producto',  'Nuevo Producto',    'manage_woocommerce', CKG_SLUG,                  'ckg_admin_page'   );
    add_submenu_page( CKG_SLUG, 'Modo Express',    '⚡ Modo Express',   'manage_woocommerce', CKG_SLUG . '-express',     'ckg_express_page' );
    add_submenu_page( CKG_SLUG, 'Importar CSV',    '📊 Importar CSV',   'manage_woocommerce', CKG_SLUG . '-import',      'ckg_import_page'  );
    add_submenu_page( CKG_SLUG, 'Historial',       '📋 Historial',      'manage_woocommerce', CKG_SLUG . '-history',       'ckg_history_page'   );
    add_submenu_page( CKG_SLUG, 'Enriquecer',      '🚀 Enriquecer',     'manage_woocommerce', CKG_SLUG . '-enrich',        'ckg_enrich_page'    );
    add_submenu_page( CKG_SLUG, 'Ajustes',         'Ajustes',           'manage_woocommerce', CKG_SLUG . '-settings',      'ckg_settings_page'  );
    add_submenu_page( CKG_SLUG, 'Log',              '📋 Log',            'manage_woocommerce', CKG_SLUG . '-log',           'ckg_log_page'       );
    add_submenu_page( CKG_SLUG, 'Prompts',          '✍️ Prompts',        'manage_woocommerce', CKG_SLUG . '-prompts',       'ckg_prompts_page'   );
    add_submenu_page( CKG_SLUG, 'Revisiones',      '🕐 Revisiones',     'manage_woocommerce', CKG_SLUG . '-revisions',     'ckg_revisions_page' );
} );

/* ── Procesar formulario ──────────────────────────────────────────────── */
add_action( 'admin_post_ckg_create_product', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado.' );
    check_admin_referer( 'ckg_create_product' );
    set_time_limit( 300 );
    if ( function_exists( 'ini_set' ) ) @ini_set( 'memory_limit', '256M' );

    $data       = ckg_parse_form( $_POST );
    $product_id = absint( $_POST['product_id'] ?? 0 );
    $redir      = admin_url( 'admin.php?page=' . CKG_SLUG );
    $is_edit    = $product_id > 0 && get_post_type( $product_id ) === 'product';

    try {
        $result = $is_edit
            ? CKG_Woo_Creator::update( $product_id, $data )
            : CKG_Woo_Creator::create( $data );
    } catch ( \Throwable $e ) {
        error_log( 'CKG create/update product fatal: ' . $e->getMessage() );
        wp_redirect( $redir . '&error=' . urlencode( 'Error: ' . $e->getMessage() ) );
        exit;
    }

    if ( is_wp_error( $result ) ) {
        wp_redirect( $redir . '&error=' . urlencode( $result->get_error_message() ) );
        exit;
    }

    if ( class_exists( 'CKG_History' ) ) {
        $seo_score = class_exists( 'CKG_SEO_Analyzer' ) ? ( CKG_SEO_Analyzer::analyze( $data )['score'] ?? 0 ) : 0;
        CKG_History::record( $result, $data, $seo_score );
    }
    if ( class_exists( 'CKG_SocialMeta' ) ) {
        CKG_SocialMeta::apply( $result, $data );
    }

    $msg = $is_edit ? 'updated=1' : 'success=1';
    wp_redirect( $redir . '&' . $msg . '&product_id=' . $result );
    exit;
} );

/* ── AJAX: Cargar producto existente en el formulario (modo edicion) ─── */
add_action( 'wp_ajax_ckg_load_product_for_edit', function () {
    ckg_check_ajax();
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
        wp_send_json_error( [ 'message' => 'Producto no encontrado.' ] );
    }
    $data = CKG_History::clone_product( $product_id );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( [ 'message' => $data->get_error_message() ] );
    }
    // Indicar que es edicion y no clonar
    $data['edit_mode']  = true;
    $data['product_id'] = $product_id;
    $data['product_name'] = get_the_title( $product_id ); // sin "(copia)"
    $data['sku']          = get_post_meta( $product_id, '_sku', true );
    wp_send_json_success( $data );
} );

/* ── Parser de datos del formulario ───────────────────────────────────── */
function ckg_parse_form( array $p ): array {

    $vars = [];
    foreach ( ( $p['var_label'] ?? [] ) as $i => $label ) {
        if ( empty( $label ) ) continue;
        $vars[] = [
            'label'      => sanitize_text_field( $label ),
            'price'      => floatval( $p['var_price'][$i] ?? 0 ),
            'price_sale' => floatval( $p['var_sale'][$i]  ?? 0 ),
            'sku'        => sanitize_text_field( $p['var_sku'][$i] ?? '' ),
        ];
    }

    $homos = [];
    foreach ( ( $p['homo_tipo'] ?? [] ) as $i => $tipo ) {
        if ( empty( $tipo ) ) continue;
        $homos[] = [
            'tipo'        => sanitize_text_field( $tipo ),
            'codigo'      => sanitize_text_field( $p['homo_codigo'][$i] ?? '' ),
            'descripcion' => sanitize_text_field( $p['homo_desc'][$i]   ?? '' ),
        ];
    }

    $compat = [
        'chasis'     => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $p['compat_chasis'] ?? '' ) ) ) ),
        'categorias' => array_filter( array_map( 'trim', explode( ',',  sanitize_text_field(     $p['compat_cats']   ?? '' ) ) ) ),
        'nota'       => wp_kses_post( $p['compat_nota'] ?? '' ),
    ];

    $caract = [];
    foreach ( ( $p['caract_titulo'] ?? [] ) as $i => $titulo ) {
        if ( empty( $titulo ) ) continue;
        $caract[] = [ 'titulo' => sanitize_text_field( $titulo ), 'texto' => wp_kses_post( $p['caract_texto'][$i] ?? '' ) ];
    }

    $specs = [];
    foreach ( ( $p['spec_key'] ?? [] ) as $i => $key ) {
        if ( empty( $key ) ) continue;
        $specs[] = [ 'key' => sanitize_text_field( $key ), 'value' => sanitize_text_field( $p['spec_value'][$i] ?? '' ) ];
    }

    $cats_uso = [];
    foreach ( ( $p['catuso_titulo'] ?? [] ) as $i => $titulo ) {
        if ( empty( $titulo ) ) continue;
        $cats_uso[] = [ 'titulo' => sanitize_text_field( $titulo ), 'texto' => wp_kses_post( $p['catuso_texto'][$i] ?? '' ) ];
    }

    $faq = [];
    foreach ( ( $p['faq_pregunta'] ?? [] ) as $i => $preg ) {
        if ( empty( $preg ) ) continue;
        $faq[] = [ 'pregunta' => sanitize_text_field( $preg ), 'respuesta' => wp_kses_post( $p['faq_resp'][$i] ?? '' ) ];
    }

    $acc = [];
    foreach ( ( $p['acc_name'] ?? [] ) as $i => $name ) {
        if ( empty( $name ) ) continue;
        $acc[] = [
            'name'        => sanitize_text_field( $name ),
            'description' => sanitize_text_field( $p['acc_desc'][$i]  ?? '' ),
            'price'       => floatval( $p['acc_price'][$i] ?? 0 ),
            'image'       => esc_url_raw( $p['acc_image'][$i] ?? '' ),
        ];
    }

    $imgs  = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $p['images_urls'] ?? '' ) ) ) );
    $cats  = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $p['categories'] ?? '' ) ) ) );
    $tags  = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $p['tags']       ?? '' ) ) ) );

    return [
        'product_name'   => sanitize_text_field( $p['product_name'] ?? '' ),
        'product_slug'   => sanitize_title( $p['product_slug'] ?? '' ),
        'sku'            => sanitize_text_field( $p['sku']   ?? '' ),
        'brand'          => sanitize_text_field( $p['brand'] ?? '' ),
        'draft'          => ! empty( $p['draft'] ),
        'images'         => $imgs,
        'categories'     => $cats,
        'tags'           => $tags,
        'short_desc'     => wp_kses_post( $p['short_desc'] ?? '' ),
        'badge_envio'    => ! empty( $p['badge_envio'] ),
        'badge_garantia' => ! empty( $p['badge_garantia'] ),
        'badge_recogida' => ! empty( $p['badge_recogida'] ),
        'badge_inter'    => ! empty( $p['badge_inter'] ),
        'badge_homol'    => ! empty( $p['badge_homol'] ),
        'homo_badge_text'=> sanitize_text_field( $p['homo_badge_text'] ?? '' ),
        'attribute_name' => sanitize_text_field( $p['attribute_name'] ?? 'Talla' ),
        'base_price'      => (float) str_replace( ',', '.', sanitize_text_field( $p['base_price']  ?? '0' ) ),
        'sale_price'      => (float) str_replace( ',', '.', sanitize_text_field( $p['sale_price']  ?? '0' ) ),
        'cost_price'      => (float) str_replace( ',', '.', sanitize_text_field( $p['cost_price']  ?? '0' ) ),
        'variations'     => $vars,
        'intro'          => wp_kses_post( $p['intro'] ?? '' ),
        'intro_image'    => esc_url_raw( $p['intro_image'] ?? '' ),
        'homologacion'   => $homos,
        'caracteristicas'=> $caract,
        'compatibilidad' => $compat,
        'compat_image'   => esc_url_raw( $p['compat_image'] ?? '' ),
        'specs'          => $specs,
        'specs_image'    => esc_url_raw( $p['specs_image']  ?? '' ),
        'categorias_uso' => $cats_uso,
        'faq'            => $faq,
        'faq_image'      => esc_url_raw( $p['faq_image']    ?? '' ),
        'conclusion'     => wp_kses_post( $p['conclusion']  ?? '' ),
        'catalog_url'    => esc_url_raw( $p['catalog_url']  ?? '' ),
        'catalog_label'  => sanitize_text_field( $p['catalog_label'] ?? '' ),
        'revision_date'  => sanitize_text_field( $p['revision_date'] ?? '' ),
        'accessories'    => $acc,
        'seo_title'              => sanitize_text_field( $p['seo_title']               ?? '' ),
        'seo_description'        => sanitize_text_field( $p['seo_description']         ?? '' ),
        'seo_keywords'           => sanitize_text_field( $p['seo_keywords']            ?? '' ),
        'seo_secondary_keywords' => sanitize_text_field( $p['seo_secondary_keywords']  ?? '' ),
        'video_url'              => esc_url_raw( $p['video_url']                       ?? '' ),
        'video_title'            => sanitize_text_field( $p['video_title']             ?? '' ),
        'video_description'      => sanitize_text_field( $p['video_description']       ?? '' ),
    ];
}

/* ── HTML del panel admin ────────────────────────────────────────────── */
function ckg_admin_page(): void {
    $success    = isset( $_GET['success'] );
    $error      = $_GET['error'] ?? '';
    $product_id = absint( $_GET['product_id'] ?? 0 );
    ?>
    <div class="wrap ckg-wrap">
    <h1>🏎️ CMSKart — Generador de Producto</h1>
    <p class="ckg-subtitle">Genera fichas de producto completas: homologaciones, especificaciones, FAQ, complementos, SEO y contenido enriquecido para CMSKart.es.</p>

    <?php if ( $success && $product_id ) : ?>
    <div class="notice notice-success is-dismissible">
        <p>✅ <strong>Producto creado.</strong>
        <a href="<?php echo get_permalink( $product_id ); ?>" target="_blank">Ver en tienda</a> &nbsp;|&nbsp;
        <a href="<?php echo get_edit_post_link( $product_id ); ?>">Editar en WooCommerce</a></p>
    </div>

    <!-- Web Story panel (post-creation) -->
    <div class="ckg-card ckg-story-panel" id="web-story-panel">
        <div class="ckg-story-panel-inner">
            <div class="ckg-story-icon">📖</div>
            <div class="ckg-story-content">
                <h3>Google Web Story</h3>
                <p>Genera automáticamente una Web Story de 4 slides (imagen, homologaciones, specs y CTA)
                que puede aparecer en <strong>Google Discover</strong>, Google Images y carruseles de búsqueda.</p>
                <?php
                $existing_story = CKG_Web_Story::get_story_id( $product_id );
                if ( $existing_story && get_post_status( $existing_story ) === 'publish' ) : ?>
                    <p class="ckg-ok">✅ Web Story ya generada: <a href="<?php echo get_permalink( $existing_story ); ?>" target="_blank">Ver story</a> | <a href="<?php echo get_edit_post_link( $existing_story ); ?>">Editar</a></p>
                <?php elseif ( CKG_Web_Story::plugin_active() ) : ?>
                    <button type="button" class="button button-primary" id="btn-create-story"
                        data-product-id="<?php echo $product_id; ?>">
                        📖 Generar Web Story ahora
                    </button>
                <?php else : ?>
                    <p class="ckg-err">⚠️ Plugin "Web Stories" no instalado.
                    <a href="<?php echo admin_url( 'plugin-install.php?s=web+stories&tab=search&type=term' ); ?>">Instalarlo ahora</a></p>
                <?php endif; ?>
                <div id="story-creation-status" style="margin-top:8px;font-size:13px;"></div>
            </div>
        </div>
    </div>
    <?php endif; if ( $error ) : ?>
    <div class="notice notice-error is-dismissible"><p>❌ <?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
        <input type="hidden" name="action" value="ckg_create_product">
        <?php wp_nonce_field( 'ckg_create_product' ); ?>

        <!-- TABS ──────────────────────────────────────────────────── -->
        <div class="ckg-tabs">
            <button type="button" class="ckg-tab active" data-target="tab-basic">📦 Básico</button>
            <button type="button" class="ckg-tab" data-target="tab-images">🖼️ Imágenes</button>
            <button type="button" class="ckg-tab" data-target="tab-variations">🔄 Variaciones</button>
            <button type="button" class="ckg-tab" data-target="tab-homol">✅ Homologación</button>
            <button type="button" class="ckg-tab" data-target="tab-compat">🔗 Compatibilidad</button>
            <button type="button" class="ckg-tab" data-target="tab-content">📝 Contenido SEO</button>
            <button type="button" class="ckg-tab" data-target="tab-accessories">🔩 Complementos</button>
            <button type="button" class="ckg-tab" data-target="tab-seo">🔍 SEO</button>
            <button type="button" class="ckg-tab ckg-tab--ai" data-target="tab-ai">🤖 IA / Plantillas</button>
            <button type="button" class="ckg-tab ckg-tab--comp" data-target="tab-competitors">🕵️ Competencia</button>
        </div>

        <!-- Barra de completitud cruzada -->
        <div class="ckg-progress-bar-wrap" id="ckg-completeness-bar">
            <div class="ckg-progress-tabs">
                <div class="ckg-prog-tab" data-tab="tab-basic"       title="Básico">📦 <span class="prog-pct" id="prog-basic">0%</span></div>
                <div class="ckg-prog-tab" data-tab="tab-images"      title="Imágenes">🖼️ <span class="prog-pct" id="prog-images">0%</span></div>
                <div class="ckg-prog-tab" data-tab="tab-variations"  title="Variaciones">🔄 <span class="prog-pct" id="prog-variations">0%</span></div>
                <div class="ckg-prog-tab" data-tab="tab-homol"       title="Homologación">✅ <span class="prog-pct" id="prog-homol">0%</span></div>
                <div class="ckg-prog-tab" data-tab="tab-content"     title="Contenido SEO">📝 <span class="prog-pct" id="prog-content">0%</span></div>
                <div class="ckg-prog-tab" data-tab="tab-seo"         title="SEO">🔍 <span class="prog-pct" id="prog-seo">0%</span></div>
            </div>
            <div class="ckg-prog-overall">
                <div class="ckg-prog-bar"><div class="ckg-prog-fill" id="ckg-prog-fill"></div></div>
                <span id="ckg-prog-label">0% completado</span>
            </div>
        </div>

        <!-- ═══ TAB 1: BÁSICO ══════════════════════════════════════ -->
        <div class="ckg-tab-panel active" id="tab-basic"><div class="ckg-card">
            <h2>Datos básicos</h2>
            <table class="form-table">
                <tr>
                    <th><label for="product_name">Nombre del producto *</label></th>
                    <td><input type="text" name="product_name" id="product_name" class="large-text" required placeholder="Casco Bell RS7K GP — Homologado CIK-FIA"></td>
                </tr>
                <tr>
                    <th><label for="product_slug">Slug URL</label></th>
                    <td><input type="text" name="product_slug" id="product_slug" class="regular-text" placeholder="casco-bell-rs7k-gp-cik-fia">
                    <p class="description">Auto-generado del nombre si se deja vacío.</p></td>
                </tr>
                <tr>
                    <th><label for="sku">SKU / Referencia</label></th>
                    <td><input type="text" name="sku" id="sku" class="regular-text" placeholder="BELL-RS7K-GP"></td>
                </tr>
                <tr>
                    <th><label for="brand">Marca</label></th>
                    <td><input type="text" name="brand" id="brand" class="regular-text" placeholder="Bell, Alpinestars, OTK…">
                    <p class="description">Si ya existe en WooCommerce se reutiliza; si no, se crea.</p></td>
                </tr>
                <tr>
                    <th><label for="categories">Categorías</label></th>
                    <td><input type="text" name="categories" id="categories" class="regular-text" placeholder="Cascos y Accesorios Karting, Equipamiento piloto Karting">
                    <p class="description">Separadas por coma. Se crean si no existen.</p></td>
                </tr>
                <tr>
                    <th><label for="tags">Etiquetas</label></th>
                    <td><input type="text" name="tags" id="tags" class="regular-text" placeholder="casco, homologado, CIK-FIA, Bell"></td>
                </tr>
                <tr>
                    <th>Descripción corta</th>
                    <td><textarea name="short_desc" rows="4" class="large-text" placeholder="El Casco Bell RS7K GP es el casco de karting de mayor gama…"></textarea></td>
                </tr>
                <tr>
                    <th>Badges informativos</th>
                    <td>
                        <label><input type="checkbox" name="badge_envio"    value="1" checked> 🚚 Envío 24-72h</label><br>
                        <label><input type="checkbox" name="badge_garantia" value="1" checked> 🔧 Garantía fabricante</label><br>
                        <label><input type="checkbox" name="badge_recogida" value="1"> 📍 Recogida en karting</label><br>
                        <label><input type="checkbox" name="badge_inter"    value="1" checked> 🌍 Envío internacional</label><br>
                        <label><input type="checkbox" name="badge_homol"    value="1">
                            ✅ Badge homologación:
                        </label>
                        <input type="text" name="homo_badge_text" class="regular-text" style="margin-left:8px" placeholder="CIK-FIA 2023 · Snell SA2025">
                    </td>
                </tr>
                <tr>
                    <th>URL Ficha técnica / PDF</th>
                    <td>
                        <div class="ckg-media-row">
                            <input type="url" name="catalog_url" class="regular-text ckg-url-field" placeholder="https://cmskart.es/fichas/bell-rs7k-gp.pdf">
                            <button type="button" class="button ckg-media-btn" data-type="application/pdf,application/zip,application/msword" data-target-prev="1">📎 Subir / Seleccionar</button>
                        </div>
                        <input type="text" name="catalog_label" class="regular-text" style="margin-top:6px" placeholder="📖 Ver ficha técnica completa">
                    </td>
                </tr>
                <tr>
                    <th>Fecha de revisión</th>
                    <td><input type="text" name="revision_date" class="small-text" placeholder="abril 2026"></td>
                </tr>
                <tr>
                    <th>Estado al crear</th>
                    <td>
                        <label><input type="radio" name="draft" value="1" checked> <strong>Borrador</strong> (recomendado)</label>&nbsp;&nbsp;
                        <label><input type="radio" name="draft" value=""> <strong>Publicar</strong></label>
                    </td>
                </tr>
            </table>
        </div></div>

        <!-- ═══ TAB 2: IMÁGENES ════════════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-images"><div class="ckg-card">
            <h2>Imágenes del producto</h2>
            <p class="description">La <strong>primera URL</strong> = imagen principal. El resto van a la galería. El plugin las importa a tu biblioteca de medios automáticamente.</p>

            <div class="ckg-img-actions">
                <button type="button" class="button button-primary" id="ckg-open-gallery-picker">
                    📷 Seleccionar imágenes desde Biblioteca de Medios
                </button>
                <span class="ckg-img-hint">O pega las URLs manualmente abajo (una por línea)</span>
            </div>

            <!-- Previsualización de imágenes seleccionadas -->
            <div id="ckg-img-preview" class="ckg-img-preview-grid"></div>

            <textarea name="images_urls" id="images_urls" rows="12" class="large-text ckg-mono"
                placeholder="https://cmskart.es/wp-content/uploads/.../imagen.webp
https://cmskart.es/wp-content/uploads/.../imagen_1.webp
..."></textarea>

            <p class="description" style="margin-top:6px">
                <strong>Tip:</strong> Si usas el selector de biblioteca, las URLs se añaden automáticamente arriba.
            </p>
        </div></div>

        <!-- ═══ TAB 3: VARIACIONES ═════════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-variations"><div class="ckg-card">
            <h2>Atributo y variaciones</h2>

            <!-- Importar atributo existente -->
            <div class="ckg-import-box" id="import-attr-box">
                <div class="ckg-import-header">
                    <span>🔍 <strong>Importar atributo existente de WooCommerce</strong></span>
                    <button type="button" class="button" id="btn-load-attrs">Cargar atributos</button>
                </div>
                <div id="attr-selector-wrap" style="display:none">
                    <select id="attr-selector" class="regular-text">
                        <option value="">— Seleccionar atributo —</option>
                    </select>
                    <button type="button" class="button button-primary" id="btn-import-attr">⬇ Importar este atributo</button>
                    <div id="attr-terms-preview" class="ckg-terms-preview"></div>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th>Nombre del atributo</th>
                    <td><input type="text" name="attribute_name" id="attribute_name" class="regular-text" value="Talla" placeholder="Talla, Color, Versión…">
                    <p class="description">Al importar un atributo existente se rellena automáticamente.</p></td>
                </tr>
            </table>

            <!-- Panel de precio — en tab Variaciones -->
            <div class="ckg-price-panel" style="background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;padding:14px 16px;margin-bottom:16px">
                <h3 style="margin-top:0">Precio del producto</h3>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th>Precio base (EUR)</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <input type="number" name="base_price" id="base_price" class="small-text" step="0.01" min="0" placeholder="0.00">
                                <button type="button" class="button button-small" id="btn-propagate-price-simple">Aplicar a todas las variaciones</button>
                                <span id="discount-badge" style="font-size:12px;color:#c0392b;font-weight:700"></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Precio rebajado (EUR)</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="number" name="sale_price" id="sale_price" class="small-text" step="0.01" min="0" placeholder="Sin oferta">
                                <span id="margin-badge" style="font-size:12px;font-weight:700"></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Coste <small style="font-weight:400">(opcional)</small></th>
                        <td>
                            <input type="number" name="cost_price" id="cost_price" class="small-text" step="0.01" min="0" placeholder="Tu precio de compra">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- PANEL DE PRECIO — HTML estatico, sin JS de apertura -->
            <div class="ckg-card" style="background:#f8f9fa;margin-bottom:16px">
                <h3 style="margin-top:0">Precio del producto</h3>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th>Precio base (EUR)</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <input type="number" name="base_price" id="base_price"
                                    class="small-text" step="0.01" min="0" placeholder="0.00">
                                <span style="color:#888;font-size:12px">EUR</span>
                                <button type="button" class="button button-small"
                                    id="btn-propagate-price-simple">
                                    Aplicar a todas las variaciones
                                </button>
                                <span id="discount-badge"
                                    style="font-size:12px;color:#c0392b;font-weight:700"></span>
                            </div>
                            <p class="description">
                                Si las variaciones no tienen precio individual,
                                se usara este como precio de todas.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Precio rebajado (EUR)</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <input type="number" name="sale_price" id="sale_price"
                                    class="small-text" step="0.01" min="0"
                                    placeholder="Dejar vacio si no hay oferta">
                                <span style="color:#888;font-size:12px">EUR</span>
                                <span id="margin-badge"
                                    style="font-size:12px;font-weight:700"></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Coste
                            <small style="font-weight:400;display:block;color:#888">
                                (opcional)
                            </small>
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <input type="number" name="cost_price" id="cost_price"
                                    class="small-text" step="0.01" min="0"
                                    placeholder="Tu precio de compra">
                                <span style="color:#888;font-size:12px">EUR</span>
                                <span id="cost-margin-badge"
                                    style="font-size:12px;font-weight:700"></span>
                            </div>
                            <p class="description">
                                Calcula el margen automaticamente.
                                No se publica en la tienda.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <h3>Variaciones del producto</h3>
            <table class="ckg-repeater" id="var-table">
                <thead><tr>
                    <th>Etiqueta (ej: S, L, XL…)</th>
                    <th>Precio (€)</th>
                    <th>Precio oferta (€)</th>
                    <th>SKU</th>
                    <th></th>
                </tr></thead>
                <tbody id="var-body">
                    <tr class="ckg-row">
                        <td><input type="text"   name="var_label[]" class="regular-text" placeholder="S"></td>
                        <td><input type="number" name="var_price[]" class="small-text" step="0.01" placeholder="299.00"></td>
                        <td><input type="number" name="var_sale[]"  class="small-text" step="0.01" placeholder=""></td>
                        <td><input type="text"   name="var_sku[]"   class="regular-text" placeholder="BELL-RS7K-S"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button" id="add-var">+ Añadir variación</button>
        </div></div>

        <!-- ═══ TAB 4: HOMOLOGACIÓN ════════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-homol"><div class="ckg-card">
            <h2>Homologaciones</h2>
            <p class="description">Badges visuales en la descripción. Ejemplos: CIK-FIA, Snell SA2025, CMR 2016, FIA 8877-2022, ISO 9001…</p>
            <table class="ckg-repeater" id="homo-table">
                <thead><tr>
                    <th>Organismo / Tipo</th>
                    <th>Código / Norma</th>
                    <th>Descripción (opcional)</th>
                    <th></th>
                </tr></thead>
                <tbody id="homo-body">
                    <tr class="ckg-row">
                        <td><input type="text" name="homo_tipo[]"   class="regular-text" placeholder="CIK-FIA"></td>
                        <td><input type="text" name="homo_codigo[]" class="regular-text" placeholder="Homologación 2023"></td>
                        <td><input type="text" name="homo_desc[]"   class="regular-text" placeholder="OK, KZ, Junior…"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                    <tr class="ckg-row">
                        <td><input type="text" name="homo_tipo[]"   class="regular-text" placeholder="Snell"></td>
                        <td><input type="text" name="homo_codigo[]" class="regular-text" placeholder="SA2025"></td>
                        <td><input type="text" name="homo_desc[]"   class="regular-text"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button ckg-add-row" data-body="homo-body" data-tpl="homo">+ Añadir homologación</button>
        </div></div>

        <!-- ═══ TAB 5: COMPATIBILIDAD ══════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-compat"><div class="ckg-card">
            <h2>Compatibilidad</h2>

            <!-- Cargar desde WooCommerce -->
            <div class="ckg-import-box">
                <div class="ckg-import-header">
                    <span>📥 <strong>Cargar datos desde tu WooCommerce</strong></span>
                    <button type="button" class="button" id="btn-load-compat">Cargar categorías y marcas</button>
                </div>
                <div id="compat-wc-panel" style="display:none">
                    <div class="ckg-compat-wc-cols">
                        <div>
                            <strong>Categorías de productos:</strong>
                            <div id="compat-cats-list" class="ckg-checkbox-list"></div>
                            <button type="button" class="button button-small" id="btn-apply-cats">⬇ Añadir seleccionadas a "Categorías de competición"</button>
                        </div>
                        <div>
                            <strong>Marcas disponibles:</strong>
                            <div id="compat-brands-list" class="ckg-checkbox-list"></div>
                            <button type="button" class="button button-small" id="btn-apply-brands">⬇ Añadir seleccionadas a "Chasis compatibles"</button>
                        </div>
                    </div>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th>Chasis compatibles</th>
                    <td>
                        <textarea name="compat_chasis" id="compat_chasis" rows="6" class="large-text ckg-mono"
                            placeholder="OTK Tony Kart&#10;OTK Exprit&#10;Intrepid MS3&#10;Lenzokart LR-X&#10;Kart Republic KR1"></textarea>
                        <p class="description">Un chasis por línea. Puedes añadir desde las marcas de WooCommerce.</p>
                    </td>
                </tr>
                <tr>
                    <th>Categorías de competición</th>
                    <td>
                        <input type="text" name="compat_cats" id="compat_cats" class="large-text"
                            placeholder="KZ, KZ2, OK, OK Junior, Rotax, Mini 60">
                        <p class="description">Separadas por coma. Puedes cargar desde tus categorías WooCommerce.</p>
                    </td>
                </tr>
                <tr>
                    <th>Nota de compatibilidad</th>
                    <td><textarea name="compat_nota" rows="3" class="large-text" placeholder="Para una compatibilidad exacta, consulta con nuestro equipo técnico…"></textarea></td>
                </tr>
                <tr>
                    <th>Imagen sección compatibilidad</th>
                    <td>
                        <div class="ckg-media-row">
                            <input type="url" name="compat_image" class="regular-text ckg-url-field" placeholder="https://…">
                            <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️ Seleccionar imagen</button>
                        </div>
                    </td>
                </tr>
            </table>
        </div></div>

        <!-- ═══ TAB 6: CONTENIDO SEO ═══════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-content"><div class="ckg-card">
            <h2>📝 Descripción enriquecida SEO</h2>

            <h3>1. Introducción</h3>
            <textarea name="intro" rows="5" class="large-text" placeholder="El <strong>Casco Bell RS7K GP</strong> es el casco de karting de mayor gama…"></textarea>
            <div class="ckg-media-row" style="margin-top:6px">
                <input type="url" name="intro_image" class="regular-text ckg-url-field" placeholder="URL imagen introducción (opcional)">
                <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️ Seleccionar</button>
            </div>
            <hr>

            <h3>2. Características técnicas (H3)</h3>
            <table class="ckg-repeater" id="caract-table">
                <thead><tr><th>Título H3</th><th>Texto descriptivo</th><th></th></tr></thead>
                <tbody id="caract-body">
                    <tr class="ckg-row">
                        <td><input type="text" name="caract_titulo[]" class="regular-text" placeholder="Carcasa en Carbono / Kevlar"></td>
                        <td><textarea name="caract_texto[]" rows="3" class="large-text"></textarea></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button ckg-add-row" data-body="caract-body" data-tpl="caract">+ Añadir característica</button>
            <hr>

            <!-- IMPORTAR SPECS DE FABRICANTE -->
            <div class="ckg-card" style="background:#f0f7ff;border:1px solid #b3d4f5;margin-bottom:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer"
                     id="toggle-import-specs">
                    <h4 style="margin:0;color:#1a3a6e">
                        Importar specs desde texto de fabricante
                    </h4>
                    <span id="toggle-import-specs-icon" style="font-size:18px;color:#2271b1">+</span>
                </div>
                <div id="import-specs-panel" style="display:none;margin-top:12px">
                    <p class="description" style="margin-bottom:8px">
                        Pega el texto del catalogo o ficha tecnica del fabricante.
                        La IA extraera las especificaciones y rellenara la tabla automaticamente.
                    </p>
                    <textarea id="specs-import-text" rows="8"
                        style="width:100%;font-size:13px;font-family:monospace;border:1px solid #b3d4f5;border-radius:4px;padding:8px"
                        placeholder="Pega aqui el texto del fabricante...&#10;&#10;Ejemplo:&#10;Motor: IAME X30 125cc&#10;Potencia: 30 CV&#10;Peso: 68 kg&#10;Homologacion: CIK-FIA 2024-2027"></textarea>
                    <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="button button-primary" id="btn-parse-specs-text">
                            Extraer especificaciones con IA
                        </button>
                        <button type="button" class="button" id="btn-parse-specs-manual">
                            Parsear sin IA (linea por linea)
                        </button>
                        <span id="parse-specs-status" style="font-size:12px;color:#50575e"></span>
                    </div>
                </div>
            </div>

            <h3>3. Tabla de especificaciones</h3>
            <table class="ckg-repeater" id="specs-table">
                <thead><tr><th>Parámetro</th><th>Valor</th><th></th></tr></thead>
                <tbody id="specs-body">
                    <tr class="ckg-row">
                        <td><input type="text" name="spec_key[]"   class="regular-text" placeholder="Peso"></td>
                        <td><input type="text" name="spec_value[]" class="regular-text" placeholder="1.680 g (talla L)"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                    <tr class="ckg-row">
                        <td><input type="text" name="spec_key[]"   class="regular-text" placeholder="Material carcasa"></td>
                        <td><input type="text" name="spec_value[]" class="regular-text" placeholder="Carbono / Kevlar"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                    <tr class="ckg-row">
                        <td><input type="text" name="spec_key[]"   class="regular-text" placeholder="Homologación"></td>
                        <td><input type="text" name="spec_value[]" class="regular-text" placeholder="CIK-FIA 2023 · Snell SA2025"></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button ckg-add-row" data-body="specs-body" data-tpl="spec">+ Añadir fila</button>
            <div class="ckg-media-row" style="margin-top:8px">
                <input type="url" name="specs_image" class="regular-text ckg-url-field" placeholder="URL imagen junto a specs (opcional)">
                <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️ Seleccionar</button>
            </div>
            <hr>

            <h3>4. ¿Para qué categoría es?</h3>
            <table class="ckg-repeater" id="catuso-table">
                <thead><tr><th>Categoría (H3)</th><th>Descripción</th><th></th></tr></thead>
                <tbody id="catuso-body">
                    <tr class="ckg-row">
                        <td><input type="text" name="catuso_titulo[]" class="regular-text" placeholder="KZ / KZ2"></td>
                        <td><textarea name="catuso_texto[]" rows="3" class="large-text"></textarea></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button ckg-add-row" data-body="catuso-body" data-tpl="catuso">+ Añadir categoría</button>
            <hr>

            <h3>5. Preguntas frecuentes</h3>
            <p class="description">Genera accordion + schema FAQ JSON-LD automáticamente.</p>
            <table class="ckg-repeater" id="faq-table">
                <thead><tr><th>Pregunta</th><th>Respuesta</th><th></th></tr></thead>
                <tbody id="faq-body">
                    <tr class="ckg-row">
                        <td><input type="text" name="faq_pregunta[]" class="regular-text" placeholder="¿El casco viene con visera?"></td>
                        <td><textarea name="faq_resp[]" rows="3" class="large-text"></textarea></td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                <button type="button" class="button" id="add-faq">+ Añadir pregunta</button>
                <button type="button" class="button" id="btn-generate-faq-answers">
                    🤖 Generar respuestas vacías con IA
                </button>
            </div>
            <div class="ckg-media-row" style="margin-top:8px">
                <input type="url" name="faq_image" class="regular-text ckg-url-field" placeholder="URL imagen sección FAQ (opcional)">
                <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️ Seleccionar</button>
            </div>
            <hr>

            <h3>6. Conclusión (CTA)</h3>
            <textarea name="conclusion" rows="5" class="large-text" placeholder="En resumen..."></textarea>
            <button type="button" class="button ckg-improve-btn" data-field="conclusion" data-section="conclusion" style="margin-top:4px">✨ Mejorar</button>

            <hr>
            <h3>7. 🎬 Video del producto <small style="font-weight:400;color:#888;font-size:12px">Opcional — se incrusta antes de la conclusión</small></h3>
            <table class="form-table" style="margin-top:8px">
                <tr>
                    <th>URL del video</th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center">
                            <input type="url" name="video_url" id="video_url" class="large-text" placeholder="https://www.youtube.com/watch?v=...">
                            <button type="button" class="button ckg-media-btn" data-type="video">📁</button>
                        </div>
                        <p class="description">Compatible: <strong>YouTube</strong>, <strong>Vimeo</strong>, <code>.mp4</code>, <code>.webm</code> o cualquier URL con oEmbed.</p>
                        <div id="video-preview-wrap" style="display:none;margin-top:8px"><div id="video-preview-inner" class="ckg-video-preview-admin"></div></div>
                    </td>
                </tr>
                <tr>
                    <th>Título del video</th>
                    <td><input type="text" name="video_title" class="large-text" placeholder="Bell RS7K GP en acción — CMSKart"></td>
                </tr>
                <tr>
                    <th>Descripción breve</th>
                    <td><input type="text" name="video_description" class="large-text" placeholder="Vídeo de prueba del casco en circuito"></td>
                </tr>
            </table>
        </div></div>

        <!-- ═══ TAB 7: COMPLEMENTOS ════════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-accessories"><div class="ckg-card">
            <h2>🔩 Complementos y accesorios relacionados</h2>
            <p class="description">Aparecen bajo el producto con checkboxes y calculadora de precio total en tiempo real.</p>

            <!-- Buscador de productos WooCommerce -->
            <div class="ckg-import-box">
                <div class="ckg-import-header">
                    <span>🛒 <strong>Añadir desde productos existentes de CMSKart</strong></span>
                    <button type="button" class="button" id="btn-init-product-search">Abrir buscador</button>
                </div>
                <div id="product-search-panel" style="display:none">
                    <div class="ckg-search-controls">
                        <select id="ps-category" class="regular-text">
                            <option value="">Todas las categorías</option>
                        </select>
                        <input type="text" id="ps-query" class="regular-text" placeholder="Buscar por nombre…">
                        <button type="button" class="button button-primary" id="btn-do-search">🔍 Buscar</button>
                        <button type="button" class="button" id="btn-add-selected-products" style="display:none">
                            ⬇ Añadir seleccionados como complementos
                        </button>
                    </div>
                    <div id="ps-results" class="ckg-ps-results"></div>
                </div>
            </div>

            <!-- Tabla de complementos (editable manualmente también) -->
            <h3 style="margin-top:20px">Complementos configurados:</h3>
            <table class="ckg-repeater" id="acc-table">
                <thead><tr>
                    <th>Nombre</th>
                    <th>Descripción corta</th>
                    <th>Precio adicional (€)</th>
                    <th>URL imagen</th>
                    <th></th>
                </tr></thead>
                <tbody id="acc-body">
                    <tr class="ckg-row">
                        <td><input type="text"   name="acc_name[]"  class="regular-text" placeholder="Visera ahumada Bell"></td>
                        <td><input type="text"   name="acc_desc[]"  class="regular-text" placeholder="Para condiciones de mucho sol"></td>
                        <td><input type="number" name="acc_price[]" class="small-text" step="0.01" placeholder="45.00"></td>
                        <td>
                            <div class="ckg-media-row">
                                <input type="url" name="acc_image[]" class="regular-text ckg-url-field" placeholder="https://…">
                                <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️</button>
                            </div>
                        </td>
                        <td><button type="button" class="button ckg-rm">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="button" id="add-acc">+ Añadir complemento manualmente</button>
        </div></div>

        <!-- ═══ TAB 8: SEO ══════════════════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-seo"><div class="ckg-card">
            <h2>SEO — Yoast / RankMath</h2>
            <div class="ckg-seo-analyzer-box">
                <div class="ckg-seo-score-header">
                    <span class="ckg-seo-score-label">Puntuación SEO</span>
                    <div class="ckg-seo-score-circle" id="seo-score-circle">
                        <span id="seo-score-num">—</span>
                    </div>
                    <span class="ckg-seo-grade" id="seo-grade">Sin analizar</span>
                    <button type="button" class="button" id="btn-seo-analyze">🔍 Analizar SEO</button>
                </div>
                <div id="seo-checklist" class="ckg-seo-checklist"></div>
                <div id="seo-summary" class="ckg-seo-summary"></div>
            </div>
            <table class="form-table">
                <tr>
                    <th><label for="seo_title">Title SEO</label></th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:flex-start">
                            <input type="text" name="seo_title" id="seo_title" class="large-text" placeholder="Casco Bell RS7K GP | CIK-FIA + Snell SA2025 | CMSKart">
                            <button type="button" class="button ckg-improve-btn" data-field="seo_title" data-section="seo_title" title="Si está vacío genera desde cero con el nombre del producto">✨</button>
                        </div>
                        <p class="description"><span id="ckg-title-count">0</span>/60 caracteres &nbsp;·&nbsp; <em>Si está vacío, ✨ genera automáticamente desde el nombre del producto</em></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="seo_description">Meta description</label></th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:flex-start">
                            <textarea name="seo_description" id="seo_description" rows="3" class="large-text" placeholder="Casco Bell RS7K GP homologado CIK-FIA y Snell SA2025. Carbono. Envío 24-72h. Compra en CMSKart."></textarea>
                            <button type="button" class="button ckg-improve-btn" data-field="seo_description" data-section="seo_description" title="Si está vacío genera desde cero con el nombre del producto" style="height:auto;align-self:flex-start;margin-top:2px">✨</button>
                        </div>
                        <p class="description"><span id="ckg-desc-count">0</span>/155 caracteres &nbsp;·&nbsp; <em>Si está vacío, ✨ genera automáticamente</em></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="seo_keywords">Focus keyword</label></th>
                    <td><input type="text" name="seo_keywords" id="seo_keywords" class="regular-text" placeholder="casco bell rs7k gp karting"></td>
                </tr>
                <tr>
                    <th><label>Keywords secundarias (LSI)</label></th>
                    <td>
                        <input type="text" name="seo_secondary_keywords" id="seo_secondary_keywords" class="large-text"
                            placeholder="karting competicion, casco karting homologado, bell karting...">
                        <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <button type="button" class="button" id="btn-generate-lsi">
                                🤖 Generar 12 LSI keywords con IA
                            </button>
                            <span id="lsi-status" style="font-size:12px;color:#50575e"></span>
                        </div>
                        <div id="lsi-chips" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px"></div>
                        <p class="description">Keywords relacionadas semánticamente. Mejoran el posicionamiento sin canibalización.</p>
                    </td>
                </tr>
            </table>
        </div></div>


        <!-- ═══ TAB 9: IA / PLANTILLAS ═══════════════════════════════ -->
        <div class="ckg-tab-panel" id="tab-ai"><div class="ckg-card">
            <h2>🤖 Inteligencia Artificial &amp; Plantillas</h2>

            <!-- ── OLLAMA STATUS ────────────────────────────────────── -->
            <div class="ckg-import-box">
                <div class="ckg-import-header">
                    <span>🔗 <strong>Ollama (LLM local)</strong> — mejora tu contenido con IA</span>
                    <button type="button" class="button" id="btn-test-ollama">🔗 Probar conexión</button>
                </div>
                <div id="ollama-status" style="margin-top:8px;font-size:13px;"></div>
                <p class="description" style="margin-top:6px">Configura el endpoint y modelo en <a href="<?php echo admin_url('admin.php?page=' . CKG_SLUG . '-settings'); ?>">Ajustes del plugin</a>.</p>
            </div>

            <!-- ── MEJORAR TODO ──────────────────────────────────────── -->
            <div class="ckg-ai-action-box">
                <h3>Mejorar todos los campos de texto de golpe</h3>
                <p class="description">Ollama mejorará: descripción corta, introducción, conclusión, nota de compatibilidad y meta SEO. Rellena primero el nombre del producto y los campos base.</p>
                <button type="button" class="button button-primary button-large" id="btn-improve-all">
                    🚀 Mejorar TODO con Ollama
                </button>
            </div>

            <!-- ── MEJORAR CAMPOS INDIVIDUALES ──────────────────────── -->
            <div class="ckg-ai-fields">
                <h3>Mejorar campo individual</h3>
                <p class="description">Escribe contenido base en el campo y pulsa ✨ para que Ollama lo mejore en contexto.</p>
                <table class="form-table">
                    <tr>
                        <th>Descripción corta</th>
                        <td>
                            <textarea name="" id="ai_shortdesc_tmp" rows="3" class="large-text" placeholder="Escribe una versión base aquí y pulsa Mejorar…"></textarea>
                            <button type="button" class="button ckg-improve-btn" data-field="ai_shortdesc_tmp" data-section="short_desc" data-target-field="short_desc" style="margin-top:4px">✨ Mejorar con Ollama → aplicar a "Descripción corta"</button>
                        </td>
                    </tr>
                    <tr>
                        <th>Introducción</th>
                        <td>
                            <div style="display:flex;gap:6px;align-items:flex-start">
                                <textarea id="ai_intro_tmp" rows="4" class="large-text" placeholder="Versión base de la introducción…"></textarea>
                            </div>
                            <button type="button" class="button ckg-improve-btn" data-field="ai_intro_tmp" data-section="intro" style="margin-top:4px">✨ Mejorar introducción → aplicar al formulario</button>
                        </td>
                    </tr>
                    <tr>
                        <th>SEO Title</th>
                        <td>
                            <button type="button" class="button ckg-improve-btn" data-field="seo_title" data-section="seo_title">✨ Mejorar SEO Title</button>
                            <p class="description">Mejora directamente el campo SEO Title existente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Meta description</th>
                        <td>
                            <button type="button" class="button ckg-improve-btn" data-field="seo_description" data-section="seo_description">✨ Mejorar Meta description</button>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ── PLANTILLAS PREDEFINIDAS ───────────────────────────── -->
            <hr>
            <h3>📋 Plantillas predefinidas por tipo de producto</h3>
            <p class="description">Rellena automáticamente: atributo de variación, homologaciones, especificaciones técnicas, secciones de contenido y FAQ según el tipo de producto.</p>
            <div class="ckg-template-selector">
                <select id="template-selector" class="regular-text">
                    <option value="">— Seleccionar tipo de producto —</option>
                    <?php foreach ( CKG_Templates::list() as $tpl ) : ?>
                    <option value="<?php echo esc_attr( $tpl['id'] ); ?>">
                        <?php echo esc_html( $tpl['icon'] . ' ' . $tpl['name'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary" id="btn-load-template">⬇ Aplicar plantilla</button>
            </div>
            <p class="description" style="margin-top:8px">
                💡 <strong>Tip:</strong> La plantilla rellena los campos vacíos. También puedes escribir la marca en la pestaña Básico y las homologaciones se sugerirán automáticamente.
            </p>

            <hr>
            <h3>📋 Clonar producto existente</h3>
            <p class="description">Carga todos los datos de un producto WooCommerce existente en el formulario para modificar solo lo necesario.</p>
            <div class="ckg-search-controls" style="margin-bottom:10px">
                <select id="clone-category-filter" class="regular-text">
                    <option value="">Todas las categorías</option>
                </select>
                <input type="text" id="clone-product-search" class="regular-text" placeholder="Buscar producto para clonar…">
                <button type="button" class="button" id="btn-init-clone-search">🔍 Buscar</button>
            </div>
            <div id="clone-search-results" style="margin-bottom:10px"></div>

            <hr>
            <h3>💾 Guardar / Cargar formulario como JSON</h3>
            <p class="description">Guarda el estado actual del formulario para reutilizarlo, compartirlo o retomarlo más tarde.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button type="button" class="button button-primary" id="btn-export-json">📥 Exportar formulario como JSON</button>
                <button type="button" class="button" id="btn-show-import-json">📤 Cargar JSON guardado</button>
            </div>
            <div id="export-json-panel" style="display:none;margin-top:12px">
                <textarea id="export-json-output" rows="8" class="large-text ckg-mono" readonly placeholder="El JSON aparecerá aquí…"></textarea>
                <button type="button" class="button" id="btn-copy-json" style="margin-top:6px">📋 Copiar al portapapeles</button>
            </div>
            <div id="import-json-panel" style="display:none;margin-top:12px">
                <textarea id="import-json-input" rows="8" class="large-text ckg-mono" placeholder="Pega aquí el JSON exportado previamente…"></textarea>
                <button type="button" class="button button-primary" id="btn-import-json" style="margin-top:6px">📤 Cargar en el formulario</button>
            </div>
        </div></div>

        <!-- ═══ TAB 10: COMPETENCIA + IMPORTADOR FABRICANTE ══════════ -->
        <div class="ckg-tab-panel" id="tab-competitors"><div class="ckg-card">
            <h2>🕵️ Análisis de competidores &amp; Importador de fabricante</h2>

            <!-- ── ANÁLISIS COMPETIDORES ─────────────────────────────── -->
            <div class="ckg-import-box">
                <div class="ckg-import-header">
                    <span>🕵️ <strong>Análisis de competidores</strong> — extrae info y sintetiza con Ollama</span>
                </div>
                <p class="description" style="margin-top:6px">
                    Introduce URLs de webs de la competencia (una por línea). El plugin descargará las páginas,
                    extraerá precio, descripción, specs, FAQ y homologaciones, y Ollama sintetizará lo mejor en
                    contenido original para CMSKart.
                </p>
                <textarea id="competitor-urls" rows="5" class="large-text ckg-mono"
                    placeholder="https://www.competidor1.es/casco-bell-rs7k/
https://www.competidor2.com/bell-rs7k-gp/
https://www.competidor3.es/producto/casco-bell-rs7k-gp/"></textarea>

                <div class="ckg-comp-controls">
                    <select id="competitor-section" class="regular-text">
                        <option value="all">Todo (intro + specs + FAQ + conclusión)</option>
                        <option value="intro">Solo introducción</option>
                        <option value="specs">Solo especificaciones</option>
                        <option value="faq">Solo FAQ</option>
                        <option value="conclusion">Solo conclusión</option>
                    </select>
                    <button type="button" class="button button-primary" id="btn-analyze-competitors">
                        🕵️ Analizar competidores
                    </button>
                </div>

                <div id="competitor-results" style="margin-top:16px"></div>
            </div>

            <!-- ── EXTRACTOR DE ATRIBUTOS ─────────────────────────────── -->
            <hr>
            <h3>🏷️ Extraer y crear atributos WooCommerce desde la competencia</h3>
            <p class="description">
                El plugin analiza las tablas de especificaciones y datos estructurados de las páginas de la competencia,
                extrae los atributos de producto (Talla, Material, Color, Homologación…) y los crea directamente
                como <strong>atributos WooCommerce</strong> con sus valores.
            </p>
            <div class="ckg-import-box">
                <div class="ckg-import-header">
                    <span>🏷️ URLs para extracción de atributos (una por línea):</span>
                    <button type="button" class="button button-primary" id="btn-extract-attrs">🔍 Extraer atributos</button>
                </div>
                <textarea id="attr-extract-urls" rows="4" class="large-text ckg-mono"
                    placeholder="https://www.competidor1.es/casco-bell-rs7k/
https://www.competidor2.com/bell-rs7k-gp/"></textarea>
                <div id="attr-extract-results" style="margin-top:12px"></div>
            </div>

            <!-- ── IMPORTADOR IMÁGENES FABRICANTE ────────────────────── -->
            <hr>
            <h3>🏭 Importar imágenes desde URL del fabricante</h3>
            <p class="description">
                Pega la URL de la página del producto en la web del <strong>fabricante</strong> (Bell, Alpinestars, OMP, OTK…).
                El plugin descargará las imágenes del producto, las convertirá a <strong>WebP 600×600</strong>
                con fondo blanco, y las añadirá a tu Biblioteca de Medios con nombre, alt text,
                leyenda y descripción basados en el nombre del producto.
            </p>
            <div class="ckg-media-row" style="margin-bottom:10px">
                <input type="url" id="manufacturer_img_url" class="large-text" placeholder="https://www.bell-helmets.com/products/rs7k-gp">
                <button type="button" class="button button-primary" id="btn-import-manufacturer-images">
                    🏭 Importar imágenes del fabricante
                </button>
            </div>
            <div id="mfr-img-status" style="font-size:13px;margin-bottom:8px"></div>
            <div id="mfr-img-preview" class="ckg-mfr-img-grid-wrap"></div>

            <div class="ckg-helper-box">
                <strong>ℹ️ Cómo funciona:</strong><br>
                1. El plugin analiza la página del fabricante y encuentra las imágenes del producto.<br>
                2. Las descarga y convierte a WebP 600×600px con fondo blanco (sin distorsión).<br>
                3. Las importa a tu biblioteca de medios con nombre: <code><?php
                    $ex = sanitize_title( 'Nombre del producto' );
                    echo esc_html( $ex . '.webp, ' . $ex . '_2.webp…' );
                ?></code><br>
                4. Añade automáticamente alt, leyenda y descripción con el nombre del producto.<br>
                5. Las URLs aparecen en la pestaña <strong>Imágenes</strong> listas para usar.
            </div>
            <!-- Análisis competitivo -->
            <hr>
            <h3>🥊 Análisis SEO competitivo vs Google</h3>
            <p class="description">Compara tu producto con el Top 5-6 resultados de Google para tu keyword. Obtienes puntuación relativa, métricas de competidores y recomendaciones de Ollama.</p>
            <button type="button" class="button button-primary" id="btn-competitive-seo">
                🥊 Analizar vs competidores en Google
            </button>
            <div id="competitive-results" style="margin-top:16px"></div>
        </div></div>

        <!-- SUBMIT ──────────────────────────────────────────────── -->
        <input type="hidden" name="product_id" id="edit_product_id" value="0">
        <div class="ckg-submit-bar">
            <button type="submit" class="button button-primary button-hero">Crear Producto en CMSKart</button>
            <span class="ckg-submit-note">Se creara como borrador.</span>
            <span class="ckg-story-status-inline" id="story-plugin-status"></span>
        </div>
    </form>


    <!-- ── JS TEMPLATES ──────────────────────────────────────────── -->
    <script type="text/html" id="tmpl-var-row">
        <tr class="ckg-row">
            <td><input type="text"   name="var_label[]" class="regular-text" placeholder="Talla / opción"></td>
            <td><input type="number" name="var_price[]" class="small-text" step="0.01"></td>
            <td><input type="number" name="var_sale[]"  class="small-text" step="0.01"></td>
            <td><input type="text"   name="var_sku[]"   class="regular-text" placeholder="REF-XXX"></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-homo-row">
        <tr class="ckg-row">
            <td><input type="text" name="homo_tipo[]"   class="regular-text"></td>
            <td><input type="text" name="homo_codigo[]" class="regular-text"></td>
            <td><input type="text" name="homo_desc[]"   class="regular-text"></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-caract-row">
        <tr class="ckg-row">
            <td><input type="text" name="caract_titulo[]" class="regular-text"></td>
            <td><textarea name="caract_texto[]" rows="3" class="large-text"></textarea></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-spec-row">
        <tr class="ckg-row">
            <td><input type="text" name="spec_key[]"   class="regular-text"></td>
            <td><input type="text" name="spec_value[]" class="regular-text"></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-catuso-row">
        <tr class="ckg-row">
            <td><input type="text" name="catuso_titulo[]" class="regular-text"></td>
            <td><textarea name="catuso_texto[]" rows="3" class="large-text"></textarea></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-faq-row">
        <tr class="ckg-row">
            <td><input type="text" name="faq_pregunta[]" class="regular-text"></td>
            <td><textarea name="faq_resp[]" rows="3" class="large-text"></textarea></td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    <script type="text/html" id="tmpl-acc-row">
        <tr class="ckg-row">
            <td><input type="text"   name="acc_name[]"  class="regular-text"></td>
            <td><input type="text"   name="acc_desc[]"  class="regular-text"></td>
            <td><input type="number" name="acc_price[]" class="small-text" step="0.01"></td>
            <td>
                <div class="ckg-media-row">
                    <input type="url" name="acc_image[]" class="regular-text ckg-url-field">
                    <button type="button" class="button ckg-media-btn" data-type="image" data-target-prev="1">🖼️</button>
                </div>
            </td>
            <td><button type="button" class="button ckg-rm">✕</button></td>
        </tr>
    </script>
    </div><!-- /ckg-wrap -->
    <?php
}

/* ── Ajustes ─────────────────────────────────────────────────────────── */
/* ══════════════════════════════════════════════════════════════════════
   PAGINA: Enriquecer catalogo existente
══════════════════════════════════════════════════════════════════════ */
function ckg_enrich_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
    $available_prompts = class_exists( 'CKG_Prompt_Manager' ) ? CKG_Prompt_Manager::get_all() : [];
    ?>
    <div class="wrap ckg-wrap">
    <h1>Enriquecer catalogo — CMSKart Product Generator</h1>
    <p>Genera contenido SEO (descripcion, FAQ, meta) para los productos existentes que todavia no
    han sido procesados por el plugin. Usa Ollama para generar el contenido de cada producto.</p>

    <!-- Estadisticas -->
    <div class="ckg-card" style="margin-bottom:16px" id="enrich-stats-panel">
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <div class="ckg-stat-box">
                <div class="ckg-stat-num" id="stat-total">--</div>
                <div class="ckg-stat-label">Productos totales</div>
            </div>
            <div class="ckg-stat-box">
                <div class="ckg-stat-num" id="stat-enriched" style="color:#27ae60">--</div>
                <div class="ckg-stat-label">Ya enriquecidos</div>
            </div>
            <div class="ckg-stat-box">
                <div class="ckg-stat-num" id="stat-pending" style="color:#f39c12">--</div>
                <div class="ckg-stat-label">Sin enriquecer</div>
            </div>
            <div class="ckg-stat-box">
                <div class="ckg-stat-num" id="stat-queue" style="color:#2271b1">--</div>
                <div class="ckg-stat-label">En cola ahora</div>
            </div>
        </div>
        <button type="button" class="button" id="btn-refresh-enrich-stats" style="margin-top:10px">
            Actualizar estadisticas
        </button>
    </div>

    <!-- BUSCADOR DE PRODUCTO INDIVIDUAL -->
    <div class="ckg-card" style="margin-bottom:16px;border-left:4px solid #2271b1">
        <h3 style="margin-top:0">🔍 Enriquecer producto específico</h3>
        <p style="color:#50575e;margin:0 0 10px">Busca por nombre o SKU y enriquece uno o varios productos directamente.</p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="text" id="enrich-search-query"
                placeholder="Nombre o SKU del producto..."
                style="width:280px" class="regular-text">
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
            <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select id="enrich-search-prompt-id" style="min-width:200px">
                    <?php foreach ( $available_prompts ?? [] as $p ) : ?>
                    <option value="<?php echo (int)$p['id']; ?>">
                        <?php echo esc_html( $p['nombre'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary" id="btn-enrich-selected" style="display:none">
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

    <!-- Selector de productos a enriquecer -->
    <div class="ckg-card" style="margin-bottom:16px">
        <h3 style="margin-top:0">Seleccionar productos</h3>
        <table class="form-table" style="margin:0">
            <tr>
                <th>Filtrar por categoria</th>
                <td>
                    <select id="enrich-category" class="regular-text">
                        <option value="">Todas las categorias</option>
                        <?php if ( ! is_wp_error( $cats ) ): foreach ( $cats as $cat ): ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>">
                            <?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?>)
                        </option>
                        <?php endforeach; endif; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Que enriquecer</th>
                <td>
                    <label>
                        <input type="checkbox" id="enrich-only-empty" checked>
                        Solo productos sin descripcion (recomendado)
                    </label>
                </td>
            </tr>
            <tr>
                <th>Limite</th>
                <td>
                    <select id="enrich-limit" class="small-text">
                        <option value="50">50 productos</option>
                        <option value="100" selected>100 productos</option>
                        <option value="200">200 productos</option>
                        <option value="500">500 productos</option>
                    </select>
                </td>
            </tr>
        </table>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button type="button" class="button" id="btn-enrich-analyze">
                Analizar productos
            </button>
            <span id="enrich-analyze-result" style="font-size:13px;color:#50575e"></span>
        </div>
        <div id="enrich-start-panel" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #e0e0e0">
            <button type="button" class="button button-primary" id="btn-enrich-start">
                Iniciar enriquecimiento
            </button>
            <button type="button" class="button" id="btn-enrich-clear" style="border-color:#c0392b;color:#c0392b">
                Limpiar cola
            </button>
        </div>
    </div>

    <!-- Progreso -->
    <div class="ckg-card" id="enrich-progress-panel" style="display:none">
        <h3 style="margin-top:0">Progreso</h3>
        <div class="ckg-prog-bar" style="margin-bottom:8px;height:12px">
            <div class="ckg-prog-fill" id="enrich-prog-fill" style="width:0%"></div>
        </div>
        <div id="enrich-prog-label" style="font-size:13px;margin-bottom:10px"></div>
        <div id="enrich-current" style="font-size:12px;color:#888;margin-bottom:12px"></div>
        <div id="enrich-log" style="max-height:200px;overflow-y:auto;font-size:12px;background:#f8f9fa;padding:10px;border-radius:4px;font-family:monospace"></div>
        <button type="button" class="button" id="btn-enrich-stop" style="margin-top:12px;border-color:#c0392b;color:#c0392b">
            Detener
        </button>
    </div>

    <!-- Migracion de atributos -->
    <div class="ckg-card" style="margin-top:16px">
        <h3 style="margin-top:0">Limpieza de atributos WooCommerce</h3>
        <p>Analiza los atributos globales de WooCommerce y clasifica cuales son especificaciones
        tecnicas (candidatos a eliminar) y cuales son variaciones reales (talla, color, material).</p>
        <p><strong>Antes de migrar se hace un backup automatico en la base de datos.</strong>
        Las specs tecnicas se mueven a los metadatos del producto y se pueden recuperar.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="button" id="btn-attr-analyze">
                Analizar atributos
            </button>
        </div>
        <div id="attr-analysis-panel" style="display:none;margin-top:12px"></div>
    </div>

    </div><!-- .wrap -->
    <?php
}

function ckg_settings_page(): void {
    if ( isset( $_POST['ckg_save'] ) ) {
        check_admin_referer( 'ckg_settings' );
        update_option( 'ckg_settings', [
            'envio_texto'    => sanitize_text_field( $_POST['envio_texto']    ?? '' ),
            'garantia_texto' => sanitize_text_field( $_POST['garantia_texto'] ?? '' ),
            'recogida_texto' => sanitize_text_field( $_POST['recogida_texto'] ?? '' ),
            'contact_phone'  => sanitize_text_field( $_POST['contact_phone']  ?? '' ),
            'contact_email'  => sanitize_email(      $_POST['contact_email']  ?? '' ),
            'chroma_endpoint'    => esc_url_raw( sanitize_text_field( $_POST['chroma_endpoint']    ?? 'http://localhost:8000' ) ),
            'chroma_embed_model' => sanitize_text_field( $_POST['chroma_embed_model'] ?? 'nomic-embed-text' ),
            'exif_enabled'       => ! empty( $_POST['exif_enabled'] ),
            'store_city'         => sanitize_text_field( $_POST['store_city']         ?? '' ),
            'store_province'     => sanitize_text_field( $_POST['store_province']     ?? '' ),
            'store_country'      => sanitize_text_field( $_POST['store_country']      ?? 'Espana' ),
            'store_lat'          => sanitize_text_field( $_POST['store_lat']          ?? '' ),
            'store_lng'          => sanitize_text_field( $_POST['store_lng']          ?? '' ),
            'llm_provider'            => sanitize_key( $_POST['llm_provider'] ?? 'ollama' ),
            'llm_model_openai'        => sanitize_text_field( $_POST['llm_model_openai']      ?? 'gpt-4o-mini' ),
            'llm_model_anthropic'     => sanitize_text_field( $_POST['llm_model_anthropic']   ?? 'claude-haiku-4-5' ),
            'llm_model_perplexity'    => sanitize_text_field( $_POST['llm_model_perplexity']  ?? 'sonar' ),
            'llm_model_deepseek'      => sanitize_text_field( $_POST['llm_model_deepseek']    ?? 'deepseek-chat' ),
            // No guardar si el campo contiene bullets de ofuscacion (• = U+2022)
            'llm_key_openai'      => ( str_contains( $_POST['llm_key_openai']     ?? '', '•' ) ? ( $s['llm_key_openai']     ?? '' ) : sanitize_text_field( $_POST['llm_key_openai']     ?? '' ) ),
            'llm_key_anthropic'   => ( str_contains( $_POST['llm_key_anthropic']  ?? '', '•' ) ? ( $s['llm_key_anthropic']  ?? '' ) : sanitize_text_field( $_POST['llm_key_anthropic']  ?? '' ) ),
            'llm_key_perplexity'  => ( str_contains( $_POST['llm_key_perplexity'] ?? '', '•' ) ? ( $s['llm_key_perplexity'] ?? '' ) : sanitize_text_field( $_POST['llm_key_perplexity'] ?? '' ) ),
            'llm_key_deepseek'    => ( str_contains( $_POST['llm_key_deepseek']   ?? '', '•' ) ? ( $s['llm_key_deepseek']   ?? '' ) : sanitize_text_field( $_POST['llm_key_deepseek']   ?? '' ) ),
            'ollama_endpoint'         => esc_url_raw( sanitize_text_field( $_POST['ollama_endpoint'] ?? '' ) ),
            'ollama_model'            => sanitize_text_field( $_POST['ollama_model'] ?? 'llama3.2' ),
            'gpu_profile'             => sanitize_key( $_POST['gpu_profile'] ?? 'm4000' ),
            'gpu_num_ctx'             => absint( $_POST['gpu_num_ctx']      ?? 4096 ),
            'gpu_num_predict'         => absint( $_POST['gpu_num_predict']  ?? 1024 ),
            'gpu_temperature'         => (float) ( $_POST['gpu_temperature'] ?? 0.65 ),
            'gpu_timeout'             => absint( $_POST['gpu_timeout']       ?? 180 ),
            'shared_llm_option_key'   => sanitize_text_field( $_POST['shared_llm_option_key'] ?? '' ),
            'shared_llm_subkey_map'   => [
                'ollama_endpoint' => sanitize_text_field( $_POST['shared_llm_subkey_endpoint'] ?? '' ),
                'ollama_model'    => sanitize_text_field( $_POST['shared_llm_subkey_model']    ?? '' ),
            ],
            // Colecciones ChromaDB
            'chroma_col_knowledge' => sanitize_text_field( $_POST['chroma_col_knowledge'] ?? 'cmskart_conocimiento' ),
            'chroma_col_scraper'   => sanitize_text_field( $_POST['chroma_col_scraper']   ?? 'ckg_scraper_cache' ),
            'chroma_col_html'      => sanitize_text_field( $_POST['chroma_col_html']      ?? 'ckg_html_cache' ),
            // Qdrant
            'qdrant_endpoint'     => esc_url_raw( sanitize_text_field( $_POST['qdrant_endpoint']     ?? 'http://localhost:6333' ) ),
            'qdrant_tunnel'       => esc_url_raw( sanitize_text_field( $_POST['qdrant_tunnel']       ?? '' ) ),
            'qdrant_embed_model'  => sanitize_text_field( $_POST['qdrant_embed_model']  ?? 'nomic-embed-text' ),
            'qdrant_col_knowledge'=> sanitize_text_field( $_POST['qdrant_col_knowledge'] ?? 'cmskart_conocimiento' ),
            'scraper_api_url'     => esc_url_raw( sanitize_text_field( $_POST['scraper_api_url'] ?? 'http://localhost:8001' ) ),
        ] );
        echo '<div class="notice notice-success"><p>✅ Ajustes guardados.</p></div>';
    }
    $s = get_option( 'ckg_settings', [] );
    ?>
    <div class="wrap">
    <h1>Ajustes -- CMSKart Product Generator</h1>

    <!-- Panel de integraciones sociales (fuera del form, solo lectura) -->
    <div class="ckg-card" style="margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
            <h2 style="margin:0;font-size:15px">Integraciones activas (Facebook, Google, Pinterest)</h2>
            <div style="display:flex;gap:8px">
                <button type="button" class="button" id="btn-check-social-plugins">Detectar plugins</button>
            </div>
        </div>
        <p class="description" style="margin:0 0 10px">
            Al crear un producto, CMSKart rellena automaticamente los campos de estos plugins si estan instalados.
            Usa el diagnostico para verificar que los campos se estan escribiendo correctamente.
        </p>
        <div id="social-plugins-status" style="margin-bottom:12px">
            <p style="color:#888;font-size:13px">Pulsa "Detectar plugins" para ver las integraciones activas.</p>
        </div>
        <div style="padding-top:12px;border-top:1px solid #e0e0e0">
            <strong style="font-size:13px">Diagnostico de campos en producto existente:</strong>
            <p class="description" style="margin:4px 0 8px">
                Selecciona un producto ya creado con el plugin para ver exactamente que campos
                escriben Facebook, Google y Pinterest -- y cuales estan vacios.
            </p>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="number" id="scan-product-id" class="small-text" placeholder="ID producto (vacio = ultimo)">
                <button type="button" class="button" id="btn-scan-social-meta">Escanear campos sociales</button>
                <button type="button" class="button" id="btn-find-social-fields" style="border-color:#7c3aed;color:#7c3aed">Buscar campos exactos en BD</button>
            </div>
            <div id="social-meta-scan-results" style="margin-top:12px"></div>
        </div>
    </div>

    <form method="post">
        <?php wp_nonce_field( 'ckg_settings' ); ?>
        <table class="form-table">
            <tr><th>Texto badge envío</th><td><input type="text" name="envio_texto"    class="regular-text" value="<?php echo esc_attr( $s['envio_texto']    ?? 'Envío en 24-72h hábiles' ); ?>"></td></tr>
            <tr><th>Texto badge garantía</th><td><input type="text" name="garantia_texto" class="regular-text" value="<?php echo esc_attr( $s['garantia_texto'] ?? 'Garantía de Fabricante' ); ?>"></td></tr>
            <tr><th>Texto badge recogida</th><td><input type="text" name="recogida_texto" class="regular-text" value="<?php echo esc_attr( $s['recogida_texto'] ?? 'Consulta recogida en tu karting' ); ?>"></td></tr>
            <tr>
                <th colspan="2">
                    <!-- ═══ SELECTOR DE PROVEEDOR LLM ═══════════════════════════ -->
                    <div class="ckg-llm-providers-box">
                        <h3 style="margin:0 0 14px;color:#1a1a2e;font-size:15px;">🤖 Proveedor de IA</h3>
                        <?php
                        $active_provider = $s['llm_provider'] ?? 'ollama';
                        $all_providers   = class_exists('CKG_LLM') ? CKG_LLM::providers() : [];
                        ?>
                        <div class="ckg-provider-grid">
                        <?php foreach ( $all_providers as $pid => $pdata ) :
                            $is_active = $pid === $active_provider;
                            $api_key   = $s["llm_key_{$pid}"] ?? '';
                            $has_key   = ! empty( trim( $api_key ) );
                            $model_val = $s["llm_model_{$pid}"] ?? $pdata['default_model'];
                        ?>
                            <label class="ckg-provider-card <?php echo $is_active ? 'ckg-provider-card--active' : ''; ?>"
                                   data-provider="<?php echo esc_attr($pid); ?>">
                                <input type="radio" name="llm_provider" value="<?php echo esc_attr($pid); ?>"
                                    <?php checked($active_provider, $pid); ?>>
                                <div class="ckg-provider-header">
                                    <span class="ckg-provider-icon"><?php echo $pdata['icon']; ?></span>
                                    <span class="ckg-provider-name"><?php echo esc_html($pdata['label']); ?></span>
                                    <span class="ckg-provider-cost <?php echo $pdata['cost'] === 'Gratis' ? 'ckg-cost-free' : ($pdata['cost'] === 'Muy económico' ? 'ckg-cost-cheap' : 'ckg-cost-paid'); ?>">
                                        <?php echo esc_html($pdata['cost']); ?>
                                    </span>
                                </div>
                                <div class="ckg-provider-note"><?php echo esc_html($pdata['note']); ?></div>
                                <?php if ( $pid !== 'ollama' ) : ?>
                                <div class="ckg-provider-config" id="config-<?php echo $pid; ?>">
                                    <?php if ( $has_key ) : ?>
                                    <span class="ckg-key-status ckg-key-ok">🔑 API Key configurada</span>
                                    <?php else : ?>
                                    <span class="ckg-key-status ckg-key-missing">⚠️ API Key requerida</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        </div>

                        <!-- Configuración dinámica por proveedor -->
                        <div id="provider-config-panels" style="margin-top:16px">
                        <?php foreach ( $all_providers as $pid => $pdata ) :
                            $api_key   = $pid !== 'ollama' ? ( $s["llm_key_{$pid}"] ?? '' ) : '';
                            $model_val = $s["llm_model_{$pid}"] ?? $pdata['default_model'];
                            $display   = $pid === $active_provider ? '' : 'display:none';
                        ?>
                            <div class="ckg-provider-panel" id="panel-<?php echo $pid; ?>" style="<?php echo $display; ?>">
                            <?php if ( $pid === 'ollama' ) : ?>
                                <p class="description">Configura el endpoint y modelo de Ollama en la sección de abajo.</p>
                            <?php else : ?>
                                <table class="form-table" style="margin:0">
                                    <tr>
                                        <th style="padding-left:0">API Key</th>
                                        <td>
                                            <div class="ckg-apikey-row">
                                                <input type="password"
                                                    name="llm_key_<?php echo $pid; ?>"
                                                    class="regular-text ckg-apikey-input"
                                                    value="<?php echo esc_attr( $api_key ? str_repeat('•', min(20, strlen($api_key)-4)) . substr($api_key, -4) : '' ); ?>"
                                                    placeholder="<?php
                                                        $placeholders = [
                                                            'openai'     => 'sk-...',
                                                            'anthropic'  => 'sk-ant-...',
                                                            'perplexity' => 'pplx-...',
                                                            'deepseek'   => 'sk-...',
                                                        ];
                                                        echo $placeholders[$pid] ?? 'API Key...';
                                                    ?>"
                                                    autocomplete="new-password"
                                                    data-provider="<?php echo $pid; ?>">
                                                <button type="button" class="button ckg-toggle-key" data-target="llm_key_<?php echo $pid; ?>">👁</button>
                                                <?php if ( $api_key ) : ?>
                                                    <button type="button" class="button ckg-clear-key" data-field="llm_key_<?php echo $pid; ?>">✕ Borrar</button>
                                                <?php endif; ?>
                                            </div>
                                            <p class="description">
                                                Obtén tu API Key en:
                                                <?php
                                                $links = [
                                                    'openai'     => '<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>',
                                                    'anthropic'  => '<a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a>',
                                                    'perplexity' => '<a href="https://www.perplexity.ai/settings/api" target="_blank">perplexity.ai/settings/api</a>',
                                                    'deepseek'   => '<a href="https://platform.deepseek.com/api_keys" target="_blank">platform.deepseek.com</a>',
                                                ];
                                                echo $links[$pid] ?? '';
                                                ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="padding-left:0">Modelo</th>
                                        <td>
                                            <select name="llm_model_<?php echo $pid; ?>" class="regular-text">
                                            <?php foreach ( $pdata['models'] as $model_id => $model_label ) : ?>
                                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($model_val, $model_id); ?>>
                                                    <?php echo esc_html($model_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <hr style="margin:20px 0">
                    <!-- ═══ FIN SELECTOR PROVEEDOR ═══════════════════════════════ -->

                    <div class="ckg-shared-llm-box">
                        <div class="ckg-shared-llm-header">
                            <strong>🔗 Configuración Ollama</strong>
                            <?php
                            $shared_key = $s['shared_llm_option_key'] ?? '';
                            $resolved   = class_exists('CKG_Ollama') ? CKG_Ollama::get_resolved_endpoint() : '';
                            if ( $shared_key ) :
                            ?>
                            <span class="ckg-shared-badge">
                                ✅ Usando config de: <code><?php echo esc_html($shared_key); ?></code>
                                — Endpoint: <code><?php echo esc_html($resolved); ?></code>
                                <button type="button" class="button button-small" id="btn-clear-shared-llm">✕ Usar config propia</button>
                            </span>
                            <?php else : ?>
                            <span class="ckg-shared-badge ckg-shared-badge--own">
                                Usando config propia <?php echo $resolved ? '· <code>' . esc_html($resolved) . '</code>' : '(vacío)'; ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Detección automática -->
                        <div class="ckg-shared-llm-detect">
                            <button type="button" class="button" id="btn-detect-llm">
                                🔍 Detectar configuración Ollama de otros plugins
                            </button>
                            <span class="description" style="margin-left:8px">
                                Escanea wp_options buscando endpoints de Ollama / túneles Cloudflare de otros plugins activos.
                            </span>
                        </div>

                        <div id="llm-detect-results" style="margin-top:12px;display:none">
                            <p class="description"><strong>Configuraciones detectadas — selecciona la que quieras usar:</strong></p>
                            <div id="llm-configs-list"></div>
                        </div>

                        <!-- Config propia (fallback) -->
                        <details style="margin-top:14px">
                            <summary style="cursor:pointer;font-size:13px;color:#50575e">
                                ⚙️ O configura manualmente (solo si no usas un plugin compartido)
                            </summary>
                            <table class="form-table" style="margin:10px 0 0">
                                <tr>
                                    <th style="padding-left:0"><label for="ollama_endpoint">Endpoint propio</label></th>
                                    <td>
                                        <input type="url" name="ollama_endpoint" id="ollama_endpoint" class="regular-text"
                                            value="<?php echo esc_attr( $s['ollama_endpoint'] ?? '' ); ?>"
                                            placeholder="http://localhost:11434">
                                        <p class="description">Si hay una config compartida activa, este campo queda vacío y se ignora.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th style="padding-left:0"><label for="ollama_model">Modelo Ollama</label></th>
                                    <td>
                                        <input type="text" name="ollama_model" id="ollama_model" class="regular-text"
                                            value="<?php echo esc_attr( $s['ollama_model'] ?? 'llama3.2' ); ?>"
                                            placeholder="llama3.2">
                                        <p class="description">Recomendados en español: <code>llama3.2</code>, <code>mistral</code>, <code>qwen2.5</code>, <code>deepseek-r1:8b</code></p>
                                    </td>
                                </tr>
                            </table>
                        </details>

                        <!-- Campo oculto para la config compartida (se rellena por JS) -->
                        <input type="hidden" name="shared_llm_option_key" id="shared_llm_option_key"
                            value="<?php echo esc_attr( $s['shared_llm_option_key'] ?? '' ); ?>">
                        <input type="hidden" name="shared_llm_subkey_endpoint" id="shared_llm_subkey_endpoint"
                            value="<?php echo esc_attr( ($s['shared_llm_subkey_map']['ollama_endpoint'] ?? '') ); ?>">
                        <input type="hidden" name="shared_llm_subkey_model" id="shared_llm_subkey_model"
                            value="<?php echo esc_attr( ($s['shared_llm_subkey_map']['ollama_model'] ?? '') ); ?>">

                        <div id="llm-save-status" style="margin-top:8px;font-size:13px;"></div>
                    </div>
                </th>
            </tr>
            <tr>
                <th colspan="2">
                    <div class="ckg-shared-llm-box" style="border-color:#c0392b;margin-bottom:0">
                        <div class="ckg-shared-llm-header" style="margin-bottom:14px">
                            <strong>⚡ Perfil de GPU — Contexto y rendimiento</strong>
                            <?php
                            $gpu_profile = class_exists('CKG_Ollama') ? CKG_Ollama::gpu_profile() : [];
                            $active_profile = $s['gpu_profile'] ?? 'm4000';
                            if ( $gpu_profile ) :
                            ?>
                            <span class="ckg-shared-badge">
                                Activo: <strong><?php echo esc_html( $gpu_profile['label'] ); ?></strong>
                                &nbsp;·&nbsp; num_ctx: <code><?php echo esc_html( $gpu_profile['num_ctx'] ); ?></code>
                                &nbsp;·&nbsp; num_predict: <code><?php echo esc_html( $gpu_profile['num_predict'] ); ?></code>
                                &nbsp;·&nbsp; timeout: <code><?php echo esc_html( $gpu_profile['timeout'] ); ?>s</code>
                            </span>
                            <?php endif; ?>
                        </div>

                        <table class="form-table" style="margin:0">
                            <tr>
                                <th style="padding-left:0">Seleccionar GPU</th>
                                <td>
                                    <div class="ckg-gpu-selector">

                                        <label class="ckg-gpu-card <?php echo $active_profile === 'm4000' ? 'ckg-gpu-card--active' : ''; ?>">
                                            <input type="radio" name="gpu_profile" value="m4000" <?php checked($active_profile, 'm4000'); ?>>
                                            <div class="ckg-gpu-card-inner">
                                                <div class="ckg-gpu-name">🖥️ Quadro M4000</div>
                                                <div class="ckg-gpu-vram">8 GB GDDR5</div>
                                                <div class="ckg-gpu-specs">
                                                    num_ctx: <strong>4 096</strong><br>
                                                    num_predict: <strong>1 024</strong><br>
                                                    timeout: <strong>180s</strong>
                                                </div>
                                                <div class="ckg-gpu-note">Seguro hasta 7B</div>
                                            </div>
                                        </label>

                                        <label class="ckg-gpu-card <?php echo $active_profile === 'rtx5070' ? 'ckg-gpu-card--active' : ''; ?>">
                                            <input type="radio" name="gpu_profile" value="rtx5070" <?php checked($active_profile, 'rtx5070'); ?>>
                                            <div class="ckg-gpu-card-inner">
                                                <div class="ckg-gpu-name">🚀 RTX 5070</div>
                                                <div class="ckg-gpu-vram">12 GB GDDR7</div>
                                                <div class="ckg-gpu-specs">
                                                    num_ctx: <strong>8 192</strong><br>
                                                    num_predict: <strong>2 048</strong><br>
                                                    timeout: <strong>120s</strong>
                                                </div>
                                                <div class="ckg-gpu-note">Óptimo hasta 8B</div>
                                            </div>
                                        </label>

                                        <label class="ckg-gpu-card <?php echo $active_profile === 'rtx5070_max' ? 'ckg-gpu-card--active' : ''; ?>">
                                            <input type="radio" name="gpu_profile" value="rtx5070_max" <?php checked($active_profile, 'rtx5070_max'); ?>>
                                            <div class="ckg-gpu-card-inner">
                                                <div class="ckg-gpu-name">⚡ RTX 5070 Max</div>
                                                <div class="ckg-gpu-vram">12 GB — modelos ≤ 4B</div>
                                                <div class="ckg-gpu-specs">
                                                    num_ctx: <strong>16 384</strong><br>
                                                    num_predict: <strong>4 096</strong><br>
                                                    timeout: <strong>120s</strong>
                                                </div>
                                                <div class="ckg-gpu-note">Solo llama3.2:3b · phi3 · qwen:3b</div>
                                            </div>
                                        </label>

                                        <label class="ckg-gpu-card <?php echo $active_profile === 'custom' ? 'ckg-gpu-card--active' : ''; ?>">
                                            <input type="radio" name="gpu_profile" value="custom" <?php checked($active_profile, 'custom'); ?>>
                                            <div class="ckg-gpu-card-inner">
                                                <div class="ckg-gpu-name">🔧 Personalizado</div>
                                                <div class="ckg-gpu-vram">valores manuales</div>
                                                <div class="ckg-gpu-specs">
                                                    num_ctx: <strong><?php echo esc_html($s['gpu_num_ctx'] ?? '4096'); ?></strong><br>
                                                    num_predict: <strong><?php echo esc_html($s['gpu_num_predict'] ?? '1024'); ?></strong><br>
                                                    timeout: <strong><?php echo esc_html($s['gpu_timeout'] ?? '180'); ?>s</strong>
                                                </div>
                                                <div class="ckg-gpu-note">Configura abajo</div>
                                            </div>
                                        </label>

                                    </div>
                                </td>
                            </tr>
                            <tr id="custom-gpu-row" style="<?php echo $active_profile !== 'custom' ? 'display:none' : ''; ?>">
                                <th style="padding-left:0">Valores personalizados</th>
                                <td>
                                    <table style="border-collapse:collapse">
                                        <tr>
                                            <td style="padding:4px 16px 4px 0"><label>num_ctx</label></td>
                                            <td><input type="number" name="gpu_num_ctx" class="small-text" value="<?php echo esc_attr($s['gpu_num_ctx'] ?? 4096); ?>" min="512" max="131072" step="512"></td>
                                            <td style="padding-left:8px"><span class="description">Ventana de contexto (entrada + salida)</span></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:4px 16px 4px 0"><label>num_predict</label></td>
                                            <td><input type="number" name="gpu_num_predict" class="small-text" value="<?php echo esc_attr($s['gpu_num_predict'] ?? 1024); ?>" min="128" max="8192" step="128"></td>
                                            <td style="padding-left:8px"><span class="description">Tokens máximos en la respuesta</span></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:4px 16px 4px 0"><label>temperature</label></td>
                                            <td><input type="number" name="gpu_temperature" class="small-text" value="<?php echo esc_attr($s['gpu_temperature'] ?? 0.65); ?>" min="0" max="2" step="0.05"></td>
                                            <td style="padding-left:8px"><span class="description">0 = determinista · 1 = creativo · 2 = caótico</span></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:4px 16px 4px 0"><label>timeout (s)</label></td>
                                            <td><input type="number" name="gpu_timeout" class="small-text" value="<?php echo esc_attr($s['gpu_timeout'] ?? 180); ?>" min="30" max="600" step="10"></td>
                                            <td style="padding-left:8px"><span class="description">Segundos máx de espera de respuesta</span></td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                </th>
            </tr>
            <!-- ChromaDB -->
            <tr>
                <th colspan="2">
                    <div class="ckg-shared-llm-box" style="border-color:#7c3aed">
                        <div class="ckg-shared-llm-header" style="margin-bottom:14px">
                            <strong>🧠 ChromaDB — Base de datos vectorial</strong>
                            <span class="ckg-shared-badge">
                                Búsqueda semántica · Detección de duplicados · FAQ inteligente
                            </span>
                        </div>
                        <p class="description" style="margin-bottom:12px">
                            ChromaDB indexa tus productos como vectores semánticos. Permite detectar duplicados antes de crear,
                            sugerir FAQ de productos similares, recuperar conocimiento de marcas y detectar canibalización de keywords SEO.
                            Requiere ChromaDB corriendo localmente y Ollama con el modelo <code>nomic-embed-text</code>.
                        </p>

                        <!-- Status en tiempo real -->
                        <div id="chroma-status-bar" style="margin-bottom:12px">
                            <button type="button" class="button" id="btn-chroma-ping">
                                🔗 Verificar conexión ChromaDB
                            </button>
                            <span id="chroma-status-text" style="margin-left:10px;font-size:13px"></span>
                        </div>
                        <div id="chroma-stats-panel" style="display:none;margin-bottom:12px">
                            <div class="ckg-chroma-stats-grid" id="chroma-stats-grid"></div>
                        </div>

                        <table class="form-table" style="margin:0">
                            <tr>
                                <th style="padding-left:0">Endpoint ChromaDB</th>
                                <td>
                                    <input type="url" name="chroma_endpoint" class="regular-text"
                                        value="<?php echo esc_attr( $s['chroma_endpoint'] ?? 'http://localhost:8000' ); ?>"
                                        placeholder="http://localhost:8000">
                                    <p class="description">
                                        Arranca ChromaDB con: <code>chroma run --path /ruta/a/db --port 8000</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0">Modelo de embeddings</th>
                                <td>
                                    <select name="chroma_embed_model" class="regular-text">
                                        <?php
                                        $embed_model = $s['chroma_embed_model'] ?? 'nomic-embed-text';
                                        $embed_models = [
                                            'nomic-embed-text'   => 'nomic-embed-text — recomendado (274MB, rápido)',
                                            'mxbai-embed-large'  => 'mxbai-embed-large — mayor precisión (670MB)',
                                            'all-minilm'         => 'all-minilm — muy ligero (45MB, menos preciso)',
                                        ];
                                        foreach ( $embed_models as $val => $label ) {
                                            printf( '<option value="%s"%s>%s</option>',
                                                esc_attr($val),
                                                selected($embed_model, $val, false),
                                                esc_html($label)
                                            );
                                        }
                                        ?>
                                    </select>
                                    <p class="description">
                                        Instalar con: <code>ollama pull nomic-embed-text</code>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <!-- Indexación masiva -->
                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(124,58,237,.2)">
                            <strong>Indexar catálogo existente</strong>
                            <p class="description" style="margin:4px 0 10px">
                                Indexa todos tus productos WooCommerce actuales en ChromaDB.
                                Necesario la primera vez o si instalas ChromaDB con catálogo ya existente.
                            </p>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <button type="button" class="button button-primary" id="btn-chroma-bulk-index">
                                    🧠 Indexar todo el catálogo
                                </button>
                                <div id="chroma-bulk-progress" style="display:none;flex:1">
                                    <div class="ckg-prog-bar" style="margin-bottom:4px">
                                        <div class="ckg-prog-fill" id="chroma-bulk-fill" style="background:#7c3aed;width:0%"></div>
                                    </div>
                                    <span id="chroma-bulk-label" style="font-size:12px;color:#50575e"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </th>
            </tr>

            <!-- QDRANT -->
            <tr>
                <th colspan="2">
                    <div class="ckg-shared-llm-box" style="border-color:#f39c12">
                        <div class="ckg-shared-llm-header" style="margin-bottom:12px">
                            <strong>🔶 Qdrant — Conocimiento técnico de fabricantes</strong>
                            <span id="qdrant-status-badge" style="margin-left:10px;font-size:12px;padding:2px 10px;border-radius:12px;background:#eee">—</span>
                            <button type="button" class="button button-small" id="btn-qdrant-ping" style="margin-left:8px">Verificar conexión</button>
                        </div>
                        <p style="color:#50575e;font-size:13px;margin:0 0 10px">
                            PDFs de fabricantes (IAME, Rotax, OTK) y homologaciones CIK-FIA con búsqueda mixta semántica + filtros.
                            Alimentado por <code>alimentar_qdrant.py</code> en tu PC local.
                        </p>
                        <div id="qdrant-stats" style="display:none;margin-bottom:10px;font-size:12px;background:#fffbea;padding:6px 10px;border-radius:4px;border:1px solid #f39c12"></div>
                        <table class="form-table" style="max-width:650px;margin:0">
                            <tr>
                                <th style="width:180px">Endpoint local</th>
                                <td>
                                    <input type="text" name="qdrant_endpoint"
                                        value="<?php echo esc_attr( $s['qdrant_endpoint'] ?? 'http://localhost:6333' ); ?>"
                                        class="regular-text" placeholder="http://localhost:6333">
                                    <p class="description">Arrancar con <code>start_qdrant.bat</code></p>
                                </td>
                            </tr>
                            <tr>
                                <th>Túnel Cloudflare</th>
                                <td>
                                    <input type="text" name="qdrant_tunnel"
                                        value="<?php echo esc_attr( $s['qdrant_tunnel'] ?? '' ); ?>"
                                        class="regular-text" placeholder="https://xxx.trycloudflare.com">
                                    <p class="description">Si está configurado, el plugin usa el túnel en vez del local.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Modelo embeddings</th>
                                <td>
                                    <input type="text" name="qdrant_embed_model"
                                        value="<?php echo esc_attr( $s['qdrant_embed_model'] ?? 'nomic-embed-text' ); ?>"
                                        class="regular-text" placeholder="nomic-embed-text">
                                    <p class="description">Debe coincidir con el modelo usado en <code>alimentar_qdrant.py</code></p>
                                </td>
                            </tr>
                            <tr>
                                <th>Colección conocimiento</th>
                                <td>
                                    <input type="text" name="qdrant_col_knowledge"
                                        value="<?php echo esc_attr( $s['qdrant_col_knowledge'] ?? 'cmskart_conocimiento' ); ?>"
                                        class="regular-text" placeholder="cmskart_conocimiento">
                                </td>
                            </tr>
                        </table>
                        <!-- Test búsqueda -->
                        <div style="margin-top:12px;padding:10px;background:#fef9e7;border-radius:4px">
                            <strong style="font-size:12px">Probar búsqueda en Qdrant:</strong>
                            <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
                                <input type="text" id="qdrant-test-query" placeholder="Ej: motor X30 especificaciones" style="flex:1;min-width:180px">
                                <input type="text" id="qdrant-test-brand" placeholder="Fabricante" style="width:120px">
                                <select id="qdrant-test-tipo" style="width:130px">
                                    <option value="">Todos</option>
                                    <option value="manual">Manuales</option>
                                    <option value="homologacion">Homologaciones</option>
                                    <option value="especificacion">Especificaciones</option>
                                </select>
                                <button type="button" class="button" id="btn-qdrant-search">Buscar</button>
                            </div>
                            <div id="qdrant-search-results" style="margin-top:8px;font-size:12px;max-height:180px;overflow-y:auto"></div>
                        </div>
                    </div>
                </th>
            </tr>

            <!-- EXIF / Localización -->
            <tr>
                <th colspan="2">
                    <div class="ckg-shared-llm-box" style="border-color:#27ae60">
                        <div class="ckg-shared-llm-header" style="margin-bottom:14px">
                            <strong>📍 Metadatos EXIF de localización en imágenes</strong>
                            <?php $exif_on = !empty($s['exif_enabled']); ?>
                            <span class="ckg-shared-badge <?php echo $exif_on ? '' : 'ckg-shared-badge--own'; ?>">
                                <?php echo $exif_on ? '✅ Activado — coordenadas GPS en cada imagen importada' : '○ Desactivado'; ?>
                            </span>
                        </div>
                        <p class="description" style="margin-bottom:12px">
                            Al importar imágenes de fabricantes, el plugin incrustará automáticamente las coordenadas GPS de tu tienda
                            y metadatos del producto (IPTC, XMP) en cada imagen. Mejora el SEO local en Google Images.
                        </p>
                        <table class="form-table" style="margin:0">
                            <tr>
                                <th style="padding-left:0">Activar EXIF automático</th>
                                <td>
                                    <label><input type="checkbox" name="exif_enabled" value="1" <?php checked(!empty($s['exif_enabled'])); ?>>
                                    Incrustar GPS y metadatos en imágenes importadas</label>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0">Localización de la tienda</th>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                                        <input type="text" name="store_city" class="regular-text" placeholder="Ciudad"
                                            value="<?php echo esc_attr($s['store_city'] ?? 'Sevilla'); ?>">
                                        <input type="text" name="store_province" class="regular-text" placeholder="Provincia/Comunidad"
                                            value="<?php echo esc_attr($s['store_province'] ?? 'Andalucía'); ?>">
                                        <input type="text" name="store_country" style="width:100px" placeholder="País"
                                            value="<?php echo esc_attr($s['store_country'] ?? 'España'); ?>">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0">Coordenadas GPS</th>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                                        <input type="text" name="store_lat" style="width:130px" placeholder="Latitud (ej: 37.3891)"
                                            value="<?php echo esc_attr($s['store_lat'] ?? ''); ?>" id="store_lat">
                                        <input type="text" name="store_lng" style="width:130px" placeholder="Longitud (ej: -5.9845)"
                                            value="<?php echo esc_attr($s['store_lng'] ?? ''); ?>" id="store_lng">
                                        <button type="button" class="button" id="btn-geocode-store">
                                            🌍 Geocodificar dirección
                                        </button>
                                        <span id="geocode-status" style="font-size:12px"></span>
                                    </div>
                                    <p class="description">Pulsa "Geocodificar" para obtener las coordenadas automáticamente a partir de la ciudad y provincia.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </th>
            </tr>

            <tr><th>Teléfono</th><td><input type="text" name="contact_phone" class="regular-text" value="<?php echo esc_attr( $s['contact_phone'] ?? '' ); ?>"></td></tr>
            <tr><th>Email</th><td><input type="email" name="contact_email" class="regular-text" value="<?php echo esc_attr( $s['contact_email'] ?? 'info@cmskart.es' ); ?>"></td></tr>
        </table>
        <p><button type="submit" name="ckg_save" class="button button-primary">Guardar</button></p>
    </form>
    </div>

    <script>
    jQuery(function($){
        var _ajax  = '<?php echo esc_url( admin_url("admin-ajax.php") ); ?>';
        var _nonce = '<?php echo wp_create_nonce("ckg_admin_nonce"); ?>';

        function qdrantPing() {
            var $badge = $('#qdrant-status-badge');
            $badge.text('Verificando...').css({'background':'#fff3cd','color':'#856404'});
            $.post(_ajax, { action: 'ckg_qdrant_ping', nonce: _nonce }, function(r) {
                if (!r || !r.success) {
                    $badge.text('Error').css({'background':'#fde8e8','color':'#c0392b'});
                    return;
                }
                var d = r.data;
                if (d.online) {
                    $badge.text('Online').css({'background':'#d4edda','color':'#27ae60'});
                    var html = 'URL: <code>' + d.url + '</code> &nbsp;';
                    if (d.stats) {
                        $.each(d.stats, function(col, count) {
                            html += '| <strong>' + col + '</strong>: ' + count + ' ';
                        });
                    }
                    $('#qdrant-stats').html(html).show();
                } else {
                    $badge.text('Offline').css({'background':'#fde8e8','color':'#c0392b'});
                    $('#qdrant-stats').html('Sin conexion — URL: <code>' + d.url + '</code>').show();
                }
            }).fail(function(xhr) {
                $('#qdrant-status-badge').text('Error ' + xhr.status).css({'background':'#fde8e8','color':'#c0392b'});
            });
        }

        $('#btn-qdrant-ping').on('click', qdrantPing);

        $('#btn-qdrant-search').on('click', function(){
            var q = $('#qdrant-test-query').val().trim();
            if (!q) return;
            var $res = $('#qdrant-search-results').html('<em>Buscando...</em>');
            $.post(_ajax, {
                action: 'ckg_qdrant_search', nonce: _nonce,
                query: q, brand: $('#qdrant-test-brand').val(),
                tipo_doc: $('#qdrant-test-tipo').val(), limit: 5,
            }, function(r) {
                if (!r.success) { $res.html('Error'); return; }
                var html = '';
                (r.data.results || []).forEach(function(res) {
                    html += '<div style="border-bottom:1px solid #ddd;padding:4px 0">';
                    html += '<b style="color:#27ae60">' + Math.round(res.score*100) + '%</b> ';
                    html += '[' + (res.tipo_doc||'?') + '] ' + (res.fabricante||'') + '<br>';
                    html += '<span style="font-size:11px">' + (res.text||'').substring(0,200) + '...</span></div>';
                });
                $res.html(html || '<em>Sin resultados.</em>');
            });
        });

        // Auto-ping solo si hay tunel configurado (no localhost por defecto)
        var ep = $('input[name="qdrant_tunnel"]').val() || $('input[name="qdrant_endpoint"]').val();
        if (ep && ep.indexOf('trycloudflare') !== -1) {
            setTimeout(qdrantPing, 800);
        }
    });
    </script>
    <?php
}
