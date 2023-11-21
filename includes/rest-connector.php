<?php
namespace RV\Websites_REST_Connector;

class Rest_Connector {

    private static $instance = null;

    protected $api_url;
    protected $api_consumer_key;
    protected $api_consumer_secret;

    public function __construct() {
        if (Settings::get_mode() === 'send') {
            $this->load_api_credentials();
        }
        $this->register_hooks();
    
        // If woocommerce is active, register woocommerce hooks
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            $this->register_woocommerce_hooks();
        }
    }

    protected function load_api_credentials() {
        try {
            $settings = Settings::get_all();
            $this->api_username = $settings['wrc_api_username'] ?? '';
            $this->api_password = $settings['wrc_api_password'] ?? '';
            $this->api_url = $settings['wrc_api_url'] ?? '';
        } catch (\Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }
    }

    protected function check_api_credentials() {
        if (empty($this->api_username) || empty($this->api_password) || empty($this->api_url)) {
            throw new \Exception('API credentials are not set');
        }
    }

    /**
     * Register hooks
     */
    public function register_hooks() {
        $mode = Settings::get_mode();
        if ($mode === 'receive') {
            add_action('rest_api_init', [$this, 'register_rest_routes']);
        } else {
            add_action('save_post', [$this, 'send_post_data_to_rest_api'], 10, 2);
        }
    }

    /**
     * Register woocommerce actions
     */
    public function register_woocommerce_hooks() {
        $mode = Settings::get_mode();
        if ($mode === 'receive') {
            add_action('rest_api_init', [$this, 'register_product_rest_routes']);
        } else {
            add_action('pre_post_update', [$this, 'capture_pre_saved_product_data']);
            add_action('save_post', [$this, 'send_product_data_to_rest_api'], 10, 2);
        }
    }

    /**
     * Register post related REST routes
     */
    public function register_rest_routes() {
        register_rest_route('wrc/v1', '/receive-post-data', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_post_data'],
            'permission_callback' => [$this, 'permission_callback']
        ]);
    }

    /**
     * Register product related REST routes
     */
    public function register_product_rest_routes() {
        register_rest_route('wrc/v1', '/receive-product-data', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_product_data'],
            'permission_callback' => [$this, 'permission_callback']
        ]);
    }

    public function capture_pre_saved_product_data($product_id) {

        // Avoiding autosave and revisions
        if (!$this->is_right_post_save($product_id)) 
            return;

        $prev_version = wc_get_product($product_id);
        $product_data = [
            'sku'           => $prev_version->get_sku(),
            'post_name'     => $prev_version->get_slug(),
            'post_title'    => $prev_version->get_name(),
            'post_content'  => $prev_version->get_description(),
            'post_excerpt'  => $prev_version->get_short_description(),
        ];

        // Prepare product for sending
        update_post_meta($product_id, 'wrc_product_sent', true);

        // Store previous version in transient for 1 hour
        set_transient('previous_product_version_' . $product_id, $product_data, 60 * 60);
    }

    /**
     * Send post data to REST API
     */
    public function send_post_data_to_rest_api($post_id, $post) {
        try {
            if ($post->post_type !== 'product') {
                $data = $this->prepare_post_data($post);
                $endpoint = '/wrc/v1/receive-post-data';
                $this->send_data_to_rest_api($endpoint, $data);
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    /** 
     * Send product data to REST API
     */
    public function send_product_data_to_rest_api($product_id, $post) {

        try {
            // Avoiding autosave, revisions, and new posts
            if (!$this->is_right_post_save($product_id)) 
                return;

            // Avoid sending posts
            if ($post->post_type !== 'product') 
                return;
            
            // Get changed data only
            $data = $this->prepare_product_data($product_id);
            if (!$data) throw new \Exception('No data to send');

            // Send data to REST API
            $this->send_data_to_rest_api('/wrc/v1/receive-product-data', $data);
            
            // Delete transient with previous version of product data
            delete_transient('previous_product_version_' . $product_id);

            // Reset product sent flag
            delete_post_meta($product_id, 'wrc_product_sent');

        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    /**
     * Prepare product data for sending to REST API
     * 
     * Compares current product data with previous version of product data
     * and only adds data to array when it's different
     * to avoid sending unnecessary data to REST API
     */
    public function prepare_product_data($product_id) {
        
        // Get previous version of product from transient
        $prev_version = get_transient('previous_product_version_' . $product_id);

        if (!$prev_version)
            return;

        $data = [];
        $post = get_post($product_id);
        $post_data = $post->to_array();
        $sku = get_post_meta($product_id, '_sku', true);

        if (!$sku) return;

        // Compare product data with previous version and only add data when it's different
        foreach ($prev_version as $key => $value) {
            if ($key === 'sku') continue;
            if ($post_data[$key] !== $value) {
                $data[$key] = $post_data[$key];
            }
        }

        // One of the required fields has to be different otherwise it makes no sense to send the data
        if (!array_filter($data)) return;
        if (empty($data)) return;

        // Something changed, then add default required fields
        $data['language'] = 'nl';
        $data['sku'] = $sku;

        return $data;
    }

    /**
     * Prepare post data for sending to REST API
     */
    public function prepare_post_data($post) {
        return [
            'id' => $post->ID,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_name' => $post->post_name,
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
            'post_date' => $post->post_date,
        ];
    }

    /**
     * Receive post data from REST API
     */
    public function receive_post_data(\WP_REST_Request $request) {
        try {
            $data = json_decode($request->get_body(), true);
            do_action('wrc_rest_api_receive_post_data', $data, $request);
            return new \WP_REST_Response('Post processed successfully', 200);
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return new \WP_REST_Response('Error processing post request', 500);
        }
    }

    /**
     * Receive product data from REST API
     */
    public function receive_product_data(\WP_REST_Request $request) {
        try {
            $data = json_decode($request->get_body(), true);
            do_action('wrc_rest_api_receive_product_data', $data, $request);
            return new \WP_REST_Response('Product processed successfully', 200);
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return new \WP_REST_Response('Error processing product request', 500);
        }
    }

    /**
     * Send data to REST API
     */
    public function send_data_to_rest_api($endpoint, $data = []) {

        $this->check_api_credentials();

        // Prepare request url
        $api_url = $this->api_url = rtrim($this->api_url, '/');
        $endpoint = '/' . ltrim($endpoint, '/');
        $request_url = $api_url . $endpoint;

        $args = [
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_username . ':' . $this->api_password)
            ],
            'body' => json_encode($data),
            'timeout' => 180,
        ];

        // If local development, disable ssl verification
        if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            $args['sslverify'] = false;
        }
        $response = wp_remote_post($request_url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            throw new \Exception($error_message);
        } 
        return $response;
    }

    /**
     * Check if user is allowed to access REST API
     */
    public function permission_callback() {

        if ( !isset($_SERVER['PHP_AUTH_USER']) ) {
            return new \WP_Error('rest_forbidden', esc_html__('Authentication Required'), ['status' => 401]);
        }
        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];

        if ($username !== 'username1' || $password !== 'password1') {
            return new \WP_Error( 'rest_forbidden', esc_html__('Invalid Credentials'), ['status' => 401]);
        }
        return true;
    }

    public function is_right_post_save($post_id) {
        return !( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) && !wp_is_post_revision( $post_id );
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

}

Rest_Connector::get_instance();