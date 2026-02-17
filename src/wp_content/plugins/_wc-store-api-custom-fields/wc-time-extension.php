<?php
/**
 * Plugin Name: Konfusius Shift Store API Extension
 * Description: Adds planned stock + time interval logic and exposes them via Store API.
 * Author: M. Schädle
 * Version: 3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KS_Shift_Plugin {

    const META_TIME = 'time_interval';
    const META_PLAN = 'planned_stock';
    const NAMESPACE = 'konfusius_shift';

    public function __construct() {

        // Admin UI
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_parent_fields' ] );
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'add_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_parent_fields' ] );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_fields' ], 10, 2 );

        // Store API
        add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api_extension' ] );
    }

    /* ============================================================
     * ADMIN UI
     * ============================================================ */

    public function add_parent_fields() {

        global $post;
        $product = wc_get_product( $post->ID );

        echo '<div class="options_group">';

        echo '<p class="form-field description">'
        . __( 'Dient ausschließlich der Planung der Schichten. Die Bestände müssen separat verwaltet werden.', 'woocommerce' )
        . '</p>';

        // Für einfache Produkte: Zeitintervall anzeigen
        if ( ! $product->is_type( 'variable' ) ) {
            woocommerce_wp_text_input([
                'id'          => self::META_TIME,
                'label'       => __( 'Zeitintervall', 'woocommerce' ),
                'desc_tip'    => true,
                'description' => __( 'Zeitintervall für diese Schicht.', 'woocommerce' ),
            ]);
        }

        if ( $product->is_type('variable') && ! $product->managing_stock() ) {
            $sum = 0;
            foreach ( $product->get_children() as $child_id ) {
                $sum += (int) get_post_meta( $child_id, self::META_PLAN, true );
            }

            woocommerce_wp_text_input([
                'id'                => self::META_PLAN . '_sum',
                'label'             => __( 'Summe Schichten Geplant', 'woocommerce' ),
                'type'              => 'number',
                'value'             => $sum,
                'custom_attributes' => [ 'readonly' => 'readonly' ],
                'desc_tip'          => true,
                'description'       => __( 'Summe der geplanten Anzahl aller Schicht-Variationen (informativ).', 'woocommerce' ),
            ]);
        }

        $desc = '';
        if ( ! $product->is_type('variable') ) {
            $desc = __( 'Geplante Anzahl an Schichten.', 'woocommerce' );
        } else {
            if ( $product->managing_stock() ) {
                $desc = __( 'Diese Schicht verwaltet den Bestand selbst, hat aber Variationen.', 'woocommerce' );
            } else {
                $desc = __( 'Einträge hier werden auf alle Schicht-Variationen angewendet.', 'woocommerce' );
            }
        }

        woocommerce_wp_text_input([
            'id'                => self::META_PLAN,
            'label'             => __( 'Geplante Anzahl', 'woocommerce' ),
            'type'              => 'number',
            'custom_attributes' => [
                'step' => '1',
                'min'  => '0'
            ],
            'desc_tip'          => true,
            'description'       => $desc,
        ]);

        echo '</div>';
    }

    public function add_variation_fields( $loop, $variation_data, $variation ) {

        // Für Variationen: Zeitintervall anzeigen
        woocommerce_wp_text_input([
            'id'            => self::META_TIME . '[' . $loop . ']',
            'label'         => __( 'Zeitintervall', 'woocommerce' ),
            'value'         => get_post_meta( $variation->ID, self::META_TIME, true ),
            'wrapper_class' => 'form-row form-row-full',
        ]);
    }

    public function save_parent_fields( $post_id ) {

        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        // Save time interval for simple products
        if ( isset( $_POST[self::META_TIME] ) && ! $product->is_type( 'variable' ) ) {
            update_post_meta(
                $post_id,
                self::META_TIME,
                sanitize_text_field( $_POST[self::META_TIME] )
            );
        }

        // Save planned stock
        if ( isset( $_POST[self::META_PLAN] ) ) {

            $value = absint( $_POST[self::META_PLAN] );

            if ( $product->is_type( 'variable' ) ) {

                // Check if parent manages stock
                if ( $product->managing_stock() ) {
                    // Mode B: parent-managed
                    update_post_meta( $post_id, self::META_PLAN, $value );
                } else {
                    // Mode A: variations manage stock
                    $sum = 0;
                    foreach ( $product->get_children() as $child_id ) {
                        update_post_meta( $child_id, self::META_PLAN, $value );
                        $sum += $value;
                    }
                    update_post_meta( $post_id, self::META_PLAN, $sum );
                }

            } else {
                // Simple product
                update_post_meta( $post_id, self::META_PLAN, $value );
            }
        }
    }

    public function save_variation_fields( $variation_id, $i ) {

        if ( isset( $_POST[self::META_TIME][$i] ) ) {
            update_post_meta(
                $variation_id,
                self::META_TIME,
                sanitize_text_field( $_POST[self::META_TIME][$i] )
            );
        }
    }

    /* ============================================================
     * STORE API
     * ============================================================ */

    public function register_store_api_extension() {

        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'product',
            'namespace'       => self::NAMESPACE,
            'data_callback'   => [ $this, 'extend_product_response' ],
            'schema_callback' => [ $this, 'extend_product_schema' ],
            'schema_type'     => ARRAY_A,
        ]);
    }

    private function get_meta_string( $id, $key ) {
        $v = get_post_meta( $id, $key, true );
        return $v !== '' ? (string) $v : null;
    }

    private function get_meta_int( $id, $key ) {
        $v = get_post_meta( $id, $key, true );
        return $v !== '' ? absint( $v ) : null;
    }

    public function extend_product_response( $product ) {

        $data = [
            'time_interval'  => $this->get_meta_string( $product->get_id(), self::META_TIME ),
            'planned_stock'  => null,
            'stock_count'    => null,
            'variation_data' => [],
        ];

        // Einfaches Produkt: direkt Werte zurückgeben
        if ( ! $product->is_type('variable') ) {
            $data['planned_stock'] = $this->get_meta_int( $product->get_id(), self::META_PLAN );
            $data['stock_count']  = $product->managing_stock() ? $product->get_stock_quantity() : null;
            return $data;
        }

        $parent_manages = $product->managing_stock();

        // Variables Produkt, Parent-managed: Werte des Parents zurückgeben, Variationen auflisten (ohne eigene Bestandswerte, da diese vom Parent gesteuert werden)
        if ( $parent_manages ) {
            $data['planned_stock'] = $this->get_meta_int( $product->get_id(), self::META_PLAN );
            $data['stock_count']   = $product->get_stock_quantity();

            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) continue;

                $data['variation_data'][] = [
                    'id'            => $variation_id,
                    'name'          => $variation->get_name(),
                    'time_interval' => $this->get_meta_string( $variation_id, self::META_TIME ),
                    'planned_stock' => null,
                    'stock_count'   => null, // variations do not manage stock themselves
                ];
            }

            return $data;
        }

        // Variables Produkt, Variationen-managed: Werte der Variationen aggregieren
        $sum_planned = 0;
        $sum_stock   = 0;

        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) continue;

            $planned = $this->get_meta_int( $variation_id, self::META_PLAN );
            $stock   = $variation->managing_stock() ? $variation->get_stock_quantity() : null;

            $sum_planned += (int) $planned;
            $sum_stock   += $stock ?? 0;

            $data['variation_data'][] = [
                'id'            => $variation_id,
                'name'          => $variation->get_name(),
                'time_interval' => $this->get_meta_string( $variation_id, self::META_TIME ),
                'planned_stock' => $planned,
                'stock_count'   => $stock,
            ];
        }

        $data['planned_stock'] = $sum_planned;
        $data['stock_count']   = $sum_stock; // parent shows sum of variations stock_count

        return $data;
    }

    public function extend_product_schema() {

        return [
            'properties' => [
                'time_interval' => [ 'type' => 'string', 'nullable' => true, 'readonly' => true ],
                'planned_stock' => [ 'type' => 'integer', 'nullable' => true, 'readonly' => true ],
                'stock_count'   => [ 'type' => 'integer', 'nullable' => true, 'readonly' => true ],
                'variation_data' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'            => [ 'type' => 'integer' ],
                            'name'          => [ 'type' => 'string' ],
                            'time_interval' => [ 'type' => 'string', 'nullable' => true ],
                            'planned_stock' => [ 'type' => 'integer', 'nullable' => true ],
                            'stock_count'   => [ 'type' => 'integer', 'nullable' => true ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

new KS_Shift_Plugin();
