<?php
/**
 * Plugin Name: WC Variation & Order Meta Extender
 * Description: Adds a custom tracking field to products/variations and appends it to Order Items without overwriting core data.
 * Author: M. Schädle
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;
$fieldname = 'time_interval';

/**
 * 1. UI: Add the input fields to Admin
 */
// Simple Products
add_action( 'woocommerce_product_options_general_product_data', function() use ($fieldname) {
    echo '<div class="options_group">';
    woocommerce_wp_text_input( array(
        'id'          => $fieldname,
        'label'       => __( 'Time Interval', 'woocommerce' ),
        'description' => __( 'Addition field: This will be appended to orders.', 'woocommerce' ),
        'desc_tip'    => true
    ) );
    echo '</div>';
});

// Variations
add_action( 'woocommerce_product_after_variable_attributes', function( $loop, $variation_data, $variation ) use ($fieldname) {
    woocommerce_wp_text_input( array(
        'id'            => $fieldname . '[' . $loop . ']',
        'label'         => __( 'Time Interval', 'woocommerce' ),
        'value'         => get_post_meta( $variation->ID, $fieldname, true ), // Use variable here
        'wrapper_class' => 'form-row form-row-full',
    ) );
}, 10, 3 );

/**
 * 2. SAVE: Store the data securely
 */
add_action( 'woocommerce_process_product_meta', function( $post_id ) use ($fieldname) {
    if ( isset( $_POST[$fieldname] ) ) {
        update_post_meta( $post_id, $fieldname, sanitize_text_field( $_POST[$fieldname] ) );
    }
});

add_action( 'woocommerce_save_product_variation', function( $variation_id, $i ) use ($fieldname) {
    if ( isset( $_POST[$fieldname][$i] ) ) {
        update_post_meta( $variation_id, $fieldname, sanitize_text_field( $_POST[$fieldname][$i] ) );
    }
}, 10, 2 );

/**
 * 3. APPEND: Add to Order Item Meta (Non-overwriting)
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) use ($fieldname) {
    $id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
    $val = get_post_meta( $id, $fieldname, true );

    if ( ! empty( $val ) ) {
        // Appends to meta_data array; does not overwrite item properties
        $item->add_meta_data( 'App Value', $val );
    }
}, 10, 4 );

/**
 * 4. API: Expose for your App
 */
add_action( 'rest_api_init', function() use ($fieldname) {
    register_rest_field( array( 'product', 'product_variation' ), 'app_custom_val', array(
        'get_callback' => function( $object ) use ($fieldname) {
            $id = isset( $object['id'] ) ? $object['id'] : $object['ID'];
            return get_post_meta( $id, $fieldname, true );
        },
        'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) )
    ) );
});