<?php
if (!defined('ABSPATH'))
    exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;    

class AWCDP_Backend
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

    /**
     * The token.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $_token;

    /**
     * The main plugin file.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $file;

    /**
     * The main plugin directory.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $dir;

    /**
     * The plugin assets directory.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $assets_dir;

    /**
     * Suffix for Javascripts.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $script_suffix;

    /**
     * The plugin assets URL.
     * @var     string
     * @access  public
     * @since   1.0.0
    */
    public $assets_url;
    public $hook_suffix = array();

    /**
     * Constructor function.
     * @access  public
     * @return  void
     * @since   1.0.0
    */
    public function __construct( $file = '', $version = '1.0.0' )
    {
        $this->_version = $version;
        $this->_token = AWCDP_TOKEN;
        $this->file = $file;
        $this->dir = dirname( $this->file );
        $this->assets_dir = trailingslashit( $this->dir ) . 'assets';
        $this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );
        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

		if ($this->check_woocommerce_active()) {

        //reg activation hook
        register_activation_hook( $this->file, array( $this, 'install' ) );
        //reg admin menu
        add_action( 'admin_menu', array( $this, 'register_root_page' ) );
        //enqueue scripts & styles
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 10, 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles' ), 10, 1 );
        add_action('admin_head', array( $this, 'awcdp_custom_styles') );

        $plugin = plugin_basename($this->file);
        //add action links to link to link list display on the plugins page
        add_filter( "plugin_action_links_$plugin", array( $this, 'add_settings_link' ) );

        //add_action('woocommerce_product_write_panel_tabs', array($this, 'awcdp_tab_panel_tabs'));
        add_filter( 'woocommerce_product_data_tabs', array($this, 'awcdp_custom_product_tab'), 10, 1 );
        add_action('woocommerce_product_data_panels', array($this, 'awcdp_tab_data_panels'));

        /*  order */
        add_filter( 'admin_body_class', array( $this, 'awcdp_admin_body_class') );
        add_action('admin_footer', array($this, 'awcdp_remove_statuses_deposit'));
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'awcdp_admin_order_totals_after_total'));

        add_action('add_meta_boxes', array($this, 'awcdp_partial_payments_metabox'), 31, 2);
        add_action('wp_ajax_awcdp_reload_payments_metabox', array($this, 'ajax_partial_payments_summary'), 10);
        add_action('woocommerce_ajax_add_order_item_meta', array($this, 'awcdp_add_order_item_meta'), 10, 2);
        add_action('woocommerce_order_after_calculate_totals', array($this, 'awcdp_recalculate_totals'), 10, 2);

        /// add_action('wp_ajax_wc_deposits_recalculate_deposit', array($this, 'recalculate_deposit_callback'));

        add_action('woocommerce_process_product_meta', array($this, 'awcdp_process_product_meta'));

		}

    }

    /**
     *
     *
     * Ensures only one instance of AWCDP is loaded or can be loaded.
     *
     * @return Main AWCDP instance
     * @see WordPress_Plugin_Template()
     * @since 1.0.0
     * @static
    */
    public static function instance($file = '', $version = '1.0.0')
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($file, $version);
        }
        return self::$_instance;
    }

	public function check_woocommerce_active() {
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        if (is_multisite()) {
            $plugins = get_site_option('active_sitewide_plugins');
            if (isset($plugins['woocommerce/woocommerce.php']))
                return true;
        }
        return false;
    }

    /**
     * Creating admin pages
     */
    public function register_root_page()
    {

        $this->hook_suffix[] = add_menu_page( esc_html__('Deposits For WooCommerce', 'deposits-partial-payments-for-woocommerce'), esc_html__('Deposits', 'deposits-partial-payments-for-woocommerce'), 'manage_woocommerce', AWCDP_TOKEN.'_admin_ui', array($this, 'admin_ui'), esc_url($this->assets_url) . '/images/icon.png', 25);
        // $this->hook_suffix[] = add_submenu_page( AWCDP_TOKEN.'_admin_ui', __('Settings', 'deposits-partial-payments-for-woocommerce'), __('Settings', 'deposits-partial-payments-for-woocommerce'), 'manage_woocommerce', AWCDP_TOKEN.'_settings_ui', array($this, 'admin_ui_settings'));


    }

    /**
     * Calling view function for admin page components
    */
    public function admin_ui()
    {
        AWCDP_Backend::view('admin-root', []);
    }

    /**
     * Adding new link(Configure) in plugin listing page section
    */
    public function add_settings_link($links)
    {
        $settings = '<a href="' . admin_url( 'admin.php?page='.AWCDP_TOKEN.'_admin_ui#/' ) . '">' . esc_html__( 'Settings', 'deposits-partial-payments-for-woocommerce' ) . '</a>';
        array_push( $links, $settings );
	      $upgrade    = '<a href="https://acowebs.com/woocommerce-deposits-partial-payments/" target="_blank" style="font-weight:600;color:#00a32a;">' . __('Upgrade to PRO','deposits-partial-payments-for-woocommerce') . '</a>';
        array_push( $links, $upgrade );
        return $links;
    }

    /**
     * Including View templates
    */
    static function view( $view, $data = array() )
    {
        //extract( $data );
        include( plugin_dir_path(__FILE__) . 'views/' . $view . '.php' );
    }


    function awcdp_admin_body_class( $classes ) {
      $current_screen = get_current_screen();
      if( $current_screen->id == 'edit-awcdp_payment' ){
        return "$classes post-type-shop_order";
      } else {
		return $classes;
	  }
    }


    /**
     * Load admin CSS.
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function admin_enqueue_styles($hook = '')
    {

      $currentScreen = get_current_screen();
      $screenID = $currentScreen->id; //
      if (strpos($screenID, 'awcdp_') !== false) {

        wp_register_style($this->_token . '-admin', esc_url($this->assets_url) . 'css/backend.css', array(), $this->_version);
        wp_enqueue_style($this->_token . '-admin');

      }

    }

    /**
     * Load admin Javascript.
     * @access  public
     * @return  void
     * @since   1.0.0
    */
    public function admin_enqueue_scripts($hook = '')
    {
        if (!isset($this->hook_suffix) || empty($this->hook_suffix)) {
            return;
        }

        $screen = get_current_screen();

        wp_enqueue_script('jquery');

        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $payment_gateway_options = array();
        foreach ( $payment_gateways as $gateway ) {
          $payment_gateway_options[ $gateway->id ] = $gateway->get_title();
        }
        $payment_gateway_options = array_map(function ($k, $v) {
            return array( 'id' => $k, 'name'  => $v );
        }, array_keys($payment_gateway_options), $payment_gateway_options);

        if ( in_array( $screen->id, $this->hook_suffix ) ) {
            if ( !wp_script_is( 'wp-i18n', 'registered' ) ) {
                wp_register_script( 'wp-i18n', esc_url( $this->assets_url ) . 'js/i18n.min.js', array('jquery'), $this->_version, true );
            }
            wp_enqueue_script( $this->_token . '-backend', esc_url( $this->assets_url ) . 'js/backend.js', array('wp-i18n'), $this->_version, true );
            wp_localize_script( $this->_token . '-backend', 'awcdp_object', array(
                    'api_nonce' => wp_create_nonce('wp_rest'),
                    'root' => rest_url('awcdp/v1/'),
                    'text_domain' => 'deposits-partial-payments-for-woocommerce',
                    'assets_url' => $this->assets_url,
                    'security' => wp_create_nonce('awcdp-deposits-partial-payments-refresh'),
                    'payment_gateways' => (array)$payment_gateway_options,
                )
            );

	         wp_set_script_translations($this->_token . '-backend', 'deposits-partial-payments-for-woocommerce' );
        }
    }

    function awcdp_custom_styles(){
      echo '<style>
    li.awcdp_deposits_tab a:before {
      content: "\e01e" !important;
      font-family: woocommerce !important;
    }
    
    .wc-orders-list-table-awcdp_payment .column-order_number a.order-preview { display: none; }
  </style>';
    }

    function awcdp_remove_statuses_deposit(){

      $current_screen = get_current_screen();
      if( $current_screen->id == 'awcdp_payment' || $current_screen->id == 'woocommerce_page_wc-orders--awcdp_payment' ){
        ?>
        <script>
            jQuery(document).ready(function ($) {
                jQuery('select#order_status').find('option[value="wc-partially-paid"]').remove();
                jQuery('select#order_status').find('option[value="wc-processing"]').remove();
            })
        </script>
        <?php
      }

    }

    function awcdp_custom_product_tab($default_tabs){
      $default_tabs['awcdp_deposits'] = array(
          'label'   =>  __( 'Deposits', 'deposits-partial-payments-for-woocommerce' ),
          'target'  =>  'awcdp_deposits_tab_data',
          'priority' => 90,
          'class'   => array('awcdp_deposits_tab')
      );
      return $default_tabs;
    }

    function awcdp_tab_panel_tabs(){
      ?>
        <li class="awcdp_deposits_tab"><a href="#awcdp_deposits_tab_data"><span><?php esc_html_e('Deposits', 'deposits-partial-payments-for-woocommerce'); ?></span></a></li>
      <?php
    }

    function awcdp_tab_data_panels(){
		
		
		$awcdp_gs = get_option('awcdp_general_settings');
		if( isset($awcdp_gs['enable_deposits']) && $awcdp_gs['enable_deposits'] == 1){
		  
		  global $post;
        $product = wc_get_product( $post->ID );
        if($product){
        ?>
        <div id="awcdp_deposits_tab_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <?php
                    woocommerce_wp_select(array(
                      'id' => '_awcdp_deposit_enabled',
                      'label' => esc_html__('Enable Deposit ', 'deposits-partial-payments-for-woocommerce'),
                      'options' => array(
                        'yes' => esc_html__('Yes', 'deposits-partial-payments-for-woocommerce'),
                        'no' => esc_html__('No', 'deposits-partial-payments-for-woocommerce'),
                      ),
                      'description' => esc_html__('Allow customers to pay a deposit for this product.', 'deposits-partial-payments-for-woocommerce'),
                      'desc_tip' => true,
                    ));

                    woocommerce_wp_select(array(
                      'id' => '_awcdp_deposit_type',
                      'label' => esc_html__('Deposit type', 'deposits-partial-payments-for-woocommerce'),
                      'options' => array(
                        '' => esc_html__('Select', 'deposits-partial-payments-for-woocommerce'),
                        'fixed' => esc_html__('Fixed', 'deposits-partial-payments-for-woocommerce'),
                        'percent' => esc_html__('Percentage', 'deposits-partial-payments-for-woocommerce'),
                      )
                    ));

                    woocommerce_wp_text_input(array(
                      'id' => '_awcdp_deposits_deposit_amount',
                      'label' => esc_html__('Deposit Amount', 'deposits-partial-payments-for-woocommerce'),
                      'description' => esc_html__('The amount of deposit needed. Do not include currency or percent symbols.', 'deposits-partial-payments-for-woocommerce'),
                      'desc_tip' => true,
                      'type' => 'number',
                      'custom_attributes' => array(
                          'min' => '0.0',
                          'step' => '0.01'
                      )
                    ));
                    ?>
                </p>
            </div>
        </div>
        <?php
		}
	  
		} else {
        ?>
        <div id="awcdp_deposits_tab_data" class="panel woocommerce_options_panel">
          <div class="options_group" style="padding: 20px;" >
            <h3><?php echo esc_html__('Deposit Disabled ', 'deposits-partial-payments-for-woocommerce'); ?></h3>
            <p style="padding-left: 0px;" >Please enable the deposit option from our <a href="<?php echo admin_url('admin.php?page=awcdp_admin_ui#/'); ?>" target="_blank" >settings</a> page.</p>
          </div>
        </div>
        <?php
      }  
	  
    }


    function awcdp_process_product_meta($post_id){

        $product = wc_get_product($post_id);
        $product_type = $product->get_type();

        $enable = isset($_POST['_awcdp_deposit_enabled']) ? sanitize_text_field($_POST['_awcdp_deposit_enabled']) : 'no';

        $type = isset($_POST['_awcdp_deposit_type']) ? sanitize_text_field($_POST['_awcdp_deposit_type']) : '';
        $amount = isset($_POST['_awcdp_deposits_deposit_amount']) &&
        is_numeric($_POST['_awcdp_deposits_deposit_amount']) ? floatval(sanitize_text_field($_POST['_awcdp_deposits_deposit_amount'])) : '';

		/*
        $type = isset($_POST['_awcdp_deposit_type']) ? sanitize_text_field($_POST['_awcdp_deposit_type']) : 'fixed';
        $amount = isset($_POST['_awcdp_deposits_deposit_amount']) &&
        is_numeric($_POST['_awcdp_deposits_deposit_amount']) ? floatval(sanitize_text_field($_POST['_awcdp_deposits_deposit_amount'])) : 0.0;
		*/

        $product->update_meta_data(AWCDP_DEPOSITS_META_KEY, $enable);
        $product->update_meta_data(AWCDP_DEPOSITS_TYPE, $type);
        $product->update_meta_data(AWCDP_DEPOSITS_AMOUNT, $amount);
        $product->save();
    }


    function awcdp_admin_order_totals_after_total($order_id){
        $order = wc_get_order($order_id);
        if ($order->get_type() == AWCDP_POST_TYPE) {
          return;
        }
        $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true);
        if ($has_deposit == 'yes') {
        $payments = $this->awcdp_get_order_partial_payments($order_id);
        $deposit = 0; $remaining = 0;

        if ($payments) {
          foreach ($payments as $payment) {
            if ($payment->get_meta('_awcdp_deposits_payment_type', true) == 'deposit') {
              $deposit += $payment->get_total() - $payment->get_total_refunded();
            } else {
              $remaining += $payment->get_total() - $payment->get_total_refunded();
            }
          }
        }

        ?>
        <tr>
          <td class="label"><?php esc_html_e('Deposit', 'deposits-partial-payments-for-woocommerce'); ?> : </td>
          <td width="1%"></td>
          <td class="total paid"><?php echo wp_kses_post( wc_price($deposit, array('currency' => $order->get_currency()))); ?></td>
        </tr>
        <tr class="awcdp-remaining">
          <td class="label"><?php esc_html_e('Future payments', 'deposits-partial-payments-for-woocommerce'); ?>:</td>
          <td width="1%"></td>
          <td class="total balance"><?php echo wp_kses_post( wc_price($remaining, array('currency' => $order->get_currency()))); ?></td>
        </tr>

        <?php
      }
    }

    function awcdp_get_order_partial_payments($order_id, $args = array(), $object = true){
      $orders = array();
        $default_args = array(
          'post_type' => AWCDP_POST_TYPE,
          'post_parent' => $order_id,
          'post_status' => 'any',
          'numberposts' => -1,
        );
        $args = ($args) ? wp_parse_args($args, $default_args) : $default_args;
        // $payments = get_posts($args);
        $payments = wc_get_orders($args);
        if ( $payments ) {
          foreach ( $payments as $payment) {
            // $orders[] = ($object) ? wc_get_order($payment->ID) : $payment->ID;
            $orders[] = ($object) ? wc_get_order($payment->get_id()) : $payment->ID;
          }
        }
        return $orders;
    }

    function awcdp_partial_payments_metabox( $post_type, $post_or_order_object ){
      // global $post;
      // $order = wc_get_order($post->ID);

      $order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : wc_get_order( get_the_id() );

      $screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
      $screen1 = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders--awcdp_payment' : AWCDP_POST_TYPE ;

      if ($order) {
        if ($order->get_type() == AWCDP_POST_TYPE) {
          add_meta_box(
            'awcdp_deposits_partial_payments',
            esc_html__('Partial Payments', 'deposits-partial-payments-for-woocommerce'),
            array($this, 'awcdp_original_order_details'),
            $screen1,
            'side',
            'high'
          );
        } else {
          $has_deposit = $order->get_meta('_awcdp_deposits_order_has_deposit', true) == 'yes';
          if ($has_deposit || $order->is_editable()) {
            add_meta_box(
              'awcdp_deposits_partial_payments',
              esc_html__('Partial payment details', 'deposits-partial-payments-for-woocommerce'),
              array($this, 'partial_payments_summary'),
              $screen,
              'normal',
              'high'
            );
          }
        }
      }

    }


    function partial_payments_summary($post_or_order_object){

      // global $post;
      // $order = wc_get_order($post->ID);

      $order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : wc_get_order( get_the_id() );
		  $post_or_order_object;

      $atts = array(
        'order' => $order,
      );
      $wsettings = new AWCDP_Deposits();
      echo $return_string = $wsettings->awcdp_get_template('admin/order-partial-payments.php', $atts );

    }

    function ajax_partial_payments_summary(){

      check_ajax_referer('awcdp-deposits-partial-payments-refresh', 'security');
      if (!current_user_can('edit_shop_orders')) {
        wp_die(-1);
      }
      $order_id = absint(sanitize_text_field($_POST['order_id']));
      $order = wc_get_order($order_id);
      if($order) {
        ob_start();
        $atts = array(
          'order' => $order,
        );
        $wsettings = new AWCDP_Deposits();
        echo $return_string = $wsettings->awcdp_get_template('admin/order-partial-payments.php', $atts );
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
      }
      wp_die();

    }

    function awcdp_original_order_details(){
      // global $post;
      //$order = wc_get_order($post->ID); 
      $order = wc_get_order( get_the_id() ); 
      if ($order){
        $parent = wc_get_order($order->get_parent_id());
        if ($parent){
          ?>
          <p><?php echo wp_kses_post( sprintf(__('This is a partial payment for order %s', 'deposits-partial-payments-for-woocommerce'), $parent->get_order_number()) ); ?></p>
          <a class="button btn" href="<?php echo esc_url($parent->get_edit_order_url()); ?> "> <?php esc_html_e('View', 'deposits-partial-payments-for-woocommerce'); ?> </a>
          <?php
        }
      }
    }

    function awcdp_add_order_item_meta($item_id, $item){

      $product = $item->get_product();
      $awcdp_gs = get_option('awcdp_general_settings');
      $default_checked = ( isset($awcdp_gs['default_selected']) ) ? $awcdp_gs['default_selected'] : 'deposit';

      if ( $default_checked != 'full'){

        $wfontend = new AWCDP_Front_End();
        $deposit_enabled = $wfontend->awcdp_deposits_enabled( $product->get_id() );

        if ( $deposit_enabled ) {
          $deposit = $this->awcdp_calculate_product_deposit($product);
          $price_include_tax = get_option('woocommerce_prices_include_tax');
          if ($price_include_tax == 'yes') {
            $amount = wc_get_price_including_tax($product);
          } else {
            $amount = wc_get_price_excluding_tax($product);
          }
          $deposit = $deposit * $item->get_quantity();
          $amount = $amount * $item->get_quantity();

          if ($deposit < $amount && $deposit > 0) {
            $deposit_meta['enable'] = 'yes';
            $deposit_meta['deposit'] = $deposit;
            $deposit_meta['remaining'] = $amount - $deposit;
            $deposit_meta['total'] = $amount;
            $item->add_meta_data('awcdp_deposit_meta', $deposit_meta, true);
            $item->save();
          }
        }
      }

    }

    function awcdp_calculate_product_deposit($product){

      $wfontend = new AWCDP_Front_End();
      $deposit_enabled = $wfontend->awcdp_deposits_enabled( $product->get_id() );
      $product_type = $product->get_type();
      if ($deposit_enabled) {

        $deposit = $wfontend->awcdp_get_deposit_amount($product->get_id());
        $deposit = floatval($deposit);
        $type = $wfontend->awcdp_get_deposit_type($product->get_id());

        $price_include_tax = get_option('woocommerce_prices_include_tax');
        if ($price_include_tax == 'yes') {
          $amount = wc_get_price_including_tax($product);
        } else {
          $amount = wc_get_price_excluding_tax($product);
        }

        switch ($product_type) {
            case 'subscription' :
                if (class_exists('WC_Subscriptions_Product')) {
                  $amount = \WC_Subscriptions_Product::get_sign_up_fee($product);
                  if ($type == 'fixed') {
                  } else {
                    $deposit = $amount * ($deposit / 100.0);
                  }
                }
                break;
            case 'yith_bundle' :
                $amount = $product->price_per_item_tot;
                if ($type == 'fixed') {
                } else {
                    $deposit = $amount * ($deposit / 100.0);
                }
                break;
            case 'variable' :
                if ($type == 'fixed') {
                } else {
                  $deposit = $amount * ($deposit / 100.0);
                }
                break;
            default:
                if ($type != 'fixed') {
                  $deposit = $amount * ($deposit / 100.0);
                }
                break;
        }
        return floatval($deposit);
      }
    }

    function awcdp_recalculate_totals($and_taxes, $order){

      $schedule = $order->get_meta('_awcdp_deposits_payment_schedule', true);
       if (!empty($schedule) && is_array($schedule)) {
           $payment = null; $second_payment_order = null;
           $total = 0.0;
           $due_payments = array();
           $due_payments_total = 0.0;

           foreach ($schedule as $payment) {
             $payment_order = wc_get_order($payment['id']);
             if ($payment_order){
                if ($payment['type'] !== 'deposit' && $payment_order->get_status() !== 'completed') {
                  $due_payments[] = $payment_order;
                  $due_payments_total += floatval($payment_order->get_total());
                }
                  $total += floatval($payment_order->get_total());
             }
           }

           $difference = floatval($order->get_total()) - $total;
           if ($difference > 0 || $difference < 0) {
               $positive = $difference > 0;
               $difference = abs($difference);
               $diff_record = $difference;
               $count = 0;
               foreach ($due_payments as $key => $due_payment) {
                   $count++;
                   $percentage = floatval($due_payment->get_total()) / $due_payments_total * 100;
                   $amount = $difference / 100 * $percentage;
                   if (count($due_payments) === $count) {
                     $amount = $diff_record;
                   } else {
                     $diff_record -= $amount;
                   }
                   if ($positive) {
                     foreach ($due_payment->get_fees() as $item) {
                       $item->set_total(floatval($item->get_total()) + $amount);
                       $item->save();
                     }
                   } else {
                     foreach ($due_payment->get_fees() as $item) {
                       $item->set_total(floatval($item->get_total()) - $amount);
                       $item->save();
                     }
                   }
                   $due_payment->calculate_totals(false);
                   $due_payment->save();
               }
               $second_payment = $order->get_meta('_awcdp_deposits_second_payment', true);
               if ($positive) {
                 $second_payment += $difference;
               } else {
                 $second_payment -= $difference;
               }
               $order->update_meta_data('_awcdp_deposits_second_payment', wc_format_decimal(floatval($second_payment)));
               $order->save();
           }
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

    /**
     * Installation. Runs on activation.
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function install()
    {
        $this->_log_version_number();

        flush_rewrite_rules();
    }

    /**
     * Log the plugin version number.
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    private function _log_version_number()
    {
        update_option($this->_token . '_version', $this->_version);
    }


}
