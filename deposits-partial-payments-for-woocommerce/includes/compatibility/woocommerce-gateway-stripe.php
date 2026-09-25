<?php
/**
 * Compatibility with WooCommerce Stripe Payment Gateway (by Automattic / WooCommerce)
 * Plugin URI: https://wordpress.org/plugins/woocommerce-gateway-stripe/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! class_exists( 'Comp_WooCommerce_Gateway_Stripe' ) ) {

class Comp_WooCommerce_Gateway_Stripe {

    private static $instance = null;

    private $original_cart_total_for_fragments = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        add_filter( 'woocommerce_calculated_total', array( $this, 'override_cart_total_for_stripe_checkout_session' ), 999999, 2 );

        add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'prepare_cart_total_for_stripe_fragments' ), 15 );
        add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'restore_cart_total_after_stripe_fragments' ), 25 );

        add_action( 'woocommerce_checkout_order_processed', array( $this, 'ensure_checkout_session_matches_deposit_order' ), 10, 3 );

        add_filter( 'wc_stripe_payment_request_product_data', array( $this, 'override_product_page_total' ), 10, 2 );

        add_action( 'wc_ajax_wc_stripe_get_selected_product_data', array( $this, 'inject_deposit_option_for_product_ajax' ), 1 );

        add_action( 'wc_ajax_wc_stripe_create_checkout_session', array( $this, 'override_cart_total_before_stripe_session' ), 0 );
        add_action( 'wc_ajax_wc_stripe_update_checkout_session', array( $this, 'override_cart_total_before_stripe_session' ), 0 );

        add_filter( 'woocommerce_payment_successful_result', array( $this, 'mirror_checkout_session_to_parent' ), 10, 2 );

        add_action( 'wc_gateway_stripe_process_response', array( $this, 'sync_deposit_suborder_after_ap_charge' ), 10, 2 );

        add_filter( 'wc_stripe_allowed_payment_processing_statuses', array( $this, 'add_partially_paid_to_allowed_statuses' ), 10, 2 );

        add_action( 'woocommerce_before_thankyou', array( $this, 'sync_deposit_suborder_on_thankyou' ), 1 );

    }

    /**
     * Override cart total to the deposit amount for Stripe Checkout Session contexts.
     *
     * This covers:
     * - Store API cart/checkout REST requests (blocks checkout)
     * - Stripe Checkout Session create/update AJAX requests
     *
     * Note: We do NOT override woocommerce_calculated_total during normal classic
     * checkout update_order_review rendering, because the order review table needs
     * WC()->cart->get_total('edit') to be the full cart total in order to display
     * the correct "Total" and "Future payments" amounts ($total - $deposit).
     * Instead, for classic checkout, we isolate the total override to the
     * woocommerce_update_order_review_fragments filter (priorities 15-25), which runs
     * after the table HTML is generated and right when Stripe's session synchronize() runs.
     */
    function override_cart_total_for_stripe_checkout_session( $cart_total, $cart ) {
        if ( ! $this->is_deposit_info_enabled() ) {
            return $cart_total;
        }
        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
        if ( $deposit_amount <= 0 || $deposit_amount >= $cart_total ) {
            return $cart_total;
        }

        // Store API (blocks checkout) — covers cart, checkout, and session sync endpoints.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && $this->is_store_api_cart_request() ) {
            return $deposit_amount;
        }

        // Stripe Checkout Session AJAX (create/update session).
        if ( $this->is_stripe_checkout_session_ajax() ) {
            return $deposit_amount;
        }

        return $cart_total;
    }

    /**
     * Temporarily set the cart total to the deposit amount before Stripe's
     * Checkout Session synchronization runs on classic checkout fragments.
     * Runs at priority 15 (Stripe's add_classic_fragment runs at priority 20).
     * At this point, the order review HTML table has already been rendered with
     * the full cart total, so Future Payments ($total - $deposit) remains correct.
     */
    public function prepare_cart_total_for_stripe_fragments( $fragments ) {
        if ( ! $this->is_deposit_info_enabled() || ! $this->is_stripe_optimized_checkout_active() ) {
            return $fragments;
        }

        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
        if ( $deposit_amount <= 0 ) {
            return $fragments;
        }

        $this->original_cart_total_for_fragments = WC()->cart->total;
        WC()->cart->total                        = $deposit_amount;

        add_filter( 'woocommerce_calculated_total', array( $this, 'return_deposit_amount_during_fragments' ), 999999, 2 );

        return $fragments;
    }

    public function return_deposit_amount_during_fragments( $total, $cart ) {
        if ( $this->is_deposit_info_enabled() ) {
            $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
            if ( $deposit_amount > 0 ) {
                return $deposit_amount;
            }
        }
        return $total;
    }

    /**
     * Restore the original cart total after Stripe's session synchronization.
     * Runs at priority 25 (after Stripe's add_classic_fragment at priority 20).
     */
    public function restore_cart_total_after_stripe_fragments( $fragments ) {
        remove_filter( 'woocommerce_calculated_total', array( $this, 'return_deposit_amount_during_fragments' ), 999999 );

        if ( null !== $this->original_cart_total_for_fragments && isset( WC()->cart ) ) {
            WC()->cart->total                        = $this->original_cart_total_for_fragments;
            $this->original_cart_total_for_fragments = null;
        }

        return $fragments;
    }

    /**
     * Ensure the Stripe Checkout Session context amount matches the deposit sub-order
     * total right after the order is processed, before Stripe's process_payment() runs.
     *
     * In WooCommerce classic checkout, the deposit plugin creates a parent order (full total)
     * and a deposit sub-order (deposit total) and returns the sub-order ID. Stripe's
     * process_payment_with_checkout_session() runs validate_for_order(), which checks
     * that the session context's amount matches the order total. This hook guarantees
     * that the context amount in cache matches the sub-order amount, preventing the error:
     * "The payment amount no longer matches the order total. Please refresh the page and try again."
     */
    public function ensure_checkout_session_matches_deposit_order( $order_id, $posted_data, $order ) {
        if ( ! class_exists( 'WC_Stripe_Checkout_Session_Context' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $checkout_session_id = isset( $_POST['wc_stripe_checkout_session_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['wc_stripe_checkout_session_id'] ) )
            : '';

        if ( empty( $checkout_session_id ) ) {
            return;
        }

        $order_obj = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order_id );
        if ( ! $order_obj ) {
            return;
        }

        $is_deposit_order = ( $order_obj->get_type() === 'awcdp_payment' )
            || ( $order_obj->get_parent_id() > 0 )
            || ( 'yes' === $order_obj->get_meta( '_awcdp_deposits_order_has_deposit', true ) );

        if ( ! $is_deposit_order && ! $this->is_deposit_info_enabled() ) {
            return;
        }

        $context = WC_Stripe_Checkout_Session_Context::get_context( $checkout_session_id );
        if ( ! is_array( $context ) ) {
            return;
        }

        $currency     = strtolower( $order_obj->get_currency() );
        $order_amount = class_exists( 'WC_Stripe_Helper' )
            ? WC_Stripe_Helper::get_stripe_amount( (float) $order_obj->get_total(), $currency )
            : intval( round( (float) $order_obj->get_total() * 100 ) );

        if ( (int) ( $context['amount'] ?? 0 ) !== $order_amount ) {
            $context['amount']   = $order_amount;
            $context['currency'] = $currency;
            WC_Stripe_Checkout_Session_Context::set_context( $checkout_session_id, $context );
        }
    }



    function override_product_page_total( $data, $product ) {

        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return $data;
        }

        $product_id = $product->get_id();

        if ( ! $this->is_deposit_enabled_for_product( $product_id ) ) {
            return $data;
        }

        if ( $this->is_checkout_mode() && ! $this->is_deposit_forced_for_product( $product_id ) ) {
            return $data;
        }

        $deposit_amount = $this->calculate_product_deposit_amount( $product );

        if ( $deposit_amount === null || $deposit_amount <= 0 ) {
            return $data;
        }

        $currency      = get_woocommerce_currency();
        $stripe_amount = class_exists( 'WC_Stripe_Helper' )
            ? WC_Stripe_Helper::get_stripe_amount( $deposit_amount, $currency )
            : intval( round( $deposit_amount * 100 ) );

        if ( isset( $data['total']['amount'] ) ) {
            $data['total']['amount'] = $stripe_amount;
        }

        if ( ! empty( $data['displayItems'] ) && is_array( $data['displayItems'] ) ) {
            foreach ( $data['displayItems'] as &$item ) {
                if ( isset( $item['label'] ) && $item['label'] === $product->get_name() ) {
                    $item['amount'] = $stripe_amount;
                    break;
                }
            }
            unset( $item );
        }

        return $data;
    }

    function inject_deposit_option_for_product_ajax() {

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! $product_id ) {
            return;
        }

        if ( ! $this->is_deposit_enabled_for_product( $product_id ) ) {
            return;
        }

        if ( $this->is_checkout_mode() && ! $this->is_deposit_forced_for_product( $product_id ) ) {
            return;
        }

        $_POST['awcdp_deposit_option']    = 'yes';
        $_REQUEST['awcdp_deposit_option'] = 'yes';

        add_filter( 'wc_stripe_calculated_total', array( $this, 'override_legacy_ajax_total' ), 10, 3 );
    }

    function override_legacy_ajax_total( $calculated_total, $order_total, $cart ) {

        if ( ! $this->is_deposit_info_enabled() ) {
            return $calculated_total;
        }

        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
        if ( $deposit_amount <= 0 ) {
            return $calculated_total;
        }

        $currency = get_woocommerce_currency();
        if ( class_exists( 'WC_Stripe_Helper' ) ) {
            return WC_Stripe_Helper::get_stripe_amount( $deposit_amount, $currency );
        }
        return intval( round( $deposit_amount * 100 ) );
    }

    // 

    public function override_cart_total_before_stripe_session() {
        if ( ! isset( WC()->cart ) ) {
            return;
        }
        if ( ! isset( WC()->cart->deposit_info ) ) {
            WC()->cart->calculate_totals();
        }
        if ( ! $this->is_deposit_active_in_cart() ) {
            return;
        }
        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
        if ( $deposit_amount <= 0 ) {
            return;
        }
        WC()->cart->total = $deposit_amount;
        add_filter( 'woocommerce_calculated_total', array( $this, 'return_deposit_amount_once' ), PHP_INT_MAX, 2 );
    }

    public function return_deposit_amount_once( $total, $cart ) {
        remove_filter( 'woocommerce_calculated_total', array( $this, 'return_deposit_amount_once' ), PHP_INT_MAX );
        if ( ! $this->is_deposit_active_in_cart() ) {
            return $total;
        }
        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );
        return $deposit_amount > 0 ? $deposit_amount : $total;
    }
    
    public function mirror_checkout_session_to_parent( $result, $order_id ) {
        $sub_order = wc_get_order( $order_id );

        if ( ! $sub_order ) {
            return $result;
        }

        if ( $sub_order->get_type() !== 'awcdp_payment' ) {
            return $result;
        }

        if ( $sub_order->get_meta( '_awcdp_deposits_payment_type', true ) !== 'deposit' ) {
            return $result;
        }

        $session_id = $sub_order->get_meta( '_stripe_checkout_session_id', true );
        if ( empty( $session_id ) ) {
            return $result;
        }

        $parent_id = $sub_order->get_parent_id();
        if ( ! $parent_id ) {
            return $result;
        }

        $parent = wc_get_order( $parent_id );
        if ( ! $parent || $parent->get_type() !== 'shop_order' ) {
            return $result;
        }

        $parent->update_meta_data( '_stripe_checkout_session_id', $session_id );

        $parent->update_meta_data( '_awcdp_ap_deposit_suborder_id', $order_id );

        $parent->save();

        return $result;
    }

    public function sync_deposit_suborder_after_ap_charge( $charge, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        if ( $order->get_type() !== 'shop_order' ) {
            return;
        }

        if ( $order->get_meta( '_awcdp_deposits_order_has_deposit', true ) !== 'yes' ) {
            return;
        }

        if ( $order->get_meta( '_awcdp_deposits_deposit_paid', true ) === 'yes' ) {
            return;
        }

        $deposit_sub_order_id = absint( $order->get_meta( '_awcdp_ap_deposit_suborder_id', true ) );

        if ( ! $deposit_sub_order_id ) {
            $schedule = $order->get_meta( '_awcdp_deposits_payment_schedule', true );
            if ( is_array( $schedule ) && ! empty( $schedule['deposit']['id'] ) ) {
                $deposit_sub_order_id = absint( $schedule['deposit']['id'] );
            }
        }

        if ( ! $deposit_sub_order_id ) {
            return;
        }

        $deposit_order = wc_get_order( $deposit_sub_order_id );
        if ( ! $deposit_order ) {
            return;
        }

        if ( $deposit_order->get_status() === 'completed' ) {
            if ( $order->get_meta( '_awcdp_deposits_deposit_paid', true ) !== 'yes' ) {
                $order->update_meta_data( '_awcdp_deposits_deposit_paid', 'yes' );
                $order->save();
            }
            return;
        }

        $deposit_order->set_payment_method( $order->get_payment_method() );
        $deposit_order->set_payment_method_title( $order->get_payment_method_title() );

        $charge_id = isset( $charge->id ) ? sanitize_text_field( $charge->id ) : '';
        if ( $charge_id ) {
            $deposit_order->update_meta_data( '_awcdp_stripe_charge_id', $charge_id );
        }

        $note = $charge_id
            ? sprintf( __( 'Stripe deposit payment received via Adaptive Pricing (Charge ID: %s).', 'deposits-partial-payments-for-woocommerce' ), $charge_id )
            : __( 'Stripe deposit payment received via Adaptive Pricing.', 'deposits-partial-payments-for-woocommerce' );

        $deposit_order->update_status( 'completed', $note );

        $presentment_currency = $order->get_meta( '_stripe_presentment_currency', true );
        $presentment_amount   = $order->get_meta( '_stripe_presentment_amount', true );

        if ( $presentment_currency && $presentment_amount
            && method_exists( 'WC_Stripe_Helper', 'get_woocommerce_amount_from_stripe_amount' ) ) {
            $human_amount = WC_Stripe_Helper::get_woocommerce_amount_from_stripe_amount(
                (int) $presentment_amount,
                $presentment_currency
            );
            $deposit_order->add_order_note(
                sprintf(
                    __( 'Local currency deposit via Adaptive Pricing. Amount paid was: %1$s %2$s', 'deposits-partial-payments-for-woocommerce' ),
                    strtoupper( $presentment_currency ),
                    $human_amount
                )
            );
        }

        $deposit_order->save();

        $order->update_meta_data( '_awcdp_deposits_deposit_paid', 'yes' );
        $order->save();

        $new_status = apply_filters(
            'woocommerce_payment_complete_order_status',
            $order->needs_processing() ? 'processing' : 'completed',
            $order->get_id(),
            $order
        );

        if ( $order->get_status() !== $new_status ) {
            $order->update_status(
                $new_status,
                __( 'Deposit sub-order completed. Parent order status updated by AWCDP Stripe compatibility.', 'deposits-partial-payments-for-woocommerce' )
            );
        }
    }

    public function add_partially_paid_to_allowed_statuses( $statuses, $order ) {
        if ( $order instanceof WC_Order
            && $order->get_meta( '_awcdp_deposits_order_has_deposit', true ) === 'yes' ) {
            $statuses[] = 'partially-paid';
        }
        return $statuses;
    }
    
    public function sync_deposit_suborder_on_thankyou( $order_id ) {

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        if ( $order->get_type() === 'awcdp_payment' ) {
            $deposit_order = $order;
            $parent        = wc_get_order( $order->get_parent_id() );
        } elseif ( $order->get_type() === 'shop_order' ) {
            $parent        = $order;
            $sub_order_id  = absint( $parent->get_meta( '_awcdp_ap_deposit_suborder_id', true ) );
            if ( ! $sub_order_id ) {
                $schedule     = $parent->get_meta( '_awcdp_deposits_payment_schedule', true );
                $sub_order_id = ( is_array( $schedule ) && ! empty( $schedule['deposit']['id'] ) )
                    ? absint( $schedule['deposit']['id'] )
                    : 0;
            }
            $deposit_order = $sub_order_id ? wc_get_order( $sub_order_id ) : null;
        } else {
            return;
        }

        if ( ! $parent || ! $deposit_order ) {
            return;
        }

        if ( $parent->get_meta( '_awcdp_deposits_order_has_deposit', true ) !== 'yes' ) {
            return;
        }

        if ( $parent->get_meta( '_awcdp_deposits_deposit_paid', true ) === 'yes' ) {
            return;
        }

        if ( $deposit_order->get_status() === 'completed' ) {
            return;
        }

        $redirect_processed = $deposit_order->get_meta( '_stripe_upe_redirect_processed', true );
        if ( ! $redirect_processed || true !== wc_string_to_bool( $redirect_processed ) ) {
            return;
        }

        $deposit_order->set_payment_method( $parent->get_payment_method() );
        $deposit_order->set_payment_method_title( $parent->get_payment_method_title() );
        $deposit_order->update_status(
            'completed',
            __( 'Stripe deposit payment confirmed via Adaptive Pricing (synced on thank-you page before webhook arrived).', 'deposits-partial-payments-for-woocommerce' )
        );
        $deposit_order->save();

        $parent->update_meta_data( '_awcdp_deposits_deposit_paid', 'yes' );
        $parent->save();

        $new_status = apply_filters(
            'woocommerce_payment_complete_order_status',
            $parent->needs_processing() ? 'processing' : 'completed',
            $parent->get_id(),
            $parent
        );
        if ( $parent->get_status() !== $new_status ) {
            $parent->update_status(
                $new_status,
                __( 'Parent order status updated on thank-you page after deposit confirmation.', 'deposits-partial-payments-for-woocommerce' )
            );
        }
    }
    

    //

    private function is_deposit_active_in_cart() {
        return (
            ! is_null( WC()->cart )
            && isset( WC()->cart->deposit_info['deposit_enabled'] )
            && true === WC()->cart->deposit_info['deposit_enabled']
            && isset( WC()->cart->deposit_info['deposit_amount'] )
            && floatval( WC()->cart->deposit_info['deposit_amount'] ) > 0
        );
    }

    function is_store_api_cart_request() {
        if ( empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
            return false;
        }
        $route = $GLOBALS['wp']->query_vars['rest_route'];
        return (
            strpos( $route, '/wc/store/v1/cart' ) === 0
            || strpos( $route, '/wc/store/v1/checkout' ) === 0
        );
    }

    /**
     * Check if the current request is a classic checkout AJAX request.
     * Covers: update_order_review (order review refresh) and checkout (place order).
     */
    private function is_classic_checkout_ajax() {
        if ( ! wp_doing_ajax() ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_text_field( wp_unslash( $_GET['wc-ajax'] ) ) : '';
        return in_array( $wc_ajax, array( 'update_order_review', 'checkout' ), true );
    }

    /**
     * Check if the current request is a Stripe Checkout Session AJAX request.
     */
    private function is_stripe_checkout_session_ajax() {
        if ( ! wp_doing_ajax() ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_text_field( wp_unslash( $_GET['wc-ajax'] ) ) : '';
        return in_array( $wc_ajax, array( 'wc_stripe_create_checkout_session', 'wc_stripe_update_checkout_session' ), true );
    }

    /**
     * Check if Stripe Optimized Checkout Suite (Checkout Sessions) is active.
     * This is backward compatible — returns false for older Stripe plugin versions
     * that don't have the Checkout Session Manager.
     */
    private function is_stripe_optimized_checkout_active() {
        // WC_Stripe_Checkout_Session_Manager only exists in Stripe plugin v10.6+
        // with Optimized Checkout Suite enabled.
        return class_exists( 'WC_Stripe_Checkout_Session_Manager' );
    }

    function is_deposit_info_enabled() {
        return (
            ! is_null( WC()->cart )
            && isset( WC()->cart->deposit_info['deposit_enabled'] )
            && true === WC()->cart->deposit_info['deposit_enabled']
            && isset( WC()->cart->deposit_info['deposit_amount'] )
            && floatval( WC()->cart->deposit_info['deposit_amount'] ) > 0
        );
    }

    function is_checkout_mode() {
        $awcdp_gs      = get_option( 'awcdp_general_settings' );
        $checkout_mode = isset( $awcdp_gs['checkout_mode'] ) ? (bool) $awcdp_gs['checkout_mode'] : false;
        return $checkout_mode;
    }

    function is_deposit_enabled_for_product( $product_id ) {
        $awcdp_gs    = get_option( 'awcdp_general_settings' );
        $globally_on = isset( $awcdp_gs['enable_deposits'] ) && $awcdp_gs['enable_deposits'] == 1;

        $product  = wc_get_product( $product_id );
        $check_id = ( $product && $product->get_type() === 'variation' )
            ? $product->get_parent_id()
            : $product_id;
        $prod_obj = wc_get_product( $check_id );

        if ( ! $prod_obj ) {
            return $globally_on;
        }

        $override = $prod_obj->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
        if ( $override ) {
            return $prod_obj->get_meta( '_awcdp_deposits_enable_deposit', true ) === 'yes';
        }

        return $globally_on;
    }

    function is_deposit_forced_for_product( $product_id ) {
        $awcdp_gs    = get_option( 'awcdp_general_settings' );
        $globally_on = isset( $awcdp_gs['force_deposit'] ) && $awcdp_gs['force_deposit'] == 1;

        $product  = wc_get_product( $product_id );
        $check_id = ( $product && $product->get_type() === 'variation' )
            ? $product->get_parent_id()
            : $product_id;
        $prod_obj = wc_get_product( $check_id );

        if ( ! $prod_obj ) {
            return $globally_on;
        }

        $override = $prod_obj->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
        if ( $override ) {
            return $prod_obj->get_meta( '_awcdp_deposits_force_deposit', true ) === 'yes';
        }

        return $globally_on;
    }

    function calculate_product_deposit_amount( $product ) {

        $product_id = $product->get_id();
        $awcdp_gs   = get_option( 'awcdp_general_settings' );

        $amount_type  = isset( $awcdp_gs['deposit_type'] )   ? $awcdp_gs['deposit_type']              : 'percentage';
        $amount_value = isset( $awcdp_gs['deposit_amount'] ) ? floatval( $awcdp_gs['deposit_amount'] ) : 0;

        $check_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
        $prod_obj = wc_get_product( $check_id );

        if ( $prod_obj ) {
            $override = $prod_obj->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $type_meta = $prod_obj->get_meta( '_awcdp_deposits_amount_type', true );
                if ( $type_meta ) {
                    $amount_type = $type_meta;
                }
                $val_meta = $prod_obj->get_meta( '_awcdp_deposits_deposit_amount', true );
                if ( $val_meta !== '' && $val_meta !== false ) {
                    $amount_value = floatval( $val_meta );
                }
            }
        }

        if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) {
            $base_price = wc_get_price_including_tax( $product );
        } else {
            $base_price = wc_get_price_excluding_tax( $product );
        }

        if ( $base_price <= 0 ) {
            return null;
        }

        switch ( $amount_type ) {

            case 'fixed':
                $deposit = $amount_value;
                if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) {
                    $tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
                    $taxes     = WC_Tax::calc_tax( $amount_value, $tax_rates, false );
                    $deposit  += array_sum( $taxes );
                }
                break;

            case 'percent':
            case 'percentage':
                $deposit = $base_price * ( $amount_value / 100.0 );
                break;

            case 'payment_plan':
                $deposit = $this->calculate_payment_plan_deposit( $prod_obj, $awcdp_gs, $base_price );
                if ( $deposit === null ) {
                    return null;
                }
                break;

            default:
                return null;
        }

        if ( $deposit <= 0 ) {
            return null;
        }

        return round( $deposit, wc_get_price_decimals() );
    }

    function calculate_payment_plan_deposit( $prod_obj, $awcdp_gs, $base_price ) {

        $plan_id = null;

        if ( $prod_obj ) {
            $override = $prod_obj->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $plans = $prod_obj->get_meta( '_awcdp_deposits_payment_plans', true );
                if ( is_array( $plans ) && ! empty( $plans ) ) {
                    $plan_id = (int) $plans[0];
                }
            }
        }

        if ( ! $plan_id ) {
            $global_plans = isset( $awcdp_gs['payment_plan'] ) ? $awcdp_gs['payment_plan'] : array();
            if ( is_array( $global_plans ) && ! empty( $global_plans ) ) {
                $plan_id = (int) $global_plans[0];
            }
        }

        if ( ! $plan_id ) {
            return null;
        }

        $deposit_percentage = get_post_meta( $plan_id, 'deposit_percentage', true );

        if ( $deposit_percentage ) {
            return $base_price * ( floatval( $deposit_percentage ) / 100.0 );
        }

        $plan_type  = get_post_meta( $plan_id, 'amount_type', true );
        $plan_value = floatval( get_post_meta( $plan_id, 'deposit_amount', true ) );

        if ( 'fixed' === $plan_type ) {
            return $plan_value;
        }
        if ( $plan_value > 0 ) {
            return $base_price * ( $plan_value / 100.0 );
        }

        return null;
    }
}
}

Comp_WooCommerce_Gateway_Stripe::get_instance();
