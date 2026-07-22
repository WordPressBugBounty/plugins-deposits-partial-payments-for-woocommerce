<?php
/**
 * Compatibility with Revolut Gateway for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/revolut-gateway-for-woocommerce/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Comp_Revolut_Gateway' ) ) {

class Comp_Revolut_Gateway {

    private static $instance = null;

    /**
     * Deposit amount to restore after Revolut's hook.
     *
     * @var float|null
     */
    private $deposit_total_to_restore = null;

    /**
     * WC order ID being processed (guard against re-entrancy).
     *
     * @var int|null
     */
    private $processing_order_id = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        add_filter( 'woocommerce_calculated_total', array( $this, 'return_deposit_as_cart_total' ), 9999999, 2 );

        // Priority 299 — fires BEFORE Revolut's woocommerce_checkout_revolut_order_processed at 300.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'before_revolut_hook' ), 299, 3 );

        // Priority 301 — fires AFTER Revolut's hook, restores the deposit total.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'after_revolut_hook' ), 301, 3 );
    }


    public function return_deposit_as_cart_total( $cart_total, $cart ) {

        // Never interfere during the checkout order-creation POST.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['revolut_create_wc_order'] ) || isset( $_POST['woocommerce-process-checkout-nonce'] ) ) {
            return $cart_total;
        }
        // phpcs:enable

        if ( ! $this->deposit_is_active() ) {
            return $cart_total;
        }

        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );

        if ( $deposit_amount <= 0 || $deposit_amount >= $cart_total ) {
            return $cart_total;
        }

        return $deposit_amount;
    }


    public function before_revolut_hook( $wc_order_id, $posted_data, $wc_order ) {

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $is_revolut_cc = isset( $_POST['revolut_create_wc_order'] )
            && (bool) wc_clean( wp_unslash( $_POST['revolut_create_wc_order'] ) )
            && isset( $posted_data['payment_method'] )
            && WC_Gateway_Revolut_CC::GATEWAY_ID === $posted_data['payment_method'];
        // phpcs:enable

        if ( ! $is_revolut_cc ) {
            return;
        }

        if ( $wc_order->get_type() !== AWCDP_POST_TYPE ) {
            return;
        }

        $parent_id    = $wc_order->get_parent_id();
        $parent_order = $parent_id ? wc_get_order( $parent_id ) : null;

        if ( ! $parent_order || ! is_a( $parent_order, 'WC_Order' ) ) {
            return;
        }

        $full_total    = (float) $parent_order->get_total();
        $deposit_total = (float) $wc_order->get_total();

        if ( $this->deposit_is_active() ) {
            return;
        }

        if ( $full_total <= 0 || $full_total <= $deposit_total ) {
            return;
        }

        $this->deposit_total_to_restore = $deposit_total;
        $this->processing_order_id      = $wc_order_id;

        $wc_order->set_total( $full_total );
    }

    /*
    public function before_revolut_hook( $wc_order_id, $posted_data, $wc_order ) {

        // Only applies to revolut_cc standard checkout.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $is_revolut_cc = isset( $_POST['revolut_create_wc_order'] )
            && (bool) wc_clean( wp_unslash( $_POST['revolut_create_wc_order'] ) )
            && isset( $posted_data['payment_method'] )
            && WC_Gateway_Revolut_CC::GATEWAY_ID === $posted_data['payment_method'];
        // phpcs:enable

        if ( ! $is_revolut_cc ) {
            return;
        }

        // The $wc_order at this point is the AWCDP deposit partial order (type: awcdp_payment).
        // For standard (non-deposit) orders it is a shop_order — nothing to do.
        if ( ! defined( 'AWCDP_POST_TYPE' ) || $wc_order->get_type() !== AWCDP_POST_TYPE ) {
            return;
        }

        // Get the parent order (main shop_order, total = full cart amount).
        $parent_id    = $wc_order->get_parent_id();
        $parent_order = $parent_id ? wc_get_order( $parent_id ) : null;

        if ( ! $parent_order || ! is_a( $parent_order, 'WC_Order' ) ) {
            return;
        }

        $full_total    = (float) $parent_order->get_total();
        $deposit_total = (float) $wc_order->get_total();

        if ( $full_total <= 0 || $full_total <= $deposit_total ) {
            return;
        }

        // Stash the deposit amount so we can restore it afterwards.
        $this->deposit_total_to_restore = $deposit_total;
        $this->processing_order_id      = $wc_order_id;

        // Temporarily make the partial order look like the full-amount order to Revolut.
        $wc_order->set_total( $full_total );
    }
*/

    public function after_revolut_hook( $wc_order_id, $posted_data, $wc_order ) {

        if ( $this->deposit_total_to_restore === null || $this->processing_order_id !== $wc_order_id ) {
            return;
        }

        $wc_order->set_total( $this->deposit_total_to_restore );

        // Clear state.
        $this->deposit_total_to_restore = null;
        $this->processing_order_id      = null;
    }


    private function deposit_is_active() {
        return (
            ! is_null( WC()->cart )
            && isset( WC()->cart->deposit_info['deposit_enabled'] )
            && true === WC()->cart->deposit_info['deposit_enabled']
            && isset( WC()->cart->deposit_info['deposit_amount'] )
            && floatval( WC()->cart->deposit_info['deposit_amount'] ) > 0
        );
    }



}

}

Comp_Revolut_Gateway::get_instance();