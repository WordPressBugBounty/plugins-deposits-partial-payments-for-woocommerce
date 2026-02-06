<?php
/**
 * Compatibility with Merchant Pro
 * https://athemes.com/merchant/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Merchant_Pro' ) ) {

	class Comp_Merchant_Pro {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			if ( class_exists( 'Merchant_Pro_Storewide_Sale' ) ) {
				add_filter( 'awcdp_update_product_price', array( $this, 'awcdp_update_product_price_Merchant_Pro_sale'), 10, 2 );
    		}

		}

		/* 
		This code snippet updates the product price based on the Merchant Pro sale offer.
		It checks if a sale offer is available for the product and applies the discount accordingly. 
		*/
		function awcdp_update_product_price_Merchant_Pro_sale( $price, $product_id ) {
			if ( ! class_exists( 'Merchant_Pro_Storewide_Sale' ) ) {
				return $price;
			}
			$product = wc_get_product( $product_id );
			$storewide_sale = new Merchant_Pro_Storewide_Sale(
				Merchant_Modules::get_module( Merchant_Storewide_Sale::MODULE_ID )
			);
			$offer = $storewide_sale->available_offer( $product_id );

			if ( empty( $offer['discount_type'] ) || empty( $offer['discount_value'] ) ) {
				return $price;
			}

			$discount_target = $offer['discount_target'] ?? 'regular';
			switch ( $discount_target ) {
				case 'regular':
					$base_price = wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] );
					break;
				case 'sale':
					$base_price = wc_get_price_to_display( $product, [ 'price' => $product->get_sale_price( 'edit' ) ] );
					break;
				default:
					$base_price = wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] );
					break;
			}

			return $storewide_sale->calculate_discounted_price( $base_price, $offer, $product );
		}






	}
}

Comp_Merchant_Pro::get_instance();