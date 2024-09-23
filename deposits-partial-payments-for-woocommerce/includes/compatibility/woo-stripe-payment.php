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
		
		function awcdp_checkout_mode(){
			$awcdp_gs = get_option('awcdp_general_settings');
			$checkout_mode = ( isset($awcdp_gs['checkout_mode']) ) ? $awcdp_gs['checkout_mode'] : false;
			return $checkout_mode;
		}
		


	}
}

Comp_woo_stripe_payment::get_instance();