<?php
/**
 * Compatibility with Klarna Payments for WooCommerce
 * https://wordpress.org/plugins/klarna-payments-for-woocommerce/
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Comp_Klarna_Payments' ) ) {

    class Comp_Klarna_Payments {

        /**
         * Singleton instance
         * @var Comp_Klarna_Payments|null
         */
        private static $instance = null;

        /**
         * Flag to check if Klarna Payments is active
         * @var bool
         */
        private $klarna_active = false;

        /**
         * Get singleton instance
         * @return Comp_Klarna_Payments
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor
         */
        public function __construct() {
            $this->klarna_active = $this->is_klarna_active();

            if ( $this->klarna_active ) {
                $this->init_hooks();
            }
        }

        /**
         * Initialize hooks
         */
        private function init_hooks() {
            // Filter the Klarna API request arguments to use deposit amount
            add_filter( 'kp_wc_api_request_args', array( $this, 'modify_klarna_request_args' ), 10, 1 );
            
            // Also filter both session creation and update filters
            add_filter( 'wc_klarna_payments_create_session_args', array( $this, 'modify_klarna_session_args' ), 10, 1 );
            add_filter( 'wc_klarna_payments_update_session_args', array( $this, 'modify_klarna_session_args' ), 10, 1 );
        }

        /**
         * Check if checkout mode is enabled in ACO Deposit settings
         * 
         * @return bool
         */
        private function awcdp_checkout_mode() {
            $awcdp_gs = get_option( 'awcdp_general_settings' );
            return isset( $awcdp_gs['checkout_mode'] ) ? (bool) $awcdp_gs['checkout_mode'] : false;
        }

        /**
         * Check if deposit is enabled for the current cart/checkout
         * 
         * @return bool
         */
        private function is_deposit_enabled() {
            if ( ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
                return false;
            }

            // Check if deposit info exists and is enabled
            if ( isset( WC()->cart->deposit_info ) && 
                 isset( WC()->cart->deposit_info['deposit_enabled'] ) && 
                 WC()->cart->deposit_info['deposit_enabled'] === true ) {
                return true;
            }

            // For checkout mode, also check POST data
            if ( $this->awcdp_checkout_mode() ) {
                $is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
                
                if ( $is_ajax && isset( $_POST['post_data'] ) ) {
                    parse_str( $_POST['post_data'], $post_data );
                    if ( isset( $post_data['awcdp_deposit_option'] ) && $post_data['awcdp_deposit_option'] === 'deposit' ) {
                        return true;
                    }
                }
                
                // Check direct POST
                if ( isset( $_POST['awcdp_deposit_option'] ) && $_POST['awcdp_deposit_option'] === 'deposit' ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Get the deposit amount from cart
         * 
         * @return float
         */
        private function get_deposit_amount() {
            if ( isset( WC()->cart->deposit_info ) && isset( WC()->cart->deposit_info['deposit_amount'] ) ) {
                return floatval( WC()->cart->deposit_info['deposit_amount'] );
            }
            return 0.0;
        }

        /**
         * Get the deposit tax amount from cart deposit breakdown
         * 
         * @return float
         */
        private function get_deposit_tax_amount() {
            if ( isset( WC()->cart->deposit_info ) && isset( WC()->cart->deposit_info['deposit_breakdown'] ) ) {
                $breakdown = WC()->cart->deposit_info['deposit_breakdown'];
                $tax = 0.0;
                
                if ( isset( $breakdown['taxes'] ) ) {
                    $tax += floatval( $breakdown['taxes'] );
                }
                if ( isset( $breakdown['shipping_taxes'] ) ) {
                    $tax += floatval( $breakdown['shipping_taxes'] );
                }
                
                return $tax;
            }
            return 0.0;
        }

        /**
         * Convert amount to minor units (cents/pence/öre)
         * 
         * Uses the currency's actual minor unit decimals, NOT WooCommerce's display setting.
         * Klarna always expects amounts in the currency's smallest unit.
         * 
         * @param float $amount The amount in major units
         * @return int The amount in minor units
         */
        private function to_minor_units( $amount ) {
            $decimals = $this->get_currency_decimals();
            return absint( round( $amount * pow( 10, $decimals ) ) );
        }

        /**
         * Get the actual decimal places for the currency's minor unit.
         * 
         * This returns the currency's true minor unit decimals, not WooCommerce's display setting.
         * For example, SEK always has 2 decimals (öre) even if WooCommerce displays 0.
         * 
         * @return int Number of decimals for the currency's minor unit
         */
        private function get_currency_decimals() {
            $currency = get_woocommerce_currency();
            
            // Currencies with 0 decimals (minor unit = major unit)
            $zero_decimal_currencies = array(
                'BIF', // Burundian Franc
                'CLP', // Chilean Peso
                'DJF', // Djiboutian Franc
                'GNF', // Guinean Franc
                'ISK', // Icelandic Króna
                'JPY', // Japanese Yen
                'KMF', // Comorian Franc
                'KRW', // South Korean Won
                'PYG', // Paraguayan Guarani
                'RWF', // Rwandan Franc
                'UGX', // Ugandan Shilling
                'VND', // Vietnamese Dong
                'VUV', // Vanuatu Vatu
                'XAF', // Central African CFA Franc
                'XOF', // West African CFA Franc
                'XPF', // CFP Franc
            );
            
            // Currencies with 3 decimals
            $three_decimal_currencies = array(
                'BHD', // Bahraini Dinar
                'IQD', // Iraqi Dinar
                'JOD', // Jordanian Dinar
                'KWD', // Kuwaiti Dinar
                'LYD', // Libyan Dinar
                'OMR', // Omani Rial
                'TND', // Tunisian Dinar
            );
            
            if ( in_array( $currency, $zero_decimal_currencies, true ) ) {
                return 0;
            }
            
            if ( in_array( $currency, $three_decimal_currencies, true ) ) {
                return 3;
            }
            
            // Default to 2 decimals for most currencies (EUR, USD, GBP, SEK, NOK, DKK, etc.)
            return 2;
        }

        /**
         * Modify Klarna session args (for create/update session)
         * 
         * @param array $args The request arguments
         * @return array Modified arguments
         */
        public function modify_klarna_session_args( $args ) {
            if ( ! isset( $args['body'] ) ) {
                return $args;
            }

            $body = json_decode( $args['body'], true );
            
            if ( ! is_array( $body ) ) {
                return $args;
            }

            $body = $this->modify_klarna_body( $body );
            $args['body'] = wp_json_encode( $body );

            return $args;
        }

        /**
         * Modify Klarna API request args 
         * This filter is applied to the body array directly
         * 
         * @param array $body The request body
         * @return array Modified body
         */
        public function modify_klarna_request_args( $body ) {
            // Only modify if it's an array (the body content)
            if ( ! is_array( $body ) ) {
                return $body;
            }

            return $this->modify_klarna_body( $body );
        }

        /**
         * Modify the Klarna request body to use deposit amounts
         * 
         * @param array $body The request body array
         * @return array Modified body
         */
        private function modify_klarna_body( $body ) {
            // Only proceed if deposit is enabled
            if ( ! $this->is_deposit_enabled() ) {
                return $body;
            }

            $deposit_amount = $this->get_deposit_amount();
            
            // If no deposit amount, don't modify
            if ( $deposit_amount <= 0 ) {
                return $body;
            }

            // Get current order amount for comparison
            $original_order_amount = isset( $body['order_amount'] ) ? $body['order_amount'] : 0;
            
            // Convert deposit to minor units (Klarna uses cents/pence)
            $deposit_minor = $this->to_minor_units( $deposit_amount );
            
            // Calculate the deposit tax in minor units
            $deposit_tax = $this->get_deposit_tax_amount();
            $deposit_tax_minor = $this->to_minor_units( $deposit_tax );

            // Calculate net amount (amount excluding tax)
            $deposit_net_minor = $deposit_minor - $deposit_tax_minor;

            // Only modify if the deposit is less than the original order amount
            if ( $deposit_minor >= $original_order_amount ) {
                return $body;
            }

            // Set the order amount to the deposit amount
            $body['order_amount'] = $deposit_minor;
            $body['order_tax_amount'] = $deposit_tax_minor;

            // Get text settings for labels
            $awcdp_ts = get_option( 'awcdp_text_settings' );
            $deposit_label = isset( $awcdp_ts['deposit_amount_text'] ) && ! empty( $awcdp_ts['deposit_amount_text'] ) 
                ? $awcdp_ts['deposit_amount_text'] 
                : __( 'Deposit Payment', 'deposits-partial-payments-for-woocommerce' );

            // Replace order lines with a single deposit line
            // This is necessary because Klarna validates that order_amount = sum of order_lines
            $body['order_lines'] = array(
                array(
                    'type'                  => 'physical',
                    'reference'             => 'DEPOSIT',
                    'name'                  => $deposit_label,
                    'quantity'              => 1,
                    'quantity_unit'         => 'pcs',
                    'unit_price'            => $deposit_minor,
                    'tax_rate'              => $this->calculate_effective_tax_rate( $deposit_net_minor, $deposit_tax_minor ),
                    'total_amount'          => $deposit_minor,
                    'total_discount_amount' => 0,
                    'total_tax_amount'      => $deposit_tax_minor,
                ),
            );

            return $body;
        }

        /**
         * Calculate effective tax rate in basis points (10000 = 100%)
         * 
         * @param int $net_amount Net amount in minor units
         * @param int $tax_amount Tax amount in minor units
         * @return int Tax rate in basis points
         */
        private function calculate_effective_tax_rate( $net_amount, $tax_amount ) {
            if ( $net_amount <= 0 ) {
                return 0;
            }

            // Klarna expects tax rate as basis points (10000 = 100%, so 25% = 2500)
            return absint( round( ( $tax_amount / $net_amount ) * 10000 ) );
        }

        /**
         * Check if Klarna Payments plugin is active
         * 
         * @return bool
         */
        private function is_klarna_active() {
            // Check by plugin file
            if ( in_array( 
                'klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php', 
                apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) 
            ) ) {
                return true;
            }

            // Check for multisite
            if ( is_multisite() ) {
                $plugins = get_site_option( 'active_sitewide_plugins' );
                if ( isset( $plugins['klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php'] ) ) {
                    return true;
                }
            }

            // Check by class existence
            if ( class_exists( 'WC_Klarna_Payments' ) || class_exists( 'KP_WC' ) ) {
                return true;
            }

            return false;
        }
    }
}

// Initialize the compatibility class
Comp_Klarna_Payments::get_instance();
