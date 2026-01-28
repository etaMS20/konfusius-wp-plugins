<?php

/**
 * Plugin Name: Konfusius Invite Field Checkout Meta
 * Description: Intercepts cart API checkout POST requests and looks for `invited_by` attribute within the payload. Its value is validated against a set of allowed values, if invalid the checkout is cancelled. If valid, sets the "billing_invite" field of the orders billing object.
 * Author: M. Schädle
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// Save invited_by field to order metadata during checkout. On checkout, this hook is called first
function custom_process_checkout_meta($order, $request)
{
    $data = $request->get_params();
    $invited_by = isset($data['invited_by']) ? sanitize_text_field($data['invited_by']) : 'unknown';
    $gebote_confirm = isset($data['gebote_confirm']) ? filter_var($data['gebote_confirm'], FILTER_VALIDATE_BOOLEAN) : false;
    $disclaimer_confirm = isset($data['disclaimer_confirm']) ? filter_var($data['disclaimer_confirm'], FILTER_VALIDATE_BOOLEAN) : null;
    $disclaimer_experience = isset($data['disclaimer_experience']) ? sanitize_text_field($data['disclaimer_experience']) : null;

    $order->update_meta_data('billing_invite', $invited_by);
    $order->update_meta_data('billing_gebote', $gebote_confirm);
    $order->update_meta_data('billing_disclaimer_experience', $disclaimer_experience);
    $order->update_meta_data('billing_disclaimer_confirmed', $disclaimer_confirm);

    $order->save_meta_data();

    // check the 'invited_by' value against the allowed values
    $allowed_invited_by_values = get_thwcfe_billing_invite_options();

    // If the value is invalid, throw an exception to cancel the checkout
    if (!in_array($invited_by, $allowed_invited_by_values)) {
        throw new Exception('The given invited_by value is not allowed. Please provide a valid value.');
    }

    if ($gebote_confirm !== true) {
        throw new Exception('The gebote_confirm field must be confirmed true');
    }

    if ($disclaimer_confirm !== null && !$disclaimer_confirm === true) {
        throw new Exception('disclaimer_confirm must be true if provided');
    }
}
add_action('woocommerce_store_api_checkout_update_order_from_request', 'custom_process_checkout_meta', 10, 2);

// change default order status for cod
add_filter('woocommerce_cod_process_payment_order_status', 'custom_cod_payment_order_status', 10, 2);
function custom_cod_payment_order_status()
{
    return 'on-hold'; // Payment needs manual confirmation, stock IS reduced
}

/**
 * CUSTOM ENDPOINTS:
 * -> 1. GET thwcfe_sections
 * -> 2. GET allowed billing_invite values
 */
function get_thwcfe_specific_option()
{
    $option_name = 'thwcfe_sections';
    $option_value = get_option($option_name, []);

    return [
        'option_name' => $option_name,
        'option_value' => $option_value
    ];
}

function register_thwcfe_sections_endpoint()
{
    register_rest_route('custom/v1', '/thwcfe_sections', [
        'methods' => 'GET',
        'callback' => 'get_thwcfe_specific_option',
        'permission_callback' => '__return_true'
    ]);
}
add_action('rest_api_init', 'register_thwcfe_sections_endpoint');

// Return allowed billing_invite values
function get_thwcfe_billing_invite_options()
{
    $option_name = 'thwcfe_sections';
    $option_value = get_option($option_name, []);
    return array_keys($option_value['billing']->fields['billing_invite']->options);
}

function register_thwcfe_billing_invite_endpoint()
{
    register_rest_route('custom/v1', '/allowed_invite_options', [
        'methods' => 'GET',
        'callback' => 'get_thwcfe_billing_invite_options',
        'permission_callback' => '__return_true' // Public access
    ]);
}
add_action('rest_api_init', 'register_thwcfe_billing_invite_endpoint');

// get orders by meta endpoint using custom SQL query
function get_orders_by_meta($request)
{
    global $wpdb;

    $meta_key = sanitize_text_field($request['meta_key']);
    $meta_value = sanitize_text_field($request['meta_value']);
    $years = isset($request['years']) ? explode(',', sanitize_text_field($request['years'])) : [];
    $statuses = isset($request['statuses']) ? explode(',', sanitize_text_field($request['statuses'])) : ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'trash'];

    // construct SQL query
    $sql = "
        SELECT posts.ID
        FROM {$wpdb->prefix}posts AS posts
        INNER JOIN {$wpdb->prefix}postmeta AS postmeta
            ON posts.ID = postmeta.post_id
        WHERE posts.post_type = 'shop_order'
        AND postmeta.meta_key = %s
        AND postmeta.meta_value LIKE %s
    ";

    $query_params = [$meta_key, '%' . $wpdb->esc_like($meta_value) . '%'];

    // add status filter if provided
    if (!empty($statuses)) {
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql .= " AND posts.post_status IN ($status_placeholders)";
        $query_params = array_merge($query_params, $statuses);
    }

    // add year filter if provided
    if (!empty($years)) {
        $year_placeholders = implode(',', array_fill(0, count($years), '%d'));
        $sql .= " AND YEAR(posts.post_date) IN ($year_placeholders)";
        $query_params = array_merge($query_params, $years);
    }

    // run the SQL query
    $order_ids = $wpdb->get_col($wpdb->prepare($sql, ...$query_params));

    if (empty($order_ids)) {
        return new WP_Error('no_orders', 'No orders found with the given meta key, status, or year(s).', array('status' => 404));
    }

    // fetch order data for each order ID
    $filtered_orders = array_map(function ($order_id) {
        $order = wc_get_order($order_id);

        return [
            'id' => $order->get_id(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'billing' => [
                'full_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'billing_invite' => $order->get_meta('billing_invite', true), // Fetches the invite field
            ],
            'line_items' => array_values(array_map(function ($item) {
                $variation_id = $item->get_variation_id();
                return [
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $variation_id ? $variation_id : null,
                    'name' => $item->get_name(),
                ];
            }, $order->get_items())),
            'total' => $order->get_total(),
            'status' => $order->get_status(),
        ];
    }, $order_ids);

    return rest_ensure_response($filtered_orders);
}

// register API Route
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/orders-by-meta/', array(
        'methods' => 'GET',
        'callback' => 'get_orders_by_meta',
        'args' => array(
            'meta_key' => array('required' => true),
            'meta_value' => array('required' => true),
            'years' => array( // accepts comma-separated years (optional)
                'required' => false,
                'validate_callback' => function ($param) {
                    return preg_match('/^\d{4}(,\d{4})*$/', $param);
                }
            ),
            'statuses' => array( // accepts comma-separated wc-statuses (optional)
                'required' => false,
                'validate_callback' => function ($param) {
                    return preg_match('/^(wc-[a-zA-Z0-9_-]+)(,wc-[a-zA-Z0-9_-]+)*$/', $param);
                }
            ),
        ),
        'permission_callback' => '__return_true'
    ));
}, 10, 0);
?>