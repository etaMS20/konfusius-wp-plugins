<?php
function load_angular_theme()
{
    $theme_path = content_url('angular-app-staging');

    wp_enqueue_style('angular-style', $theme_path . '/styles.css', [], null);

    wp_enqueue_script('angular-runtime', $theme_path . '/runtime.js', [], null, true);
    wp_enqueue_script('angular-polyfills', $theme_path . '/polyfills.js', ['angular-runtime'], null, true);
    wp_enqueue_script('angular-main', $theme_path . '/main.js', ['angular-runtime', 'angular-polyfills'], null, true);

    // Inline script to ensure Angular starts inside WordPress theme
    add_action('wp_footer', function () {
        echo "<script>document.addEventListener('DOMContentLoaded', function() { if (document.querySelector('app-root')) { console.log('Angular bootstrapping...'); } else { console.error('Angular app-root not found!'); } });</script>";
    });
}
add_action('wp_enqueue_scripts', 'load_angular_theme');

add_filter('show_admin_bar', '__return_false');
