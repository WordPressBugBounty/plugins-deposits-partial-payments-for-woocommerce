<?php
/**
 * Compatibility with Payment Plugins for Stripe WooCommerce By Payment Plugins
 * https://wordpress.org/plugins/woo-stripe-payment/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_woo_stripe_payment' ) ) {

	class Comp_woo_stripe_payment {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_filter( 'wc_stripe_output_display_items', array( $this, 'awcdp_update_wc_stripe_output_display_items' ),10 ,3 );

			add_filter( 'wc_stripe_cart_data', array( $this, 'filter_cart_data' ), 10, 2 );
			add_action( 'wc_stripe_add_script_data', array( $this, 'filter_product_data' ), 10, 2 );

		}


		function filter_cart_data( $data, $cart ) {
			$deposit_amount = $this->get_active_deposit_amount();

			if ( $deposit_amount === false ) {
				return $data;
			}

			$currency = get_woocommerce_currency();

			// Override the totals Stripe will charge.
			$data['total']         = round( $deposit_amount, 2 );
			$data['totalCents']    = wc_stripe_add_number_precision( $deposit_amount, $currency );
			$data['subtotal']      = round( $deposit_amount, 2 );
			$data['subtotalCents'] = wc_stripe_add_number_precision( $deposit_amount, $currency );

			$deposit_label = apply_filters(
				'awcdp_stripe_express_checkout_deposit_label',
				esc_html__( 'Deposit', 'deposits-partial-payments-for-woocommerce' )
			);

			$data['lineItems'] = array(
				array(
					'label'       => $deposit_label,
					'amount'      => round( $deposit_amount, 2 ),
					'amountCents' => wc_stripe_add_number_precision( $deposit_amount, $currency ),
					'type'        => 'product',
				),
			);

			return $data;
		}

		function filter_product_data( $asset_data, $context ) {
			// Only relevant on single-product pages.
			if ( ! $context->is_product() ) {
				return;
			}

			// Grab whatever transform_product() already wrote.
			$product_data = $asset_data->get( 'product' );
			if ( empty( $product_data ) || empty( $product_data['id'] ) ) {
				return;
			}

			$product = wc_get_product( $product_data['id'] );
			if ( ! $product ) {
				return;
			}

			// Calculate the deposit amount for this product.
			$deposit_amount = $this->awcdp_checkout_mode()
				? $this->get_checkout_mode_product_deposit( $product )
				: $this->get_product_level_deposit( $product );

			if ( $deposit_amount === false || $deposit_amount <= 0 ) {
				return;
			}

			$currency = get_woocommerce_currency();

			$deposit_label = apply_filters(
				'awcdp_stripe_express_checkout_deposit_label',
				esc_html__( 'Deposit', 'deposits-partial-payments-for-woocommerce' )
			);

			// Overwrite price fields.
			$product_data['price']       = round( $deposit_amount, 2 );
			$product_data['total']       = round( $deposit_amount, 2 );
			$product_data['priceCents']  = wc_stripe_add_number_precision( $deposit_amount, $currency );
			$product_data['totalCents']  = wc_stripe_add_number_precision( $deposit_amount, $currency );

			// Replace the single line item so the sheet total matches.
			$product_data['lineItems'] = array(
				array(
					'id'          => $product->get_id(),
					'label'       => $deposit_label,
					'amount'      => round( $deposit_amount, 2 ),
					'amountCents' => wc_stripe_add_number_precision( $deposit_amount, $currency ),
					'type'        => 'product',
				),
			);

			$asset_data->add( 'product', $product_data );
		}

	function get_active_deposit_amount() {
		if ( ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return false;
		}

		$deposit_info = WC()->cart->deposit_info ?? null;

		if ( ! is_array( $deposit_info ) ) {
			return false;
		}

		if ( empty( $deposit_info['deposit_enabled'] ) ) {
			return false;
		}

		if ( ! isset( $deposit_info['deposit_amount'] ) ) {
			return false;
		}

		$deposit_amount = floatval( $deposit_info['deposit_amount'] );

		if ( $deposit_amount <= 0 ) {
			return false;
		}

		// In checkout mode, respect the customer's explicit "pay full" choice
		// stored in the WC session by AWCDP's awcdp_update_order_review().
		if ( $this->awcdp_checkout_mode() && WC()->session ) {
			$session_flag = WC()->session->get( 'deposit_enabled', null );
			// null  → not yet set; use AWCDP's calculated value (deposit_enabled = true above)
			// false → customer explicitly chose "pay full amount"
			if ( $session_flag === false ) {
				return false;
			}
		}

		return $deposit_amount;
	}

	function get_checkout_mode_product_deposit( $product ) {
		if ( ! defined( 'AWCDP_DEPOSITS_META_KEY' ) ) {
			return false;
		}

		$awcdp_gs = get_option( 'awcdp_general_settings' );

		if ( empty( $awcdp_gs['enable_deposits'] ) || (int) $awcdp_gs['enable_deposits'] !== 1 ) {
			return false;
		}

		$price       = wc_get_price_to_display( $product );
		$amount_type = isset( $awcdp_gs['deposit_type'] ) ? $awcdp_gs['deposit_type'] : 'percentage';

		// Payment plan: look up deposit percentage from the first plan.
		if ( $amount_type === 'payment_plan' ) {
			$plan_ids = isset( $awcdp_gs['payment_plan'] ) ? (array) $awcdp_gs['payment_plan'] : array();
			if ( empty( $plan_ids ) ) {
				return false;
			}
			$pct = floatval( get_post_meta( $plan_ids[0], 'deposit_percentage', true ) );
			return $pct > 0 ? $price * ( $pct / 100 ) : false;
		}

		$deposit_value = isset( $awcdp_gs['deposit_amount'] ) ? floatval( $awcdp_gs['deposit_amount'] ) : 0;

		if ( $deposit_value <= 0 ) {
			return false;
		}

		return $amount_type === 'fixed' ? $deposit_value : $price * ( $deposit_value / 100 );
	}

	function get_product_level_deposit( $product ) {
        if ( ! defined( 'AWCDP_DEPOSITS_META_KEY' ) ) {
            return false;
        }

        $awcdp_gs = get_option( 'awcdp_general_settings' );

        // Deposits must be globally on.
        if ( empty( $awcdp_gs['enable_deposits'] ) || (int) $awcdp_gs['enable_deposits'] !== 1 ) {
            return false;
        }

        $product_id = $product->get_id();

        // Explicitly disabled for this product.
        if ( get_post_meta( $product_id, AWCDP_DEPOSITS_META_KEY, true ) === 'no' ) {
            return false;
        }

        $price = wc_get_price_to_display( $product );

        // Resolve deposit type and value: product meta → global settings.
        $amount_type   = get_post_meta( $product_id, AWCDP_DEPOSITS_TYPE, true );
        $deposit_value = get_post_meta( $product_id, AWCDP_DEPOSITS_AMOUNT, true );

        // If product-level amount is a payment plan, resolve deposit percentage.
        if ( $amount_type === 'payment_plan' ) {
            $plan_id = get_post_meta( $product_id, AWCDP_DEPOSITS_PLAN, true );
            $plan_id = is_array( $plan_id ) ? $plan_id[0] : $plan_id;
            $pct     = $plan_id ? floatval( get_post_meta( $plan_id, 'deposit_percentage', true ) ) : 0;
            return $pct > 0 ? $price * ( $pct / 100 ) : false;
        }

        // No product-level setting – fall back to global.
        if ( $deposit_value === '' || $deposit_value === false ) {
            return $this->get_checkout_mode_product_deposit( $product );
        }

        $deposit_value = floatval( $deposit_value );
        if ( $deposit_value <= 0 ) {
            return false;
        }

        return $amount_type === 'fixed' ? $deposit_value : $price * ( $deposit_value / 100 );
    }

    function awcdp_checkout_mode() {
        $awcdp_gs = get_option( 'awcdp_general_settings' );
        return ! empty( $awcdp_gs['checkout_mode'] );
    }






		function awcdp_update_wc_stripe_output_display_items( $data, $page, $dis ){

			$display_rows = false;
			if ( $this->awcdp_checkout_mode() ) {
				if ( isset($_POST['post_data'] )) {
					parse_str($_POST['post_data'], $post_data);
					$display_rows = isset($post_data['awcdp_deposit_option']) && $post_data['awcdp_deposit_option'] == 'deposit';
				}
			} else {
				$display_rows = true;
			}
			if ($display_rows && isset(WC()->cart->deposit_info, WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] == true) {
				$total = WC()->cart->deposit_info['deposit_amount'];
				$data['total'] = wc_format_decimal( $total, 2 );
				$data['total_cents'] = wc_stripe_add_number_precision( $total, get_woocommerce_currency() );
			}
			return $data;
		}
		
		// function awcdp_checkout_mode(){
		// 	$awcdp_gs = get_option('awcdp_general_settings');
		// 	$checkout_mode = ( isset($awcdp_gs['checkout_mode']) ) ? $awcdp_gs['checkout_mode'] : false;
		// 	return $checkout_mode;
		// }
		


	}
}

Comp_woo_stripe_payment::get_instance();