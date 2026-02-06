<?php
/**
 * WooCommerce Blocks Checkout Handler for Deposits
 *
 * @package AWCDP
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AWCDP_Blocks_Checkout {

    public static function init() {
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'prepare_deposit_info'), 5, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'trigger_original_order_meta'), 5, 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'set_order_deposit_meta'), 15, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'create_partial_payments_for_order'), 20, 1);
        add_filter('woocommerce_payment_complete_order_status', array(__CLASS__, 'fix_schedule_before_status_check'), 5, 2);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'complete_deposit_partial_payment'), 5, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'sync_deposit_status_on_order_change'), 5, 4);

        add_action('woocommerce_thankyou', array(__CLASS__, 'show_blocks_thankyou_partial_payments'), 5, 1);
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'show_blocks_order_partial_payments_summary'), 5, 1);
    }

    private static function is_full_payment_selected() {
        if (!WC()->session) {
            return false;
        }
        return (WC()->session->get('awcdp_deposit_option') === 'full');
    }
    
    private static function ensure_schedule_has_ids($schedule) {
        if (!is_array($schedule)) {
            return $schedule;
        }
        
        $fixed_schedule = array();
        foreach ($schedule as $key => $payment) {
            if (!is_array($payment)) {
                continue;
            }
            if (!isset($payment['id'])) {
                $payment['id'] = '';
            }
            if (!isset($payment['type'])) {
                $payment['type'] = ($key === 'deposit') ? 'deposit' : 'partial_payment';
            }
            if (!isset($payment['total'])) {
                $payment['total'] = 0;
            }
            $fixed_schedule[$key] = $payment;
        }
        return $fixed_schedule;
    }

       
    private static function is_blocks_checkout_order($order) {
        $deposit_option = $order->get_meta('_awcdp_deposit_option', true);
        if ($deposit_option === 'deposit' || $deposit_option === 'full') {
            return true;
        }
        $created_via = $order->get_created_via();
        if ($created_via === 'store-api') {
            return true;
        }
        return false;
    }
    
    public static function show_blocks_thankyou_partial_payments($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Only handle partial payment orders here - show parent order summary
        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            $parent_order = wc_get_order($order->get_parent_id());
            if (!$parent_order) {
                return;
            }
            
            if (!self::is_blocks_checkout_order($parent_order)) {
                return;
            }
            
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
            
            if (apply_filters('awcdp_blocks_disable_orders_details_table', true)) {
                self::show_parent_order_summary($order);
            }
            
            remove_action('woocommerce_order_details_after_order_table', 'woocommerce_order_again_button');
        }
        // Main order partial payments table is now handled by woocommerce_order_details_after_order_table hook
    }
    
    public static function show_blocks_order_partial_payments_summary($order) {
        if (!$order) {
            return;
        }
        
        // Skip partial payment orders
        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }
        
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit !== 'yes') {
            return;
        }
        
        // Only show for blocks checkout orders
        if (!self::is_blocks_checkout_order($order)) {
            return;
        }

       if (is_account_page()) {
            return;
        }
        
        $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        if (!is_array($schedule) || empty($schedule)) {
            return;
        }
        
        // Works on both thank you page and my account order details page
        if (apply_filters('awcdp_blocks_show_partial_payments_summary', true, $order)) {
            self::render_partial_payments_summary($order->get_id(), $schedule);
        }
    }
    
    private static function show_parent_order_summary($partial_payment) {
        if (!class_exists('AWCDP_Deposits')) {
            return;
        }
        
        $atts = array(
            'order_id' => $partial_payment->get_parent_id(),
            'partial_payment' => $partial_payment,
        );
        
        $wsettings = new AWCDP_Deposits();
        echo $wsettings->awcdp_get_template('order/awcdp-order-details.php', $atts);
    }
    
    private static function render_partial_payments_summary($order_id, $schedule) {
        if (!class_exists('AWCDP_Deposits')) {
            return;
        }
        
        $atts = array(
            'order_id' => $order_id,
            'schedule' => $schedule,
        );
        
        $wsettings = new AWCDP_Deposits();
        echo $wsettings->awcdp_get_template('order/awcdp-partial-payment-details.php', $atts);
    }
    
    
    public static function complete_deposit_partial_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }
        
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit !== 'yes') {
            return;
        }
        
        $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        if (!is_array($schedule) || empty($schedule)) {
            return;
        }
        
        foreach ($schedule as $key => $payment) {
            if (!is_array($payment) || !isset($payment['type'])) {
                continue;
            }
            
            if ($payment['type'] === 'deposit' && !empty($payment['id'])) {
                $deposit_payment = wc_get_order($payment['id']);
                if ($deposit_payment && $deposit_payment->get_status() !== 'completed') {
                    $deposit_payment->set_payment_method($order->get_payment_method());
                    $deposit_payment->set_payment_method_title($order->get_payment_method_title());
                    $deposit_payment->set_status('completed');
                    $deposit_payment->add_order_note(__('Deposit payment completed via blocks checkout.', 'deposits-partial-payments-for-woocommerce'));
                    $deposit_payment->save();
                    
                    $order->update_meta_data('_awcdp_deposits_deposit_paid', 'yes');
                    $order->update_meta_data('_awcdp_deposits_deposit_payment_time', current_time('timestamp'));
                    $order->save();
                }
                break;
            }
        }
    }
    
    public static function sync_deposit_status_on_order_change($order_id, $old_status, $new_status, $order) {
        if (!$order) {
            return;
        }
        
        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }
        
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit !== 'yes') {
            return;
        }
        
        $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        if (!is_array($schedule) || empty($schedule)) {
            return;
        }
        
        foreach ($schedule as $key => $payment) {
            if (!is_array($payment) || !isset($payment['type'])) {
                continue;
            }
            
            if ($payment['type'] === 'deposit' && !empty($payment['id'])) {
                $deposit_payment = wc_get_order($payment['id']);
                if ($deposit_payment) {
                    $current_status = $deposit_payment->get_status();
                    
                    if ($current_status === 'completed') {
                        break;
                    }
                    
                    $deposit_payment->set_payment_method($order->get_payment_method());
                    $deposit_payment->set_payment_method_title($order->get_payment_method_title());
                    
                    if ($new_status === 'on-hold' && $current_status !== 'on-hold') {
                        $deposit_payment->set_status('on-hold');
                        $deposit_payment->add_order_note(__('Deposit payment awaiting payment confirmation (offline payment).', 'deposits-partial-payments-for-woocommerce'));
                        $deposit_payment->save();
                    } elseif ($new_status === 'pending' && $current_status !== 'pending') {
                        $deposit_payment->set_status('pending');
                        $deposit_payment->save();
                    } elseif ($new_status === 'processing' && $current_status !== 'completed' && $current_status !== 'processing') {
                        $deposit_payment->set_status('completed');
                        $deposit_payment->add_order_note(__('Deposit payment completed.', 'deposits-partial-payments-for-woocommerce'));
                        $deposit_payment->save();
                        
                        $order->update_meta_data('_awcdp_deposits_deposit_paid', 'yes');
                        $order->update_meta_data('_awcdp_deposits_deposit_payment_time', current_time('timestamp'));
                        $order->save();
                    } elseif ($new_status === 'cancelled' || $new_status === 'failed') {
                        $deposit_payment->set_status($new_status);
                        $deposit_payment->save();
                    }
                }
                break;
            }
        }
    }
    
    public static function fix_schedule_before_status_check($new_status, $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return $new_status;
        }
        
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit !== 'yes') {
            return $new_status;
        }
        
        $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        if (!is_array($schedule) || empty($schedule)) {
            return $new_status;
        }
        
        $needs_fix = false;
        foreach ($schedule as $payment) {
            if (is_array($payment) && !isset($payment['id'])) {
                $needs_fix = true;
                break;
            }
        }
        
        if ($needs_fix) {
            $fixed_schedule = self::ensure_schedule_has_ids($schedule);
            $order->update_meta_data('_awcdp_deposits_payment_schedule', $fixed_schedule);
            $order->save();
        }
        
        return $new_status;
    }

    public static function prepare_deposit_info($order, $request = null) {
        if (!WC()->cart || !WC()->session) {
            return;
        }
        
        $awcdp_gs = get_option('awcdp_general_settings');
        $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
        
        if (!$checkout_mode) {
            return;
        }
        
        if (!isset($awcdp_gs['enable_deposits']) || $awcdp_gs['enable_deposits'] != 1) {
            return;
        }
        
        $deposit_option = WC()->session->get('awcdp_deposit_option');
        
        if (!$deposit_option) {
            $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
            $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
            $deposit_option = $force_deposit ? 'deposit' : $default;
            WC()->session->set('awcdp_deposit_option', $deposit_option);
        }
        
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        
        if ($deposit_option === 'full') {
            $_POST['awcdp_deposit_option'] = 'full';
            $_REQUEST['awcdp_deposit_option'] = 'full';
            
            WC()->cart->deposit_info['deposit_enabled'] = false;
            WC()->session->set('deposit_enabled', false);
            
            $order->update_meta_data('_awcdp_deposit_option', 'full');
            $order->update_meta_data('_awcdp_is_deposit', 'no');
            $order->update_meta_data('_awcdp_deposits_order_has_deposit', 'no');
            return;
        }
        
        $_POST['awcdp_deposit_option'] = 'deposit';
        $_REQUEST['awcdp_deposit_option'] = 'deposit';
        
        $selected_plan = WC()->session->get('awcdp_selected_plan');
        if ($selected_plan) {
            $_POST['awcdp-selected-plan'] = $selected_plan;
            $_REQUEST['awcdp-selected-plan'] = $selected_plan;
        }
        
        WC()->cart->deposit_info['deposit_enabled'] = true;
        WC()->session->set('deposit_enabled', true);
        
        WC()->cart->calculate_totals();
    }

    public static function trigger_original_order_meta($order) {
        if (!$order) {
            return;
        }

        if (self::is_full_payment_selected()) {
            $order->update_meta_data('_awcdp_deposit_option', 'full');
            $order->update_meta_data('_awcdp_is_deposit', 'no');
            $order->update_meta_data('_awcdp_deposits_order_has_deposit', 'no');
            $order->save();
            return;
        }

        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }
        
        if (!WC()->cart || !isset(WC()->cart->deposit_info['deposit_amount'])) {
            self::prepare_deposit_info($order);
        }
        
        if (self::is_full_payment_selected()) {
            return;
        }
        
        if (!isset(WC()->cart->deposit_info['deposit_enabled']) || WC()->cart->deposit_info['deposit_enabled'] !== true) {
            return;
        }
        
        do_action('woocommerce_checkout_update_order_meta', $order->get_id(), array());
        
        $order->read_meta_data(true);
        
        $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        if (is_array($schedule) && !empty($schedule)) {
            $fixed_schedule = self::ensure_schedule_has_ids($schedule);
            $order->update_meta_data('_awcdp_deposits_payment_schedule', $fixed_schedule);
            $order->save();
        }
    }

    public static function set_order_deposit_meta($order) {
        if (!$order) {
            return;
        }

        if (self::is_full_payment_selected()) {
            $order->update_meta_data('_awcdp_deposit_option', 'full');
            $order->update_meta_data('_awcdp_is_deposit', 'no');
            $order->update_meta_data('_awcdp_deposits_order_has_deposit', 'no');
            $order->delete_meta_data('_awcdp_deposits_deposit_amount');
            $order->delete_meta_data('_awcdp_deposits_second_payment');
            $order->delete_meta_data('_awcdp_deposits_payment_schedule');
            $order->save();
            return;
        }

        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }
        
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit === 'yes') {
            $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
            if (is_array($schedule) && !empty($schedule)) {
                $fixed_schedule = self::ensure_schedule_has_ids($schedule);
                $order->update_meta_data('_awcdp_deposits_payment_schedule', $fixed_schedule);
                $order->save();
            }
            return;
        }

        if (!WC()->cart || !isset(WC()->cart->deposit_info)) {
            self::prepare_deposit_info($order);
        }

        if (self::is_full_payment_selected()) {
            return;
        }

        if (!isset(WC()->cart->deposit_info['deposit_enabled']) || WC()->cart->deposit_info['deposit_enabled'] !== true) {
            return;
        }

        $deposit = isset(WC()->cart->deposit_info['deposit_amount']) ? WC()->cart->deposit_info['deposit_amount'] : 0;
        
        if ($deposit <= 0) {
            return;
        }

        if (isset(WC()->cart->deposit_info['payment_schedule'])) {
            $second_payment = array_sum(array_column(WC()->cart->deposit_info['payment_schedule'], 'total'));
        } else {
            $second_payment = WC()->cart->get_total('edit') - $deposit;
        }

        $deposit_breakdown = isset(WC()->cart->deposit_info['deposit_breakdown']) ? WC()->cart->deposit_info['deposit_breakdown'] : array();
        $sorted_schedule = isset(WC()->cart->deposit_info['payment_schedule']) ? WC()->cart->deposit_info['payment_schedule'] : array();

        $dep_tax = 0;
        foreach ($deposit_breakdown as $dep_k => $dep_v) {
            if ($dep_k == 'taxes' || $dep_k == 'shipping_taxes') {
                $dep_tax += $dep_v;
            }
        }

        $deposit_data = array(
            'id' => '',
            'title' => esc_html__('Deposit', 'deposits-partial-payments-for-woocommerce'),
            'type' => 'deposit',
            'total' => $deposit,
            'taxes' => round($dep_tax, wc_get_price_decimals(), PHP_ROUND_HALF_DOWN),
            'cart_items' => isset($deposit_breakdown['cart_items']) ? $deposit_breakdown['cart_items'] : $deposit,
            'shipping' => isset($deposit_breakdown['shipping']) ? $deposit_breakdown['shipping'] : 0,
            'discount' => isset($deposit_breakdown['discount']) ? $deposit_breakdown['discount'] : 0,
            'discount_total' => isset($deposit_breakdown['discount_total']) ? $deposit_breakdown['discount_total'] : 0,
            'discount_tax' => isset($deposit_breakdown['discount_tax']) ? $deposit_breakdown['discount_tax'] : 0,
        );
        
        $sorted_schedule = self::ensure_schedule_has_ids($sorted_schedule);
        $sorted_schedule = array('deposit' => $deposit_data) + $sorted_schedule;
        
        $order->update_meta_data('_awcdp_deposits_payment_schedule', $sorted_schedule);
        $order->update_meta_data('_awcdp_deposits_order_has_deposit', 'yes');
        $order->update_meta_data('_awcdp_deposits_deposit_paid', 'no');
        $order->update_meta_data('_awcdp_deposits_second_payment_paid', 'no');
        $order->update_meta_data('_awcdp_deposits_deposit_amount', $deposit);
        $order->update_meta_data('_awcdp_deposits_second_payment', $second_payment);
        $order->update_meta_data('_awcdp_deposits_deposit_breakdown', $deposit_breakdown);
        $order->update_meta_data('_awcdp_deposits_deposit_payment_time', '');
        $order->update_meta_data('_awcdp_deposits_second_payment_reminder_email_sent', 'no');
        $order->save();
    }

    public static function create_partial_payments_for_order($order) {
        if (!$order) {
            return;
        }

        if (self::is_full_payment_selected()) {
            return;
        }

        if (defined('AWCDP_POST_TYPE') && $order->get_type() === AWCDP_POST_TYPE) {
            return;
        }

        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        
        if ($has_deposit !== 'yes') {
            return;
        }

        $payment_schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
        
        if (!is_array($payment_schedule) || empty($payment_schedule)) {
            return;
        }

        foreach ($payment_schedule as $payment) {
            if (is_array($payment) && !empty($payment['id'])) {
                return;
            }
        }

        self::create_partial_payments($order, $payment_schedule);
    }

    private static function create_partial_payments($order, $payment_schedule) {
        $user_agent = wc_get_user_agent();
        $order_vat_exempt = $order->get_meta('is_vat_exempt', true) ?: 'no';
        
        $awcdp_as = get_option('awcdp_advanced_settings');
        $taxe_split_display = (isset($awcdp_as['show_taxe_split_display']) && $awcdp_as['show_taxe_split_display'] == 1) ? 'yes' : 'no';
        
        $partial_payments_structure = 'single';
        if (class_exists('AWCDP_Partial_Payment_Structure')) {
            $partial_payments_structure = AWCDP_Partial_Payment_Structure::get_structure('checkout');
        }

        $deposit_id = null;

        foreach ($payment_schedule as $partial_key => $payment) {
            if (!is_array($payment)) {
                continue;
            }
            
            $partial_payment = new AWCDP_Order();
            $partial_payment->set_customer_id($order->get_customer_id());
            
            $amount = round(isset($payment['total']) ? $payment['total'] : 0, wc_get_price_decimals());
            
            $name = esc_html__('Partial Payment for order %s', 'deposits-partial-payments-for-woocommerce');
            $partial_payment_name = apply_filters('awcdp_deposits_partial_payment_name', sprintf($name, $order->get_order_number()), $payment, $order->get_id());

            if ($partial_payments_structure === 'copy_items' && class_exists('AWCDP_Partial_Payment_Structure')) {
                $partial_payment = AWCDP_Partial_Payment_Structure::create_itemized_partial_payment($partial_payment, $order, $payment, $partial_key);
                $partial_payment->set_total($amount);
            } else {
                $item = new WC_Order_Item_Fee();
                
                if ($taxe_split_display == 'yes' && isset($payment['cart_items'])) {
                    if ($partial_key === 'deposit') {
                        $fee_amount = round($payment['cart_items'], wc_get_price_decimals(), PHP_ROUND_HALF_DOWN);
                    } else {
                        $fee_amount = round($payment['cart_items'], wc_get_price_decimals(), PHP_ROUND_HALF_UP);
                    }
                    $item->set_props(array('total' => $fee_amount));
                } else {
                    $item->set_props(array('total' => $amount));
                }
                
                $item->set_name($partial_payment_name);
                $partial_payment->add_item($item);
                $partial_payment->add_meta_data('_awcdp_itemized_payments', 'no');
            }

            $partial_payment->set_parent_id($order->get_id());
            $partial_payment->add_meta_data('is_vat_exempt', $order_vat_exempt);
            $partial_payment->add_meta_data('_awcdp_deposits_payment_type', isset($payment['type']) ? $payment['type'] : 'partial_payment');
            $partial_payment->add_meta_data('_awcdp_deposits_payment_det', $payment);
            
            if (is_numeric($partial_key)) {
                $partial_payment->add_meta_data('_awcdp_deposits_partial_payment_date', $partial_key);
            }
            
            $partial_payment->set_currency($order->get_currency());
            $partial_payment->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
            $partial_payment->set_customer_ip_address($order->get_customer_ip_address());
            $partial_payment->set_customer_user_agent($user_agent);
            
            if ($partial_payments_structure !== 'copy_items') {
                $partial_payment->set_total($amount);
            }

            $partial_payment->set_billing_first_name($order->get_billing_first_name());
            $partial_payment->set_billing_last_name($order->get_billing_last_name());
            $partial_payment->set_billing_company($order->get_billing_company());
            $partial_payment->set_billing_address_1($order->get_billing_address_1());
            $partial_payment->set_billing_address_2($order->get_billing_address_2());
            $partial_payment->set_billing_city($order->get_billing_city());
            $partial_payment->set_billing_state($order->get_billing_state());
            $partial_payment->set_billing_postcode($order->get_billing_postcode());
            $partial_payment->set_billing_country($order->get_billing_country());
            $partial_payment->set_billing_email($order->get_billing_email());
            $partial_payment->set_billing_phone($order->get_billing_phone());

            $partial_payment->save();
            
            $payment_schedule[$partial_key]['id'] = $partial_payment->get_id();

            if ($taxe_split_display == 'yes' && $partial_payments_structure !== 'copy_items') {
                if (isset($payment['taxes']) && $payment['taxes'] != 0) {
                    $partial_payment->set_cart_tax($payment['taxes']);
                    $partial_payment->save();
                }

                if (isset($payment['shipping']) && $payment['shipping'] != 0) {
                    $item2 = new WC_Order_Item_Shipping();
                    $item2->set_shipping_rate(new WC_Shipping_Rate());
                    $item2->set_props(array('total' => $payment['shipping']));
                    $item2->set_order_id($partial_payment->get_id());
                    $partial_payment->add_item($item2);
                    $partial_payment->save();
                }

                if (isset($payment['discount_total']) && $payment['discount_total'] != 0) {
                    $discount_name = esc_html__('Coupon discount', 'deposits-partial-payments-for-woocommerce');
                    $itemC = new WC_Order_Item_Fee();
                    $itemC->set_name($discount_name);
                    $itemC->set_amount(-$payment['discount_total']);
                    $itemC->set_tax_status('none');
                    $itemC->set_total_tax(0);
                    $itemC->set_total(-$payment['discount_total']);
                    $partial_payment->add_item($itemC);
                    $partial_payment->save();
                }
            }

            if (isset($payment['type']) && $payment['type'] === 'deposit') {
                $deposit_id = $partial_payment->get_id();
                $partial_payment->set_payment_method($order->get_payment_method());
                $partial_payment->set_payment_method_title($order->get_payment_method_title());
                $partial_payment->save();
            }

            do_action('awcdp_deposits_do_partial_payment_meta', $partial_payment);

            if ($taxe_split_display == 'yes' || $partial_payments_structure === 'copy_items') {
                if (get_option('woocommerce_prices_include_tax') === 'yes') {
                    $partial_payment->calculate_totals(true);
                } else {
                    $partial_payment->calculate_totals();
                }
            }
            
            $partial_payment->save();
        }

        $order->update_meta_data('_awcdp_deposits_payment_schedule', $payment_schedule);
        
        if ($partial_payments_structure === 'copy_items') {
            $order->update_meta_data('_awcdp_itemized_payments', 'yes');
        } else {
            $order->update_meta_data('_awcdp_itemized_payments', 'no');
        }
        
        $order->save();
        
        self::sync_deposit_status_after_creation($order, $deposit_id);
    }
    
    private static function sync_deposit_status_after_creation($order, $deposit_id) {
        if (!$deposit_id) {
            return;
        }
        
        $deposit_payment = wc_get_order($deposit_id);
        if (!$deposit_payment) {
            return;
        }
        
        $main_order_status = $order->get_status();
        $deposit_status = $deposit_payment->get_status();
        
        if ($main_order_status === 'on-hold' && $deposit_status !== 'on-hold') {
            $deposit_payment->set_status('on-hold');
            $deposit_payment->add_order_note(__('Deposit payment awaiting payment confirmation (offline payment).', 'deposits-partial-payments-for-woocommerce'));
            $deposit_payment->save();
        } elseif (in_array($main_order_status, array('processing', 'completed'))) {
            $deposit_payment->set_status('completed');
            $deposit_payment->add_order_note(__('Deposit payment completed.', 'deposits-partial-payments-for-woocommerce'));
            $deposit_payment->save();
            
            $order->update_meta_data('_awcdp_deposits_deposit_paid', 'yes');
            $order->update_meta_data('_awcdp_deposits_deposit_payment_time', current_time('timestamp'));
            $order->save();
        }
    }
}
