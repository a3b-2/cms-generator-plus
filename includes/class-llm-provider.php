<?php
/**
 * CKG_LLM
 * Router unificado de proveedores LLM para CMSKart Product Generator.
 *
 * Proveedores soportados:
 *   ollama      → LLM local via Ollama (gratis, privado)
 *   openai      → OpenAI API (gpt-4o-mini, gpt-4o, gpt-4.1-mini)
 *   anthropic   → Anthropic API (claude-haiku-4-5, claude-sonnet-4-6)
 *   perplexity  → Perplexity API (sonar, sonar-pro)
 *   deepseek    → DeepSeek API (deepseek-chat, deepseek-reasoner)
 *
 * Uso:
 *   CKG_LLM::complete( $prompt, $ctx )        → usa el proveedor activo
 *   CKG_LLM::improve( $section, $text, $ctx ) → mejora una sección
 *   CKG_LLM::improve_raw( $prompt, $ctx )     → prompt directo
 *   CKG_LLM::ping()                            → test de conectividad
 *   CKG_LLM::active_provider()                 → array con info del proveedor activo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_LLM {

    /* ── Definición de proveedores ────────────────────────────────── */

    public static function providers(): array {
        return [
            'ollama' => [
                'label'    => 'Ollama (Local)',
                'icon'     => '🟢',
                'requires' => 'endpoint',
                'cost'     => 'Gratis',
                'models'   => [],   // dinámico vía API
                'default_model' => 'llama3.2',
                'note'     => 'Modelos locales. Privado, sin coste. Requiere GPU.',
            ],
            'openai' => [
                'label'    => 'OpenAI (ChatGPT)',
                'icon'     => '🟠',
                'requires' => 'api_key',
                'cost'     => 'De pago',
                'models'   => [
                    'gpt-4o-mini'  => 'GPT-4o Mini — rápido y económico',
                    'gpt-4.1-mini' => 'GPT-4.1 Mini — equilibrado',
                    'gpt-4o'       => 'GPT-4o — máxima calidad',
                ],
                'default_model' => 'gpt-4o-mini',
                'note'     => 'API de OpenAI. Requiere API Key en platform.openai.com',
            ],
            'anthropic' => [
                'label'    => 'Anthropic (Claude)',
                'icon'     => '🟣',
                'requires' => 'api_key',
                'cost'     => 'De pago',
                'models'   => [
                    'claude-haiku-4-5'   => 'Claude Haiku 4.5 — ultra rápido y económico',
                    'claude-sonnet-4-6'  => 'Claude Sonnet 4.6 — potente y equilibrado',
                ],
                'default_model' => 'claude-haiku-4-5',
                'note'     => 'API de Anthropic. Requiere API Key en console.anthropic.com',
            ],
            'perplexity' => [
                'label'    => 'Perplexity',
                'icon'     => '🔵',
                'requires' => 'api_key',
                'cost'     => 'De pago',
                'models'   => [
                    'sonar'         => 'Sonar — búsqueda en tiempo real',
                    'sonar-pro'     => 'Sonar Pro — búsqueda premium',
                    'sonar-reasoning' => 'Sonar Reasoning — razonamiento avanzado',
                ],
                'default_model' => 'sonar',
                'note'     => 'API de Perplexity. Acceso a info en tiempo real. Requiere API Key en perplexity.ai',
            ],
            'deepseek' => [
                'label'    => 'DeepSeek',
                'icon'     => '🟡',
                'requires' => 'api_key',
                'cost'     => 'Muy económico',
                'models'   => [
                    'deepseek-chat'     => 'DeepSeek Chat — uso general',
                    'deepseek-reasoner' => 'DeepSeek Reasoner (R1) — razonamiento',
                ],
                'default_model' => 'deepseek-chat',
                'note'     => 'API de DeepSeek. Muy económico. Requiere API Key en platform.deepseek.com',
            ],
        ];
    }

    /* ── Proveedor y modelo activos ───────────────────────────────── */

    public static function active_provider_id(): string {
        $s = get_option( 'ckg_settings', [] );
        return $s['llm_provider'] ?? 'ollama';
    }

    public static function active_model(): string {
        $s          = get_option( 'ckg_settings', [] );
        $provider   = self::active_provider_id();
        $providers  = self::providers();
        $default    = $providers[ $provider ]['default_model'] ?? '';

        // Para Ollama usamos el campo ya existente
        if ( $provider === 'ollama' ) {
            return class_exists( 'CKG_Ollama' ) ? CKG_Ollama::get_model_public() : $default;
        }

        return $s[ "llm_model_{$provider}" ] ?? $default;
    }

    public static function active_provider(): array {
        $id       = self::active_provider_id();
        $all      = self::providers();
        $provider = $all[ $id ] ?? $all['ollama'];
        return array_merge( $provider, [
            'id'           => $id,
            'active_model' => self::active_model(),
        ] );
    }

    /* ── Punto de entrada unificado ───────────────────────────────── */

    /**
     * Envía un prompt al proveedor activo y devuelve la respuesta.
     * @param string $prompt   Texto del prompt
     * @param array  $ctx      Contexto adicional (product_name, brand, etc.)
     * @param array  $options  Overrides opcionales (temperature, max_tokens, etc.)
     */
    public static function complete( string $prompt, array $ctx = [], array $options = [] ) {
        $provider = self::active_provider_id();

        switch ( $provider ) {
            case 'ollama':
                return class_exists( 'CKG_Ollama' )
                    ? CKG_Ollama::improve_raw( $prompt, $ctx )
                    : new WP_Error( 'no_ollama', 'Clase CKG_Ollama no disponible.' );

            case 'openai':
                return self::call_openai( $prompt, $options );

            case 'anthropic':
                return self::call_anthropic( $prompt, $options );

            case 'perplexity':
                return self::call_perplexity( $prompt, $options );

            case 'deepseek':
                return self::call_deepseek( $prompt, $options );

            default:
                return new WP_Error( 'unknown_provider', "Proveedor '$provider' no reconocido." );
        }
    }

    /**
     * Mejora una sección de contenido (wrapper del sistema de secciones de Ollama).
     * Para proveedores de pago usa el mismo prompt system pero con su API.
     */
    public static function improve( string $section, string $content, array $ctx = [] ) {
        if ( empty( trim( $content ) ) ) {
            return new WP_Error( 'empty', 'El campo está vacío.' );
        }

        $provider = self::active_provider_id();

        // Ollama usa su propio método con prompts optimizados
        if ( $provider === 'ollama' ) {
            return class_exists( 'CKG_Ollama' )
                ? CKG_Ollama::improve( $section, $content, $ctx )
                : new WP_Error( 'no_ollama', 'Clase CKG_Ollama no disponible.' );
        }

        // Para el resto, construir el prompt del sistema de secciones
        $prompt = self::build_section_prompt( $section, $content, $ctx );
        return self::complete( $prompt, $ctx );
    }

    /**
     * Prompt directo al proveedor activo.
     */
    public static function improve_raw( string $prompt, array $ctx = [] ) {
        return self::complete( $prompt, $ctx );
    }

    /* ── Test de conectividad ─────────────────────────────────────── */

    public static function ping(): bool {
        $provider = self::active_provider_id();

        if ( $provider === 'ollama' ) {
            return class_exists( 'CKG_Ollama' ) && CKG_Ollama::ping();
        }

        // Para proveedores de pago: verificar que hay API key configurada
        $s   = get_option( 'ckg_settings', [] );
        $key = $s[ "llm_key_{$provider}" ] ?? '';
        return ! empty( trim( $key ) );
    }

    public static function ping_with_test(): array {
        $provider = self::active_provider_id();

        if ( $provider === 'ollama' ) {
            $online = class_exists( 'CKG_Ollama' ) && CKG_Ollama::ping();
            $models = $online && class_exists( 'CKG_Ollama' ) ? CKG_Ollama::list_models() : [];
            return [
                'ok'      => $online,
                'message' => $online ? 'Ollama online ✅' : 'Ollama no responde ❌',
                'models'  => $models,
            ];
        }

        $s   = get_option( 'ckg_settings', [] );
        $key = trim( $s[ "llm_key_{$provider}" ] ?? '' );

        if ( empty( $key ) ) {
            return [
                'ok'      => false,
                'message' => 'API Key no configurada. Añádela en Ajustes.',
                'models'  => [],
            ];
        }

        // Test real con una llamada mínima
        $result = self::complete( 'Responde solo con: OK', [], [ 'max_tokens' => 5 ] );
        $ok     = ! is_wp_error( $result ) && ! empty( $result );

        return [
            'ok'      => $ok,
            'message' => $ok
                ? ucfirst( $provider ) . ' conectado ✅ — modelo: ' . self::active_model()
                : 'Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'respuesta vacía' ),
            'models'  => [],
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       IMPLEMENTACIONES POR PROVEEDOR
    ════════════════════════════════════════════════════════════════ */

    /* ── OpenAI ──────────────────────────────────────────────────── */

    private static function call_openai( string $prompt, array $options = [] ) {
        $s     = get_option( 'ckg_settings', [] );
        $key   = trim( $s['llm_key_openai'] ?? '' );
        $model = self::active_model();

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'OpenAI API Key no configurada. Ve a CMSKart → Ajustes.' );
        }

        $gpu   = class_exists( 'CKG_Ollama' ) ? CKG_Ollama::gpu_profile() : [];
        $max_t = $options['max_tokens'] ?? ( $gpu['num_predict'] ?? 2048 );
        $temp  = $options['temperature'] ?? ( $gpu['temperature'] ?? 0.65 );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Eres un redactor SEO experto en karting de competición para CMSKart.es. Idioma: español de España. Responde solo con el texto solicitado.',
                    ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'max_tokens'  => $max_t,
                'temperature' => $temp,
            ] ),
        ] );

        return self::parse_openai_response( $response );
    }

    private static function parse_openai_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'openai_conn', 'Error de conexión con OpenAI: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 ) return new WP_Error( 'openai_auth', 'API Key de OpenAI no válida o expirada.' );
        if ( $code === 429 ) return new WP_Error( 'openai_rate', 'Límite de peticiones OpenAI alcanzado. Espera un momento.' );
        if ( $code === 402 ) return new WP_Error( 'openai_billing', 'Sin crédito en tu cuenta de OpenAI.' );
        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            return new WP_Error( 'openai_http', "OpenAI error: $msg" );
        }

        $text = trim( $body['choices'][0]['message']['content'] ?? '' );
        return $text ?: new WP_Error( 'openai_empty', 'OpenAI devolvió respuesta vacía.' );
    }

    /* ── Anthropic Claude ────────────────────────────────────────── */

    private static function call_anthropic( string $prompt, array $options = [] ) {
        $s     = get_option( 'ckg_settings', [] );
        $key   = trim( $s['llm_key_anthropic'] ?? '' );
        $model = self::active_model();

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'Anthropic API Key no configurada. Ve a CMSKart → Ajustes.' );
        }

        $gpu   = class_exists( 'CKG_Ollama' ) ? CKG_Ollama::gpu_profile() : [];
        $max_t = $options['max_tokens'] ?? min( $gpu['num_predict'] ?? 2048, 4096 );
        $temp  = $options['temperature'] ?? ( $gpu['temperature'] ?? 0.65 );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => $model,
                'max_tokens' => $max_t,
                'system'     => 'Eres un redactor SEO experto en karting de competición para CMSKart.es. Idioma: español de España. Responde solo con el texto solicitado, sin preámbulos.',
                'messages'   => [
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'temperature' => $temp,
            ] ),
        ] );

        return self::parse_anthropic_response( $response );
    }

    private static function parse_anthropic_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'anthropic_conn', 'Error de conexión con Anthropic: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 ) return new WP_Error( 'anthropic_auth', 'API Key de Anthropic no válida.' );
        if ( $code === 429 ) return new WP_Error( 'anthropic_rate', 'Límite de peticiones Anthropic alcanzado.' );
        if ( $code === 529 ) return new WP_Error( 'anthropic_overload', 'Anthropic sobrecargado. Inténtalo en unos segundos.' );
        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            return new WP_Error( 'anthropic_http', "Anthropic error: $msg" );
        }

        $text = trim( $body['content'][0]['text'] ?? '' );
        return $text ?: new WP_Error( 'anthropic_empty', 'Anthropic devolvió respuesta vacía.' );
    }

    /* ── Perplexity ──────────────────────────────────────────────── */

    private static function call_perplexity( string $prompt, array $options = [] ) {
        $s     = get_option( 'ckg_settings', [] );
        $key   = trim( $s['llm_key_perplexity'] ?? '' );
        $model = self::active_model();

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'Perplexity API Key no configurada. Ve a CMSKart → Ajustes.' );
        }

        $gpu   = class_exists( 'CKG_Ollama' ) ? CKG_Ollama::gpu_profile() : [];
        $max_t = $options['max_tokens'] ?? ( $gpu['num_predict'] ?? 2048 );
        $temp  = $options['temperature'] ?? ( $gpu['temperature'] ?? 0.65 );

        $response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Eres un redactor SEO experto en karting de competición para CMSKart.es. Idioma: español de España. Responde solo con el texto solicitado.',
                    ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'max_tokens'  => $max_t,
                'temperature' => $temp,
            ] ),
        ] );

        // Perplexity usa el mismo formato de respuesta que OpenAI
        return self::parse_openai_response( $response );
    }

    /* ── DeepSeek ────────────────────────────────────────────────── */

    private static function call_deepseek( string $prompt, array $options = [] ) {
        $s     = get_option( 'ckg_settings', [] );
        $key   = trim( $s['llm_key_deepseek'] ?? '' );
        $model = self::active_model();

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'DeepSeek API Key no configurada. Ve a CMSKart → Ajustes.' );
        }

        $gpu   = class_exists( 'CKG_Ollama' ) ? CKG_Ollama::gpu_profile() : [];
        $max_t = $options['max_tokens'] ?? ( $gpu['num_predict'] ?? 2048 );
        $temp  = $options['temperature'] ?? ( $gpu['temperature'] ?? 0.65 );

        $response = wp_remote_post( 'https://api.deepseek.com/chat/completions', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Eres un redactor SEO experto en karting de competición para CMSKart.es. Idioma: español de España. Responde solo con el texto solicitado.',
                    ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'max_tokens'  => $max_t,
                'temperature' => $temp,
                'stream'      => false,
            ] ),
        ] );

        // DeepSeek usa el mismo formato que OpenAI
        return self::parse_openai_response( $response );
    }

    /* ── Prompt de secciones (para proveedores de pago) ──────────── */

    private static function build_section_prompt( string $section, string $content, array $ctx ): string {
        $name  = $ctx['product_name'] ?? 'producto de karting';
        $brand = $ctx['brand']        ? " de la marca {$ctx['brand']}" : '';
        $homo  = ! empty( $ctx['homologaciones'] )
            ? 'Homologaciones: ' . implode( ', ', $ctx['homologaciones'] ) . '.'
            : '';

        $base = "Producto: $name$brand. $homo\n\nReglas: español de España, tono profesional para pilotos de karting, no inventes datos técnicos.\n\n";

        $tasks = [
            'short_desc'      => "Reescribe esta descripción corta (máx 90 palabras, impactante, ventaja principal):\n\n",
            'intro'           => "Mejora este párrafo de introducción (150-200 palabras, empieza con el producto en negrita):\n\n",
            'caracteristica'  => "Mejora este texto de característica técnica (3-5 frases, técnico y persuasivo):\n\n",
            'conclusion'      => "Mejora esta conclusión (120-160 palabras, 3 puntos fuertes + CTA directo):\n\n",
            'faq_answer'      => "Mejora esta respuesta de FAQ (4-6 frases, directa y técnicamente precisa):\n\n",
            'compat_nota'     => "Mejora esta nota de compatibilidad (2-3 frases, clara y útil):\n\n",
            'seo_title'       => "Genera un SEO title (EXACTAMENTE 50-60 caracteres). Responde SOLO el title:\n\nReferencia: ",
            'seo_description' => "Genera una meta description (EXACTAMENTE 140-155 caracteres). Responde SOLO la meta description:\n\nReferencia: ",
        ];

        $task = $tasks[ $section ] ?? "Mejora este texto manteniendo estructura y longitud aproximada:\n\n";
        return $base . $task . $content;
    }

    /* ════════════════════════════════════════════════════════════════
       PERPLEXITY — BÚSQUEDAS EN TIEMPO REAL
       Aprovecha la capacidad de búsqueda web de sonar/sonar-pro
    ════════════════════════════════════════════════════════════════ */

    /**
     * Busca información actual de un producto en internet vía Perplexity.
     * Devuelve datos estructurados: specs, precio, homologaciones, FAQ real.
     *
     * @param string $product_name  Nombre del producto
     * @param string $brand         Marca (opcional, mejora resultados)
     * @param string $focus         'full' | 'specs' | 'price' | 'homos' | 'faq'
     */
    public static function search_product( string $product_name, string $brand = '', string $focus = 'full' ): array|\WP_Error {
        $s   = get_option( 'ckg_settings', [] );
        $key = trim( $s['llm_key_perplexity'] ?? '' );

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'Perplexity API Key no configurada en CMSKart → Ajustes.' );
        }

        $name_full = $product_name . ( $brand ? ' ' . $brand : '' );

        $prompts = [
            'full' => <<<PROMPT
Busca información actual sobre el producto de karting "$name_full".
Necesito en formato JSON estructurado:
{
  "product_name": "nombre oficial completo",
  "brand": "marca",
  "price_eur": "precio en euros (número o rango)",
  "short_desc": "descripción de 2-3 frases en español",
  "specs": [{"key": "...", "value": "..."}, ...],
  "homologaciones": [{"tipo": "...", "codigo": "...", "vigente": true/false}],
  "faq": [{"pregunta": "...", "respuesta": "..."}],
  "competitors_prices": [{"tienda": "...", "precio": "...", "url": "..."}],
  "intro": "párrafo de introducción SEO en español de 150 palabras"
}
Responde SOLO el JSON, sin texto adicional.
PROMPT,
            'specs' => 'Busca las especificaciones técnicas detalladas de "' . $name_full . '" karting. Devuelve JSON: {"specs": [{"key": "...", "value": "..."}]}. Solo JSON.',
            'price' => 'Busca el precio actual de "' . $name_full . '" en tiendas de karting en España. Devuelve JSON: {"prices": [{"tienda": "...", "precio": "...", "url": "..."}], "precio_medio": "..."}. Solo JSON.',
            'homos' => 'Homologaciones vigentes (CIK-FIA, Snell, FIA) del "' . $name_full . '" en 2025. Devuelve JSON: {"homologaciones": [{"tipo": "...", "codigo": "...", "vigente": true, "expira": "..."}]}. Solo JSON.',
            'faq'  => 'Preguntas frecuentes de usuarios de karting sobre "' . $name_full . '". Las 5 más comunes con respuestas. JSON: {"faq": [{"pregunta": "...", "respuesta": "..."}]}. Solo JSON.',
        ];

        $prompt = $prompts[ $focus ] ?? $prompts['full'];

        // Usar sonar-pro si está disponible para mejor calidad en búsquedas
        $model_key = $s['llm_model_perplexity'] ?? 'sonar';

        $response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model_key,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Eres un experto en karting de competición. Respondes SIEMPRE en JSON válido sin texto adicional. Buscas información actualizada y real.',
                    ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'max_tokens'  => 2000,
                'temperature' => 0.1,
                'return_citations' => true,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'perplexity_conn', 'Error de conexión con Perplexity: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            return new WP_Error( 'perplexity_err', "Perplexity error: $msg" );
        }

        $text = trim( $body['choices'][0]['message']['content'] ?? '' );
        if ( empty( $text ) ) {
            return new WP_Error( 'perplexity_empty', 'Perplexity no devolvió respuesta.' );
        }

        // Parsear JSON
        $clean   = preg_replace( '/```json|```/', '', $text );
        $clean   = trim( $clean );

        $decoded = json_decode( $clean, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            // Intentar extraer JSON del texto (objeto o array)
            if ( preg_match( '/(\{.*\}|\[.*\])/s', $clean, $m ) ) {
                $decoded = json_decode( $m[0], true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                    $decoded = null;
                }
            } else {
                $decoded = null;
            }
        }

        if ( ! is_array( $decoded ) ) {
            // Devolver como texto crudo si no se puede parsear
            return [
                '_raw'      => $text,
                '_parsed'   => false,
                'citations' => $body['citations'] ?? [],
            ];
        }

        // Añadir fuentes si las hay
        $decoded['_citations'] = $body['citations'] ?? [];
        $decoded['_parsed']    = true;

        return $decoded;
    }

    /**
     * Verifica si una homologación específica sigue vigente en 2025.
     *
     * @param string $product_name  Nombre del producto
     * @param string $tipo          Tipo de homologación (CIK-FIA, Snell SA2025, etc.)
     * @param string $codigo        Código/versión de la homologación
     */
    public static function verify_homologacion( string $product_name, string $tipo, string $codigo ): array|\WP_Error {
        $s   = get_option( 'ckg_settings', [] );
        $key = trim( $s['llm_key_perplexity'] ?? '' );

        if ( empty( $key ) ) {
            return new WP_Error( 'no_key', 'Perplexity API Key no configurada.' );
        }

        $prompt = '¿La homologación ' . $tipo . ' ' . $codigo . ' del "' . $product_name . '" está vigente para karting en 2025? '
                . '¿Cuándo expira? ¿Versión más nueva requerida? '
                . 'JSON: {"vigente": true/false, "expira": "...", "nota": "...", "version_actual": "..."}. Solo JSON.';

        $response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $s['llm_model_perplexity'] ?? 'sonar',
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'Responde SOLO en JSON válido.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'max_tokens'  => 400,
                'temperature' => 0.1,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) return new WP_Error( 'err', $body['error']['message'] ?? "HTTP $code" );

        $text    = trim( $body['choices'][0]['message']['content'] ?? '' );
        $clean   = trim( preg_replace( '/```json|```/', '', $text ) );
        $decoded = json_decode( $clean, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            if ( preg_match( '/(\{.*\}|\[.*\])/s', $clean, $m ) ) {
                $decoded = json_decode( $m[0], true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                    $decoded = null;
                }
            } else {
                $decoded = null;
            }
        }

        return is_array( $decoded ) ? $decoded : [ 'vigente' => null, 'nota' => $text ];
    }

    /**
     * Busca precios de competidores en tiempo real.
     *
     * @param string $product_name
     * @param string $brand
     */
    public static function search_competitor_prices( string $product_name, string $brand = '' ): array|\WP_Error {
        return self::search_product( $product_name, $brand, 'price' );
    }

    /**
     * Obtiene FAQ basadas en búsquedas reales de usuarios.
     */
    public static function search_real_faq( string $product_name, string $brand = '' ): array|\WP_Error {
        return self::search_product( $product_name, $brand, 'faq' );
    }

    /**
     * Detecta si Perplexity está configurado y disponible.
     */
    public static function perplexity_available(): bool {
        $s = get_option( 'ckg_settings', [] );
        return ! empty( trim( $s['llm_key_perplexity'] ?? '' ) );
    }
}