<?php
/**
 * CKG_Shortcodes
 * [ckg_accessories product_id="123"]
 * [ckg_catalog_btn url="..." label="..."]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Shortcodes {

    public static function init(): void {
        add_shortcode( 'ckg_accessories', [ __CLASS__, 'sc_accessories' ] );
        add_shortcode( 'ckg_catalog_btn', [ __CLASS__, 'sc_catalog_btn' ] );
    }

    public static function sc_accessories( $atts ): string {
        $atts = shortcode_atts( [ 'product_id' => 0 ], $atts );
        $id   = absint( $atts['product_id'] ) ?: get_the_ID();
        if ( ! $id ) return '';

        $json = get_post_meta( $id, '_ckg_accessories', true );
        if ( empty( $json ) ) return '';
        $acc = json_decode( $json, true );
        if ( ! is_array( $acc ) ) return '';

        ob_start();
        self::render_accessories( $acc );
        return ob_get_clean();
    }

    public static function sc_catalog_btn( $atts ): string {
        $atts = shortcode_atts( [ 'url' => '', 'label' => '📖 Ver ficha técnica completa' ], $atts );
        if ( empty( $atts['url'] ) ) return '';
        return '<div class="ckg-catalog-btn-wrap"><a href="' . esc_url( $atts['url'] ) . '" class="ckg-catalog-btn" target="_blank" rel="noopener">' . esc_html( $atts['label'] ) . '</a></div>';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RENDER: Accesorios/Complementos relacionados
    //  Con calculadora de precio total en tiempo real
    // ─────────────────────────────────────────────────────────────────────
    public static function render_accessories( array $accessories ): void {
        ?>
        <div class="ckg-accessories">
            <h4 class="ckg-acc-title">🔩 Complementos recomendados:</h4>

            <div class="ckg-acc-list">
                <?php foreach ( $accessories as $acc ) :
                    $price_fmt = number_format( (float) $acc['price'], 2, ',', '.' );
                    $is_free   = (float) $acc['price'] === 0.0;
                ?>
                <div class="ckg-acc-item">
                    <label class="ckg-acc-label">
                        <input
                            type="checkbox"
                            class="ckg-acc-check"
                            data-price="<?php echo esc_attr( $acc['price'] ); ?>"
                            data-name="<?php echo esc_attr( $acc['name'] ); ?>"
                        >
                        <?php if ( ! empty( $acc['image'] ) ) : ?>
                        <img
                            src="<?php echo esc_url( $acc['image'] ); ?>"
                            alt="<?php echo esc_attr( $acc['name'] ); ?>"
                            class="ckg-acc-img"
                            loading="lazy"
                        >
                        <?php endif; ?>
                        <span class="ckg-acc-info">
                            <span class="ckg-acc-name"><?php echo esc_html( $acc['name'] ); ?></span>
                            <?php if ( ! empty( $acc['description'] ) ) : ?>
                            <span class="ckg-acc-desc"><?php echo esc_html( $acc['description'] ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ckg-acc-price <?php echo $is_free ? 'ckg-acc-free' : ''; ?>">
                            <?php echo $is_free ? 'Gratis' : '+' . $price_fmt . '€'; ?>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ckg-total-row">
                <span class="ckg-total-label">
                    Precio total estimado
                    <small>(precio orientativo)</small>
                </span>
                <span class="ckg-total-amount">—</span>
            </div>
        </div>
        <?php
    }
}

CKG_Shortcodes::init();
