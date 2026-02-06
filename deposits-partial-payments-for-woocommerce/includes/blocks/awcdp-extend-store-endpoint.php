<?php
/**
 * WooCommerce Store API Extension for Deposits
 *
 * @package AWCDP
 * @since 3.2.0
 */

use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;

if (!defined('ABSPATH')) {
    exit;
}

class AWCDP_Extend_Store_Endpoint {

    private static $extend;
    const IDENTIFIER = 'awcdp';

    public static function init() {
        self::$extend = StoreApi::container()->get(ExtendSchema::class);
        self::extend_store();
        
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'setup_deposit_for_rest'), 5);
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'trigger_deposit_calculation'), 999);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'ensure_deposit_before_order'), 1, 2);
    }
    
    private static function is_full_payment_selected() {
        if (!WC()->session) {
            return false;
        }
        return (WC()->session->get('awcdp_deposit_option') === 'full');
    }
    
    public static function setup_deposit_for_rest() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return;
        }
        
        if (!WC()->cart || !WC()->session) {
            return;
        }
        
        $awcdp_gs = get_option('awcdp_general_settings');
        
        if (!isset($awcdp_gs['enable_deposits']) || $awcdp_gs['enable_deposits'] != 1) {
            return;
        }
        
        $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
        
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        
        if ($checkout_mode) {
            $deposit_option = WC()->session->get('awcdp_deposit_option');
            
            if (!$deposit_option) {
                $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
                $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
                $deposit_option = $force_deposit ? 'deposit' : $default;
                WC()->session->set('awcdp_deposit_option', $deposit_option);
            }
            
            if ($deposit_option === 'full') {
                WC()->cart->deposit_info['deposit_enabled'] = false;
                WC()->session->set('deposit_enabled', false);
                $_POST['awcdp_deposit_option'] = 'full';
                return;
            }
            
            WC()->cart->deposit_info['deposit_enabled'] = true;
            WC()->session->set('deposit_enabled', true);
            
            $_POST['awcdp_deposit_option'] = 'deposit';
            
            $selected_plan = WC()->session->get('awcdp_selected_plan');
            if ($selected_plan) {
                $_POST['awcdp-selected-plan'] = $selected_plan;
            }
        }
    }
    
    public static function ensure_deposit_before_order($order, $request = null) {
        self::setup_deposit_for_rest();
        
        if (self::is_full_payment_selected()) {
            return;
        }
        
        if (!isset(WC()->cart->deposit_info['deposit_amount']) || WC()->cart->deposit_info['deposit_amount'] <= 0) {
            WC()->cart->calculate_totals();
        }
    }

    public static function trigger_deposit_calculation() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return;
        }
        
        if (!WC()->cart || !WC()->session) {
            return;
        }
        
        $awcdp_gs = get_option('awcdp_general_settings');
        
        if (!isset($awcdp_gs['enable_deposits']) || $awcdp_gs['enable_deposits'] != 1) {
            return;
        }
        
        $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
        
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        
        if ($checkout_mode) {
            $deposit_option = WC()->session->get('awcdp_deposit_option');
            
            if (!$deposit_option) {
                $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
                $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
                $deposit_option = $force_deposit ? 'deposit' : $default;
                WC()->session->set('awcdp_deposit_option', $deposit_option);
            }
            
            if ($deposit_option === 'full') {
                WC()->cart->deposit_info['deposit_enabled'] = false;
                WC()->session->set('deposit_enabled', false);
                return;
            }
            
            WC()->cart->deposit_info['deposit_enabled'] = true;
            WC()->session->set('deposit_enabled', true);
        }
    }

    public static function extend_store() {
        if (is_callable(array(self::$extend, 'register_endpoint_data'))) {
            self::$extend->register_endpoint_data(
                array(
                    'endpoint' => CartSchema::IDENTIFIER,
                    'namespace' => self::IDENTIFIER,
                    'data_callback' => array(__CLASS__, 'extend_cart_data'),
                    'schema_callback' => array(__CLASS__, 'extend_cart_schema'),
                    'schema_type' => ARRAY_N,
                )
            );
        }

        if (is_callable(array(self::$extend, 'register_endpoint_data'))) {
            self::$extend->register_endpoint_data(
                array(
                    'endpoint' => CheckoutSchema::IDENTIFIER,
                    'namespace' => self::IDENTIFIER,
                    'data_callback' => array(__CLASS__, 'extend_cart_data'),
                    'schema_callback' => array(__CLASS__, 'extend_cart_schema'),
                    'schema_type' => ARRAY_N,
                )
            );
        }

        if (is_callable(array(self::$extend, 'register_update_callback'))) {
            self::$extend->register_update_callback(
                array(
                    'namespace' => self::IDENTIFIER,
                    'callback' => array(__CLASS__, 'handle_deposit_update'),
                )
            );
        }
    }

    public static function handle_deposit_update($data) {
        if (!WC()->session || !WC()->cart) {
            return;
        }
        
        if (isset($data['deposit_option'])) {
            $deposit_option = sanitize_text_field($data['deposit_option']);
            WC()->session->set('awcdp_deposit_option', $deposit_option);
            
            if (!is_array(WC()->cart->deposit_info)) {
                WC()->cart->deposit_info = array();
            }
            
            if ($deposit_option === 'full') {
                WC()->cart->deposit_info['deposit_enabled'] = false;
                WC()->session->set('deposit_enabled', false);
                $_POST['awcdp_deposit_option'] = 'full';
            } else {
                WC()->cart->deposit_info['deposit_enabled'] = true;
                WC()->session->set('deposit_enabled', true);
                $_POST['awcdp_deposit_option'] = 'deposit';
            }
        }

        if (isset($data['selected_plan'])) {
            $selected_plan = absint($data['selected_plan']);
            WC()->session->set('awcdp_selected_plan', $selected_plan);
            WC()->session->set('awcdp-selected-plan', $selected_plan);
            $_POST['awcdp-selected-plan'] = $selected_plan;
        }

        WC()->cart->calculate_totals();
    }

    public static function extend_cart_data() {
        $data = array(
            'deposit_info' => array(
                'deposit_enabled' => false,
                'deposit_amount' => 0,
                'remaining_amount' => 0,
                'has_payment_plans' => false,
                'payment_schedule' => array(),
                'deposit_breakdown' => array(),
            ),
            'currently_selected' => 'deposit',
            'selected_plan' => null,
        );

        if (!WC()->cart || !WC()->session) {
            return $data;
        }

        $awcdp_gs = get_option('awcdp_general_settings');
        
        if (!isset($awcdp_gs['enable_deposits']) || $awcdp_gs['enable_deposits'] != 1) {
            return $data;
        }
        
        $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
        $deposit_option = WC()->session->get('awcdp_deposit_option');
        $selected_plan = WC()->session->get('awcdp_selected_plan');

        if (!$deposit_option) {
            $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
            $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
            $deposit_option = $force_deposit ? 'deposit' : $default;
            WC()->session->set('awcdp_deposit_option', $deposit_option);
        }

        $data['currently_selected'] = $deposit_option;
        $data['selected_plan'] = $selected_plan;

        if ($deposit_option === 'full') {
            $data['deposit_info']['deposit_enabled'] = false;
            return $data;
        }

        $cart_deposit_info = isset(WC()->cart->deposit_info) && is_array(WC()->cart->deposit_info) 
            ? WC()->cart->deposit_info 
            : array();
        
        $calculated_info = self::calculate_deposit_info($checkout_mode, $deposit_option, $awcdp_gs, $selected_plan);

        if (!empty($cart_deposit_info) && isset($cart_deposit_info['deposit_amount']) && $cart_deposit_info['deposit_amount'] > 0) {
            $deposit_enabled = isset($cart_deposit_info['deposit_enabled']) ? $cart_deposit_info['deposit_enabled'] : false;
            $deposit_amount = floatval($cart_deposit_info['deposit_amount']);
            $has_payment_plans = isset($cart_deposit_info['has_payment_plans']) ? $cart_deposit_info['has_payment_plans'] : false;
            $deposit_breakdown = isset($cart_deposit_info['deposit_breakdown']) ? $cart_deposit_info['deposit_breakdown'] : array();
            $payment_schedule = isset($cart_deposit_info['payment_schedule']) ? $cart_deposit_info['payment_schedule'] : array();
        } else {
            $deposit_enabled = $calculated_info['deposit_enabled'];
            $deposit_amount = $calculated_info['deposit_amount'];
            $has_payment_plans = $calculated_info['has_payment_plans'];
            $deposit_breakdown = $calculated_info['deposit_breakdown'];
            $payment_schedule = $calculated_info['payment_schedule'];
        }

        $data['deposit_info'] = array(
            'deposit_enabled' => $deposit_enabled,
            'deposit_amount' => $deposit_amount,
            'has_payment_plans' => $has_payment_plans,
            'payment_schedule' => self::format_payment_schedule($payment_schedule),
            'deposit_breakdown' => $deposit_breakdown,
        );
        
        if (!empty($data['deposit_info']['payment_schedule'])) {
            $remaining = 0;
            foreach ($data['deposit_info']['payment_schedule'] as $payment) {
                $remaining += isset($payment['total']) ? floatval($payment['total']) : 0;
            }
            $data['deposit_info']['remaining_amount'] = $remaining;
        } elseif ($deposit_amount > 0) {
            $cart_total = floatval(WC()->cart->get_total('edit'));
            $data['deposit_info']['remaining_amount'] = max(0, $cart_total - $deposit_amount);
        }
        return $data;
    }

    private static function calculate_deposit_info($checkout_mode, $deposit_option, $awcdp_gs, $selected_plan = null) {
        $deposit_info = array(
            'deposit_enabled' => false,
            'deposit_amount' => 0,
            'has_payment_plans' => false,
            'payment_schedule' => array(),
            'deposit_breakdown' => array(),
        );
        
        if (!WC()->cart) {
            return $deposit_info;
        }
        
        if ($deposit_option === 'full') {
            return $deposit_info;
        }
        
        if ($checkout_mode) {
            if ($deposit_option !== 'deposit') {
                return $deposit_info;
            }
            
            $cart_subtotal = floatval(WC()->cart->get_subtotal());
            $cart_total = floatval(WC()->cart->get_total('edit'));
            
            if ($cart_subtotal <= 0 && $cart_total <= 0) {
                return $deposit_info;
            }
            
            $base_amount = $cart_subtotal > 0 ? $cart_subtotal : $cart_total;
            $amount_type = isset($awcdp_gs['deposit_type']) ? $awcdp_gs['deposit_type'] : 'percent';
            $deposit_amount_setting = isset($awcdp_gs['deposit_amount']) ? floatval($awcdp_gs['deposit_amount']) : 0;
            
            $deposit_amount = 0;
            
            switch ($amount_type) {
                case 'fixed':
                    $deposit_amount = $deposit_amount_setting;
                    break;
                    
                case 'percent':
                    $deposit_amount = ($base_amount * $deposit_amount_setting) / 100;
                    break;
                    
                case 'payment_plan':
                    $available_plans = isset($awcdp_gs['payment_plan']) ? $awcdp_gs['payment_plan'] : array();
                    if (!empty($available_plans)) {
                        $plan_id = $selected_plan ? $selected_plan : (is_array($available_plans) ? $available_plans[0] : $available_plans);
                        
                        $deposit_percentage = get_post_meta($plan_id, 'deposit_percentage', true);
                        if ($deposit_percentage) {
                            $deposit_amount = ($base_amount * floatval($deposit_percentage)) / 100;
                            $deposit_info['has_payment_plans'] = true;
                            
                            $payment_details = get_post_meta($plan_id, 'payment_details', true);
                            if (is_array($payment_details)) {
                                $schedule = array();
                                $cumulative_timestamp = current_time('timestamp');
                                $cumulative_timestamp = apply_filters('awcdp_change_deposit_start_date', $cumulative_timestamp);
                                
                                foreach ($payment_details as $detail) {
                                    $payment_percentage = isset($detail['percentage']) ? floatval($detail['percentage']) : 0;
                                    $payment_amount = ($base_amount * $payment_percentage) / 100;
                                    
                                    if (isset($detail['date_term']) && $detail['date_term'] == 'ondate' && isset($detail['date']) && !empty($detail['date'])) {
                                        $cumulative_timestamp = strtotime($detail['date']);
                                    } elseif (isset($detail['after']) && isset($detail['after_term'])) {
                                        $after = intval($detail['after']);
                                        $after_term = strtolower(trim($detail['after_term']));
                                        $after_term = rtrim($after_term, 's');
                                        $after_term = str_replace(array('(', ')'), '', $after_term);
                                        
                                        $cumulative_timestamp = strtotime(date('Y-m-d', $cumulative_timestamp) . " +{$after} {$after_term}s");
                                    }
                                    
                                    $schedule[$cumulative_timestamp] = array(
                                        'type' => 'partial_payment',
                                        'total' => round($payment_amount, wc_get_price_decimals()),
                                    );
                                }
                                $deposit_info['payment_schedule'] = $schedule;
                            }
                        }
                    }
                    break;
            }
            
            if ($deposit_amount > 0 && $deposit_amount < $cart_total) {
                $deposit_info['deposit_enabled'] = true;
                $deposit_info['deposit_amount'] = round($deposit_amount, wc_get_price_decimals());
                
                if (empty($deposit_info['payment_schedule'])) {
                    $remaining = $cart_total - $deposit_amount;
                    $deposit_info['payment_schedule'] = array(
                        'unlimited' => array(
                            'type' => 'second_payment',
                            'total' => round($remaining, wc_get_price_decimals()),
                        )
                    );
                }
                
                $deposit_info['deposit_breakdown'] = array(
                    'cart_items' => round($deposit_amount, wc_get_price_decimals()),
                );
            } elseif ($deposit_amount >= $cart_total && $deposit_amount > 0) {
                $deposit_info['deposit_enabled'] = true;
                $deposit_info['deposit_amount'] = round($cart_total, wc_get_price_decimals());
                $deposit_info['payment_schedule'] = array();
                $deposit_info['deposit_breakdown'] = array(
                    'cart_items' => round($cart_total, wc_get_price_decimals()),
                );
            }
            
            return $deposit_info;
        }
        
        $deposit_amount = 0;
        $deposit_enabled = false;
        
        foreach (WC()->cart->get_cart_contents() as $cart_item) {
            if (isset($cart_item['awcdp_deposit']) && 
                isset($cart_item['awcdp_deposit']['enable']) && 
                $cart_item['awcdp_deposit']['enable'] == 1) {
                
                $deposit_enabled = true;
                
                if (isset($cart_item['awcdp_deposit']['deposit'])) {
                    $deposit_amount += floatval($cart_item['awcdp_deposit']['deposit']);
                }
            }
        }
        
        if ($deposit_enabled && $deposit_amount > 0) {
            $deposit_info['deposit_enabled'] = true;
            $deposit_info['deposit_amount'] = round($deposit_amount, wc_get_price_decimals());
            
            $cart_total = floatval(WC()->cart->get_total('edit'));
            $remaining = max(0, $cart_total - $deposit_amount);
            
            $deposit_info['payment_schedule'] = array(
                'unlimited' => array(
                    'type' => 'second_payment',
                    'total' => round($remaining, wc_get_price_decimals()),
                )
            );
            
            $deposit_info['deposit_breakdown'] = array(
                'cart_items' => round($deposit_amount, wc_get_price_decimals()),
            );
        }
        
        return $deposit_info;
    }

    private static function format_payment_schedule($schedule) {
        if (!is_array($schedule)) {
            return array();
        }

        $formatted = array();
        foreach ($schedule as $timestamp => $payment) {
            $formatted[] = array(
                'timestamp' => $timestamp === 'unlimited' || $timestamp === 'deposit' ? null : intval($timestamp),
                'type' => isset($payment['type']) ? $payment['type'] : 'partial_payment',
                'total' => isset($payment['total']) ? floatval($payment['total']) : 0,
                'id' => isset($payment['id']) ? $payment['id'] : null,
            );
        }

        return $formatted;
    }

    public static function extend_cart_schema() {
        return array(
            'deposit_info' => array(
                'type' => 'object',
                'description' => __('Deposit information', 'deposits-partial-payments-for-woocommerce'),
                'context' => array('view', 'edit'),
                'properties' => array(
                    'deposit_enabled' => array('type' => 'boolean'),
                    'deposit_amount' => array('type' => 'number'),
                    'remaining_amount' => array('type' => 'number'),
                    'has_payment_plans' => array('type' => 'boolean'),
                    'payment_schedule' => array('type' => 'array'),
                ),
            ),
            'currently_selected' => array(
                'type' => 'string',
                'context' => array('view', 'edit'),
            ),
            'selected_plan' => array(
                'type' => 'integer',
                'context' => array('view', 'edit'),
            ),
        );
    }
}
