<?php
/**
 * PixelYourSite Compatibility for ACO WooCommerce Deposits Plugin
 * 
 * This class ensures that purchase events are properly tracked when deposits are enabled.
 * It handles initial deposit payments, partial payments, and full payment completions.
 * 
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AWCDP_PixelYourSite_Compatibility {
    
    /**
     * Constructor - Initialize hooks and filters
     */
    public function __construct() {
        // Check if PixelYourSite is active
        if (!$this->is_pixelyoursite_active()) {
            return;
        }
        
        $this->init_hooks();
    }
    
    /**
     * Check if PixelYourSite plugin is active
     * 
     * @return bool
     */
    private function is_pixelyoursite_active() {
        return function_exists('PYS') || 
               class_exists('PixelYourSite\PYS') || 
               class_exists('PixelYourSite\Events') ||
               function_exists('pys_get_option');
    }
    
    /**
     * Initialize all hooks and filters
     */
    private function init_hooks() {
        // Track purchase events for deposit orders
        add_action('woocommerce_order_status_partially-paid', array($this, 'track_deposit_purchase_event'), 999);
        add_action('woocommerce_order_status_completed', array($this, 'track_final_payment_event'), 999);
        add_action('woocommerce_order_status_processing', array($this, 'track_deposit_purchase_event'), 999);
        
        // Hook into after payment actions
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'), 999);
        add_action('awcdp_deposits_after_partial_payment', array($this, 'track_partial_payment'), 999);
        add_action('awcdp_deposits_after_full_payment', array($this, 'track_full_payment'), 999);
        
        // Modify PixelYourSite parameters
        add_filter('pys_woo_complete_payment_event_params', array($this, 'modify_pys_event_params'), 10, 2);
        add_filter('pys_purchase_params', array($this, 'modify_purchase_params'), 10, 2);
        
        // Register custom order status for tracking
        add_filter('pys_woo_order_tracking_status', array($this, 'modify_tracking_status'), 10, 2);
        
        // Ensure deposit orders are tracked
        add_filter('pys_woo_track_purchase_enabled', array($this, 'enable_purchase_tracking'), 10, 2);
        
        // Handle AJAX purchase tracking
        add_action('wp_ajax_awcdp_pys_track_purchase', array($this, 'handle_ajax_tracking'));
        add_action('wp_ajax_nopriv_awcdp_pys_track_purchase', array($this, 'handle_ajax_tracking'));
        
        // Add JavaScript for client-side tracking
        add_action('woocommerce_thankyou', array($this, 'add_tracking_script'), 5);
        
        // Register custom order types with PixelYourSite
        add_filter('pys_woo_custom_order_types', array($this, 'register_custom_order_types'));
        
        // Ensure events fire for deposit checkout
        add_filter('pys_disable_by_gdpr', array($this, 'ensure_deposit_tracking'), 10, 2);
    }
    
    /**
     * Track purchase event when deposit is paid
     * 
     * @param int $order_id
     */
    public function track_deposit_purchase_event($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_deposit_order($order)) {
            return;
        }
        
        // Check if event was already tracked
        $tracked = get_post_meta($order_id, '_awcdp_pys_purchase_tracked', true);
        if ($tracked === 'yes') {
            return;
        }
        
        // Get purchase data
        $purchase_data = $this->get_purchase_event_data($order);
        
        // Try multiple methods to fire the event
        $this->fire_purchase_event($purchase_data, $order);
        
        // Mark as tracked
        update_post_meta($order_id, '_awcdp_pys_purchase_tracked', 'yes');
    }
    
    /**
     * Track final payment event
     * 
     * @param int $order_id
     */
    public function track_final_payment_event($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_deposit_order($order)) {
            return;
        }
        
        // Check if the order has remaining balance
        $deposit_meta = get_post_meta($order_id, '_awcdp_deposits_order_has_deposit', true);
        $remaining_balance = floatval(get_post_meta($order_id, 'awcdp_deposits_balance_amount', true));
        
        if ($order->get_status() === 'completed' && $deposit_meta === 'yes' && $remaining_balance <= 0) {
            // Check if full payment was tracked
            $full_tracked = get_post_meta($order_id, '_awcdp_pys_full_payment_tracked', true);
            
            if ($full_tracked !== 'yes') {
                $purchase_data = $this->get_purchase_event_data($order, true);
                $this->fire_purchase_event($purchase_data, $order);
                
                update_post_meta($order_id, '_awcdp_pys_full_payment_tracked', 'yes');
            }
        }
    }
    
    /**
     * Handle payment complete action
     * 
     * @param int $order_id
     */
    public function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_deposit_order($order)) {
            return;
        }
        
        // Schedule tracking to ensure it fires after all other processes
        wp_schedule_single_event(time() + 2, 'awcdp_pys_deferred_tracking', array($order_id));
    }
    
    /**
     * Track partial payment
     * 
     * @param int $payment_id
     */
    public function track_partial_payment($payment_id) {
        $payment_order = wc_get_order($payment_id);
        
        if (!$payment_order || $payment_order->get_type() !== 'awcdp_payment') {
            return;
        }
        
        $parent_id = $payment_order->get_parent_id();
        $parent_order = wc_get_order($parent_id);
        
        if ($parent_order) {
            $purchase_data = array(
                'event_name' => 'PartialPayment',
                'order_id' => $parent_id,
                'payment_id' => $payment_id,
                'value' => $payment_order->get_total(),
                'currency' => $payment_order->get_currency(),
                'payment_method' => $payment_order->get_payment_method(),
            );
            
            $this->fire_custom_event('PartialPayment', $purchase_data);
        }
    }
    
    /**
     * Track full payment completion
     * 
     * @param int $order_id
     */
    public function track_full_payment($order_id) {
        $this->track_final_payment_event($order_id);
    }
    
    /**
     * Get purchase event data for tracking
     * 
     * @param WC_Order $order
     * @param bool $is_full_payment
     * @return array
     */
    private function get_purchase_event_data($order, $is_full_payment = false) {
        $items = array();
        $content_ids = array();
        $content_names = array();
        $categories = array();
        $num_items = 0;
        
        // Get order items data
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            $product_id = $product->get_id();
            $content_ids[] = (string) $product_id;
            $content_names[] = $product->get_name();
            
            // Get categories
            $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_categories);
            
            $items[] = array(
                'id' => (string) $product_id,
                'name' => $product->get_name(),
                'category' => implode(', ', $product_categories),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total() / $item->get_quantity(),
            );
            
            $num_items += $item->get_quantity();
        }
        
        // Calculate value based on payment type
        if ($is_full_payment) {
            $value = $order->get_total();
            $event_label = 'Full Payment';
        } else {
            // Get deposit amount from order meta
            $deposit_amount = floatval(get_post_meta($order->get_id(), '_awcdp_deposits_deposit_amount', true));
            
            // If not found, check order items for deposit data
            if (!$deposit_amount) {
                foreach ($order->get_items() as $item) {
                    $deposit_meta = $item->get_meta('_awcdp_deposits_deposit_amount');
                    if ($deposit_meta) {
                        $deposit_amount += floatval($deposit_meta);
                    }
                }
            }
            
            $value = $deposit_amount ?: $order->get_total();
            $event_label = 'Deposit Payment';
        }
        
        return array(
            'event_name' => 'Purchase',
            'event_label' => $event_label,
            'order_id' => $order->get_id(),
            'value' => (float) $value,
            'currency' => $order->get_currency(),
            'content_type' => 'product',
            'content_ids' => $content_ids,
            'content_name' => implode(', ', $content_names),
            'contents' => $items,
            'num_items' => $num_items,
            'categories' => array_unique($categories),
            'payment_method' => $order->get_payment_method(),
            'shipping' => (float) $order->get_shipping_total(),
            'tax' => (float) $order->get_total_tax(),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'is_deposit' => !$is_full_payment,
        );
    }
    
    /**
     * Fire purchase event using multiple methods
     * 
     * @param array $data
     * @param WC_Order $order
     */
    private function fire_purchase_event($data, $order) {
        // Method 1: Direct PYS function call
        if (function_exists('pys_add_event')) {
            pys_add_event('Purchase', $data);
        }
        
        // Method 2: Use PixelYourSite Events class
        if (class_exists('PixelYourSite\Events')) {
            try {
                $events = PixelYourSite\Events::getInstance();
                if (method_exists($events, 'addEvent')) {
                    $events->addEvent('woo', 'Purchase', $data);
                }
            } catch (Exception $e) {
                error_log('AWCDP PYS Compatibility: ' . $e->getMessage());
            }
        }
        
        // Method 3: Trigger WordPress action that PYS might listen to
        do_action('pys_event_purchase', $order, $data);
        do_action('pys_woo_purchase_event', $order, $data);
        
        // Method 4: Direct Facebook Pixel integration
        if (function_exists('pys_get_facebook_pixel')) {
            $facebook = pys_get_facebook_pixel();
            if ($facebook && method_exists($facebook, 'addEvent')) {
                $facebook->addEvent('Purchase', $data);
            }
        }
        
        // Method 5: Store event for later processing
        $this->store_event_for_processing($order, $data);
    }
    
    /**
     * Fire custom event
     * 
     * @param string $event_name
     * @param array $data
     */
    private function fire_custom_event($event_name, $data) {
        if (function_exists('pys_add_event')) {
            pys_add_event($event_name, $data);
        }
        
        do_action('pys_custom_event', $event_name, $data);
    }
    
    /**
     * Store event for later processing if direct firing fails
     * 
     * @param WC_Order $order
     * @param array $data
     */
    private function store_event_for_processing($order, $data) {
        $stored_events = get_option('awcdp_pys_pending_events', array());
        $stored_events[$order->get_id()] = $data;
        update_option('awcdp_pys_pending_events', $stored_events);
    }
    
    /**
     * Modify PYS event parameters
     * 
     * @param array $params
     * @param WC_Order $order
     * @return array
     */
    public function modify_pys_event_params($params, $order) {
        if (!$order || !$this->is_deposit_order($order)) {
            return $params;
        }
        
        // Add deposit information to params
        $params['is_deposit_order'] = true;
        
        $deposit_amount = floatval(get_post_meta($order->get_id(), '_awcdp_deposits_deposit_amount', true));
        if ($deposit_amount) {
            $params['deposit_amount'] = $deposit_amount;
        }
        
        $remaining_balance = floatval(get_post_meta($order->get_id(), 'awcdp_deposits_balance_amount', true));
        if ($remaining_balance) {
            $params['remaining_balance'] = $remaining_balance;
        }
        
        // Adjust value for deposit orders if needed
        if ($order->get_status() === 'partially-paid' && $deposit_amount) {
            $params['value'] = (float) $deposit_amount;
        }
        
        return $params;
    }
    
    /**
     * Modify purchase parameters
     * 
     * @param array $params
     * @param array $args
     * @return array
     */
    public function modify_purchase_params($params, $args) {
        if (isset($args['order_id'])) {
            $order = wc_get_order($args['order_id']);
            
            if ($order && $this->is_deposit_order($order)) {
                $params['deposit_enabled'] = true;
                $params['payment_plan'] = get_post_meta($order->get_id(), '_awcdp_deposits_payment_plan', true);
            }
        }
        
        return $params;
    }
    
    /**
     * Modify tracking status for deposit orders
     * 
     * @param string $status
     * @param WC_Order $order
     * @return string
     */
    public function modify_tracking_status($status, $order) {
        if ($status === 'partially-paid' && $this->is_deposit_order($order)) {
            // Treat partially-paid as processing for tracking purposes
            return 'processing';
        }
        
        return $status;
    }
    
    /**
     * Enable purchase tracking for deposit orders
     * 
     * @param bool $enabled
     * @param WC_Order $order
     * @return bool
     */
    public function enable_purchase_tracking($enabled, $order) {
        if ($this->is_deposit_order($order)) {
            return true;
        }
        
        return $enabled;
    }
    
    /**
     * Register custom order types with PixelYourSite
     * 
     * @param array $types
     * @return array
     */
    public function register_custom_order_types($types) {
        $types[] = 'awcdp_payment';
        return $types;
    }
    
    /**
     * Ensure deposit tracking is not disabled by GDPR settings
     * 
     * @param bool $disable
     * @param string $context
     * @return bool
     */
    public function ensure_deposit_tracking($disable, $context = '') {
        if ($context === 'purchase' && isset($_GET['key'])) {
            $order_id = wc_get_order_id_by_order_key($_GET['key']);
            $order = wc_get_order($order_id);
            
            if ($order && $this->is_deposit_order($order)) {
                // Don't disable tracking for deposit orders
                return false;
            }
        }
        
        return $disable;
    }
    
    /**
     * Add tracking script to thank you page
     * 
     * @param int $order_id
     */
    public function add_tracking_script($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_deposit_order($order)) {
            return;
        }
        
        $purchase_data = $this->get_purchase_event_data($order);
        
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Ensure PixelYourSite is loaded
                if (typeof pys !== 'undefined' || typeof pysOptions !== 'undefined') {
                    // Try to fire event directly
                    try {
                        var purchaseData = <?php echo json_encode($purchase_data); ?>;
                        
                        // Try different methods to fire the event
                        if (typeof pys !== 'undefined' && typeof pys.fireEvent === 'function') {
                            pys.fireEvent('Purchase', purchaseData);
                        } else if (typeof fbq !== 'undefined') {
                            // Direct Facebook Pixel call as fallback
                            fbq('track', 'Purchase', {
                                value: purchaseData.value,
                                currency: purchaseData.currency,
                                content_type: 'product',
                                content_ids: purchaseData.content_ids,
                                contents: purchaseData.contents,
                                num_items: purchaseData.num_items,
                            });
                        }
                    } catch(e) {
                        console.error('AWCDP PYS Tracking Error:', e);
                    }
                    
                    // Also send via AJAX for server-side tracking
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'awcdp_pys_track_purchase',
                            order_id: <?php echo $order_id; ?>,
                            nonce: '<?php echo wp_create_nonce('awcdp_pys_tracking'); ?>',
                            purchase_data: purchaseData
                        },
                        success: function(response) {
                            console.log('AWCDP PYS tracking completed');
                        }
                    });
                }
            });
        </script>
        <?php
    }
    
    /**
     * Handle AJAX tracking request
     */
    public function handle_ajax_tracking() {
        check_ajax_referer('awcdp_pys_tracking', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $purchase_data = isset($_POST['purchase_data']) ? $_POST['purchase_data'] : array();
        
        if ($order_id && !empty($purchase_data)) {
            $order = wc_get_order($order_id);
            
            if ($order) {
                $this->fire_purchase_event($purchase_data, $order);
                wp_send_json_success(array('message' => 'Event tracked successfully'));
            }
        }
        
        wp_send_json_error(array('message' => 'Failed to track event'));
    }
    
    /**
     * Check if order is a deposit order
     * 
     * @param WC_Order $order
     * @return bool
     */
    private function is_deposit_order($order) {
        if (!$order) {
            return false;
        }
        
        // Check various deposit indicators
        $has_deposit_meta = get_post_meta($order->get_id(), '_awcdp_deposits_order_has_deposit', true) === 'yes';
        $deposit_amount = floatval(get_post_meta($order->get_id(), '_awcdp_deposits_deposit_amount', true));
        
        // Check order items for deposit information
        $has_deposit_items = false;
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_awcdp_deposits_deposit_amount')) {
                $has_deposit_items = true;
                break;
            }
        }
        
        // Check if order status is partially-paid
        $is_partially_paid = $order->get_status() === 'partially-paid';
        
        // Check if this is a partial payment order type
        $is_payment_order = $order->get_type() === 'awcdp_payment';
        
        return $has_deposit_meta || 
               $deposit_amount > 0 || 
               $has_deposit_items || 
               $is_partially_paid ||
               $is_payment_order;
    }
}

// Initialize the compatibility class
add_action('init', function() {
    new AWCDP_PixelYourSite_Compatibility();
});

// Handle deferred tracking
add_action('awcdp_pys_deferred_tracking', function($order_id) {
    $compat = new AWCDP_PixelYourSite_Compatibility();
    $compat->track_deposit_purchase_event($order_id);
});
