<?php
/**
 * CKG_Scheduler
 *
 * Sistema de cola para generación de Web Stories vía WP-Cron.
 * Procesa 1 producto cada 6 horas: busca productos enriquecidos
 * sin Story y genera la historia automáticamente.
 *
 * Prioriza los productos más antiguos (FIFO).
 * Registra éxitos y errores en el log del plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Scheduler {

    const CRON_HOOK     = 'ckg_generate_story_cron';
    const CRON_INTERVAL = 'ckg_six_hours';
    const LOG_OPTION    = 'ckg_scheduler_log';
    const LOG_MAX       = 50; // máximo de entradas en el log

    /* ── Inicialización ──────────────────────────────────────────────── */

    public static function init(): void {
        // Registrar intervalo de 6 horas
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );

        // Programar el evento si no existe
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }

        // Enganchar el procesador de cola
        add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );

        // Registrar tamaño de imagen optimizado para Web Stories (9:16)
        add_action( 'after_setup_theme', [ __CLASS__, 'register_image_sizes' ] );
    }

    /* ── Registrar intervalo ─────────────────────────────────────────── */

    public static function add_cron_interval( array $schedules ): array {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Cada 6 horas', 'cmskart-product-generator' ),
        ];
        return $schedules;
    }

    /* ── Tamaño de imagen para Web Stories ───────────────────────────── */

    public static function register_image_sizes(): void {
        // Formato vertical 9:16 requerido por Google Web Stories
        add_image_size( 'web-story-poster', 440, 660, true );
    }

    /* ── Procesador de cola ──────────────────────────────────────────── */

    public static function process_queue(): void {
        if ( ! class_exists( 'CKG_Web_Story' ) ) {
            self::log( 'ERROR', 0, 'Clase CKG_Web_Story no disponible.' );
            return;
        }

        if ( ! CKG_Web_Story::plugin_active() ) {
            self::log( 'ERROR', 0, 'Plugin Web Stories de Google no está activo.' );
            return;
        }

        // Buscar el producto más antiguo que:
        // 1. Ha sido enriquecido por el plugin (_ckg_generated = 1)
        // 2. NO tiene ya una Web Story creada (_ckg_web_story_id no existe)
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC', // FIFO: el más antiguo primero
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_ckg_generated',
                    'value'   => '1',
                    'compare' => '=',
                ],
                [
                    'key'     => '_ckg_web_story_id',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $query = new WP_Query( $args );

        if ( ! $query->have_posts() ) {
            self::log( 'INFO', 0, 'Cola vacía: no hay productos pendientes de Story.' );
            wp_reset_postdata();
            return;
        }

        $query->the_post();
        $product_id = get_the_ID();
        wp_reset_postdata();

        // Reconstruir $data desde los meta guardados por el enriquecedor
        $data = self::build_data_from_meta( $product_id );

        // Generar la Web Story
        $result = CKG_Web_Story::create_for_product( $product_id, $data );

        if ( is_wp_error( $result ) ) {
            self::log( 'ERROR', $product_id, $result->get_error_message() );
            // Marcar el producto para no reintentarlo indefinidamente
            update_post_meta( $product_id, '_ckg_story_error', $result->get_error_message() );
        } else {
            self::log( 'OK', $product_id, 'Story creada con ID ' . $result . ' para: ' . get_the_title( $product_id ) );
            // Eliminar marca de error si existía
            delete_post_meta( $product_id, '_ckg_story_error' );
        }
    }

    /* ── Reconstruir $data desde meta del producto ───────────────────── */

    private static function build_data_from_meta( int $product_id ): array {
        $product = wc_get_product( $product_id );

        return [
            'product_name' => $product ? $product->get_name() : get_the_title( $product_id ),
            'brand'        => get_post_meta( $product_id, '_ckg_brand',          true ) ?: '',
            'specs'        => json_decode( get_post_meta( $product_id, '_ckg_specs',         true ) ?: '[]', true ) ?: [],
            'homologacion' => json_decode( get_post_meta( $product_id, '_ckg_homologaciones', true ) ?: '[]', true ) ?: [],
            'seo_title'    => get_post_meta( $product_id, 'rank_math_title',       true ) ?: '',
            'seo_keywords' => get_post_meta( $product_id, 'rank_math_focus_keyword', true ) ?: '',
        ];
    }

    /* ── Log de actividad ────────────────────────────────────────────── */

    public static function log( string $level, int $product_id, string $message ): void {
        $log = get_option( self::LOG_OPTION, [] );

        array_unshift( $log, [
            'time'       => current_time( 'mysql' ),
            'level'      => $level,
            'product_id' => $product_id,
            'message'    => $message,
        ] );

        // Limitar tamaño del log
        if ( count( $log ) > self::LOG_MAX ) {
            $log = array_slice( $log, 0, self::LOG_MAX );
        }

        update_option( self::LOG_OPTION, $log, false );

        // También a error_log de PHP para debugging
        if ( $level === 'ERROR' ) {
            error_log( "CKG_Scheduler [$level] Producto $product_id: $message" );
        }
    }

    /* ── Obtener log para mostrar en admin ───────────────────────────── */

    public static function get_log(): array {
        return get_option( self::LOG_OPTION, [] );
    }

    /* ── Obtener productos en cola ───────────────────────────────────── */

    public static function get_queue_count(): int {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_ckg_generated',    'value' => '1', 'compare' => '=' ],
                [ 'key' => '_ckg_web_story_id', 'compare' => 'NOT EXISTS' ],
            ],
        ];
        $ids = get_posts( $args );
        return count( $ids );
    }

    /* ── Forzar ejecución manual ─────────────────────────────────────── */

    public static function run_now(): void {
        do_action( self::CRON_HOOK );
    }

    /* ── Limpiar al desactivar el plugin ─────────────────────────────── */

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }
}
