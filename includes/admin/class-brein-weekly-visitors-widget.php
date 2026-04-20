<?php
/**
 * Weekly visitors dashboard widget.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Weekly_Visitors_Widget
{
    private $plugin_file;

    public function __construct()
    {
        $this->plugin_file = BREIN_ANALYTICS_PLUGIN_FILE;
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function register_widget()
    {
        wp_add_dashboard_widget(
            'brein_weekly_visitors_widget',
            'Weekly Site Visitors',
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

        $days = array();
        for ($i = 6; $i >= 0; $i--) {
            $days[] = gmdate('Y-m-d', strtotime(current_time('mysql') . " -{$i} days"));
        }

        $rows = $wpdb->get_results(
            "SELECT DATE(last_seen) AS day, COUNT(DISTINCT visitor_id) AS visitors
             FROM {$table}
             WHERE last_seen >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY day"
        );

        $counts = array_fill_keys($days, 0);
        foreach ($rows as $row) {
            $day = $row->day;
            if (isset($counts[$day])) {
                $counts[$day] = (int) $row->visitors;
            }
        }

        $values = array_values($counts);
        $total = array_sum($values);

        $sources = $wpdb->get_results(
            "SELECT referer, COUNT(DISTINCT visitor_id) AS visitors
             FROM {$table}
             WHERE last_seen >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY referer
             ORDER BY visitors DESC
             LIMIT 8"
        );



        echo '<div class="brein-weekly-card">';
        echo '<p class="brein-weekly-title">Website bezoekers</p>';
        echo '<div class="brein-weekly-number">';
        echo '<p class="brein-weekly-total">' . number_format_i18n($total) . ' </p>';
        echo '<div class="analytics-icon">
                    <span class="analytics-badge-dot" aria-hidden="true"></span>
                    <span class="analytics-badge-bg" aria-hidden="true"></span>
                </div>';


        echo '</div>';

        echo '<p class="brein-weekly-sub">Laatste 7 dagen</p>';
        echo $this->render_chart_svg($values, $days);
        echo '<div class="brein-weekly-hoverline" aria-hidden="true"></div>';
        echo '<div class="brein-weekly-dot" aria-hidden="true"></div>';
        echo '<div class="brein-weekly-tooltip" aria-hidden="true"></div>';
        echo $this->render_day_labels($days);
        echo $this->render_sources($sources);
        echo '</div>';
    }

    public function enqueue_assets($hook)
    {
        $hooks = apply_filters('brein_analytics_widget_hooks', array('index.php'));
        if (!in_array($hook, $hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'brein-widgets',
            plugins_url('assets/css/widgets.css', $this->plugin_file),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'brein-weekly-visitors',
            plugins_url('assets/js/brein-weekly-visitors.js', $this->plugin_file),
            array(),
            '1.0.0',
            true
        );
    }

    private function render_chart_svg($values, $days)
    {
        $count = count($values);
        if ($count < 2) {
            return '';
        }

        $min = min($values);
        $max = max($values);
        $range = max(1, $max - $min);

        $width = 100;
        $height = 40;
        $step = $count > 1 ? $width / ($count - 1) : $width;

        $points = array();
        foreach ($values as $i => $value) {
            $x = round($i * $step, 2);
            $norm = ($value - $min) / $range;
            $y = round($height - ($norm * ($height - 6)) - 3, 2);
            $points[] = array($x, $y);
        }

        $line = $this->build_smooth_path($points, $width, $height);
        $area = $this->build_area_path($points, $width, $height);

        $labels = array();
        $dates = array();
        foreach ($days as $day) {
            $ts = strtotime($day);
            $labels[] = date_i18n('D', $ts);
            $dates[] = date_i18n('D, j F', $ts);
        }

        return '<svg class="brein-weekly-chart" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="Weekly visitors graph" data-values="' . esc_attr(wp_json_encode($values)) . '" data-labels="' . esc_attr(wp_json_encode($labels)) . '" data-dates="' . esc_attr(wp_json_encode($dates)) . '">' .
            '<path d="' . esc_attr($area) . '" fill="#FDFFE5" />' .
            '<path class="brein-weekly-line" d="' . esc_attr($line) . '" fill="none" stroke="#F2FF49" stroke-width="1" />' .
            '</svg>';
    }

    private function build_smooth_path($points, $width, $height)
    {
        $count = count($points);
        if ($count === 0) {
            return '';
        }

        $d = 'M ' . $points[0][0] . ' ' . $points[0][1];
        $tension = 0.2;

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $i > 0 ? $points[$i - 1] : $points[$i];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = ($i + 2 < $count) ? $points[$i + 2] : $p2;

            $cp1x = $p1[0] + ($p2[0] - $p0[0]) * $tension;
            $cp1y = $p1[1] + ($p2[1] - $p0[1]) * $tension;
            $cp2x = $p2[0] - ($p3[0] - $p1[0]) * $tension;
            $cp2y = $p2[1] - ($p3[1] - $p1[1]) * $tension;

            $cp1x = $this->clamp($cp1x, 0, $width);
            $cp2x = $this->clamp($cp2x, 0, $width);
            $cp1y = $this->clamp($cp1y, 0, $height);
            $cp2y = $this->clamp($cp2y, 0, $height);

            $d .= ' C ' . round($cp1x, 2) . ' ' . round($cp1y, 2) . ', ' .
                round($cp2x, 2) . ' ' . round($cp2y, 2) . ', ' .
                $p2[0] . ' ' . $p2[1];
        }

        return $d;
    }

    private function build_area_path($points, $width, $height)
    {
        if (empty($points)) {
            return '';
        }

        $line = $this->build_smooth_path($points, $width, $height);
        $first = $points[0];
        $last = $points[count($points) - 1];

        return 'M 0 ' . $height .
            ' L ' . $first[0] . ' ' . $first[1] . ' ' .
            substr($line, 1) . // drop the leading "M"
            ' L ' . $last[0] . ' ' . $height .
            ' L 0 ' . $height .
            ' Z';
    }

    private function clamp($value, $min, $max)
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function render_day_labels($days)
    {
        $labels = array();
        foreach ($days as $day) {
            $labels[] = date_i18n('D', strtotime($day));
        }

        $html = '<div class="brein-weekly-labels">';
        foreach ($labels as $label) {
            $html .= '<span>' . esc_html($label) . '</span>';
        }
        $html .= '</div>';

        return $html;
    }

    private function render_sources($sources)
    {
        if (empty($sources)) {
            return '';
        }

        $max = 0;
        foreach ($sources as $row) {
            $max = max($max, (int) $row->visitors);
        }
        $max = max(1, $max);

        $html = '<div class="brein-weekly-sources">';
        foreach ($sources as $row) {
            $label = $this->format_referer_label($row->referer);
            $icon = $this->referer_icon_url($row->referer);
            $count = (int) $row->visitors;
            $width = round(($count / $max) * 100, 2);

            $html .= '<div class="brein-weekly-source">';
            $html .= '<div class="brein-weekly-source-name">';
            $html .= '<img class="brein-weekly-source-icon" src="' . esc_url($icon) . '" alt="">';
            $html .= '<span>' . esc_html($label) . '</span>';

            $html .= '<a href=https://' . esc_html($label) . '><svg width="14px" height="14px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="#ffffff" transform="matrix(1, 0, 0, 1, 0, 0)"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round" stroke="#CCCCCC" stroke-width="0.576"></g><g id="SVGRepo_iconCarrier"> <path d="M14.1625 18.4876L13.4417 19.2084C11.053 21.5971 7.18019 21.5971 4.79151 19.2084C2.40283 16.8198 2.40283 12.9469 4.79151 10.5583L5.51236 9.8374" stroke="#000000" stroke-width="2" stroke-linecap="round"></path> <path d="M9.8374 14.1625L14.1625 9.8374" stroke="#000000" stroke-width="2" stroke-linecap="round"></path> <path d="M9.8374 5.51236L10.5583 4.79151C12.9469 2.40283 16.8198 2.40283 19.2084 4.79151M18.4876 14.1625L19.2084 13.4417C20.4324 12.2177 21.0292 10.604 20.9988 9" stroke="#000000" stroke-width="2" stroke-linecap="round"></path> </g></svg></a>';
            $html .= '</div>';


            $html .= '<div class="brein-weekly-right">';

            $html .= '<div class="brein-weekly-source-bar"><div class="brein-weekly-source-fill" style="width:' . esc_attr($width) . '%;"></div></div>';

            $html .= '<div class="brein-number">' . number_format_i18n($count) . '</div>';

            $html .= '</div>';

            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
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
