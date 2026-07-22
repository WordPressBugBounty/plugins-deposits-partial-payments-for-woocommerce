<?php
/**
 * Compatibility with WPC Buy Now Button for WooCommerce
 * 
 * @package AWCDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent running if WPC is not active
if ( ! class_exists( 'WPCleverWpcbn' ) && get_option( 'wpcbn_settings' ) === false ) {
    return;
}

/**
 * CRITICAL: Hook into WPC's redirect filter to force deposit calculation BEFORE redirect
 */
add_filter( 'wpcbn_redirect_url', 'awcdp_wpcbn_before_redirect', 999 );

function awcdp_wpcbn_before_redirect( $redirect_url ) {
    
    
    if ( ! WC()->cart ) {
        return $redirect_url;
    }

    WC()->cart->calculate_totals();
    
    do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );

    WC()->cart->calculate_totals();

    // Save session
    if ( WC()->session ) {
        WC()->session->set( 'awcdp_wpcbn_active', true );
        WC()->session->save_data();
    }
    
    return $redirect_url;
}

/**
 * EARLY HOOK: Set deposit option BEFORE WPC's handle_buy_now runs
 */
add_action( 'wp_loaded', 'awcdp_wpcbn_early_deposit_detection', 1 );

function awcdp_wpcbn_early_deposit_detection() {
    $settings = get_option( 'wpcbn_settings', array() );
    $param = ! empty( $settings['parameter'] ) ? sanitize_title( $settings['parameter'] ) : 'buy-now';
    
    if ( ! isset( $_REQUEST[ $param ] ) ) {
        return;
    }
    
    $product_id = absint( $_REQUEST[ $param ] );
    if ( ! $product_id ) {
        return;
    }
    
    // Check if deposits are enabled globally
    $awcdp_gs = get_option( 'awcdp_general_settings' );
    if ( ! isset( $awcdp_gs['enable_deposits'] ) || $awcdp_gs['enable_deposits'] != 1 ) {
        return;
    }
    
    // Check variation_id if present
    $actual_id = $product_id;
    if ( ! empty( $_REQUEST['variation_id'] ) ) {
        $actual_id = absint( $_REQUEST['variation_id'] );
    }
    
    // Get deposit type for this product
    $deposit_type = awcdp_wpcbn_get_deposit_type( $actual_id );
    
    // Only set deposit option if not already set
    if ( ! isset( $_REQUEST['awcdp_deposit_option'] ) || $_REQUEST['awcdp_deposit_option'] === '' ) {

        $deposit_value = awcdp_wpcbn_get_deposit_value( $actual_id );
        
        $_REQUEST['awcdp_deposit_option'] = $deposit_value;
        $_POST['awcdp_deposit_option'] = $deposit_value;
        $_GET['awcdp_deposit_option'] = $deposit_value;
        
    }
    
    // ALWAYS set payment plan if type is payment_plan and deposit is enabled
    if ( $_REQUEST['awcdp_deposit_option'] === 'yes' && $deposit_type === 'payment_plan' ) {
        $plan_key = 'awcdp-' . $product_id . '-plan';
        
        // Check if plan is already in request
        $existing_plan = null;
        foreach ( $_REQUEST as $key => $value ) {
            if ( strpos( $key, 'awcdp-' ) === 0 && strpos( $key, '-plan' ) !== false ) {
                $existing_plan = $value;
                break;
            }
        }
        
        if ( ! $existing_plan ) {
            $plan_id = awcdp_wpcbn_get_first_plan( $actual_id );
            
            if ( $plan_id ) {
                $_REQUEST[ $plan_key ] = $plan_id;
                $_POST[ $plan_key ] = $plan_id;
            }
        }
    }
    
    // Initialize session early
    if ( ! WC()->session ) {
        WC()->session = new WC_Session_Handler();
        WC()->session->init();
    }
    WC()->session->set( 'awcdp_wpcbn_active', true );
    WC()->session->set( 'deposit_enabled', true );
}

/**
 * Hook into add_to_cart at a late priority to ensure deposit data is set
 */
add_action( 'woocommerce_add_to_cart', 'awcdp_wpcbn_after_add_to_cart', 100, 6 );

function awcdp_wpcbn_after_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    $settings = get_option( 'wpcbn_settings', array() );
    $param = ! empty( $settings['parameter'] ) ? sanitize_title( $settings['parameter'] ) : 'buy-now';
    
    if ( ! isset( $_REQUEST[ $param ] ) ) {
        return;
    }
    
    
    if ( ! WC()->cart ) {
        return;
    }
    
    if( isset($_REQUEST['awcdp_deposit_option']) && $_REQUEST['awcdp_deposit_option'] === 'no' ) {
        return;
    }


    $cart_contents = WC()->cart->get_cart_contents();
    if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
        return;
    }
    
    $actual_id = $variation_id ? $variation_id : $product_id;
    $deposit_value = awcdp_wpcbn_get_deposit_value( $actual_id );
    $deposit_type = awcdp_wpcbn_get_deposit_type( $actual_id );

    if ( $deposit_value === 'yes' ) {
        // Ensure awcdp_deposit array exists
        if ( ! isset( WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit'] ) ) {
            WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit'] = array();
        }
        
        // Make sure enable is set to true
        WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit']['enable'] = true;
        
        // Save original price if not already set
        if ( ! isset( WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit']['original_price'] ) ) {
            $product = wc_get_product( $actual_id );
            if ( $product ) {
                WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit']['original_price'] = $product->get_price();
            }
        }
        
        // Handle payment plan
        if ( $deposit_type === 'payment_plan' ) {
            // Check for plan in REQUEST
            $plan_id = null;
            $plan_key = 'awcdp-' . $product_id . '-plan';
            
            

            if ( isset( $_REQUEST[ $plan_key ] ) ) {
                $plan_id = $_REQUEST[ $plan_key ];
            } else {
                // Search for any plan key
                foreach ( $_REQUEST as $key => $value ) {
                    if ( strpos( $key, 'awcdp-' ) === 0 && strpos( $key, '-plan' ) !== false ) {
                        $plan_id = $value;
                        break;
                    }
                }
            }
            
            // Fallback to first plan
            if ( ! $plan_id ) {
                $plan_id = awcdp_wpcbn_get_first_plan( $actual_id );
            }
            
            if ( $plan_id ) {
                WC()->cart->cart_contents[ $cart_item_key ]['awcdp_deposit']['payment_plan'] = $plan_id;
            }
        }
        

    }
}

/**
 * Determine deposit value for a product
 */
function awcdp_wpcbn_get_deposit_value( $product_id ) {
    $awcdp_gs = get_option( 'awcdp_general_settings' );
    
    // Check if forced globally
    if ( isset( $awcdp_gs['force_deposit'] ) && $awcdp_gs['force_deposit'] == 1 ) {
        return 'yes';
    }
    
    // Check if forced at product level
    $product = wc_get_product( $product_id );
    if ( $product ) {
        $force = '';
        if ( $product->get_type() === 'variation' ) {
            $override = $product->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $force = $product->get_meta( '_awcdp_deposits_force_deposit', true );
            } else {
                $parent_id = $product->get_parent_id();
                $force = get_post_meta( $parent_id, '_awcdp_deposits_force', true );
            }
        } else {
            $force = get_post_meta( $product_id, '_awcdp_deposits_force', true );
        }
        
        if ( $force === 'yes' ) {
            return 'yes';
        }
    }
    
    // Use default setting
    $default = isset( $awcdp_gs['default_selected'] ) ? $awcdp_gs['default_selected'] : 'deposit';
    return ( $default === 'deposit' ) ? 'yes' : 'no';
}

/**
 * Get deposit type for a product
 */
function awcdp_wpcbn_get_deposit_type( $product_id ) {
    $product = wc_get_product( $product_id );
    $type = '';
    
    if ( $product ) {
        if ( $product->get_type() === 'variation' ) {
            $override = $product->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $type = $product->get_meta( '_awcdp_deposits_amount_type', true );
            } else {
                $parent_id = $product->get_parent_id();
                $type = get_post_meta( $parent_id, '_awcdp_deposits_type', true );
            }
        } else {
            $type = get_post_meta( $product_id, '_awcdp_deposit_type', true );
        }
    }

    if ( ! $type ) {
        $awcdp_gs = get_option( 'awcdp_general_settings' );
        $type = isset( $awcdp_gs['deposit_type'] ) ? $awcdp_gs['deposit_type'] : 'percent';
    }
    
    return $type;
}

/**
 * Get first payment plan for a product
 */
function awcdp_wpcbn_get_first_plan( $product_id ) {
    $product = wc_get_product( $product_id );
    $plans = false;
    
    if ( $product ) {
        if ( $product->get_type() === 'variation' ) {
            $override = $product->get_meta( '_awcdp_deposits_override_product_settings', true ) === 'yes';
            if ( $override ) {
                $plans = $product->get_meta( '_awcdp_deposits_variation_plans', true );
            } else {
                $parent_id = $product->get_parent_id();
                $plans = get_post_meta( $parent_id, '_awcdp_deposits_payment_plans', true );
            }
        } else {
            $plans = get_post_meta( $product_id, '_awcdp_deposits_payment_plans', true );
        }
    }
    
    if ( ! $plans ) {
        $awcdp_gs = get_option( 'awcdp_general_settings' );
        $plans = isset( $awcdp_gs['payment_plan'] ) ? $awcdp_gs['payment_plan'] : array();
    }
    
    if ( is_array( $plans ) && ! empty( $plans ) ) {
        return reset( $plans );
    }
    
    return false;
}

/**
 * Set payment plan in request
 */
function awcdp_wpcbn_set_payment_plan( $product_id ) {
    $type = awcdp_wpcbn_get_deposit_type( $product_id );
    
    if ( $type !== 'payment_plan' ) {
        return;
    }
    
    $plan_key = 'awcdp-' . $product_id . '-plan';
    
    if ( isset( $_REQUEST[ $plan_key ] ) && ! empty( $_REQUEST[ $plan_key ] ) ) {
        return;
    }
    
    $plan_id = awcdp_wpcbn_get_first_plan( $product_id );
    
    if ( $plan_id ) {
        $_REQUEST[ $plan_key ] = $plan_id;
        $_POST[ $plan_key ] = $plan_id;
    }
}

/**
 * ARCHIVE BUTTON: Add deposit parameter AND payment plan to Buy Now links
 */
add_filter( 'wpcbn_btn_archive', 'awcdp_wpcbn_modify_archive_button', 10, 2 );

function awcdp_wpcbn_modify_archive_button( $output, $attrs ) {
    if ( empty( $output ) ) {
        return $output;
    }
    
    $product_id = isset( $attrs['id'] ) ? absint( $attrs['id'] ) : 0;
    if ( ! $product_id ) {
        return $output;
    }
    
    $awcdp_gs = get_option( 'awcdp_general_settings' );
    if ( ! isset( $awcdp_gs['enable_deposits'] ) || $awcdp_gs['enable_deposits'] != 1 ) {
        return $output;
    }
    
    $deposit_value = awcdp_wpcbn_get_deposit_value( $product_id );
    
    // Build the extra parameters
    $extra_params = 'awcdp_deposit_option=' . $deposit_value;
    
    // If deposit is enabled and product uses payment plans, add the plan parameter
    if ( $deposit_value === 'yes' ) {
        $type = awcdp_wpcbn_get_deposit_type( $product_id );
        if ( $type === 'payment_plan' ) {
            $plan_id = awcdp_wpcbn_get_first_plan( $product_id );
            if ( $plan_id ) {
                $extra_params .= '&awcdp-' . $product_id . '-plan=' . $plan_id;
            }
        }
    }
    
    $output = preg_replace_callback(
        '/href="([^"]+)"/',
        function( $matches ) use ( $extra_params ) {
            $url = $matches[1];
            if ( strpos( $url, 'awcdp_deposit_option' ) !== false ) {
                return $matches[0];
            }
            $sep = strpos( $url, '?' ) !== false ? '&' : '?';
            return 'href="' . esc_url( $url . $sep . $extra_params ) . '"';
        },
        $output
    );
    
    return $output;
}

/**
 * SINGLE PRODUCT: JavaScript to handle deposit selection
 */
add_action( 'wp_footer', 'awcdp_wpcbn_footer_script', 99 );

function awcdp_wpcbn_footer_script() {
    if ( ! is_product() ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        $(document).on('click', '.wpcbn-btn-single, button[name="buy-now"]', function(e) {
            var $form = $(this).closest('form.cart');
            if (!$form.length) return;
            
            var $depositRadio = $form.find('input[name="awcdp_deposit_option"]:checked');
            var depositVal = $depositRadio.length ? $depositRadio.val() : '';
            
            $form.find('input.awcdp-wpcbn-compat').remove();
            
            if (depositVal) {
                $form.append('<input type="hidden" name="awcdp_deposit_option" value="' + depositVal + '" class="awcdp-wpcbn-compat">');
            }
            
            var $planRadio = $form.find('input.awcdp-plan-radio:checked');
            if ($planRadio.length && depositVal === 'yes') {
                var planName = $planRadio.attr('name');
                var planVal = $planRadio.val();
                $form.append('<input type="hidden" name="' + planName + '" value="' + planVal + '" class="awcdp-wpcbn-compat">');
            }
        });
    });
    </script>
    <?php
}
