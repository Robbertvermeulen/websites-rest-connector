<?php
namespace RV\Websites_REST_Connector;

class Rest_Connector {

    private static $instance = null;

    protected $api_url;
    protected $api_consumer_key;
    protected $api_consumer_secret;

    private function __construct() {
        $settings = get_option('websites_rest_connector_settings');
        if (empty($settings['wrc_api_url']) || empty($settings['wrc_api_username']) || empty($settings['wrc_api_password'])) {
            return;
        }
        $this->api_username = $settings['wrc_api_username'];
        $this->api_password = $settings['wrc_api_password'];
        $this->api_url = $settings['wrc_api_url'];

        $this->register_hooks();

        // If woocommerce is active, register woocommerce hooks
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            $this->register_woocommerce_hooks();
        }
    }

    /**
     * Register hooks
     */
    public function register_hooks() {
        add_action('save_post', [$this, 'send_post_data_to_rest_api'], 10, 2);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register woocommerce actions
     */
    public function register_woocommerce_hooks() {
        add_action('pre_post_update', [$this, 'capture_pre_saved_product_data']);
        add_action('save_post', [$this, 'send_product_data_to_rest_api'], 10, 2);
        add_action('rest_api_init', [$this, 'register_product_rest_routes']);
    }

    public function capture_pre_saved_product_data($product_id) {

        // Avoiding autosave and revisions
        if (!$this->is_right_post_save($product_id)) 
            return;

        $prev_version = wc_get_product($product_id);
        $product_data = [
            'id' => $prev_version->get_id(),
            'sku' => $prev_version->get_sku(),
            'slug' => $prev_version->get_slug(),
            'name' => $prev_version->get_name(),
            'description' => $prev_version->get_description(),
            'short_description' => $prev_version->get_short_description(),
        ];

        // Store previous version in transient for 1 hour
        set_transient('previous_product_version_' . $product_id, $product_data, 60 * 60);
    }

    /**
     * Send post data to REST API
     */
    public function send_post_data_to_rest_api($post_id, $post) {
        if ($post->post_type !== 'product') {
            $data = $this->prepare_post_data($post);
            $endpoint = '/wrc/v1/receive-post-data';
            $this->send_data_to_rest_api($endpoint, $data);
        }
    }

    /** 
     * Send product data to REST API
     */
    public function send_product_data_to_rest_api($product_id, $post) {

        // Avoiding autosave, revisions, and new posts
        if (!$this->is_right_post_save($product_id)) 
            return;

        if ($post->post_type !== 'product') 
            return;
        
        $product = wc_get_product($product_id);
        $data = $this->prepare_product_data($product);
        if (!$data) return;            
        $endpoint = '/wrc/v1/receive-product-data';
        $this->send_data_to_rest_api($endpoint, $data);        
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

    /**
     * Receive post data from REST API
     */
    public function receive_post_data(\WP_REST_Request $request) {
        $data = json_decode($request->get_body(), true);
        do_action('wrc_rest_api_receive_post_data', $data, $request);
        return new \WP_REST_Response('Post processed successfully', 200);
    }

    /**
     * Receive product data from REST API
     */
    public function receive_product_data(\WP_REST_Request $request) {
        $data = json_decode($request->get_body(), true);
        do_action('wrc_rest_api_receive_product_data', $data, $request);
        return new \WP_REST_Response('Product processed successfully', 200);
    }

    /**
     * Send data to REST API
     */
    public function send_data_to_rest_api($endpoint, $data = []) {

        // Prepare request url
        $api_url = $this->api_url = rtrim($this->api_url, '/');
        $endpoint = '/' . ltrim($endpoint, '/');
        $request_url = $api_url . $endpoint;

        $args = [
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_username . ':' . $this->api_password)
            ],
            'body' => json_encode($data)
        ];

        // If local development, disable ssl verification
        if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            $args['sslverify'] = false;
        }
        $response = wp_remote_post($request_url, $args);
        return $response;
    }

    /**
     * Prepare product data for sending to REST API
     * 
     * Compares current product data with previous version of product data
     * and only adds data to array when it's different
     * to avoid sending unnecessary data to REST API
     */
    public function prepare_product_data(\WC_Product $product) {

        $product_id = $product->get_id();
        
        // Get previous version of product from transient
        $prev_version = get_transient('previous_product_version_' . $product_id);

        if (!$prev_version)
            return;

        $language_code = 'nl';
        $sku = $product->get_sku();

        $slug = $product->get_slug();
        $name = $product->get_name();
        $description = $product->get_description();
        $short_description = $product->get_short_description();

        // Compare product data with previous version and only add data when it's different
        $data = [
            'slug' => $slug === $prev_version['slug'] ? null : $slug,
            'name' => $name === $prev_version['name'] ? null : $name,
            'description' => $description === $prev_version['description'] ? null : $description,
            'short_description' => $short_description === $prev_version['short_description'] ? null : $short_description,
        ];

        // One of the required fields has to be different otherwise it makes no sense to send the data
        if (!array_filter($data)) return;

        // Something changed, then add default required fields
        $data['language'] = $language_code;
        $data['sku'] = $sku;

        // Delete transient
        delete_transient('previous_product_version_' . $product_id);

        return $data;
    }

    /**
     * Prepare post data for sending to REST API
     */
    public function prepare_post_data($post) {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'date' => $post->post_date,
        ];
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