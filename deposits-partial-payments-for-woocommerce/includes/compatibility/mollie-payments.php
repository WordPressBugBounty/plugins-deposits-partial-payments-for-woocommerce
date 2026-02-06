<?php
/**
 * Compatibility with Mollie Payments for WooCommerce
 * https://wordpress.org/plugins/mollie-payments-for-woocommerce/
 * 
 * This compatibility class ensures that Mollie payment webhooks can properly find and update
 * deposit sub-orders which use the custom post type 'awcdp_payment' instead of 'shop_order'.
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Mollie_Payments' ) ) {

	class Comp_Mollie_Payments {

		private static $instance;
		private $mollie_active = false;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			$this->mollie_active = $this->is_mollie_active();
			
			if ( $this->mollie_active ) {
				$this->init_hooks();
			}
		}
		
		/**
		 * Initialize compatibility hooks
		 */
		private function init_hooks() {
			
			// Intercept order queries at multiple levels to ensure deposit orders are included
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_args', array( $this, 'force_include_deposit_orders' ), 1, 2 );
			add_filter( 'woocommerce_get_orders_query', array( $this, 'intercept_wc_get_orders' ), 1, 2 );
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'force_deposit_in_final_query' ), 1, 2 );
			add_action( 'pre_get_posts', array( $this, 'force_deposit_orders_in_wp_query' ), 1 );
			
			// Order types handling
			add_filter( 'wc_order_types', array( $this, 'force_add_deposit_type' ), 1, 2 );
			add_filter( 'woocommerce_get_order_types', array( $this, 'force_add_deposit_type' ), 1, 2 );
			add_filter( 'woocommerce_order_class', array( $this, 'use_deposit_order_class' ), 10, 2 );
			
			// Transaction ID handling
			add_action( 'woocommerce_payment_complete', array( $this, 'ensure_transaction_id_on_deposit_order' ), 10, 1 );
			
			// Payment method for deposit orders
			add_filter( 'woocommerce_order_get_payment_method', array( $this, 'get_deposit_order_payment_method' ), 10, 2 );
		}
		
		/**
		 * Force include deposit orders at the earliest possible point in order queries
		 * 
		 * @param array $query Query arguments
		 * @param array $query_vars Query variables
		 * @return array Modified query arguments
		 */
		public function force_include_deposit_orders( $query, $query_vars ) {
			
			if ( isset( $query['post_type'] ) ) {
				if ( is_string( $query['post_type'] ) && $query['post_type'] === 'shop_order' ) {
					$query['post_type'] = array( 'shop_order', AWCDP_POST_TYPE );
				} elseif ( is_array( $query['post_type'] ) && ! in_array( AWCDP_POST_TYPE, $query['post_type'] ) ) {
					$query['post_type'][] = AWCDP_POST_TYPE;
				}
			} else {
				$query['post_type'] = array( 'shop_order', AWCDP_POST_TYPE );
			}
			
			return $query;
		}
		
		/**
		 * Intercept wc_get_orders() calls to include deposit orders
		 * 
		 * @param array $query Query arguments
		 * @param array $query_vars Query variables
		 * @return array Modified query arguments
		 */
		public function intercept_wc_get_orders( $query, $query_vars ) {
			
			if ( ! isset( $query['type'] ) ) {
				$query['type'] = array( 'shop_order', AWCDP_POST_TYPE );
			} elseif ( is_string( $query['type'] ) ) {
				$query['type'] = array( $query['type'], AWCDP_POST_TYPE );
			} elseif ( is_array( $query['type'] ) && ! in_array( AWCDP_POST_TYPE, $query['type'] ) ) {
				$query['type'][] = AWCDP_POST_TYPE;
			}
			
			return $query;
		}
		
		/**
		 * Force deposit orders in WP_Query
		 * 
		 * @param WP_Query $query Query object
		 */
		public function force_deposit_orders_in_wp_query( $query ) {
			
			// dismiss admin order list to avoid conflicts in admin order listing page
			global $pagenow;
			if ( is_admin() && $pagenow === 'edit.php' && $query->get( 'post_type' ) === 'shop_order' && $query->is_main_query() ) {
				return;
			}

			$post_type = $query->get( 'post_type' );
			
			if ( empty( $post_type ) ) {
				return;
			}
			
			if ( $post_type === 'shop_order' ) {
				$query->set( 'post_type', array( 'shop_order', AWCDP_POST_TYPE ) );
			} elseif ( is_array( $post_type ) && in_array( 'shop_order', $post_type ) && ! in_array( AWCDP_POST_TYPE, $post_type ) ) {
				$post_type[] = AWCDP_POST_TYPE;
				$query->set( 'post_type', $post_type );
			}
		}
		
		/**
		 * Final modification of query variables before execution
		 * 
		 * @param array $query_vars Query variables
		 * @param array $query_args Query arguments
		 * @return array Modified query variables
		 */
		public function force_deposit_in_final_query( $query_vars, $query_args ) {
			
			if ( ! isset( $query_vars['type'] ) ) {
				$query_vars['type'] = array( 'shop_order', AWCDP_POST_TYPE );
			} elseif ( is_string( $query_vars['type'] ) ) {
				$query_vars['type'] = array( $query_vars['type'], AWCDP_POST_TYPE );
			} elseif ( is_array( $query_vars['type'] ) && ! in_array( AWCDP_POST_TYPE, $query_vars['type'] ) ) {
				$query_vars['type'][] = AWCDP_POST_TYPE;
			}
			
			return $query_vars;
		}
		
		/**
		 * Add deposit type to WooCommerce order types
		 * 
		 * @param array $order_types Order types
		 * @param string $for Context
		 * @return array Modified order types
		 */
		public function force_add_deposit_type( $order_types, $for = '' ) {
			if ( ! in_array( AWCDP_POST_TYPE, $order_types ) ) {
				$order_types[] = AWCDP_POST_TYPE;
			}
			return $order_types;
		}
		
		/**
		 * Use correct order class for deposit orders
		 * 
		 * @param string $classname Order class name
		 * @param string $order_type Order type
		 * @return string Order class name
		 */
		public function use_deposit_order_class( $classname, $order_type ) {
			if ( $order_type === AWCDP_POST_TYPE && class_exists( 'AWCDP_Order' ) ) {
				return 'AWCDP_Order';
			}
			return $classname;
		}
		
		/**
		 * Ensure transaction ID is properly set on deposit orders
		 * 
		 * @param int $order_id Order ID
		 */
		public function ensure_transaction_id_on_deposit_order( $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( ! $order || $order->get_type() !== AWCDP_POST_TYPE ) {
				return;
			}
			
			$transaction_id = $order->get_transaction_id();
			
			if ( empty( $transaction_id ) ) {
				$mollie_payment_id = $order->get_meta( '_mollie_payment_id', true );
				$mollie_order_id = $order->get_meta( '_mollie_order_id', true );
				
				if ( ! empty( $mollie_payment_id ) ) {
					$order->set_transaction_id( $mollie_payment_id );
					$order->save();
				} elseif ( ! empty( $mollie_order_id ) ) {
					$order->set_transaction_id( $mollie_order_id );
					$order->save();
				}
			}
		}
		
		/**
		 * Get payment method for deposit orders from parent if not set
		 * 
		 * @param string $payment_method Payment method
		 * @param WC_Order $order Order object
		 * @return string Payment method
		 */
		public function get_deposit_order_payment_method( $payment_method, $order ) {
			if ( $order->get_type() === AWCDP_POST_TYPE && empty( $payment_method ) ) {
				$parent_id = $order->get_parent_id();
				if ( $parent_id ) {
					$parent_order = wc_get_order( $parent_id );
					if ( $parent_order ) {
						return $parent_order->get_payment_method();
					}
				}
			}
			return $payment_method;
		}
		
		/**
		 * Check if Mollie Payments plugin is active
		 * 
		 * @return bool
		 */
		private function is_mollie_active() {
			if ( in_array( 'mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php', 
			               apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
				return true;
			}
			
			if ( is_multisite() ) {
				$plugins = get_site_option( 'active_sitewide_plugins' );
				if ( isset( $plugins['mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php'] ) ) {
					return true;
				}
			}
			
			if ( class_exists( '\Mollie\WooCommerce\Payment\MollieOrderService' ) ) {
				return true;
			}
			
			return false;
		}
	}
}

// Initialize the compatibility class
Comp_Mollie_Payments::get_instance();