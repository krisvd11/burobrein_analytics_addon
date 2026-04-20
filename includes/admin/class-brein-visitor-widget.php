<?php
/**
 * Visitor dashboard widget.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Visitor_Widget
{
    public function __construct()
    {
        add_action('admin_post_brein_clear_visitors', array($this, 'handle_clear_visitors'));
    }

    public function register_widget()
    {
        wp_add_dashboard_widget(
            'brein_visitor_widget',
            'Live Visitors',
            array($this, 'render_widget')
        );
    }

    public function render_widget()
    {
        global $wpdb;

        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if ($exists !== $table) {
            echo '<p>Visitor table is not initialized yet.</p>';
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT visitor_id, avatar_url, country, country_code, referer, device, last_seen, visit_count
             FROM {$table}
             ORDER BY last_seen DESC
             LIMIT 25"
        );

        if (empty($rows)) {
            echo '<p>No visitor data yet.</p>';
            return;
        }

        echo '<style>
            #brein_visitor_widget .inside { max-height: 520px; overflow: auto; }
            #brein_visitor_widget:not(.closed) { min-height: 520px; }
            .brein-visitors { width: 100%; max-width: 100%; margin: 0 auto; border-collapse: collapse; }
            .brein-visitors th, .brein-visitors td { text-align: start; vertical-align: middle; padding: 6px 20px; border-bottom: 1px solid #e6e6e6; font-size: 12px; }
            .brein-visitors th { font-weight: 600; color: #1d2327; }
            .brein-avatar { width: 48px; height: 48px; border-radius: 6px; background: #f2f2f2; }
            .brein-clip { display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
            .brein-center { display: flex; align-items: center; justify-content: center; gap: 6px; }
            .brein-visitors td p { margin: 0; }
            .brein-device { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        </style>';

        echo '<table class="brein-visitors">';
        echo '<thead><tr><th>Avatar</th><th>Country</th><th>Referer</th><th>Device</th><th>Last Seen</th><th>Visits</th></tr></thead>';
        echo '<tbody>';

        foreach ($rows as $row) {
            $avatar = esc_url($row->avatar_url);
            $country = esc_html($row->country);
            $country_code = isset($row->country_code) ? strtoupper($row->country_code) : '';
            $referer = (string) $row->referer;
            $referer_label = $this->format_referer_label($referer);
            $referer_icon = $this->referer_icon_url($referer);

            $device = esc_html($row->device);

            if ($device == "desktop") {
                $device_icon = "🖥️";
            } elseif ($device == "mobile") {
                $device_icon = "📱";
            } elseif ($device == "tablet") {
                $device_icon = "📲";
            } else {
                $device_icon = "";
            }

            $last_seen = esc_html($row->last_seen);
            $count = esc_html($row->visit_count);

            echo '<tr>';
            echo '<td><img class="brein-avatar" src="' . $avatar . '" alt="Visitor Avatar"></td>';
            if (!empty($country_code)) {
                $flag = 'https://purecatamphetamine.github.io/country-flag-icons/3x2/' . $country_code . '.svg';
                echo '<td><img src="' . esc_url($flag) . '" alt="' . $country_code . '" style="width:18px;height:12px;vertical-align:middle;margin-right:6px;">' . $country . '</td>';
            } else {
                echo '<td>' . $country . '</td>';
            }
            echo '<td><span class="brein-clip"><img height="20" width="20" src="' . esc_url($referer_icon) . '"><span>' . esc_html($referer_label) . '</span></span></td>';
            echo '<td><span class="brein-device"><span>' . $device_icon . '</span><span>' . $device . '</span></span></td>';
            echo '<td>' . $last_seen . '</td>';
            echo '<td>' . $count . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function handle_clear_visitors()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('brein_clear_visitors');

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;
        $wpdb->query("TRUNCATE TABLE {$table}");

        wp_safe_redirect(admin_url('index.php'));
        exit;
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

    private function referer_icon_url($referer)
    {
        $referer = (string) $referer;
        if ($referer === '' || $referer === 'direct') {
            return 'https://icons.duckduckgo.com/ip3/direct.ico';
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!$host) {
            $host = $referer;
        }

        $host = preg_replace('/^www\./i', '', $host);
        if (!$host) {
            $host = 'direct';
        }

        return 'https://icons.duckduckgo.com/ip3/' . rawurlencode($host) . '.ico';
    }
}
