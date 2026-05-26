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

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        add_filter( 'woocommerce_calculated_total', array( $this, 'override_cart_total_for_store_api' ), 999999, 2 );

        add_filter( 'wc_stripe_payment_request_product_data', array( $this, 'override_product_page_total' ), 10, 2 );

        add_action( 'wc_ajax_wc_stripe_get_selected_product_data', array( $this, 'inject_deposit_option_for_product_ajax' ), 1 );
    }

    function override_cart_total_for_store_api( $cart_total, $cart ) {

        // Only act during REST requests.
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return $cart_total;
        }

        // Only act on Store API cart / checkout routes.
        if ( ! $this->is_store_api_cart_request() ) {
            return $cart_total;
        }

        // deposit_info must already be set and enabled by AWCDP.
        if ( ! $this->is_deposit_info_enabled() ) {
            return $cart_total;
        }

        $deposit_amount = floatval( WC()->cart->deposit_info['deposit_amount'] );

        // Safety: deposit must be less than the full total.
        if ( $deposit_amount <= 0 || $deposit_amount >= $cart_total ) {
            return $cart_total;
        }

        return $deposit_amount;
    }

    function override_product_page_total( $data, $product ) {

        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return $data;
        }

        $product_id = $product->get_id();

        if ( ! $this->is_deposit_enabled_for_product( $product_id ) ) {
            return $data;
        }

        // In checkout mode the deposit radio hasn't been chosen yet on a product page,
        // so only override when the deposit is forced for this product.
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

        // Patch total.
        if ( isset( $data['total']['amount'] ) ) {
            $data['total']['amount'] = $stripe_amount;
        }

        // Patch the product display line so the payment sheet shows deposit, not full price.
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

        // In checkout mode only inject when deposit is forced.
        if ( $this->is_checkout_mode() && ! $this->is_deposit_forced_for_product( $product_id ) ) {
            return;
        }

        // Inject so that awcdp_add_cart_item_data picks it up.
        $_POST['awcdp_deposit_option']    = 'yes';
        $_REQUEST['awcdp_deposit_option'] = 'yes';

        // Hook the Stripe legacy AJAX total filter.
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

        // Resolve effective settings (product override > global).
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

        // Base price for deposit calculation (matches cart display setting).
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

        // Product-level plan override.
        if ( $prod_obj ) {
            $override = $prod_obj->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $plans = $prod_obj->get_meta( '_awcdp_deposits_payment_plans', true );
                if ( is_array( $plans ) && ! empty( $plans ) ) {
                    $plan_id = (int) $plans[0];
                }
            }
        }

        // Fall back to global plan list.
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

        // Fallback: plan stores amount_type + deposit_amount.
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
