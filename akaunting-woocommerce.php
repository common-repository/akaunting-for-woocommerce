<?php
/*
Plugin Name: Akaunting for WooCommerce
Description: Akaunting is a free and online accounting software. This plugin integrates Akaunting with WooCommerce.
Version: 2.0.2
Author: Akaunting
Author URI: https://akaunting.com
Developer: Akaunting
Developer URI: https://akaunting.com
Text Domain: akaunting-woocommerce
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class AkaWoo
{
	public function __construct()
    {
        $this->constants();

        require_once(AKAWOO_PATH . 'library/helper.php');

        $this->init();
	}

    public function init()
    {
        global $wp_customize;

        add_action('save_post_product', array($this, 'woo_product'));
        add_action('user_register', array($this, 'woo_customer'));
        add_action('woocommerce_checkout_order_processed', array($this, 'woo_order'));
        add_action('woocommerce_update_order', array($this, 'woo_order'));

        add_action( 'rest_api_init', function () {
            register_rest_route( 'wc-akaunting-for-woocommerce/v1', '/get_custom_field/(?P<id>\d+)', array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_custom_field'),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ) );
            register_rest_route( 'wc-akaunting-for-woocommerce/v1', '/get_custom_fields', array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_custom_fields'),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ) );
        } );

        add_filter('woocommerce_rest_product_object_query', array($this, 'filter_by_post_modified'), 10, 2);
        add_filter('woocommerce_rest_product_variation_object_query', array($this, 'filter_by_post_modified'), 10, 2);
        add_filter('woocommerce_rest_orders_prepare_object_query', array($this, 'filter_by_post_modified'), 10, 2);
        add_filter('woocommerce_rest_customer_query', array($this, 'filter_by_last_update'), 10, 2);

        if (!is_admin()) {
            // to do
        } elseif (!isset($wp_customize)) {
            add_action('admin_menu', array($this, 'menu'));
            add_action('admin_init', array($this, 'register_options'));
            add_filter('pre_update_option', array($this, 'check_empty_options'), 10, 2);
            add_action('plugin_action_links_' . AKAWOO_NAME, array($this, 'action_links'));
            add_filter('plugin_row_meta', array($this, 'meta_links'), 10, 2);
            add_action('send_success', array($this, 'send_success'));

            /*add_action('http_api_curl', function( $handle ) {
                curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($handle, CURLOPT_MAXREDIRS, 5);
                curl_setopt($handle, CURLOPT_HEADER, 1);
            }, 10);*/

            //$action = get_query_var('akawoo');
            if (isset($_GET['akawoo'])) {
                $action = $_GET['akawoo'];

                if (method_exists($this, $action)) {
                    $this->$action();
                }
            }
        }
    }

    public function filter_by_post_modified(array $args, \WP_REST_Request $request) {
        $updated_since = $request->get_param('updated_since');

        if (!$updated_since) {
            return $args;
        }

        $args['date_query'][] = [
                'column' => 'post_modified',
                'after' => $updated_since,
        ];

        return $args;
    }

    public function filter_by_last_update(array $args, \WP_REST_Request $request) {
        $updated_since = $request->get_param('updated_since');

        if (!$updated_since) {
            return $args;
        }

        $args['meta_query'][] = [
            'key' => 'last_update',
            'type'  => 'NUMERIC',
            'value'  => strtotime( $updated_since ),
            'compare'  => '>',
        ];

        return $args;
    }

    public function get_custom_fields($request)
    {
        global $wpdb;

        $sql   = "SELECT DISTINCT meta_key
			FROM $wpdb->postmeta
			ORDER BY meta_key";
        $keys  = $this->get_col( $wpdb->prepare( $sql ));

        return new WP_REST_Response( $keys, 200 );
    }

    protected function get_col($query)
    {
        global $wpdb;

        $wpdb->query( $query );

        $new_array = array();
        // Extract the column values.
        if ( $wpdb->last_result ) {
            for ( $i = 0, $j = count( $wpdb->last_result ); $i < $j; $i++ ) {
                $value = $wpdb->get_var( null, 0, $i );
                $new_array[ $value ] = $value;
            }
        }
        return $new_array;
    }

    /**
     * Check if a given request has access to read items.
     *
     * @param  WP_REST_Request $request Full details about the request.
     * @return WP_Error|boolean
     */
    public function get_items_permissions_check( $request ) {
        if ( ! wc_rest_check_post_permissions( 'product', 'read' ) ) {
            return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
        }

        return true;
    }

    public function constants()
    {
        if (!defined('AKAWOO_NAME')) {
            define('AKAWOO_NAME', plugin_basename(__FILE__));
        }

        if (!defined('AKAUNTINGWOO_PATH')) {
            define('AKAWOO_PATH', plugin_dir_path(__FILE__));
        }

        if (!defined('AKAWOO_ADMIN_URL')) {
            define('AKAWOO_ADMIN_URL', admin_url());
        }

        if (!defined('AKAWOO_URL')) {
            $current_directory_name = basename(dirname(__FILE__));
            define('AKAWOO_URL', plugins_url($current_directory_name) . '/');
        }
    }

    public function menu()
    {
        add_options_page('Akaunting WooCommerce Settings', 'Akaunting WooCommerce', 'manage_options', 'akaunting-woocommerce', array($this, 'display_options'));
    }

    public function action_links($links)
    {
        array_unshift($links, '<a href="'. esc_url(AKAWOO_ADMIN_URL . 'options-general.php?page=akaunting-woocommerce') .'">Settings</a>');

        return $links;
    }

    public static function meta_links($links, $file)
    {
        if (AKAWOO_NAME == $file) {
            $links[] = '<a href="https://akaunting.com/apps/woocommerce" target="_blank">WooCommerce app for Akaunting</a>';

            return $links;
        }

        return (array) $links;
    }

    public function display_options()
    {
        if (!current_user_can('manage_options'))  {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // check if the user have submitted the settings
        // wordpress will add the "settings-updated" $_GET parameter to the url
        if (isset($_GET['settings-updated'])) {
            settings_errors('akawoo-options');
        } elseif (isset($_GET['akawoo']) && ($_GET['akawoo'] == 'send-success')) {
            do_action('send_success');
        }

        require_once(AKAWOO_PATH . 'options.php');
    }

    public function register_options()
    {
        register_setting('akawoo-options', 'akawoo_url');
    }

    public function check_options()
    {
        $url = get_option('akawoo_url');
        $company_id = get_option('akawoo_company_id');
        $email = get_option('akawoo_email');
        $password = get_option('akawoo_password');

        if (empty($url) || empty($company_id) || empty($email) || empty($password)) {
            //add_settings_error('akawoo-options', 'akawoo-options', __( 'All fields are required.'));

            return false;
        }

        // Akaunting helper
        $helper = new AkauntingHelper($url, $company_id, $email, $password);

        if (true !== $check = $helper->check()) {
            switch ($check) {
                case 'install':
                    $message = 'Warning: There was a problem connecting to your Akaunting. Please, make sure that you have installed the WooCommerce app on your Akaunting! <a href="https://akaunting.com/apps/woocommerce" target="_blank">Click here</a> for more details.';
                    break;
                case 'auth':
                    $message = 'Warning: There was a problem connecting to your Akaunting. Please, make sure that the URL, Email and Password is correct!';
                    break;
                default:
                    $message = 'Warning: There was a problem connecting to your Akaunting. Please, make sure the details are correct and you have installed the WooCommerce app on your Akaunting!';
                    break;
            }

            //add_settings_error('akawoo-options', 'akawoo-options', $message);

            return false;
        }

        return $helper;
    }

    public function check_empty_options($value, $name)
    {
        $fields = array('akawoo_url');
        if (!in_array($name, $fields)) {
            return $value;
        }

        $text = ucfirst(str_replace('_', '', str_replace('akawoo_', '', $name)));

        if (empty($value)) {
            add_settings_error('akawoo-options', 'akawoo-options', $text . ' can not be empty.');
        }

        return $value;
    }

    public function send_success() {
	    $message = 'Your products, customers and orders have been successfully sent to your Akaunting!';
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e($message, 'akaunting-woocommerce'); ?></p>
        </div>
        <?php
    }

    public function send()
    {
        // Akaunting helper
        $helper = $this->check_options();

        if (!empty($helper)) {
            $this->sendProducts($helper);
            $this->sendCustomers($helper);
            $this->sendOrders($helper);
        }

        wp_redirect(AKAWOO_ADMIN_URL . 'options-general.php?page=akaunting-woocommerce&akawoo=send-success');
    }

    protected function sendProducts($helper)
    {
        $args = array(
            'limit' => -1,
            //'posts_per_page' => 10,
            //'page' => 1,
            //'paginate' => true,
        );

        $products = wc_get_products($args);

        foreach ($products as $product) {
            $data = $product->get_data();

            if ($product->get_type() == 'variable') {
                $data['variations'] = $product->get_available_variations();
            }

            $helper->storeProduct($data);
        }
    }

    protected function sendCustomers($helper)
    {
        $args = array(
            'number' => -1,
            'role' => 'customer',
            //'posts_per_page' => 10,
        );

        $users = get_users($args);

        foreach ($users as $user) {
            $customer = array(
                'meta' => get_user_meta($user->ID),
                'email' => $user->user_email,
                'address' => $this->getCustomerAddress($user),
            );

            $helper->storeCustomer($customer);
        }
    }

    protected function sendOrders($helper)
    {
        $args = array(
            'limit' => -1,
            //'posts_per_page' => 10,
        );

        $paid_statuses = ['processing', 'completed'];

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            if (!in_array(strtolower($order->status), $paid_statuses)) {
                continue;
            }

            $helper->storeOrder($order->get_data());
        }
    }

    public function woo_product($post_id, $post = null, $update = null)
    {
        if ($update) {
            return;
        }

        // Akaunting helper
        $helper = $this->check_options();

        if (empty($helper)) {
            return;
        }

        $product = wc_get_product($post_id);

        $data = $product->get_data();

        if ($data['status'] != 'publish') {
            return;
        }

        if ($product->get_type() == 'variable') {
            $data['variations'] = $product->get_available_variations();
        }

        $helper->storeProduct($data);
    }

    public function woo_customer($user_id)
    {
        // Akaunting helper
        $helper = $this->check_options();

        if (empty($helper)) {
            return;
        }

        $user = get_user_by('id', $user_id);

        $address = $this->getCustomerAddress($user);

        $customer = array(
            'meta' => get_user_meta($user->ID),
            'email' => $user->user_email,
            'address' => $address,
        );

        $helper->storeCustomer($customer);
    }

    public function woo_order($order_id)
    {
        // Akaunting helper
        $helper = $this->check_options();

        if (empty($helper)) {
            return;
        }

        $order = wc_get_order($order_id);

        $paid_statuses = ['processing', 'completed'];

        if (!in_array(strtolower($order->status), $paid_statuses)) {
            return;
        }

        $helper->storeOrder($order->get_data());
    }

    protected function getCustomerAddress($user)
    {
        $address = '';

        $address_1 = get_user_meta($user->ID, 'billing_address_1', true);
        if (!empty($address_1)) {
            $address .= $address_1;
        }

        $address_2 = get_user_meta($user->ID, 'billing_address_2', true);
        if (!empty($address_2)) {
            $address .= "\n" . $address_2;
        }

        $city = get_user_meta($user->ID, 'billing_city', true);
        if (!empty($city)) {
            $address .= "\n" . $city;
        }

        $country = get_user_meta($user->ID, 'billing_country', true);
        if (!empty($country)) {
            $address .= "\n" . wc()->countries->countries[$country];
        }

        return $address;
    }
}

function AkaWooInit() {
    new AkaWoo();
}
add_action('init', 'AkaWooInit');
