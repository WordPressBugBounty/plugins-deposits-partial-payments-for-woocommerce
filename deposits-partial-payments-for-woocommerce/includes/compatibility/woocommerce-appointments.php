<?php
/**
 * Compatibility with WooCommerce Appointments
 * https://bookingwp.com/plugins/woocommerce-appointments/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Woocommerce_Appointments' ) ) {

	class Comp_Woocommerce_Appointments {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_action( 'init', array( $this, 'awcdp_wc_register_custom_post_status' ),1 );
			add_filter( 'woocommerce_appointments_get_wc_appointment_statuses', array( $this, 'awcdp_wc_partial_payment_status' ) );
			add_filter( 'woocommerce_appointments_get_status_label', array( $this, 'awcdp_wc_partial_payment_status' ) );
			add_filter( 'woocommerce_appointments_gcal_sync_statuses', array( $this, 'awcdp_wc_custom_paid_status' ) );
			add_action( 'woocommerce_payment_complete', array( $this, 'awcdp_wc_save_order_status' ) );

			add_action( 'woocommerce_order_status_on-hold_to_partially-paid', array( $this, 'awcdp_wc_on_hold_to_partially_paid' ), 20, 2 );
			add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'awcdp_handle_partially_paid' ), 20, 2 );
			add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'awcdp_handle_completed_payment' ), 40, 2 );

			add_action( 'woocommerce_order_status_processing', array( $this, 'awcdp_publish_appointments' ), 10, 1 );
        	add_action( 'woocommerce_order_status_completed', array( $this, 'awcdp_publish_appointments' ), 10, 1 );

		}

		
		public function awcdp_wc_register_custom_post_status() {
			if ( is_admin() && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'wc_appointment' ) {
				register_post_status( 'wc-partially-paid', array(
					'label'                     => '<span class="status-partial-payment tips" data-tip="' . wc_sanitize_tooltip( _x( 'Partially Paid', 'deposits-partial-payments-for-woocommerce', 'deposits-partial-payments-for-woocommerce' ) ) . '">' . _x( 'Partially Paid', 'deposits-partial-payments-for-woocommerce', 'deposits-partial-payments-for-woocommerce' ) . '</span>',
					'exclude_from_search'       => false,
					'public'                    => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => _n_noop( 'Partially Paid <span class="count">(%s)</span>', 'Partially Paid <span class="count">(%s)</span>', 'deposits-partial-payments-for-woocommerce' ),
				) );
			}
		}
	
		function awcdp_wc_partial_payment_status($statuses){
			$statuses['wc-partially-paid'] = esc_html__( 'Partially Paid','deposits-partial-payments-for-woocommerce' );
			return $statuses;
		}

		function awcdp_wc_custom_paid_status( $statuses ) {
			$statuses[] = 'wc-partially-paid';
			return $statuses;
		}
		
		function awcdp_wc_on_hold_to_partially_paid($order_id, $order ){
			$this->awcdp_handle_partially_paid( $order->get_status(), $order_id );
		}

		function awcdp_handle_partially_paid( $order_status, $order_id ) {
			if ( 'partially-paid' === $order_status ) {
				$this->awcdp_set_status_for_appointments_in_order( $order_id, 'wc-partially-paid' );
			}
			return $order_status;
		}

		function awcdp_wc_save_order_status( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order->get_status() == 'partially-paid' ) {
				$this->awcdp_set_status_for_appointments_in_order( $order_id, 'wc-partially-paid' );  
			}
		}

		function awcdp_set_status_for_appointments_in_order( $order_id, $new_status ) {
			$appointment_ids = WC_Appointment_Data_Store::get_appointment_ids_from_order_id( $order_id );
			foreach ( $appointment_ids as $appointment_id ) {
				$appointment = get_wc_appointment( $appointment_id );
				$appointment->set_status( $new_status );
				$appointment->save();
			}
		}

		function awcdp_handle_completed_payment( $order_status, $order_id ) {
			$order = wc_get_order( $order_id );
			if ( !is_a( $order, 'WC_Order' ) ) {
				return $order_status;
			}
			if ( 'processing' !== $order_status || ! $order->has_status( 'pending' ) ) {
				return $order_status;
			}
			if ( count( $order->get_items() ) < 1 ) {
				return $order_status;
			}
			$virtual_appointment_order = false;
			foreach ( $order->get_items() as $item ) {
				if ( $item->is_type( 'line_item' ) ) {
					$product = $item->get_product();
					$virtual_appointment_order = $product && $product->is_virtual() && $product->is_type( 'appointment' );
				}
				if ( !$virtual_appointment_order ) {
					break;
				}
			}
			if ( $virtual_appointment_order ) {
				return 'completed';
			}
			return $order_status;
		}

		function awcdp_publish_appointments( $order_id ) {
			$order = wc_get_order( $order_id );
			$payment_method = $order ? $order->get_payment_method() : null;
			$order_id = apply_filters( 'woocommerce_appointments_publish_appointments_order_id', $order_id );
			$order_has_deposit = $order->get_meta( '_awcdp_deposits_order_has_deposit', true ) === 'yes';
	
			if ( $order->get_type() == AWCDP_POST_TYPE || ! $order_has_deposit ) return;
	
			$appointments = WC_Appointment_Data_Store::get_appointment_ids_from_order_id( $order_id );
	
			$no_publish = $order->has_status( 'processing' ) && 'cod' === $payment_method;
	
			foreach ( $appointments as $appointment_id ) {
				$appointment = get_wc_appointment( $appointment_id );
				if ( $no_publish ) {
					$appointment->maybe_schedule_event( 'reminder' );
					$appointment->maybe_schedule_event( 'complete' );
					// Send email notification to admin and staff.
					if ( ! as_next_scheduled_action( 'woocommerce_admin_new_appointment_notification', [ $appointment_id ] ) ) {
						as_schedule_single_action( time(), 'woocommerce_admin_new_appointment_notification', [ $appointment_id ], 'wca' );
					}
				} else {
					$appointment->set_status( 'paid' );
					$appointment->save();
				}
			}
		}



	}
}

Comp_Woocommerce_Appointments::get_instance();
