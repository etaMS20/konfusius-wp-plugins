<?php
/**
 * Plugin Name: WooCommerce Variation & Anchor Date Extender
 * Description: Appends human-readable dates to variation API without overwriting defaults.
 * Author: M. Schädle
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Register the anchor date option in WP settings
add_action('init', function() {
    register_setting('general', 'custom_anchor_date', array(
        'type' => 'string',
        'show_in_rest' => true, // THIS IS MANDATORY
    ));
});
