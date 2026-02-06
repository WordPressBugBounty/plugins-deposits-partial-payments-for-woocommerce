<?php
/**
 * WooCommerce Blocks Integration for Deposits & Partial Payments
 *
 * @package AWCDP
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('woocommerce_init', function() {
    if (file_exists(__DIR__ . '/awcdp-blocks-checkout.php')) {
        require_once __DIR__ . '/awcdp-blocks-checkout.php';
        AWCDP_Blocks_Checkout::init();
    }
}, 10);

add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('Automattic\WooCommerce\StoreApi\StoreApi')) {
        return;
    }
    
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'awcdp-blocks-integration.php';
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'awcdp-extend-store-endpoint.php';

    AWCDP_Extend_Store_Endpoint::init();

    add_action('woocommerce_blocks_cart_block_registration', function($integration_registry) {
        $integration_registry->register(new AWCDP_Blocks_Integration());
    });

    add_action('woocommerce_blocks_checkout_block_registration', function($integration_registry) {
        $integration_registry->register(new AWCDP_Blocks_Integration());
    });
});

add_action('wp_enqueue_scripts', function() {
    if (!is_checkout()) {
        return;
    }
    
    $slider_css = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-deposit-slider.css';
    if (file_exists($slider_css)) {
        wp_enqueue_style(
            'awcdp-blocks-deposit-slider',
            plugins_url('includes/blocks/build/awcdp-blocks-deposit-slider.css', AWCDP_FILE),
            array(),
            filemtime($slider_css)
        );
    }
    
    $partial_css = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-partial-payments-details.css';
    if (file_exists($partial_css)) {
        wp_enqueue_style(
            'awcdp-blocks-partial-payments-details',
            plugins_url('includes/blocks/build/awcdp-blocks-partial-payments-details.css', AWCDP_FILE),
            array(),
            filemtime($partial_css)
        );
    }
}, 20);

add_filter('block_categories_all', 'awcdp_register_block_category', 10);
add_filter('block_categories', 'awcdp_register_block_category', 10);

function awcdp_register_block_category($categories) {
    return array_merge(
        $categories,
        array(
            array(
                'slug'  => 'awcdp-blocks',
                'title' => __('Deposits & Partial Payments Blocks', 'deposits-partial-payments-for-woocommerce'),
            ),
        )
    );
}

add_action('init', 'awcdp_register_blocks');

function awcdp_register_blocks() {
    $blocks_dir = __DIR__ . '/build/js/';
    
    if (file_exists($blocks_dir . 'deposit-slider/block.json')) {
        register_block_type_from_metadata($blocks_dir . 'deposit-slider/block.json');
    }
    
    if (file_exists($blocks_dir . 'partial-payments-details/block.json')) {
        register_block_type_from_metadata($blocks_dir . 'partial-payments-details/block.json');
    }
}

function awcdp_blocks_is_full_payment_selected() {
    if (!WC()->session) {
        return false;
    }
    return (WC()->session->get('awcdp_deposit_option') === 'full');
}

add_action('woocommerce_before_calculate_totals', function() {
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }
    
    if (!WC()->session) {
        return;
    }
    
    $awcdp_gs = get_option('awcdp_general_settings');
    $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
    
    if (!$checkout_mode) {
        return;
    }
    
    $deposit_option = WC()->session->get('awcdp_deposit_option');
    
    if (!$deposit_option) {
        $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
        $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
        $deposit_option = $force_deposit ? 'deposit' : $default;
        WC()->session->set('awcdp_deposit_option', $deposit_option);
    }
    
    $_POST['awcdp_deposit_option'] = $deposit_option;
    
    if ($deposit_option === 'full') {
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        WC()->cart->deposit_info['deposit_enabled'] = false;
        WC()->session->set('deposit_enabled', false);
        return;
    }
    
    $selected_plan = WC()->session->get('awcdp_selected_plan');
    if ($selected_plan && !isset($_POST['awcdp-selected-plan'])) {
        $_POST['awcdp-selected-plan'] = $selected_plan;
    }
    
    if (!is_array(WC()->cart->deposit_info)) {
        WC()->cart->deposit_info = array();
    }
    
    WC()->cart->deposit_info['deposit_enabled'] = true;
    WC()->session->set('deposit_enabled', true);
    
}, 1);

add_action('woocommerce_cart_calculate_fees', function() {
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }
    
    if (!WC()->session) {
        return;
    }
    
    $awcdp_gs = get_option('awcdp_general_settings');
    $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
    
    if (!$checkout_mode) {
        return;
    }
    
    $deposit_option = WC()->session->get('awcdp_deposit_option');
    
    if (!$deposit_option) {
        $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
        $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
        $deposit_option = $force_deposit ? 'deposit' : $default;
        WC()->session->set('awcdp_deposit_option', $deposit_option);
    }
    
    $_POST['awcdp_deposit_option'] = $deposit_option;
    
    if ($deposit_option === 'full') {
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        WC()->cart->deposit_info['deposit_enabled'] = false;
        WC()->session->set('deposit_enabled', false);
        return;
    }
    
    $selected_plan = WC()->session->get('awcdp_selected_plan');
    if ($selected_plan && !isset($_POST['awcdp-selected-plan'])) {
        $_POST['awcdp-selected-plan'] = $selected_plan;
    }
    
}, 1);

add_action('woocommerce_store_api_checkout_update_order_from_request', function($order) {
    if (!WC()->session || !WC()->cart) {
        return;
    }
    
    $awcdp_gs = get_option('awcdp_general_settings');
    $checkout_mode = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : false;
    
    if (!$checkout_mode) {
        return;
    }
    
    $deposit_option = WC()->session->get('awcdp_deposit_option');
    
    if (!$deposit_option) {
        $default = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
        $force_deposit = isset($awcdp_gs['force_deposit']) && $awcdp_gs['force_deposit'] == 1;
        $deposit_option = $force_deposit ? 'deposit' : $default;
    }
    
    $_POST['awcdp_deposit_option'] = $deposit_option;
    
    if ($deposit_option === 'full') {
        if (!is_array(WC()->cart->deposit_info)) {
            WC()->cart->deposit_info = array();
        }
        WC()->cart->deposit_info['deposit_enabled'] = false;
        WC()->session->set('deposit_enabled', false);
        
        $order->update_meta_data('_awcdp_deposit_option', 'full');
        $order->update_meta_data('_awcdp_is_deposit', 'no');
        $order->update_meta_data('_awcdp_deposits_order_has_deposit', 'no');
        return;
    }
    
    $selected_plan = WC()->session->get('awcdp_selected_plan');
    if ($selected_plan) {
        $_POST['awcdp-selected-plan'] = $selected_plan;
    }
    
    if (!is_array(WC()->cart->deposit_info)) {
        WC()->cart->deposit_info = array();
    }
    WC()->cart->deposit_info['deposit_enabled'] = true;
    
    WC()->cart->calculate_totals();
    
}, 5);
