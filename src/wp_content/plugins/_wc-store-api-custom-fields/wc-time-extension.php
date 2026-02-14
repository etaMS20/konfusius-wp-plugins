<?php
/**
 * Plugin Name: Konfusius Store Product Time and Count Field Extender
 * Description: Adds custom fields "time_interval" and "default_instock_count" to WooCommerce products and variations. Saves these fields, appends "time_interval" to order items, and exposes both fields via the REST API.
 * Author: M. Schädle
 * Version: 1.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$time_interval_fieldname = 'time_interval';
$default_stock_fieldname = 'planned_stock';

/**
 * 1. UI: Add the input fields to Admin
 */
// Simple Products
add_action( 'woocommerce_product_options_general_product_data', function() use ($time_interval_fieldname, $default_stock_fieldname ) {
    global $post;
    $product = wc_get_product( $post->ID );
    echo '<div class="options_group">';
    if ( $product && $product->is_type( 'variable' ) ) {
        // Editable default stock for all variations
        woocommerce_wp_text_input( array(
            'id'          => $default_stock_fieldname,
            'label'       => __( 'Geplante Anzahl an Schichten für Varianten', 'woocommerce' ),
            'type'        => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min'  => '0'
            ),
            'desc_tip'    => true,
            'description' => __( 'Wird beim Speichern auf alle Varianten angewendet.', 'woocommerce' )
        ) );
        // Readonly sum of all variation stocks
        $sum = 0;
        $children = $product->get_children();
        foreach ( $children as $child_id ) {
            $sum += (int) get_post_meta( $child_id, $default_stock_fieldname, true );
        }
        woocommerce_wp_text_input( array(
            'id'                => $default_stock_fieldname . '_sum',
            'label'             => __( 'Summe aller geplanten Schichten', 'woocommerce' ),
            'type'              => 'number',
            'custom_attributes' => array( 'readonly' => 'readonly' ),
            'value'             => $sum,
            'desc_tip'          => true,
            'description'       => __( 'Summe der geplanten Anzahl an Schichten aller Varianten (informativ).', 'woocommerce' )
        ) );
    } else {
        woocommerce_wp_text_input( array(
            'id'          => $time_interval_fieldname,
            'label'       => __( 'Zeit Intervall für die Schicht', 'woocommerce' ),
            'description' => __( 'Zeitintervall für diese Schicht. Wird auch in Orders angezeigt.', 'woocommerce' ),
            'desc_tip'    => true
        ) );
        woocommerce_wp_text_input( array(
            'id'                => $default_stock_fieldname,
            'label'             => __( 'Geplante Anzahl an Schichten', 'woocommerce' ),
            'type'              => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min'  => '0'
            ),
            'desc_tip'    => true,
            'description' => __( 'Geplante Anzahl an Schichten. (informativ)', 'woocommerce' )
        ) );
    }
    echo '</div>';
});

// Variations
add_action( 'woocommerce_product_after_variable_attributes', function( $loop, $variation_data, $variation ) use ($time_interval_fieldname) {
    woocommerce_wp_text_input( array(
        'id'            => $time_interval_fieldname . '[' . $loop . ']',
        'label'         => __( 'Zeit Interval für die Schicht', 'woocommerce' ),
        'value'         => get_post_meta( $variation->ID, $time_interval_fieldname, true ),
        'wrapper_class' => 'form-row form-row-full',
    ) );
}, 10, 3 );

/**
 * 2. SAVE: Store the data securely
 */
add_action( 'woocommerce_process_product_meta', function( $post_id ) use ($default_stock_fieldname) {
    $product = wc_get_product( $post_id );
    if ( $product && $product->is_type( 'variable' ) ) {
        if ( isset( $_POST[$default_stock_fieldname] ) ) {
            $value = sanitize_text_field( $_POST[$default_stock_fieldname] );
            update_post_meta( $post_id, $default_stock_fieldname, $value );
            // Apply to all variations
            $children = $product->get_children();
            foreach ( $children as $child_id ) {
                update_post_meta( $child_id, $default_stock_fieldname, $value );
            }
        }
    } else {
        // Simple product: save as usual
        if ( isset( $_POST[$default_stock_fieldname] ) ) {
            update_post_meta( $post_id, $default_stock_fieldname, sanitize_text_field( $_POST[$default_stock_fieldname] ) );
        }
    }
}, 20 );

add_action( 'woocommerce_save_product_variation', function( $variation_id, $i ) use ($time_interval_fieldname) {
    if ( isset( $_POST[$time_interval_fieldname][$i] ) ) {
        update_post_meta( $variation_id, $time_interval_fieldname, sanitize_text_field( $_POST[$time_interval_fieldname][$i] ) );
    }
}, 10, 2 );

/**
 * 
 * 3. APPEND: Add to Order Item Meta (Non-overwriting)
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) use ($time_interval_fieldname) {
    $id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
    $val = get_post_meta( $id, $time_interval_fieldname, true );

    if ( ! empty( $val ) ) {
        // Appends to meta_data array; does not overwrite item properties
        $item->add_meta_data( 'Zeit Intervall für die Schicht', $val );
    }
}, 10, 4 );

/**
 * 4. API: Expose to REST API
 */
add_action( 'rest_api_init', function() use ($time_interval_fieldname, $default_stock_fieldname) {
    register_rest_field( array( 'product', 'product_variation' ), 'konfusius_shift', array(
        'get_callback' => function( $object ) use ($time_interval_fieldname, $default_stock_fieldname) {
            $id = isset( $object['id'] ) ? $object['id'] : $object['ID'];
            
            $time_val = get_post_meta( $id, $time_interval_fieldname, true );

            $stock_val = get_post_meta( $id, $default_stock_fieldname, true );
            
            $parent_id = wp_get_post_parent_id($id);
            if ( empty($stock_val) && $parent_id ) {
                $stock_val = get_post_meta( $parent_id, $default_stock_fieldname, true );
            }

            return array(
                $time_interval_fieldname => (string) $time_val,
                $default_stock_fieldname => (int) absint( $stock_val )
            );
        },
        'schema' => array(
            'type'       => 'object',
            'context'    => array( 'view', 'edit' ),
            'properties' => array(
                $time_interval_fieldname => array(
                    'type' => 'string',
                ),
                $default_stock_fieldname => array(
                    'type' => 'integer',
                ),
            ),
        )
    ) );
});

/**
 * 5. API: Expose to WooCommerce Store API
 */
add_action( 'woocommerce_blocks_loaded', function() use ($time_interval_fieldname, $default_stock_fieldname) {

	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}

    $data_callback = function( $product ) use ($time_interval_fieldname, $default_stock_fieldname) {
        $id = $product->get_id();

        $raw_stock = get_post_meta( $id, $default_stock_fieldname, true );
        $raw_time = get_post_meta( $id, $time_interval_fieldname, true );
        if ( ! empty( $raw_stock ) ) {
            $raw_stock = absint( $raw_stock );
        } else {
            $raw_stock = null;
        }

        if ( empty( $raw_time ) ) {
            $raw_time = null;
        }

        $data = array(
            $time_interval_fieldname    => $raw_time,
            $default_stock_fieldname    => $raw_stock,
            'variation_data'            => array()
        );

        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_available_variations('objects');
            foreach ( $variations as $variation ) {
                $v_id = $variation->get_id();
                $v_stock = get_post_meta( $v_id, $default_stock_fieldname, true );
                $final_stock = ( $v_stock !== '' ) ? absint($v_stock) : $data[$default_stock_fieldname];

                $raw_v_time = get_post_meta( $v_id, $time_interval_fieldname, true );
                if ( empty( $raw_v_time ) ) {
                    $raw_v_time = $raw_time;
                }

                $data['variation_data'][] = array(
                    'id'                     => (int) $v_id,
                    'name'                   => (string) $variation->get_name(),
                    $time_interval_fieldname => (string) $raw_v_time,
                    $default_stock_fieldname => $final_stock
                );
            }
            // Now sum the stocks
            $sum = 0;
            foreach ( $data['variation_data'] as $v ) {
                $sum += isset($v[$default_stock_fieldname]) ? (int)$v[$default_stock_fieldname] : 0;
            }
            $data['sum_planned_variations'] = $sum;
        }

        
    return $data;
    };

    $schema_callback = function() use ($time_interval_fieldname, $default_stock_fieldname) {
        return array(
            'properties' => array(
                $time_interval_fieldname => array( 'type' => 'string', 'context' => array( 'view', 'edit' ), 'readonly' => true, 'nullable' => true ),
                $default_stock_fieldname => array( 'type' => 'integer', 'context' => array( 'view', 'edit' ), 'readonly' => true, 'nullable' => true ),
                'variation_data' => array(
                    'type'     => 'array',
                    'context'  => array( 'view', 'edit' ),
                    'readonly' => true,
                    'items'    => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'                    => array( 'type' => 'integer' ),
                            'name'                  => array( 'type'=> 'string' ),
                            $time_interval_fieldname => array( 'type' => 'string', 'nullable' => true ),
                            $default_stock_fieldname => array( 'type' => 'integer', 'nullable' => true ),
                        ),
                    ),
                ),
                'sum_planned_variations' => array( 'type' => 'integer', 'context' => array( 'view', 'edit' ), 'readonly' => true, 'nullable' => true ),
            ),
        );
    };

    // register the endpoint data
    woocommerce_store_api_register_endpoint_data(
        array(
            'endpoint'        => 'product',
            'namespace'       => 'konfusius_shift',
            'data_callback'   => $data_callback,
            'schema_callback' => $schema_callback,
            'schema_type'     => ARRAY_A,
        )
    );
});