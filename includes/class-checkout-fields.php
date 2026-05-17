<?php
/**
 * CKG_Checkout_Fields
 * Añade campos de personalización en la ficha de producto del checkout.
 *
 * Cuando un producto tiene colores o configuraciones que no cambian el precio,
 * muestra un campo de texto (o select) en la página del producto antes del
 * botón "Añadir al carrito". El texto elegido se guarda en el pedido.
 *
 * Configuración por producto: meta _ckg_custom_field_label y _ckg_custom_field_options
 * El plugin lo rellena automáticamente al crear si hay atributos de tipo color/material.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Checkout_Fields {

    public static function init(): void {
        // Mostrar campo en la ficha de producto
        add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_product_field' ] );

        // Validar que se ha rellenado si es obligatorio
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_field' ], 10, 3 );

        // Guardar en el item del carrito
        add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'save_to_cart' ], 10, 3 );

        // Mostrar en el carrito
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_in_cart' ], 10, 2 );

        // Guardar en el pedido al hacer checkout
        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'save_to_order' ], 10, 4 );

        // Mostrar en el email y en el admin del pedido
        add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'display_in_order' ], 10, 3 );
    }

    /* ── Renderizar campo en la ficha del producto ───────────────────── */

    public static function render_product_field(): void {
        global $product;
        if ( ! $product ) return;

        $product_id = $product->get_id();
        $label      = get_post_meta( $product_id, '_ckg_custom_field_label', true );
        $options    = get_post_meta( $product_id, '_ckg_custom_field_options', true );
        $required   = (bool) get_post_meta( $product_id, '_ckg_custom_field_required', true );

        if ( empty( $label ) ) return; // Sin campo configurado

        $options_arr = array_filter( array_map( 'trim', explode( ',', $options ) ) );

        echo '<div class="ckg-product-custom-field" style="margin-bottom:16px">';
        echo '<label for="ckg_custom_field" style="display:block;margin-bottom:6px;font-weight:600">';
        echo esc_html( $label );
        if ( $required ) echo ' <span style="color:#c0392b">*</span>';
        echo '</label>';

        if ( ! empty( $options_arr ) ) {
            // Select con opciones predefinidas
            echo '<select name="ckg_custom_field" id="ckg_custom_field" class="ckg-custom-select"
                style="min-width:200px;padding:8px 12px;border:1px solid #ddd;border-radius:4px"' .
                ( $required ? ' required' : '' ) . '>';
            echo '<option value="">-- Selecciona --</option>';
            foreach ( $options_arr as $opt ) {
                echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
            }
            echo '</select>';
        } else {
            // Campo de texto libre
            echo '<input type="text" name="ckg_custom_field" id="ckg_custom_field"
                placeholder="' . esc_attr( $label ) . '"
                style="min-width:200px;padding:8px 12px;border:1px solid #ddd;border-radius:4px"' .
                ( $required ? ' required' : '' ) . '>';
        }

        echo '</div>';
    }

    /* ── Validación ──────────────────────────────────────────────────── */

    public static function validate_field( bool $valid, int $product_id, int $qty ): bool {
        $required = (bool) get_post_meta( $product_id, '_ckg_custom_field_required', true );
        $label    = get_post_meta( $product_id, '_ckg_custom_field_label', true );

        if ( $required && empty( $_POST['ckg_custom_field'] ) ) {
            wc_add_notice(
                sprintf( __( 'Por favor, especifica: %s', 'ckg' ), $label ),
                'error'
            );
            $valid = false;
        }

        return $valid;
    }

    /* ── Guardar en el carrito ───────────────────────────────────────── */

    public static function save_to_cart( array $cart_item_data, int $product_id, int $variation_id ): array {
        $label = get_post_meta( $product_id, '_ckg_custom_field_label', true );
        $value = sanitize_text_field( $_POST['ckg_custom_field'] ?? '' );

        if ( $label && $value ) {
            $cart_item_data['ckg_custom_field'] = [
                'label' => $label,
                'value' => $value,
            ];
            // Hacer el item único para que no se agrupe con otros sin personalización
            $cart_item_data['unique_key'] = md5( microtime() . rand() );
        }

        return $cart_item_data;
    }

    /* ── Mostrar en el carrito ───────────────────────────────────────── */

    public static function display_in_cart( array $item_data, array $cart_item ): array {
        if ( ! empty( $cart_item['ckg_custom_field'] ) ) {
            $item_data[] = [
                'key'   => esc_html( $cart_item['ckg_custom_field']['label'] ),
                'value' => esc_html( $cart_item['ckg_custom_field']['value'] ),
            ];
        }
        return $item_data;
    }

    /* ── Guardar en el pedido ────────────────────────────────────────── */

    public static function save_to_order(
        \WC_Order_Item_Product $item,
        string $cart_item_key,
        array $values,
        \WC_Order $order
    ): void {
        if ( ! empty( $values['ckg_custom_field'] ) ) {
            $item->add_meta_data(
                $values['ckg_custom_field']['label'],
                $values['ckg_custom_field']['value'],
                true
            );
        }
    }

    /* ── Mostrar en el email y admin del pedido ──────────────────────── */

    public static function display_in_order( int $item_id, $item, $order ): void {
        $label = $item->get_meta( '_ckg_custom_field_label' );
        $value = $item->get_meta( '_ckg_custom_field_value' );
        if ( $label && $value ) {
            echo '<br><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value );
        }
    }

    /* ── Configurar campo para un producto desde el plugin ───────────── */

    /**
     * Llamar desde WooCreator al crear el producto si hay atributos de color/material.
     *
     * @param int    $product_id
     * @param string $label    Ej: "Color", "Material", "Configuracion"
     * @param string $options  Valores separados por coma. Ej: "Rojo, Azul, Negro"
     * @param bool   $required Si el campo es obligatorio al comprar
     */
    public static function set_product_field( int $product_id, string $label, string $options = '', bool $required = false ): void {
        update_post_meta( $product_id, '_ckg_custom_field_label',    sanitize_text_field( $label ) );
        update_post_meta( $product_id, '_ckg_custom_field_options',  sanitize_text_field( $options ) );
        update_post_meta( $product_id, '_ckg_custom_field_required', $required ? 1 : 0 );
    }

    /**
     * Elimina el campo personalizado de un producto.
     */
    public static function remove_product_field( int $product_id ): void {
        delete_post_meta( $product_id, '_ckg_custom_field_label' );
        delete_post_meta( $product_id, '_ckg_custom_field_options' );
        delete_post_meta( $product_id, '_ckg_custom_field_required' );
    }
}
