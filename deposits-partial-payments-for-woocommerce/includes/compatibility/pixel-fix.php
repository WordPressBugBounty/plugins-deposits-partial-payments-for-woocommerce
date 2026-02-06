<?php
/**
 * Fix for Social Media Pixels tracking with Deposit Orders
 * Ensures pixels get product information from parent order when tracking deposit/partial payment orders
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Comp_Social_Pixel_Fix' ) ) {

    class Comp_Social_Pixel_Fix {

        private static $instance = null;

        public static function get_instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct() {
            // Hook early to intercept pixel data collection
            add_filter( 'woocommerce_order_get_items', array( $this, 'get_parent_order_items_for_pixels' ), 10, 3 );
            
            // Ensure parent order data is used for tracking scripts
            add_action( 'woocommerce_thankyou', array( $this, 'redirect_pixel_to_parent_order' ), 1 );
            
            // Filter order data for pixel tracking
            add_filter( 'woocommerce_order_item_product', array( $this, 'ensure_product_exists' ), 10, 2 );
            
            // For PixelYourSite and similar plugins
            add_filter( 'pys_order_received_order_id', array( $this, 'use_parent_order_for_pixels' ), 10, 1 );
            
            // For WooCommerce Google Analytics Integration
            add_filter( 'woocommerce_ga_get_order', array( $this, 'use_parent_order_object' ), 10, 1 );
        }

        /**
         * Get parent order items when child order is being tracked by pixels
         *
         * @param array $items
         * @param WC_Order $order
         * @param string $types
         * @return array
         */
        public function get_parent_order_items_for_pixels( $items, $order, $types ) {
            
            // Only process if this is a partial payment order
            if ( ! $this->is_partial_payment_order( $order ) ) {
                return $items;
            }

            // Only intercept when pixels are requesting data (not in admin)
            if ( is_admin() && ! wp_doing_ajax() ) {
                return $items;
            }

            // Get parent order
            $parent_order = $this->get_parent_order( $order );
            
            if ( ! $parent_order ) {
                return $items;
            }

            // Return parent order items for pixel tracking
            return $parent_order->get_items( $types );
        }

        /**
         * Redirect pixel tracking to use parent order on thank you page
         *
         * @param int $order_id
         */
        public function redirect_pixel_to_parent_order( $order_id ) {
            
            $order = wc_get_order( $order_id );
            
            if ( ! $order || ! $this->is_partial_payment_order( $order ) ) {
                return;
            }

            $parent_order = $this->get_parent_order( $order );
            
            if ( ! $parent_order ) {
                return;
            }

            // Set global variable that pixel plugins often check
            global $wp;
            if ( isset( $wp->query_vars['order-received'] ) ) {
                $wp->query_vars['order-received'] = $parent_order->get_id();
            }

            // Add meta to make parent order accessible
            $order->update_meta_data( '_awcdp_parent_for_tracking', $parent_order->get_id() );
            $order->save();
        }

        /**
         * Ensure product exists for order items
         *
         * @param WC_Product|false $product
         * @param WC_Order_Item $item
         * @return WC_Product|false
         */
        public function ensure_product_exists( $product, $item ) {
            
            // If product exists, return it
            if ( $product ) {
                return $product;
            }

            // Get order from item
            $order = $item->get_order();
            
            if ( ! $order || ! $this->is_partial_payment_order( $order ) ) {
                return $product;
            }

            // Get parent order and find matching product
            $parent_order = $this->get_parent_order( $order );
            
            if ( ! $parent_order ) {
                return $product;
            }

            // Get first product from parent order (for fee items in child order)
            $parent_items = $parent_order->get_items( 'line_item' );
            
            if ( ! empty( $parent_items ) ) {
                $first_item = reset( $parent_items );
                return $first_item->get_product();
            }

            return $product;
        }

        /**
         * Use parent order ID for pixel tracking plugins
         *
         * @param int $order_id
         * @return int
         */
        public function use_parent_order_for_pixels( $order_id ) {
            
            $order = wc_get_order( $order_id );
            
            if ( ! $order || ! $this->is_partial_payment_order( $order ) ) {
                return $order_id;
            }

            $parent_order = $this->get_parent_order( $order );
            
            if ( $parent_order ) {
                return $parent_order->get_id();
            }

            return $order_id;
        }

        /**
         * Use parent order object for tracking
         *
         * @param WC_Order $order
         * @return WC_Order
         */
        public function use_parent_order_object( $order ) {
            
            if ( ! $order || ! $this->is_partial_payment_order( $order ) ) {
                return $order;
            }

            $parent_order = $this->get_parent_order( $order );
            
            if ( $parent_order ) {
                return $parent_order;
            }

            return $order;
        }

        /**
         * Check if order is a partial payment order
         *
         * @param WC_Order $order
         * @return bool
         */
        private function is_partial_payment_order( $order ) {
            
            if ( ! $order ) {
                return false;
            }

            // Check if order type is partial payment
            if ( $order->get_type() === AWCDP_POST_TYPE ) {
                return true;
            }

            return false;
        }

        /**
         * Get parent order from partial payment order
         *
         * @param WC_Order $order
         * @return WC_Order|false
         */
        private function get_parent_order( $order ) {
            
            if ( ! $order ) {
                return false;
            }

            $parent_id = $order->get_parent_id();
            
            if ( ! $parent_id ) {
                return false;
            }

            $parent_order = wc_get_order( $parent_id );
            
            return $parent_order ? $parent_order : false;
        }
    }

    // Initialize the fix
    Comp_Social_Pixel_Fix::get_instance();
}
