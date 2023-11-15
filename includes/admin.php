<?php
namespace RV\Websites_REST_Connector;

/**
 * The Admin class manages the plugin's admin settings page.
 */
class Admin {

    private static $instance = null;

    /**
     * Constructor function that adds actions to the admin_menu and admin_init hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Returns the instance of the Admin class.
     *
     * @return Admin|null The instance of the Admin class.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registers the plugin's settings page.
     */
    public function register_plugin_page() {
        add_options_page(
            'REST Connector',
            'REST Connector',
            'manage_options',
            'websites-rest-connector',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Creates the plugin's settings page.
     */
    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2><?php _e('Websites REST Connector'); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'websites_rest_connector_settings' );
                do_settings_sections( 'websites_rest_connector_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Registers the plugin's settings.
     */
    public function register_settings() {
        register_setting(
            'websites_rest_connector_settings',
            'websites_rest_connector_settings',
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'websites_rest_connector_section',
            'REST API Settings',
            array( $this, 'print_section_info' ),
            'websites_rest_connector_settings'
        );

        add_settings_field(
            'wrc_api_url',
            'API URL',
            array( $this, 'api_url_callback' ),
            'websites_rest_connector_settings',
            'websites_rest_connector_section'
        );

        add_settings_field(
            'wrc_api_username',
            'API Username',
            array( $this, 'api_username_callback' ),
            'websites_rest_connector_settings',
            'websites_rest_connector_section'
        );

        add_settings_field(
            'wrc_api_password',
            'API Password',
            array( $this, 'api_password_callback' ),
            'websites_rest_connector_settings',
            'websites_rest_connector_section'
        );
    }

    /**
     * Sanitizes the plugin's settings.
     *
     * @param array $input The input settings to sanitize.
     * @return array The sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $sanitized_input = array();
        if ( isset( $input['wrc_api_username'] ) ) {
            $sanitized_input['wrc_api_username'] = sanitize_text_field( $input['wrc_api_username'] );
        }
        if ( isset( $input['wrc_api_password'] ) ) {
            $sanitized_input['wrc_api_password'] = sanitize_text_field( $input['wrc_api_password'] );
        }
        if ( isset( $input['wrc_api_url'] ) ) {
            $sanitized_input['wrc_api_url'] = sanitize_text_field( $input['wrc_api_url'] );
        }
        return $sanitized_input;
    }

    /**
     * Prints the section information for the plugin's settings.
     */
    public function print_section_info() {
        print 'Enter other WordPress\'s website REST API settings to connect:';
    }

    /**
     * Callback function for the API URL field.
     */
    public function api_url_callback() {
        $options = get_option( 'websites_rest_connector_settings' );
        printf(
            '<input type="text" id="wrc_api_url" class="regular-text" name="websites_rest_connector_settings[wrc_api_url]" value="%s" /><p class="description">The URL of the WordPress website must include /wp-json</p>',
            isset( $options['wrc_api_url'] ) ? esc_attr( $options['wrc_api_url'] ) : ''
        );
    }

    /**
     * Callback function for the API Username field.
     */
    public function api_username_callback() {
        $options = get_option( 'websites_rest_connector_settings' );
        printf(
            '<input type="text" id="wrc_api_username" class="regular-text" name="websites_rest_connector_settings[wrc_api_username]" value="%s" />',
            isset( $options['wrc_api_username'] ) ? esc_attr( $options['wrc_api_username'] ) : ''
        );
    }

    /**
     * Callback function for the API Password field.
     */
    public function api_password_callback() {
        $options = get_option( 'websites_rest_connector_settings' );
        printf(
            '<input type="text" id="wrc_api_password" class="regular-text" name="websites_rest_connector_settings[wrc_api_password]" value="%s" />',
            isset( $options['wrc_api_password'] ) ? esc_attr( $options['wrc_api_password'] ) : ''
        );
    }
}

Admin::get_instance();