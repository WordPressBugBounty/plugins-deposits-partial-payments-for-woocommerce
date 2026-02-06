<?php

if (!defined('ABSPATH')){
    exit;
}

class AWCDP_Compatibility
{
	/**
	* @var    object
	* @access  private
	* @since    1.0.0
	*/
	private static $_instance = null;

	/**
	* The version number.
	* @var     string
	* @access  public
	* @since   1.0.0
	*/
	public $_version;
	private $_active = false;

	public function __construct() {

        if ($this->check_woocommerce_active()) {
            add_action('init', array($this, 'awcdp_load_compatibility'));
            add_action( 'init', array( $this, 'awcdp_wc_register_custom_post_status' ),1 );
        }

	}
	
	function awcdp_load_compatibility(){
		
        if ( class_exists( 'WFFN_Core' ) ) {
		    require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/funnel-builder.php';
        }
		
        if ( class_exists( 'WC_Bookings' ) ) {
		    require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/woocommerce-bookings.php';
        }

        if ( class_exists( 'WC_Appointments' ) ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/woocommerce-appointments.php';
        }

        if ( class_exists( 'Iconic_Flux_Checkout' ) ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/flux-checkout.php';
        }

        if (in_array('woo-stripe-payment/stripe-payments.php', apply_filters('active_plugins', get_option('active_plugins')))  ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/woo-stripe-payment.php';
        }

        if ( class_exists( 'WC_Order_Export_Admin' ) ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/woocommerce-order-export.php';
        }

        if ( class_exists( 'YITH_WCBK' ) ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/yith-woocommerce-booking-premium.php';
        }

        require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .  'compatibility/merchant-pro.php';

        require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'compatibility/pymntpl-paypal-woocommerce.php';
        
        // pixelyoursite
        if( function_exists('PYS') || class_exists('PixelYourSite\PYS') || class_exists('PixelYourSite\Events') || function_exists('pys_get_option') ){
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'compatibility/pixelyoursite.php';
        }

        if ( in_array('mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) || defined('M4W_PLUGIN_DIR') || class_exists('\Mollie\WooCommerce\Payment\MollieOrderService') ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'compatibility/mollie-payments.php';
        }
		
		if ( class_exists( 'WC_Klarna_Payments' ) || class_exists( 'KP_WC' ) || in_array('klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ) {
            require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'compatibility/klarna-payments.php';
        }

	}
		
    public function awcdp_wc_register_custom_post_status() {
        if ( is_admin() ) {
            register_post_status( 'wc-partial-payment', array(
                'label'                     => '<span class="status-partial-payment tips" data-tip="' . wc_sanitize_tooltip( _x( 'Partially Paid', 'deposits-partial-payments-for-woocommerce', 'deposits-partial-payments-for-woocommerce' ) ) . '">' . _x( 'Partially Paid', 'deposits-partial-payments-for-woocommerce', 'deposits-partial-payments-for-woocommerce' ) . '</span>',
                'exclude_from_search'       => false,
                'public'                    => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop( 'Partially Paid <span class="count">(%s)</span>', 'Partially Paid <span class="count">(%s)</span>', 'deposits-partial-payments-for-woocommerce' ),
            ) );
        }
    }

    public function check_woocommerce_active() { 
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        if (is_multisite()) {
            $plugins = get_site_option('active_sitewide_plugins');
            if (isset($plugins['woocommerce/woocommerce.php'])){
                return true;
            }
        }
        return false;
    }

    public static function instance($file = '', $version = '1.0.0') {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($file, $version);
        }
        return self::$_instance;
    }

    public function is_active() {
        return $this->_active !== false;
    }
   
    /**
     * Permission Callback
     **/
    public function get_permission()
    {
        if (current_user_can('administrator') || current_user_can('manage_woocommerce')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }


}
