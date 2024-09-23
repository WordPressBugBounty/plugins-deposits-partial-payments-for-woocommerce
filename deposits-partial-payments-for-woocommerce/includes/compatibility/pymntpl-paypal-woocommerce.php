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
use PaymentPlugins\WooCommerce\PPCP\Factories\ShippingOptionsFactory;

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
			
			// add_filter( 'wc_ppcp_purchase_unit_factory_from_cart', array( $this, 'wc_ppcp_purchase_unit_factory_from_cart' ), 10, 2 );
        	

		}

		
		function wc_ppcp_purchase_unit_factory_from_cart($purchase_unit, $order) {




					
			// $purchase_unit = ( new PurchaseUnit() )
			// 	->setAmount( ( new Amount() )
			// 		->setValue( round( 7, 2 ) )
			// 		->setCurrencyCode( $order->get_currency() )
			// 		->setBreakdown( $order->factories->breakdown->from_cart() ) )
			// 	->setItems( $order->factories->items->from_cart() );
			// // if ( $order->cart->needs_shipping() ) {  
			// // 	$purchase_unit->setShipping( $order->factories->shipping->from_customer( 'shipping' ) );
			// // }
			 


			// // error_log(print_r( $purchase_unit , true));
			// // error_log(print_r(  $order , true));



			// $purchase_unit = ( new PurchaseUnit() )
			// ->setAmount( ( new Amount() )
			// 	->setValue( round( 7, 2 ) )
			// 	->setCurrencyCode( $order->get_currency() )
			// 	 );
			// 	 if ( WC()->cart->needs_shipping() ) {
			// 		//$purchase_unit->setShipping( $order->factories->shipping->from_customer( 'shipping' ) );
			// 	}
			// 	//$order->filter_purchase_unit( $purchase_unit, 7 );

			         



			return $purchase_unit;

		}
	






	}
}

Comp_pymntpl_paypal_woocommerce::get_instance();
