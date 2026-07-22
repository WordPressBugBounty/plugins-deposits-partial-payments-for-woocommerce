<?php
/**
 * Compatibility with WooCommerce Square By WooCommerce
 * https://wordpress.org/plugins/woocommerce-square/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_woocommerce_square' ) ) {

	class Comp_woocommerce_square {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_filter( 'wc_square_digital_wallet_js_args', array( $this, 'awcdp_square_dw_filter_js_args' ), 20 );
			add_action( 'wc_ajax_square_digital_wallet_get_payment_request', array( $this, 'awcdp_square_dw_intercept_get_payment_request' ), 1 );
			add_action( 'wc_ajax_square_digital_wallet_recalculate_totals', array( $this, 'awcdp_square_dw_intercept_recalculate_totals' ), 1 );
			add_filter( 'wc_payment_gateway_square_credit_card_get_order_base', array( $this, 'awcdp_square_dw_fix_order_payment_total' ), 20, 2 );

		}


		function awcdp_square_dw_is_deposit_session(): bool {
			return WC()->session && 'deposit' === WC()->session->get( 'awcdp_deposit_option' );
		}

		function awcdp_square_dw_get_deposit_total(): ?float {
			if ( ! WC()->cart ) {
				return null;
			}

			$deposit_sum  = 0.0;
			$found        = false;

			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['awcdp_deposit']['enable'] ) && isset( $item['awcdp_deposit']['deposit'] ) ) {
					$deposit_sum += (float) $item['awcdp_deposit']['deposit'];
					$found        = true;
				} else {
					$deposit_sum += (float) ( $item['line_total'] ?? 0 );
				}
			}

			if ( ! $found ) {
				return null;
			}

			$deposit_sum += (float) WC()->cart->get_shipping_total();
			$deposit_sum += (float) WC()->cart->get_fee_total();

			return round( $deposit_sum, 2 );
		}

		function awcdp_square_dw_patch_payment_request( array $payment_request, float $deposit_amount ): array {
			$formatted = number_format( $deposit_amount, 2, '.', '' );

			$payment_request['total']['amount'] = $formatted;

			$payment_request['lineItems'] = [
				[
					'label'   => __( 'Deposit / Partial Payment', 'woocommerce' ),
					'amount'  => $formatted,
					'pending' => false,
				],
			];

			return $payment_request;
		}


		function awcdp_square_dw_patch_json_buffer( string $buffer, float $deposit_amount ): string {
			if ( empty( $buffer ) ) {
				return $buffer;
			}

			$decoded = json_decode( $buffer, true );
			if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
				return $buffer;
			}

			$data = $decoded['data'];

			if ( is_string( $data ) ) {
				$pr = json_decode( $data, true );
				if ( is_array( $pr ) ) {
					$decoded['data'] = wp_json_encode( $this->awcdp_square_dw_patch_payment_request( $pr, $deposit_amount ) );
					return wp_json_encode( $decoded );
				}
			}

			if ( is_array( $data ) && isset( $data['total'] ) ) {
				$decoded['data'] = $this->awcdp_square_dw_patch_payment_request( $data, $deposit_amount );
				return wp_json_encode( $decoded );
			}

			return $buffer;
		}

		function awcdp_square_dw_filter_js_args( array $args ): array {
			if ( ! $this->awcdp_square_dw_is_deposit_session() ) {
				return $args;
			}

			$deposit_amount = $this->awcdp_square_dw_get_deposit_total();
			if ( null === $deposit_amount || $deposit_amount <= 0 ) {
				return $args;
			}

			if ( ! empty( $args['payment_request'] ) && is_array( $args['payment_request'] ) ) {
				$args['payment_request'] = $this->awcdp_square_dw_patch_payment_request(
					$args['payment_request'],
					$deposit_amount
				);
			}

			return $args;
		}

		function awcdp_square_dw_intercept_get_payment_request(): void {
			if ( ! $this->awcdp_square_dw_is_deposit_session() ) {
				return;
			}

			$deposit_amount = $this->awcdp_square_dw_get_deposit_total();
			if ( null === $deposit_amount || $deposit_amount <= 0 ) {
				return;
			}

			ob_start( fn( string $buffer ) => $this->awcdp_square_dw_patch_json_buffer( $buffer, $deposit_amount ) );
		}


		function awcdp_square_dw_intercept_recalculate_totals(): void {
			if ( ! $this->awcdp_square_dw_is_deposit_session() ) {
				return;
			}

			ob_start( function( string $buffer ): string {
				$deposit_amount = $this->awcdp_square_dw_get_deposit_total();
				if ( null === $deposit_amount || $deposit_amount <= 0 ) {
					return $buffer;
				}
				return $this->awcdp_square_dw_patch_json_buffer( $buffer, $deposit_amount );
			} );
		}


		function awcdp_square_dw_fix_order_payment_total( $order, $gateway ) {
			if ( ! $this->awcdp_square_dw_is_deposit_session() ) {
				return $order;
			}

			$deposit_amount = $this->awcdp_square_dw_get_deposit_total();
			if ( null === $deposit_amount || $deposit_amount <= 0 ) {
				return $order;
			}

			$order->payment_total = number_format( $deposit_amount, 2, '.', '' );

			return $order;
		}





	}
}

Comp_woocommerce_square::get_instance();