<?php
/**
 * Compatibility with WooCommerce PayPal Payments Plugin By PayPal
 * https://wordpress.org/plugins/woocommerce-paypal-payments/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_woocommerce_paypal_payments' ) ) {

	class Comp_woocommerce_paypal_payments {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'awcdp_create_order_request_started' ), 10, 1);
			add_filter( 'ppcp_create_order_request_body_data', array( $this, 'awcdp_create_order_request_body_data' ), 10, 1);

		}


    function awcdp_create_order_request_started( array $data ) {

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $deposit_option = '';

        if ( ! empty( $data['form']['awcdp_deposit_option'] ) ) {
            $deposit_option = sanitize_text_field( $data['form']['awcdp_deposit_option'] );
        } elseif ( ! empty( $data['form']['post_data'] ) ) {
            $post_fields = array();
            parse_str( $data['form']['post_data'], $post_fields );
            if ( ! empty( $post_fields['awcdp_deposit_option'] ) ) {
                $deposit_option = sanitize_text_field( $post_fields['awcdp_deposit_option'] );
            }
        }

        if ( '' === $deposit_option && WC()->session ) {
            $session_enabled = WC()->session->get( 'deposit_enabled' );
            if ( true === $session_enabled || 'yes' === $session_enabled || 1 === $session_enabled ) {
                $deposit_option = 'deposit';
            } elseif ( false === $session_enabled || 'no' === $session_enabled || 0 === $session_enabled ) {
                $deposit_option = 'full';
            }
        }

        if ( '' === $deposit_option ) {
            return;
        }

        $original_post_deposit = isset( $_POST['awcdp_deposit_option'] )
            ? $_POST['awcdp_deposit_option']
            : null;

        $_POST['awcdp_deposit_option'] = $deposit_option;

        WC()->cart->calculate_totals();

        if ( null === $original_post_deposit ) {
            unset( $_POST['awcdp_deposit_option'] );
        } else {
            $_POST['awcdp_deposit_option'] = $original_post_deposit;
        }
    }



    function awcdp_create_order_request_body_data ( array $data ) {

        $deposit_amount = $this->awcdp_ppcp_resolve_deposit_amount();

        if ( null === $deposit_amount || $deposit_amount <= 0 ) {
            return $data;
        }

        $currency = $data['purchase_units'][0]['amount']['currency_code']
            ?? get_woocommerce_currency();

        $no_decimal_currencies = array( 'HUF', 'JPY', 'TWD' );
        if ( in_array( $currency, $no_decimal_currencies, true ) ) {
            $formatted_value = (string) round( $deposit_amount, 0 );
        } else {
            $formatted_value = number_format( $deposit_amount, 2, '.', '' );
        }

        if ( isset( $data['purchase_units'][0] ) ) {
            $data['purchase_units'][0]['amount'] = array(
                'currency_code' => $currency,
                'value'         => $formatted_value,
            );

            unset( $data['purchase_units'][0]['items'] );
        }

        return $data;
    }


	function awcdp_ppcp_resolve_deposit_amount() {

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		$info = WC()->cart->deposit_info ?? null;

		if (
			is_array( $info ) &&
			! empty( $info['deposit_enabled'] ) &&
			true === $info['deposit_enabled'] &&
			isset( $info['deposit_amount'] ) &&
			floatval( $info['deposit_amount'] ) > 0
		) {
			return floatval( $info['deposit_amount'] );
		}

		return null;
	}
		

		


	}
}

Comp_woocommerce_paypal_payments::get_instance();