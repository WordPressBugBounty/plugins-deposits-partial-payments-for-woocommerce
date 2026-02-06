<?php
/**
 * Compatibility with Flux Checkout
 * https://iconicwp.com/products/flux-checkout-for-woocommerce/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Comp_Iconic_Flux_Checkout' ) ) {

	class Comp_Iconic_Flux_Checkout {

		private static $instance;
		
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			
			add_filter( 'awcdb_update_checkout', '__return_false' );
			add_action( 'flux_thankyou_before_product_details', array( $this, 'flux_thankyou_awcdp_show__summary' ),10 );
			add_action( 'wp_head', array( $this, 'flux_thankyou_head_css' ) );

			add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_order_review_framents'), 10, 1 );

		}

		function flux_thankyou_awcdp_show__summary( $order ) {

			if ( empty( $order ) ) {
				return;
			}
		
			$order = wc_get_order($order->get_id());
			if ($order->get_type() == AWCDP_POST_TYPE ) {
				$parent = wc_get_order($order->get_parent_id());
				$order_items = $parent->get_items( 'line_item' );
		
		?>
		<div class="flux-ty-product-details">
					<h2 class="flux-heading flux-heading--cart-icon flux-order-review-heading--ty">
						<?php esc_html_e( 'Your Order', 'flux-checkout' ); ?>
						<span class="flux-heading__count"><?php echo esc_html( $parent->get_item_count() ); ?></span>
					</h2>	
					<div class="flux-cart-order-item-wrap">
						<?php
						foreach ( $order_items as $item_id => $item ) {
							$product      = $item->get_product();
							$qty          = $item->get_quantity();
							$refunded_qty = $order->get_qty_refunded_for_item( $item_id );
		
							if ( $refunded_qty ) {
								$qty_display = '<del>' . esc_html( $qty ) . '</del> <ins>' . esc_html( $qty - ( $refunded_qty * -1 ) ) . '</ins>';
							} else {
								$qty_display = esc_html( $qty );
							}
		
							
							$item_class = apply_filters( 'flux_order_items_class', 'flux-cart-order-item flux-cart-order-item--ty', $item, $order );
							?>
							<div class="<?php echo esc_attr( $item_class ); ?>">
								<?php if ( $product->get_image_id() ) { ?>
									<div class="flux-cart-image flux-cart-image--ty">
										<?php
											echo wp_kses_post( $product->get_image() );
										?>
									</div>
								<?php } ?>
								<div class="flux-cart-order-item__info">
									<h3 class="flux-cart-order-item__info-name">
										<?php
										echo esc_html( $product->get_name() );
										?>
									</h3>
									<span class="flux-cart-order-item__info-varient">
										<?php
							
										do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );
		
										echo wp_kses_post( wc_display_item_meta( $item ) );
		
										do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
										?>
									</span>
									<div class="flux-cart-order-item__info-qty">
										<?php
							
										echo wp_kses_post( apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', esc_html( $qty_display ) ) . '</strong>', $item ) );
										?>
									</div>
								</div>
								<div class="flux-cart-order-item__price">
									<?php
									echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) );
									?>
								</div>
							</div>
							<?php
						}
						?>
					</div>
						<?php
		
						$totals = $parent->get_order_item_totals();
						foreach ( $totals as $key => $total ) {
							if ( 'payment_method' === $key ) {
								continue;
							}
							?>
							<div class="flux-cart-totals <?php echo 'flux-cart-totals--' . esc_html( $key ); ?>">
								<div class="flux-cart-totals__label"><span><?php echo esc_html( trim( $total['label'], ':' ) ); ?></span></div>
								<div class="flux-cart-totals__value">
									<?php
									if ( 'order_total' === $key ) {
										echo sprintf( '<div class="flux-cart-totals__currency-badge">%s</div>', esc_html( $order->get_currency() ) );
									}
									?>
									<span><?php echo wp_kses_post( $total['value'] ); ?></span>
								</div>
							</div>
							<?php
						}
						?>
				</div>
		<?php
		
			}
		
		}
		
	function flux_thankyou_head_css(){
		if( is_checkout() ) {
			?>
			<style>
				.flux-ty-product-details:nth-child(2) { display: none; }
				.flux-checkout .flux-common-wrap__content-left section.woocommerce-order-details { display: none; }
				.flux-checkout .flux-common-wrap__content-left p.awcdp_deposits_summary_title { display: none; }
				.flux-checkout .flux-common-wrap__content-left table.woocommerce-table.awcdp_deposits_summary { display: none; }
				.flux-checkout .flux-common-wrap__content-left section.woocommerce-customer-details { display: none; }
			</style>	
			<?php
		}
	}


	function update_order_review_framents( $fragments ) { 
		$fragments['.flux-review-customer'] = Iconic_Flux_Steps::get_review_customer_fragment();
		
		// Heading with cart item count.
		ob_start();
		wc_get_template( 'checkout/cart-heading.php' );
		$fragments['.flux-heading--order-review'] = ob_get_clean();
	
		$get_total = WC()->cart->get_total(); 
			$awcdp_gs = get_option('awcdp_general_settings');
		$checkout_mode = ( isset($awcdp_gs['checkout_mode']) ) ? $awcdp_gs['checkout_mode'] : false;
	
		if($checkout_mode) {
		$display_rows = false;
		if ( isset($_POST['post_data'] )) {
			parse_str($_POST['post_data'], $post_data);
			$display_rows = isset($post_data['awcdp_deposit_option']) && $post_data['awcdp_deposit_option'] == 'deposit';
		}
		if ( $display_rows ) {
			if( isset(WC()->cart->deposit_info, WC()->cart->deposit_info['deposit_enabled'] ) && WC()->cart->deposit_info['deposit_enabled'] == true  ) {
				$get_total = wc_price(WC()->cart->deposit_info['deposit_amount']);
			}
		}
		} else if ( !$checkout_mode && (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true))  {
	
			$get_total = wc_price(WC()->cart->deposit_info['deposit_amount']) ;
			
		}
	
		$new_fragments = array(
			'total'        => $get_total,
			'shipping_row' => Iconic_Flux_Steps::get_shipping_row_mobile(),
		);
	
		if ( isset( $fragments['flux'] ) ) {
			$fragments['flux'] = array_merge( $fragments['flux'], $new_fragments );
		} else {
			$fragments['flux'] = $new_fragments;
		}
		
		return $fragments;
	}
		


	}
}

Comp_Iconic_Flux_Checkout::get_instance();