<?php
/**
 * CKG_Revisions — Historial de versiones de contenido de productos.
 * Guarda una revision antes de cada cambio del enriquecedor.
 * La tabla sobrevive a reinstalaciones del plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Revisions {

    const TABLE = 'ckg_revisions';
    const MAX_REVISIONS_PER_PRODUCT = 10; // maximo historico por producto

    /* ── Crear tabla ─────────────────────────────────────────────────── */

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id    BIGINT UNSIGNED NOT NULL,
            product_name  VARCHAR(255)    NOT NULL DEFAULT '',
            accion        VARCHAR(50)     NOT NULL DEFAULT 'enriquecimiento',
            desc_antes    LONGTEXT,
            desc_corta_antes TEXT,
            desc_despues  LONGTEXT,
            desc_corta_despues TEXT,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ── Guardar revision ────────────────────────────────────────────── */

    public static function save(
        int    $product_id,
        string $desc_antes,
        string $desc_corta_antes,
        string $desc_despues    = '',
        string $desc_corta_despues = '',
        string $accion          = 'enriquecimiento'
    ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $product_name = get_the_title( $product_id ) ?: 'Producto #' . $product_id;

        $wpdb->insert( $table, [
            'product_id'         => $product_id,
            'product_name'       => mb_substr( $product_name, 0, 255 ),
            'accion'             => $accion,
            'desc_antes'         => $desc_antes,
            'desc_corta_antes'   => $desc_corta_antes,
            'desc_despues'       => $desc_despues,
            'desc_corta_despues' => $desc_corta_despues,
            'created_at'         => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

        $revision_id = (int) $wpdb->insert_id;

        // Limpiar revisiones antiguas si supera el maximo
        self::cleanup_old_revisions( $product_id );

        return $revision_id;
    }

    /* ── Actualizar desc_despues en una revision ─────────────────────── */

    public static function update_after( int $revision_id, string $desc_despues, string $desc_corta_despues ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'desc_despues' => $desc_despues, 'desc_corta_despues' => $desc_corta_despues ],
            [ 'id' => $revision_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /* ── Obtener revisiones de un producto ───────────────────────────── */

    public static function get_for_product( int $product_id, int $limit = 10 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d ORDER BY created_at DESC LIMIT %d",
            $product_id, $limit
        ), ARRAY_A ) ?: [];
    }

    /* ── Obtener una revision por ID ─────────────────────────────────── */

    public static function get( int $revision_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", $revision_id
        ), ARRAY_A );
        return $row ?: null;
    }

    /* ── Restaurar una revision ──────────────────────────────────────── */

    public static function restore( int $revision_id ): bool {
        $rev = self::get( $revision_id );
        if ( ! $rev ) return false;

        $product = wc_get_product( (int) $rev['product_id'] );
        if ( ! $product ) return false;

        // Guardar una nueva revision antes de restaurar
        self::save(
            (int) $rev['product_id'],
            $product->get_description(),
            $product->get_short_description(),
            $rev['desc_antes'],
            $rev['desc_corta_antes'],
            'restauracion'
        );

        $product->set_description( $rev['desc_antes'] );
        $product->set_short_description( $rev['desc_corta_antes'] );
        $product->save();

        // Quitar el flag de generado para que pueda re-enriquecerse
        delete_post_meta( (int) $rev['product_id'], '_ckg_generated' );

        return true;
    }

    /* ── Listado reciente para el panel ──────────────────────────────── */

    public static function get_recent( int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit
        ), ARRAY_A ) ?: [];
    }

    /* ── Estadisticas ────────────────────────────────────────────────── */

    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return [
            'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
            'products' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM $table" ),
            'today'    => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s", current_time('Y-m-d')
            ) ),
        ];
    }

    /* ── Limpiar revisiones antiguas ─────────────────────────────────── */

    private static function cleanup_old_revisions( int $product_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $max   = self::MAX_REVISIONS_PER_PRODUCT;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE product_id = %d
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM $table
                     WHERE product_id = %d
                     ORDER BY created_at DESC
                     LIMIT %d
                 ) t
             )",
            $product_id, $product_id, $max
        ) );
    }

    /* ── Borrar todas las revisiones de un producto ──────────────────── */

    public static function delete_for_product( int $product_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'product_id' => $product_id ], [ '%d' ] );
    }
}
