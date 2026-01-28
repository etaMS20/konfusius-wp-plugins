<?php
/**
 * Plugin Name: Konfusius Store Product Time and Count Field Extender
 * Description: Adds custom fields "time_interval" and "default_instock_count" to WooCommerce products and variations. Saves these fields, appends "time_interval" to order items, and exposes both fields via the REST API.
 * Author: M. Schädle
 * Version: 1.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$time_interval_fieldname = 'time_interval';
$default_stock_fieldname = 'default_instock_count';

/**
 * 1. UI: Add the input fields to Admin
 */
// Simple Products
add_action( 'woocommerce_product_options_general_product_data', function() use ($time_interval_fieldname, $default_stock_fieldname ) {
    echo '<div class="options_group">';
    woocommerce_wp_text_input( array(
        'id'          => $time_interval_fieldname,
        'label'       => __( 'Zeit Intervall für die Schicht', 'woocommerce' ),
        'description' => __( 'Zeitintervall für diese Schicht. Wird auch in Orders angezeigt.', 'woocommerce' ),
        'desc_tip'    => true
    ) );
    woocommerce_wp_text_input( array(
        'id'                => $default_stock_fieldname,
        'label'             => __( 'Standard Anzahl an Schichten', 'woocommerce' ),
        'type'              => 'number',
        'custom_attributes' => array(
            'step' => '1',
            'min'  => '0'
        ),
        'desc_tip'    => true,
        'description' => __( 'Standard Anzahl an Schichten. (nur informativ)', 'woocommerce' )
    ) );
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
add_action( 'woocommerce_process_product_meta', function( $post_id ) use ($time_interval_fieldname, $default_stock_fieldname) {
    if ( isset( $_POST[$time_interval_fieldname] ) ) {
        update_post_meta( $post_id, $time_interval_fieldname, sanitize_text_field( $_POST[$time_interval_fieldname] ) );
    }
    if ( isset( $_POST[$default_stock_fieldname] ) ) {
        // Use filter_var or floatval if you need decimals, or absint for whole numbers
        update_post_meta( $post_id, $default_stock_fieldname, sanitize_text_field( $_POST[$default_stock_fieldname] ) );
    }
});

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
            
            // Get time_interval from the specific item (product or variation)
            $time_val = get_post_meta( $id, $time_interval_fieldname, true );

            // Get the info_number. If it's a variation, look at the parent.
            $stock_val = get_post_meta( $id, $default_stock_fieldname, true );
            
            // IF current ID is a variation and info_val is empty, get parent info
            $parent_id = wp_get_post_parent_id($id);
            if ( empty($stock_val) && $parent_id ) {
                $stock_val = get_post_meta( $parent_id, $default_stock_fieldname, true );
            }

            return array(
                'time_interval' => (string) $time_val,
                'default_stock_count' => (int) absint( $stock_val )
            );
        },
        'schema' => array(
            'type'       => 'object',
            'context'    => array( 'view', 'edit' ),
            'properties' => array(
                'time_interval' => array(
                    'type' => 'string',
                ),
                'default_stock_count' => array(
                    'type' => 'integer',
                ),
            ),
        )
    ) );
});

/**
 * 5. STORE API: Expose fields via woocommerce_blocks_loaded (The Correct Timing)
 */
add_action( 'woocommerce_blocks_loaded', function() use ($time_interval_fieldname, $default_stock_fieldname) {

	// 1. Ensure the helper function exists
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}

	// 2. Register the data
	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => 'product',
			'namespace'       => 'konfusius_shift',
			'data_callback'   => function( $product ) use ($time_interval_fieldname, $default_stock_fieldname) {
				$id = $product->get_id();
				
				$time_val = get_post_meta( $id, $time_interval_fieldname, true );
				$stock_val = get_post_meta( $id, $default_stock_fieldname, true );

				// Inheritance for variations
				$parent_id = $product->get_parent_id();
				if ( empty( $stock_val ) && $parent_id ) {
					$stock_val = get_post_meta( $parent_id, $default_stock_fieldname, true );
				}

				return array(
					'time_interval'       => (string) $time_val,
					'default_stock_count' => (int) absint( $stock_val ),
				);
			},
			'schema_callback' => function() {
				return array(
					'properties' => array(
						'time_interval'       => array( 'type' => 'string', 'context' => array( 'view', 'edit' ), 'readonly' => true ),
						'default_stock_count' => array( 'type' => 'integer', 'context' => array( 'view', 'edit' ), 'readonly' => true ),
					),
				);
			},
			'schema_type'     => ARRAY_A,
		)
	);
});