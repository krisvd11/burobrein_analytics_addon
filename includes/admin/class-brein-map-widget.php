<?php
/**
 * Live Visitor Map widget.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Map_Widget
{
    private $plugin_url;
    private $plugin_file;

    public function __construct()
    {
        $this->plugin_file = BREIN_ANALYTICS_PLUGIN_FILE;
        $this->plugin_url = plugin_dir_url($this->plugin_file);
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_brein_map_visitors', array($this, 'ajax_visitors'));
    }

    public function register_map_widget()
    {
        wp_add_dashboard_widget(
            'brein_map_widget',
            'Live Visitor Map (Last 5 Minutes)',
            array($this, 'render_map_widget')
        );
    }

    public function render_map_widget()
    {
        echo '<div id="brein-map" class="brein-map"></div>';
    }

    public function enqueue_assets($hook)
    {
        $hooks = apply_filters('brein_analytics_widget_hooks', array('index.php'));
        if (!in_array($hook, $hooks, true)) {
            return;
        }

        wp_enqueue_script(
            'maplibre-js',
            'https://unpkg.com/maplibre-gl@^5.21.0/dist/maplibre-gl.js',
            array(),
            null,
            true
        );

        wp_enqueue_style(
            'maplibre-css',
            'https://unpkg.com/maplibre-gl@^5.21.0/dist/maplibre-gl.css'
        );

        wp_enqueue_style(
            'brein-map-widget',
            plugins_url('assets/css/brein-map.css', $this->plugin_file),
            array('maplibre-css'),
            '1.0.0'
        );

        wp_enqueue_script(
            'brein-map-widget',
            plugins_url('assets/js/brein-map.js', $this->plugin_file),
            array('maplibre-js'),
            '1.0.0',
            true
        );

        wp_localize_script(
            'brein-map-widget',
            'breinMapWidget',
            array(
                'styleUrl' => plugins_url('assets/map/brein-map-style.json', $this->plugin_file),
                'center' => array(-60, 25),
                'zoom' => 1.7,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('brein_map_visitors'),
                'pollMs' => 30000,
            )
        );
    }

    public function ajax_visitors()
    {
        check_ajax_referer('brein_map_visitors', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;

        $rows = $wpdb->get_results(
            "SELECT visitor_id, avatar_url, latitude, longitude, last_seen, first_seen, visit_count, country, country_code, referer, user_agent, device
             FROM {$table}
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             AND last_seen >= (NOW() - INTERVAL 5 MINUTE)
             ORDER BY last_seen DESC
             LIMIT 50"
        );

        $payload = array();
        foreach ($rows as $row) {
            $payload[] = array(
                'visitor_id' => $row->visitor_id,
                'avatar_url' => esc_url_raw($row->avatar_url),
                'lat' => (float) $row->latitude,
                'lon' => (float) $row->longitude,
                'last_seen' => $row->last_seen,
                'first_seen' => $row->first_seen,
                'visit_count' => (int) $row->visit_count,
                'country' => $row->country,
                'country_code' => $row->country_code,
                'referer' => $row->referer,
                'referer_label' => $this->format_referer_label($row->referer),
                'user_agent' => $row->user_agent,
                'device' => $row->device,
            );
        }

        wp_send_json_success($payload);
    }

    private function format_referer_label($referer)
    {
        $referer = (string) $referer;
        if ($referer === '' || $referer === 'direct') {
            return 'Direct/None';
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!$host) {
            $host = $referer;
        }

        $host = preg_replace('/^www\./i', '', $host);
        return $host ? $host : 'Unknown';
    }
}
