<?php
namespace RV\Websites_REST_Connector;

class Settings {

    public static function get_all() {
        return get_option('websites_rest_connector_settings');
    }

    public static function get_mode() {
        $settings = self::get_all();
        return $settings['wrc_mode'] ?? 'receive';
    }

}