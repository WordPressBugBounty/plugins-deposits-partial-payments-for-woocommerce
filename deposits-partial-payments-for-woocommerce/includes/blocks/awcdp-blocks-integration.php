<?php
/**
 * WooCommerce Blocks Integration Class
 *
 * @package AWCDP
 * @since 3.2.0
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

class AWCDP_Blocks_Integration implements IntegrationInterface {

    public function get_name() {
        return 'awcdp-blocks';
    }

    public function initialize() {
        $this->register_block_frontend_scripts();
        $this->register_block_editor_scripts();
        $this->register_main_integration();

        add_action('enqueue_block_assets', array($this, 'register_block_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }

    public function register_main_integration() {
        $script_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/index.js';
        $script_url = plugins_url('includes/blocks/build/index.js', AWCDP_FILE);
        $script_asset_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/index.asset.php';
        
        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version' => $this->get_file_version($script_path),
            );

        $dependencies = $script_asset['dependencies'];
        $required_deps = array(
            'wp-element',
            'wp-data',
            'wp-plugins',
            'wp-i18n',
            'wc-blocks-checkout',
            'wc-blocks-components',
            'wc-blocks-data-store',
            'wc-settings',
            'wc-price-format',
        );
        
        foreach ($required_deps as $dep) {
            if (!in_array($dep, $dependencies)) {
                $dependencies[] = $dep;
            }
        }

        wp_register_script(
            'awcdp-blocks-integration',
            $script_url,
            $dependencies,
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'awcdp-blocks-integration',
            'deposits-partial-payments-for-woocommerce',
            AWCDP_PLUGIN_PATH . 'languages'
        );
    }

    public function get_script_handles() {
        $handles = array('awcdp-blocks-integration');
        foreach ($this->get_block_handles() as $handle) {
            $handles[] = 'awcdp-blocks-' . $handle . '-frontend';
        }
        return $handles;
    }

    public function get_editor_script_handles() {
        $handles = array('awcdp-blocks-integration');
        foreach ($this->get_block_handles() as $handle) {
            $handles[] = 'awcdp-blocks-' . $handle . '-editor';
        }
        return $handles;
    }

    private function get_block_handles() {
        return array('deposit-slider', 'partial-payments-details');
    }

    public function get_script_data() {
        $awcdp_gs = get_option('awcdp_general_settings');
        
        $default_options = array(
            'checkout_mode' => false,
            'amount_type' => 'fixed',
            'deposit_amount' => '',
            'force_deposit' => false,
            'default_checked' => 'deposit',
            'deposit_text' => __('Pay Deposit', 'deposits-partial-payments-for-woocommerce'),
            'full_text' => __('Pay Full Amount', 'deposits-partial-payments-for-woocommerce'),
            'deposit_option_text' => __('Pay a deposit of', 'deposits-partial-payments-for-woocommerce'),
            'to_pay_text' => __('Due Today', 'deposits-partial-payments-for-woocommerce'),
            'future_payment_text' => __('Future Payments', 'deposits-partial-payments-for-woocommerce'),
            'payment_plans' => array(),
            'basic_buttons' => false,
            'hide_when_forced' => false,
        );
        
        if (!$awcdp_gs || !isset($awcdp_gs['enable_deposits']) || $awcdp_gs['enable_deposits'] != 1) {
            return array('disabled' => true, 'options' => $default_options);
        }

        $checkout_mode_raw = isset($awcdp_gs['checkout_mode']) ? $awcdp_gs['checkout_mode'] : null;
        $checkout_mode = ($checkout_mode_raw === true || $checkout_mode_raw === 1 || $checkout_mode_raw === '1' || $checkout_mode_raw === 'yes');

        $amount_type = isset($awcdp_gs['deposit_type']) ? $awcdp_gs['deposit_type'] : 'fixed';
        $deposit_amount = isset($awcdp_gs['deposit_amount']) ? $awcdp_gs['deposit_amount'] : '';
        $force_deposit = isset($awcdp_gs['force_deposit']) && ($awcdp_gs['force_deposit'] == 1 || $awcdp_gs['force_deposit'] === true);
        $default_checked = isset($awcdp_gs['default_selected']) ? $awcdp_gs['default_selected'] : 'deposit';
        
        $awcdp_ts = get_option('awcdp_text_settings');
        $deposit_text = isset($awcdp_ts['pay_deposit_text']) && $awcdp_ts['pay_deposit_text'] != '' 
            ? $awcdp_ts['pay_deposit_text'] 
            : __('Pay Deposit', 'deposits-partial-payments-for-woocommerce');
        $full_text = isset($awcdp_ts['pay_full_text']) && $awcdp_ts['pay_full_text'] != '' 
            ? $awcdp_ts['pay_full_text'] 
            : __('Pay Full Amount', 'deposits-partial-payments-for-woocommerce');
        $deposit_option_text = isset($awcdp_ts['deposit_text']) && $awcdp_ts['deposit_text'] != '' 
            ? $awcdp_ts['deposit_text'] 
            : __('Pay a deposit of', 'deposits-partial-payments-for-woocommerce');
        $to_pay_text = isset($awcdp_ts['to_pay_text']) && $awcdp_ts['to_pay_text'] != '' 
            ? $awcdp_ts['to_pay_text'] 
            : __('Due Today', 'deposits-partial-payments-for-woocommerce');
        $future_payment_text = isset($awcdp_ts['future_payment_text']) && $awcdp_ts['future_payment_text'] != '' 
            ? $awcdp_ts['future_payment_text'] 
            : __('Future Payments', 'deposits-partial-payments-for-woocommerce');

        $payment_plans = array();
        if ($amount_type === 'payment_plan') {
            $available_plans = isset($awcdp_gs['payment_plan']) ? $awcdp_gs['payment_plan'] : array();
            $available_plans = apply_filters('awcdp_checkout_deposit_plans', $available_plans);
            
            if (is_array($available_plans)) {
                foreach ($available_plans as $plan_id) {
                    if (get_post_status($plan_id) !== 'publish') {
                        continue;
                    }
                    
                    $plan_post = get_post($plan_id);
                    if ($plan_post) {
                        $deposit_percentage = get_post_meta($plan_id, 'deposit_percentage', true);
                        $payment_details = get_post_meta($plan_id, 'payment_details', true);
                        
                        $processed_details = array();
                        if (is_array($payment_details)) {
                            $cumulative_date = current_time('timestamp');
                            $cumulative_date = apply_filters('awcdp_change_deposit_start_date', $cumulative_date);
                            
                            foreach ($payment_details as $detail) {
                                $processed_detail = array(
                                    'percentage' => isset($detail['percentage']) ? floatval($detail['percentage']) : 0,
                                );
                                
                                if (isset($detail['date_term']) && $detail['date_term'] == 'ondate' && isset($detail['date']) && !empty($detail['date'])) {
                                    $cumulative_date = strtotime($detail['date']);
                                    $processed_detail['date'] = $cumulative_date;
                                } elseif (isset($detail['after']) && isset($detail['after_term'])) {
                                    $processed_detail['after'] = intval($detail['after']);
                                    $processed_detail['after_term'] = $detail['after_term'];
                                    
                                    $after = intval($detail['after']);
                                    $after_term = strtolower(trim($detail['after_term']));
                                    $after_term = rtrim($after_term, 's');
                                    $after_term = str_replace(array('(', ')'), '', $after_term);
                                    
                                    $cumulative_date = strtotime(date('Y-m-d', $cumulative_date) . " +{$after} {$after_term}s");
                                    
                                    if ($cumulative_date) {
                                        $processed_detail['date'] = $cumulative_date;
                                    }
                                }
                                
                                $processed_details[] = $processed_detail;
                            }
                        }
                        
                        $payment_plans[] = array(
                            'id' => $plan_id,
                            'name' => $plan_post->post_title,
                            'description' => $plan_post->post_content,
                            'deposit_percentage' => floatval($deposit_percentage),
                            'details' => $processed_details,
                        );
                    }
                }
            }
        }

        return array(
            'disabled' => false,
            'options' => array(
                'checkout_mode' => $checkout_mode,
                'amount_type' => $amount_type,
                'deposit_amount' => $deposit_amount,
                'force_deposit' => $force_deposit,
                'default_checked' => $default_checked,
                'deposit_text' => $deposit_text,
                'full_text' => $full_text,
                'deposit_option_text' => $deposit_option_text,
                'to_pay_text' => $to_pay_text,
                'future_payment_text' => $future_payment_text,
                'payment_plans' => $payment_plans,
                'basic_buttons' => false,
                'hide_when_forced' => false,
            ),
        );
    }

    public function register_block_styles() {
        if (!has_block('woocommerce/checkout') && !has_block('woocommerce/cart')) {
            return;
        }

        foreach ($this->get_block_handles() as $handle) {
            $style_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-' . $handle . '.css';
            $style_url = plugins_url('includes/blocks/build/awcdp-blocks-' . $handle . '.css', AWCDP_FILE);
            
            if (file_exists($style_path)) {
                wp_enqueue_style(
                    'awcdp-blocks-' . $handle,
                    $style_url,
                    array(),
                    $this->get_file_version($style_path)
                );
            }
        }
        
        wp_enqueue_style(
            'awcdp-blocks-frontend',
            plugins_url('assets/css/frontend.css', AWCDP_FILE),
            array(),
            AWCDP_VERSION
        );
    }
    
    public function enqueue_frontend_styles() {
        if (!is_checkout()) {
            return;
        }
        
        foreach ($this->get_block_handles() as $handle) {
            $style_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-' . $handle . '.css';
            $style_url = plugins_url('includes/blocks/build/awcdp-blocks-' . $handle . '.css', AWCDP_FILE);
            
            if (file_exists($style_path)) {
                wp_enqueue_style(
                    'awcdp-blocks-' . $handle,
                    $style_url,
                    array(),
                    $this->get_file_version($style_path)
                );
            }
        }
    }

    public function register_block_editor_scripts() {
        wp_enqueue_script('wp-date');

        foreach ($this->get_block_handles() as $handle) {
            $script_url = plugins_url('includes/blocks/build/awcdp-blocks-' . $handle . '.js', AWCDP_FILE);
            $script_asset_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-' . $handle . '.asset.php';
            
            $script_asset = file_exists($script_asset_path)
                ? require $script_asset_path
                : array('dependencies' => array(), 'version' => AWCDP_VERSION);

            wp_register_script(
                'awcdp-blocks-' . $handle . '-editor',
                $script_url,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );

            wp_set_script_translations(
                'awcdp-blocks-' . $handle . '-editor',
                'deposits-partial-payments-for-woocommerce',
                AWCDP_PLUGIN_PATH . 'languages'
            );
        }
    }

    public function register_block_frontend_scripts() {
        foreach ($this->get_block_handles() as $handle) {
            $script_url = plugins_url('includes/blocks/build/awcdp-blocks-' . $handle . '.js', AWCDP_FILE);
            $script_asset_path = AWCDP_PLUGIN_PATH . 'includes/blocks/build/awcdp-blocks-' . $handle . '.asset.php';
            
            $script_asset = file_exists($script_asset_path)
                ? require $script_asset_path
                : array('dependencies' => array(), 'version' => AWCDP_VERSION);

            wp_register_script(
                'awcdp-blocks-' . $handle . '-frontend',
                $script_url,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );

            wp_set_script_translations(
                'awcdp-blocks-' . $handle . '-frontend',
                'deposits-partial-payments-for-woocommerce',
                AWCDP_PLUGIN_PATH . 'languages'
            );
        }
    }

    protected function get_file_version($file) {
        if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG && file_exists($file)) {
            return filemtime($file);
        }
        return AWCDP_VERSION;
    }
}
