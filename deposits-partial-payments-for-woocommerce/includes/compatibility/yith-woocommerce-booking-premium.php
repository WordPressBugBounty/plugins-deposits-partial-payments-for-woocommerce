<?php
/**
 * Compatibility with YITH Booking and Appointment for WooCommerce Premium
 * https://yithemes.com/themes/plugins/yith-woocommerce-booking/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_yith_woocommerce_booking_premium' ) ) {

	class Comp_yith_woocommerce_booking_premium {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {

			add_action( 'woocommerce_order_status_partially-paid', array( $this, 'set_booking_as_paid') , 10, 2 );

		}

		function set_booking_as_paid( $order_id, $order ) {

			if ( ! ! apply_filters( 'yith_wcbk_orders_set_booking_as_paid', true, $order_id, $order ) ) {
				$bookings = yith_wcbk_booking_helper()->get_bookings_by_order( $order_id );
				if ( ! ! ( $bookings ) ) {
					foreach ( $bookings as $booking ) {
						if ( $booking instanceof YITH_WCBK_Booking && apply_filters( 'yith_wcbk_orders_should_set_booking_as_paid', ! $booking->has_status( 'cancelled' ), $booking, $order ) ) {
							$booking->update_status( 'paid' );
						}
					}
				}
			}
		}
		


	}
}

Comp_yith_woocommerce_booking_premium::get_instance();