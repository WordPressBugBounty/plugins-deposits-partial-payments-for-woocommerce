<?php
/**
 * Compatibility with FunnelKit Funnel Builder
 * https://funnelkit.com/wordpress-funnel-builder/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Funnel_Builder' ) ) {

	class Comp_Funnel_Builder {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_filter( 'awcdb_update_checkout', '__return_false' ); 
			add_action('wp_footer', [ $this, 'aco_remove_deposit_minicart'], 22);
			add_filter( 'wfty_maybe_update_order', [ $this, 'maybe_wc_deposit_order' ] );
			add_filter( 'bwf_tracking_insert_order', [ $this, 'maybe_wc_deposit_order' ] );

		}

		
		function maybe_wc_deposit_order( $order ) {

			if ( $order && $order->get_type() == AWCDP_POST_TYPE ) {
				$order = wc_get_order( $order->get_parent_id() );
			}
			return $order;

		}

		function aco_remove_deposit_minicart() {
			?>
			<script>
				jQuery( document.body ).on( 'updated_checkout',function(){
					
					jQuery('.wfacp_mb_mini_cart_sec_accordion_content .awcdp-deposit-checkout-button').remove();
		
				});
			</script>
			<?php
		}
		


	}
}

Comp_Funnel_Builder::get_instance();