<?php
/**
 * Compatibility with Advanced Order Export For WooCommerce
 * https://algolplus.com/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Woocommerce_Order_Export' ) ) {

	class Comp_Woocommerce_Order_Export {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_action("woe_ui_form_filter_by_order", function($settings){
				echo '<div><input type="hidden" name="settings[export_partial]" value="0"/>
					  <label><input type="checkbox" name="settings[export_partial]" value="1" ' .  checked( @$settings['export_partial'] ) . ' /> 
						Export partial payments</label>
			   </div>';
			});
			
			add_filter( "woe_sql_order_types", function($order_types){
				if(WC_Order_Export_Engine::$current_job_settings['export_partial']) {
					add_filter( "woe_get_order_value_order_number", function($value,$order,$field){
						return $order->get_order_number();
					},10,3);
					$order_types[] = "'awcdp_payment'";
				}
				return $order_types;
			});

		}


		


	}
}

Comp_Woocommerce_Order_Export::get_instance();