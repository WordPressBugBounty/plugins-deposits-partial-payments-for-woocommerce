<?php

/**
 * Compatibility with Payment Plugins for PayPal WooCommerce by Payment Plugins, support@paymentplugins.com
 * https://wordpress.org/plugins/pymntpl-paypal-woocommerce/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PaymentPlugins\PayPalSDK\Amount;
use PaymentPlugins\PayPalSDK\PurchaseUnit;

if ( ! class_exists( 'Comp_pymntpl_paypal_woocommerce' ) ) {

	class Comp_pymntpl_paypal_woocommerce {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			// Hook into PayPal purchase unit creation to modify amounts for deposits
			add_filter( 'wc_ppcp_purchase_unit_factory_from_cart', array( $this, 'modify_purchase_unit_for_deposit' ), 10, 2 );

		}

		/**
		 * Modify the purchase unit to use deposit amount instead of full amount
		 *
		 * @param PurchaseUnit $purchase_unit
		 * @param object $order_factory
		 * @return PurchaseUnit
		 */
		public function modify_purchase_unit_for_deposit( $purchase_unit, $order_factory ) {
			
			// Check if deposits are enabled and active in cart
			if ( ! $this->is_deposit_active() ) {
				return $purchase_unit;
			}

			// Get deposit information from cart
			$deposit_info = WC()->cart->deposit_info;
			
			if ( ! isset( $deposit_info['deposit_amount'] ) ) {
				return $purchase_unit;
			}

			$deposit_amount = floatval( $deposit_info['deposit_amount'] );
			$currency = get_woocommerce_currency();

			// Create new amount with deposit value - WITHOUT breakdown
			// PayPal validates that item_total matches sum of items, so we remove items and breakdown
			// This is acceptable for deposits as the customer sees the deposit amount clearly
			$amount = new Amount();
			$amount->setValue( round( $deposit_amount, 2 ) );
			$amount->setCurrencyCode( $currency );
			
			// Remove breakdown - this prevents PayPal validation errors
			// PayPal accepts purchase units without item-level details
			$amount->setBreakdown( null );

			// Update the purchase unit with deposit amount
			$purchase_unit->setAmount( $amount );
			
			// Remove items - this is critical to avoid "sum of items" validation error
			$purchase_unit->setItems( null );

			return $purchase_unit;
		}

		/**
		 * Check if deposit is active in current cart
		 *
		 * @return bool
		 */
		private function is_deposit_active() {
			
			// Check if WC Cart exists
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return false;
			}

			// Check if deposit info exists in cart
			if ( ! isset( WC()->cart->deposit_info ) ) {
				return false;
			}

			// Check if deposit is enabled
			if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || 
			     WC()->cart->deposit_info['deposit_enabled'] !== true ) {
				return false;
			}

			// Check if in checkout mode and deposit option is selected
			if ( $this->is_checkout_mode() ) {
				$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' ) && DOING_AJAX;
				
				if ( $is_ajax && isset( $_POST['post_data'] ) ) {
					parse_str( $_POST['post_data'], $post_data );
					if ( isset( $post_data['awcdp_deposit_option'] ) && $post_data['awcdp_deposit_option'] !== 'deposit' ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Check if checkout mode is enabled
		 *
		 * @return bool
		 */
		private function is_checkout_mode() {
			$awcdp_gs = get_option( 'awcdp_general_settings' );
			return isset( $awcdp_gs['checkout_mode'] ) && $awcdp_gs['checkout_mode'];
		}
	}
}

Comp_pymntpl_paypal_woocommerce::get_instance();
