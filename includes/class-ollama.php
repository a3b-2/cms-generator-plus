<?php
/**
 * CKG_Ollama
 * Envía contenido a Ollama (LLM local) y devuelve el texto mejorado.
 * Endpoint configurable en Ajustes del plugin (por defecto http://localhost:11434).
 *
 * Secciones disponibles:
 *   short_desc | intro | caracteristica | conclusion
 *   faq_answer | seo_title | seo_description | compat_nota
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Ollama {

    /* ── Config ──────────────────────────────────────────────────────── */

    private static function endpoint(): string {
        $raw = self::resolve_config( 'ollama_endpoint', 'http://localhost:11434' );

        // Eliminar cualquier path /api/* que pueda venir incluido en la URL guardada
        // Ej: https://tunnel.trycloudflare.com/api/generate → https://tunnel.trycloudflare.com
        $raw = preg_replace( '#/api/.*$#', '', $raw );

        return rtrim( $raw, '/' );
    }

    private static function model(): string {
        return self::resolve_config( 'ollama_model', 'llama3.2' );
    }

    /**
     * Parámetros óptimos según la GPU seleccionada en Ajustes.
     * Perfiles: m4000 | rtx5070 | rtx5070_max | custom
     */
    public static function gpu_profile(): array {
        $s       = get_option( 'ckg_settings', [] );
        $profile = $s['gpu_profile'] ?? 'm4000';

        $profiles = [
            // Quadro M4000 — 8GB GDDR5
            'm4000' => [
                'label'       => 'Quadro M4000 (8GB)',
                'num_ctx'     => 4096,
                'num_predict' => 1024,
                'temperature' => 0.65,
                'timeout'     => 180,
                'note'        => 'Contexto 4K - seguro para modelos hasta 7B',
            ],
            // RTX 5070 — 12GB GDDR7
            'rtx5070' => [
                'label'       => 'RTX 5070 (12GB)',
                'num_ctx'     => 8192,
                'num_predict' => 2048,
                'temperature' => 0.65,
                'timeout'     => 120,
                'note'        => 'Contexto 8K - optimo para llama3.2, mistral, qwen2.5',
            ],
            // RTX 5070 Max — solo modelos pequeños <= 4B
            'rtx5070_max' => [
                'label'       => 'RTX 5070 Maximo (modelos <= 4B)',
                'num_ctx'     => 16384,
                'num_predict' => 4096,
                'temperature' => 0.65,
                'timeout'     => 120,
                'note'        => 'Contexto 16K - solo para llama3.2:3b, phi3:mini, qwen2.5:3b',
            ],
        ];

        if ( $profile === 'custom' ) {
            return [
                'label'       => 'Personalizado',
                'num_ctx'     => (int) ( $s['gpu_num_ctx']      ?? 4096 ),
                'num_predict' => (int) ( $s['gpu_num_predict']  ?? 1024 ),
                'temperature' => (float) ( $s['gpu_temperature'] ?? 0.65 ),
                'timeout'     => (int) ( $s['gpu_timeout']      ?? 180 ),
                'note'        => 'Valores configurados manualmente',
            ];
        }

        return $profiles[ $profile ] ?? $profiles['m4000'];
    }

    /**
     * Resuelve un valor de configuración:
     * 1. Config propia de CMSKart (ckg_settings)
     * 2. Config compartida de otro plugin (ckg_settings['shared_llm_option_key'])
     */
    private static function resolve_config( string $key, string $default = '' ): string {
        $s = get_option( 'ckg_settings', [] );

        // 1. Config propia si está rellena
        $own = trim( $s[ $key ] ?? '' );
        if ( $own ) return $own;

        // 2. Config compartida de otro plugin
        $shared_key     = $s['shared_llm_option_key']  ?? '';  // ej: 'municipios_settings'
        $shared_subkey  = $s['shared_llm_subkey_map']  ?? [];  // ej: ['ollama_endpoint'=>'llm_url']

        if ( $shared_key ) {
            $shared = get_option( $shared_key, [] );
            if ( is_array( $shared ) ) {
                // Buscar con mapa de subclaves o directamente con el mismo nombre de clave
                $map    = is_array( $shared_subkey ) ? $shared_subkey : [];
                $lookup = $map[ $key ] ?? $key;
                $val    = trim( $shared[ $lookup ] ?? '' );
                if ( $val ) return $val;

                // Búsqueda genérica: cualquier clave que contenga 'ollama' o 'endpoint'
                foreach ( $shared as $k => $v ) {
                    if ( ! is_string( $v ) || empty( $v ) ) continue;
                    if ( $key === 'ollama_endpoint' && ( str_contains( strtolower($k), 'endpoint' ) || str_contains( strtolower($k), 'ollama' ) || str_contains( strtolower($k), 'llm' ) ) ) {
                        if ( filter_var( $v, FILTER_VALIDATE_URL ) ) return $v;
                    }
                    if ( $key === 'ollama_model' && ( str_contains( strtolower($k), 'model' ) ) ) {
                        return $v;
                    }
                }
            }
        }

        return $default;
    }

    /* ── Punto de entrada principal ──────────────────────────────────── */

    /**
     * @param string $section  Identificador de la sección (ver docblock)
     * @param string $content  Texto actual a mejorar
     * @param array  $ctx      Contexto: product_name, brand, sku, homologaciones[]
     * @return string|\WP_Error
     */
    public static function improve( string $section, string $content, array $ctx = [] ) {
        if ( empty( trim( $content ) ) ) {
            return new WP_Error( 'empty_content', 'El contenido está vacío — escribe algo primero.' );
        }

        $prompt   = self::prompt( $section, $content, $ctx );
        $endpoint = self::endpoint();
        $model    = self::model();

        $gpu = self::gpu_profile();

        $response = wp_remote_post( $endpoint . '/api/generate', [
            'timeout' => $gpu['timeout'],
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => $gpu['temperature'],
                    'num_predict' => $gpu['num_predict'],
                    'num_ctx'     => $gpu['num_ctx'],
                    'stop'        => [ '---', '###' ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ollama_connection',
                'No se pudo conectar con Ollama: ' . $response->get_error_message() .
                '. Comprueba que Ollama esté corriendo en ' . $endpoint
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body_raw = wp_remote_retrieve_body( $response );
            $decoded  = json_decode( $body_raw, true );
            $msg      = $decoded['error'] ?? "HTTP $code";
            return new WP_Error( 'ollama_http', "Ollama respondió con error: $msg" );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = trim( $body['response'] ?? '' );

        if ( empty( $text ) ) {
            return new WP_Error( 'empty_response', 'Ollama devolvió una respuesta vacía. Prueba con otro modelo.' );
        }

        return $text;
    }

    /**
     * Envía un prompt completo directamente a Ollama (sin sistema de secciones).
     * Usado por CKG_Competitor_Scraper.
     */
    public static function improve_raw( string $prompt, array $ctx = [] ) {
        $endpoint = self::endpoint();
        $model    = self::model();

        $gpu = self::gpu_profile();

        $response = wp_remote_post( $endpoint . '/api/generate', [
            'timeout' => $gpu['timeout'],
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => $gpu['temperature'],
                    'num_predict' => $gpu['num_predict'],
                    'num_ctx'     => $gpu['num_ctx'],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = trim( $body['response'] ?? '' );
        return $text ?: new WP_Error( 'empty', 'Ollama devolvió respuesta vacía.' );
    }

    /** Expone el modelo activo para uso externo */
    public static function get_model_public(): string {
        return self::model();
    }

    /** Expone el endpoint resuelto (propio o compartido) para mostrarlo en la UI */
    public static function get_resolved_endpoint(): string {
        return self::endpoint();
    }

    /* ── Test de conectividad ────────────────────────────────────────── */

    public static function ping(): bool {
        $response = wp_remote_get( self::endpoint() . '/api/tags', [ 'timeout' => 5 ] );
        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    public static function list_models(): array {
        $response = wp_remote_get( self::endpoint() . '/api/tags', [ 'timeout' => 8 ] );
        if ( is_wp_error( $response ) ) return [];
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return array_column( $body['models'] ?? [], 'name' );
    }

    /* ── Construcción de prompts ─────────────────────────────────────── */

    private static function prompt( string $section, string $content, array $ctx ): string {
        $name  = $ctx['product_name'] ?? 'producto de karting';
        $brand = $ctx['brand']        ? " de la marca {$ctx['brand']}" : '';
        $homo  = ! empty( $ctx['homologaciones'] )
            ? 'Homologaciones: ' . implode( ', ', $ctx['homologaciones'] ) . '.'
            : '';
        $sku   = $ctx['sku'] ? "SKU: {$ctx['sku']}." : '';

        $system = <<<SYS
Eres un redactor SEO experto en karting de competición para CMSKart.es, la tienda online de referencia en España.
Contexto del producto: $name$brand. $homo $sku
Reglas absolutas:
- Responde ÚNICAMENTE con el texto mejorado, sin explicaciones, comentarios ni guiones iniciales.
- Idioma: español de España.
- Usa HTML básico (<strong>, <em>) solo cuando aporte énfasis real.
- Vocabulario técnico de karting natural: homologación, CIK-FIA, competición, chasis, motor, categoría, rendimiento, piloto, pista, seguridad.
- Tono: profesional y cercano, para pilotos y preparadores.
- No inventes especificaciones técnicas que no estén en el texto original.
SYS;

        $tasks = [
            'short_desc' => "TAREA: Reescribe esta descripción corta del producto. Máx 90 palabras. Debe ser impactante, mencionar la ventaja principal y terminar con una llamada implícita a la acción.\n\nTEXTO ORIGINAL:\n$content",

            'intro' => "TAREA: Mejora este párrafo de introducción (150-200 palabras). Empieza mencionando el producto en <strong>negrita</strong>. Incluye contexto técnico y termina con la propuesta de valor clave.\n\nTEXTO ORIGINAL:\n$content",

            'caracteristica' => "TAREA: Mejora este texto de característica técnica (3-5 frases, técnico y persuasivo, con datos si están presentes en el original). NO añadas datos que no aparezcan.\n\nTEXTO ORIGINAL:\n$content",

            'conclusion' => "TAREA: Mejora esta conclusión (120-160 palabras). Resume los 3 puntos fuertes y termina con una llamada a la acción directa y motivadora para el piloto de karting.\n\nTEXTO ORIGINAL:\n$content",

            'faq_answer' => "TAREA: Mejora esta respuesta de FAQ (4-6 frases, directa, técnicamente precisa, resuelve la duda completamente).\n\nTEXTO ORIGINAL:\n$content",

            'compat_nota' => "TAREA: Mejora esta nota de compatibilidad (2-3 frases, clara y útil para el piloto, incluye una recomendación para consultar en caso de duda).\n\nTEXTO ORIGINAL:\n$content",

            'seo_title' => "TAREA: Genera un title tag SEO para Google. EXACTAMENTE entre 50-60 caracteres. Formato: [Nombre producto] | [Homologación clave si hay] | CMSKart. Responde SOLO el title, sin comillas.\n\nTEXTO DE REFERENCIA:\n$content",

            'seo_description' => "TAREA: Genera una meta description SEO para Google. EXACTAMENTE entre 140-155 caracteres. Incluye: producto, ventaja principal, homologación si aplica, y 'CMSKart'. Responde SOLO la meta description, sin comillas.\n\nTEXTO DE REFERENCIA:\n$content",
        ];

        $task = $tasks[ $section ] ?? "TAREA: Mejora el siguiente texto manteniendo la misma estructura y longitud aproximada:\n\n$content";

        return $system . "\n\n" . $task;
    }
}
