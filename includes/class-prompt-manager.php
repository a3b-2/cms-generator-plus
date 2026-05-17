<?php
/**
 * CKG_Prompt_Manager
 *
 * Gestor de prompts para el enriquecimiento de productos.
 * Permite crear, editar y seleccionar prompts por modelo y situacion.
 *
 * Variables disponibles en los prompts:
 *   {nombre}              - Nombre del producto
 *   {marca}               - Marca/fabricante
 *   {sku}                 - Referencia SKU
 *   {categoria}           - Categoria WooCommerce
 *   {precio}              - Precio actual
 *   {descripcion_actual}  - Descripcion existente en WooCommerce
 *   {specs}               - Especificaciones tecnicas (texto plano)
 *   {homologaciones}      - Homologaciones del producto
 *   {json_scraper}        - JSON completo del CKG_Context_Scraper
 *   {faq_existente}       - FAQ ya guardada en el producto
 *   {keywords}            - Keywords focus de RankMath
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Prompt_Manager {

    const TABLE = 'ckg_prompts';

    /* ── Crear tabla ─────────────────────────────────────────────────── */

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre              VARCHAR(100) NOT NULL,
            descripcion         TEXT,
            system_prompt       LONGTEXT,
            user_prompt         LONGTEXT NOT NULL,
            modelo_recomendado  VARCHAR(50) NOT NULL DEFAULT 'ollama',
            usa_scraper         TINYINT(1) NOT NULL DEFAULT 0,
            usa_chroma          TINYINT(1) NOT NULL DEFAULT 0,
            usa_qdrant          TINYINT(1) NOT NULL DEFAULT 0,
            es_predefinido      TINYINT(1) NOT NULL DEFAULT 0,
            activo              TINYINT(1) NOT NULL DEFAULT 1,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY activo (activo),
            KEY modelo_recomendado (modelo_recomendado)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Insertar prompts predefinidos si la tabla esta vacia
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        if ( $count === 0 ) {
            self::insert_default_prompts();
        }
    }

    /* ── Prompts predefinidos ────────────────────────────────────────── */

    private static function insert_default_prompts(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // ── Prompt 1: Ollama texto plano ─────────────────────────────
        $ollama_prompt = 'Actua como un Copywriter SEO tecnico experto en piezas de karting de competicion para TU UNICA TIENDA: CMSKart.es.

<datos_del_producto>
Producto: {nombre}
Marca Fabricante: {marca}
Categoria: {categoria}
Referencia (SKU): {sku}
{specs}

{descripcion_actual}
</datos_del_producto>

INSTRUCCIONES DE REDACCION:
Genera contenido enriquecido, comercial y altamente optimizado para SEO.
- Tono: Tecnico, directo, persuasivo y en Espanol de Espana.
- REGLA DE ORO: ESTA TOTALMENTE PROHIBIDO mencionar nombres de otras tiendas.
- Escribe como si el producto fuera vendido UNICA Y EXCLUSIVAMENTE por CMSKart.es.

REGLAS ESTRICTAS DE FORMATO (CRITICO PARA EL SISTEMA):
1. Devuelve UNICA Y EXCLUSIVAMENTE un objeto JSON valido.
2. NO envuelvas la respuesta en bloques markdown (PROHIBIDO usar ```json).
3. NO incluyas saludos, explicaciones ni texto adicional.

{
  "short_desc": "Descripcion corta 1-2 frases en espanol.",
  "intro": "Introduccion tecnica 100-150 palabras en espanol con datos tecnicos.",
  "conclusion": "CTA 2-3 frases animando a comprar en CMSKart.es.",
  "desc_en": "Traduccion al ingles 50-80 palabras.",
  "desc_it": "Traduzione in italiano 50-80 parole.",
  "faq": [
    {"pregunta": "Pregunta tecnica util 1", "respuesta": "Respuesta directa 1"},
    {"pregunta": "Pregunta tecnica util 2", "respuesta": "Respuesta directa 2"}
  ],
  "schema_name": "Nombre limpio para schema.org.",
  "schema_description": "Descripcion en ingles para schema Product.",
  "seo_title": "Titulo SEO maximo 60 caracteres.",
  "seo_description": "Meta descripcion 140-155 caracteres.",
  "seo_keywords": "keyword principal, secundaria 1, secundaria 2"
}';

        // ── Prompt 2: Claude SEO Completo ────────────────────────────
        $claude_system = 'Actuas como un equipo especializado compuesto por:
- Un experto SEO tecnico con dominio de Core Web Vitals, structured data y SERP features.
- Un copywriter tecnico-persuasivo especializado en motorsport y e-commerce B2C/B2B.
- Un desarrollador front-end con dominio de HTML5 semantico y Schema.org.

Tu unica fuente de verdad es el JSON del producto que se adjunta.
No inventes especificaciones tecnicas. Si un dato no aparece en la entrada usa [DATO NO DISPONIBLE].

REGLAS DE REDACCION:
- Idioma: Espanol de Espana, tono tecnico y persuasivo.
- PROHIBIDO mencionar competidores o otras tiendas. Todo atribuido a CMSKart.es.
- Densidad de keywords: 1.5-2.5% sobre el total de palabras.
- Cada parrafo minimo 3 frases. Nunca parrafos de una sola linea.
- Al menos 1 dato numerico real por seccion.

FORMATO DE SALIDA: HTML5 completo y valido, listo para inyectar en WooCommerce.
Incluir en este orden exacto:
1. Descripcion corta (short_description WooCommerce) en <div class="ckg-short-desc">
2. Descripcion larga estructurada con H2/H3 en <div class="ckg-description">
3. Tabla de especificaciones tecnicas <table class="ckg-specs-table">
4. FAQ en <div class="ckg-faq"> con schema FAQPage
5. CTA final en <div class="ckg-cta">
6. Script JSON-LD con schema Product + Offer + FAQPage';

        $claude_prompt = 'Genera la ficha completa de producto WooCommerce en HTML para CMSKart.es.

DATOS DEL PRODUCTO (JSON del scraper):
{json_scraper}

INFORMACION ADICIONAL DE WOOCOMMERCE:
- Precio actual: {precio} EUR
- URL del producto: https://cmskart.es/producto/{sku}/
- Categorias: {categoria}
- Keywords objetivo: {keywords}

Genera el HTML completo siguiendo exactamente las instrucciones del system prompt.
Incluye el JSON-LD del schema Product con precio, disponibilidad y marca reales.';

        $wpdb->insert( $table, [
            'nombre'             => 'Ollama — Texto Plano',
            'descripcion'        => 'Prompt rapido para modelos locales (qwen2.5, llama3). Genera JSON con descripcion, FAQ, SEO y traducciones. Sin scraping externo.',
            'system_prompt'      => '',
            'user_prompt'        => $ollama_prompt,
            'modelo_recomendado' => 'ollama',
            'usa_scraper'        => 0,
            'usa_chroma'         => 1,
            'usa_qdrant'         => 0,
            'es_predefinido'     => 1,
            'activo'             => 1,
        ], [ '%s','%s','%s','%s','%s','%d','%d','%d','%d','%d' ] );

        $wpdb->insert( $table, [
            'nombre'             => 'Claude — SEO Completo',
            'descripcion'        => 'Genera HTML completo de calidad profesional usando Claude API + scraper de fabricante/distribuidor + ChromaDB. Ideal para productos nuevos importantes.',
            'system_prompt'      => $claude_system,
            'user_prompt'        => $claude_prompt,
            'modelo_recomendado' => 'anthropic',
            'usa_scraper'        => 1,
            'usa_chroma'         => 1,
            'usa_qdrant'         => 1,
            'es_predefinido'     => 1,
            'activo'             => 1,
        ], [ '%s','%s','%s','%s','%s','%d','%d','%d','%d','%d' ] );
    }

    /* ── CRUD ────────────────────────────────────────────────────────── */

    public static function get_all( bool $only_active = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = $only_active ? 'WHERE activo = 1' : '';
        return $wpdb->get_results( "SELECT * FROM $table $where ORDER BY es_predefinido DESC, nombre ASC", ARRAY_A ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $fields = [
            'nombre'             => sanitize_text_field( $data['nombre'] ?? '' ),
            'descripcion'        => sanitize_textarea_field( $data['descripcion'] ?? '' ),
            'system_prompt'      => wp_unslash( $data['system_prompt'] ?? '' ),
            'user_prompt'        => wp_unslash( $data['user_prompt'] ?? '' ),
            'modelo_recomendado' => sanitize_key( $data['modelo_recomendado'] ?? 'ollama' ),
            'usa_scraper'        => (int) ( $data['usa_scraper'] ?? 0 ),
            'usa_chroma'         => (int) ( $data['usa_chroma']  ?? 0 ),
            'usa_qdrant'         => (int) ( $data['usa_qdrant']  ?? 0 ),
            'activo'             => (int) ( $data['activo'] ?? 1 ),
        ];

        if ( empty( $fields['nombre'] ) ) {
            return new \WP_Error( 'validation', 'El nombre del prompt es obligatorio.' );
        }
        if ( empty( $fields['user_prompt'] ) ) {
            return new \WP_Error( 'validation', 'El user_prompt es obligatorio.' );
        }

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $fields, [ 'id' => (int) $data['id'] ], array_fill( 0, count($fields), '%s' ), [ '%d' ] );
            return (int) $data['id'];
        }

        $fields['es_predefinido'] = 0;
        $wpdb->insert( $table, $fields );
        return (int) $wpdb->insert_id;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $prompt  = self::get( $id );
        if ( ! $prompt ) return false;
        // No permitir borrar prompts predefinidos
        if ( $prompt['es_predefinido'] ) return false;
        return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
    }

    /* ── Motor de variables ──────────────────────────────────────────── */

    /**
     * Sustituye las variables del prompt con datos reales del producto.
     * Soporta tanto datos de WooCommerce como el JSON del scraper.
     */
    public static function render( int $prompt_id, int $product_id ): array|\WP_Error {
        $prompt = self::get( $prompt_id );
        if ( ! $prompt ) {
            return new \WP_Error( 'not_found', "Prompt ID $prompt_id no encontrado." );
        }

        // Datos base de WooCommerce
        $wc_data = CKG_Context_Scraper::get_wc_data( $product_id );
        if ( is_wp_error( $wc_data ) ) return $wc_data;

        // Specs formateadas como texto
        $specs_text = '';
        if ( ! empty( $wc_data['existing_specs'] ) ) {
            $specs_text = "ESPECIFICACIONES TECNICAS:\n";
            foreach ( $wc_data['existing_specs'] as $s ) {
                $key = is_array($s) ? ($s['key'] ?? '') : $s;
                $val = is_array($s) ? ($s['value'] ?? '') : '';
                if ( $key && $val ) $specs_text .= "- $key: $val\n";
            }
        }

        // Contexto Qdrant — conocimiento tecnico del fabricante
        $qdrant_context = '';
        if ( ! empty( $prompt['usa_qdrant'] ) && class_exists( 'CKG_Qdrant' ) && CKG_Qdrant::ping() ) {
            $qdrant_results = CKG_Qdrant::find_manufacturer_knowledge(
                $wc_data['name'], $wc_data['brand'], '', 5
            );
            if ( ! empty( $qdrant_results ) ) {
                $qdrant_context = implode( "

", array_column( $qdrant_results, 'text' ) );
            }
        }

        // JSON del scraper (si el prompt lo necesita)
        $json_scraper = '';
        if ( $prompt['usa_scraper'] || str_contains( $prompt['user_prompt'], '{json_scraper}' ) ) {
            $scrape_result = CKG_Context_Scraper::scrape( $product_id );
            if ( $scrape_result['success'] && ! empty( $scrape_result['json'] ) ) {
                $json_scraper = wp_json_encode( $scrape_result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            }
        }

        // Descripcion existente — con etiqueta segun calidad
        $existing_desc  = $wc_data['existing_desc'] ?? '';
        $desc_label     = '';
        if ( strlen( $existing_desc ) > 50 ) {
            // Detectar contenido de baja calidad por longitud y patrones CSV
            $bad_patterns = ['PRODUCT CATEGORY', 'DESCRIPTION IN SPANISH', 'DESCRIPTION IN ENGLISH'];
            $is_low = strlen( $existing_desc ) < 200;
            foreach ( $bad_patterns as $p ) {
                if ( stripos( $existing_desc, $p ) !== false ) { $is_low = true; break; }
            }
            $desc_label = $is_low
                ? "TEXTO DE REFERENCIA (puede contener nombres de otras tiendas, reescribir):\n$existing_desc"
                : "DESCRIPCION PROPIA EXISTENTE (mejorar y ampliar, NO reemplazar):\n$existing_desc";
        }

        // Tabla de sustituciones
        $vars = [
            '{nombre}'             => $wc_data['name'],
            '{marca}'              => $wc_data['brand'],
            '{sku}'                => $wc_data['sku'],
            '{categoria}'          => implode( ', ', $wc_data['categories'] ),
            '{precio}'             => $wc_data['price'],
            '{descripcion_actual}' => $desc_label,
            '{specs}'              => $specs_text,
            '{homologaciones}'     => implode( ', ', $wc_data['existing_homos'] ?? [] ),
            '{json_scraper}'       => $json_scraper,
            '{faq_existente}'      => get_post_meta( $product_id, '_ckg_faq', true ) ?: '',
            '{keywords}'           => $wc_data['focus_keyword'],
            '{qdrant_context}'     => $qdrant_context,
        ];

        $user_prompt   = str_replace( array_keys($vars), array_values($vars), $prompt['user_prompt'] );
        $system_prompt = str_replace( array_keys($vars), array_values($vars), $prompt['system_prompt'] ?? '' );

        return [
            'prompt_id'     => $prompt_id,
            'prompt_nombre' => $prompt['nombre'],
            'system_prompt' => $system_prompt,
            'user_prompt'   => $user_prompt,
            'modelo'        => $prompt['modelo_recomendado'],
            'usa_scraper'   => (bool) $prompt['usa_scraper'],
            'usa_chroma'    => (bool) $prompt['usa_chroma'],
        ];
    }

    /* ── Estadisticas ────────────────────────────────────────────────── */

    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return [
            'total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
            'activos'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE activo = 1" ),
            'predefinidos' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE es_predefinido = 1" ),
        ];
    }
}
