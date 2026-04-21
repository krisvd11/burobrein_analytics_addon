<?php
/**
 * Visitor tracking.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Visitor_Tracker
{
    const TABLE = 'brein_visitors';
    const EVENTS_TABLE = 'brein_recording_events';
    const COOKIE = 'brein_visitor_id';
    const SESSION_COOKIE = 'brein_visitor_session';
    const RECORDING_SESSION_COOKIE = 'brein_recording_session_id';
    const DB_VERSION = '1.6.0';
    const SESSION_TTL = 1800;

    public function __construct()
    {
        add_action('init', array($this, 'maybe_upgrade_table'), 5);
        add_action('init', array($this, 'maybe_track_visitor'), 9);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_click_tracking_assets'));
        add_action('wp_ajax_nopriv_brein_track_consent_visitor', array($this, 'ajax_track_consent_visitor'));
        add_action('wp_ajax_brein_track_consent_visitor', array($this, 'ajax_track_consent_visitor'));
        add_action('wp_ajax_nopriv_brein_track_recording_event', array($this, 'ajax_track_recording_event'));
        add_action('wp_ajax_brein_track_recording_event', array($this, 'ajax_track_recording_event'));
    }

    public static function activate()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . self::TABLE;
        $events_table = $wpdb->prefix . self::EVENTS_TABLE;

        $visitor_sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(64) NOT NULL,
            avatar_url TEXT NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            country VARCHAR(128) NOT NULL,
            country_code VARCHAR(8) NOT NULL,
            referer TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            device VARCHAR(32) NOT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            visit_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY visitor_id (visitor_id)
        ) {$charset_collate};";

        $events_sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(64) NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            path TEXT NOT NULL,
            page_title TEXT NOT NULL,
            element_label TEXT NOT NULL,
            element_href TEXT NOT NULL,
            referrer TEXT NOT NULL,
            device VARCHAR(32) NOT NULL,
            country VARCHAR(128) NOT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY visitor_id (visitor_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($visitor_sql);
        dbDelta($events_sql);
        update_option('brein_visitors_db_version', self::DB_VERSION);
    }

    public function maybe_upgrade_table()
    {
        $current = get_option('brein_visitors_db_version');
        if ($current === self::DB_VERSION) {
            return;
        }

        self::activate();
    }

    public function maybe_track_visitor()
    {
        if (!$this->should_track_pageview_request()) {
            return;
        }

        if (!$this->has_tracking_consent()) {
            return;
        }

        $this->track_visitor();
    }

    private function should_track_pageview_request()
    {
        if ($this->is_recording_preview_request()) {
            return false;
        }

        if (is_admin()) {
            return false;
        }

        if (wp_doing_ajax()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ($method !== 'GET') {
            return false;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
        if ($accept !== '' && strpos($accept, 'text/html') === false) {
            return false;
        }

        $fetch_dest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower(wp_unslash($_SERVER['HTTP_SEC_FETCH_DEST'])) : '';
        if ($fetch_dest !== '' && !in_array($fetch_dest, array('document', 'iframe', 'empty'), true)) {
            return false;
        }

        $path = $this->current_path();
        if ($path === '') {
            return false;
        }

        if (strpos($path, '/wp-admin/') === 0 || strpos($path, '/wp-json/') === 0) {
            return false;
        }

        if (strpos($path, '/.well-known/') === 0) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, array('map', 'js', 'css', 'json', 'xml', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2'), true)) {
            return false;
        }

        return true;
    }

    private function track_visitor($overrides = array())
    {
        global $wpdb;

        $ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $referer_raw = isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';
        $referer = isset($overrides['referrer'])
            ? (sanitize_text_field($overrides['referrer']) === 'direct' ? 'direct' : esc_url_raw($overrides['referrer']))
            : ($referer_raw ? esc_url_raw($referer_raw) : 'direct');
        $page_path = isset($overrides['path']) ? $this->sanitize_text_field_deep($overrides['path']) : $this->current_path();
        $page_title = isset($overrides['page_title']) ? $this->sanitize_text_field_deep($overrides['page_title']) : $this->current_page_title();

        $visitor_id = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        if ($visitor_id === '') {
            $visitor_id = md5($ip . '|' . substr($user_agent, 0, 120));
            $this->set_visitor_cookie($visitor_id);
        }

        $avatar_url = 'https://api.dicebear.com/9.x/notionists/svg?seed=' . rawurlencode($visitor_id);
        $device = $this->detect_device($user_agent);
        $geo = $this->lookup_geo($ip);
        $lat = isset($geo['lat']) ? $geo['lat'] : null;
        $lon = isset($geo['lon']) ? $geo['lon'] : null;
        $country = !empty($geo['country']) ? $geo['country'] : 'Unknown';
        $country_code = !empty($geo['country_code']) ? strtoupper($geo['country_code']) : '';
        $now = current_time('mysql');
        $session = $this->get_session_state();

        $table = $wpdb->prefix . self::TABLE;
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE visitor_id = %s", $visitor_id)
        );

        if ($existing) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                        SET last_seen = %s,
                            visit_count = visit_count + %d,
                            ip_address = %s,
                            country = %s,
                            country_code = %s,
                            referer = %s,
                            user_agent = %s,
                            device = %s,
                            avatar_url = %s,
                            latitude = %s,
                            longitude = %s
                      WHERE visitor_id = %s",
                    $now,
                    $session['is_new'] ? 1 : 0,
                    $ip,
                    $country,
                    $country_code,
                    $referer,
                    $user_agent,
                    $device,
                    $avatar_url,
                    $lat,
                    $lon,
                    $visitor_id
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (visitor_id, avatar_url, ip_address, country, country_code, referer, user_agent, device, latitude, longitude, first_seen, last_seen, visit_count)
                      VALUES
                        (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1)",
                    $visitor_id,
                    $avatar_url,
                    $ip,
                    $country,
                    $country_code,
                    $referer,
                    $user_agent,
                    $device,
                    $lat,
                    $lon,
                    $now,
                    $now
                )
            );
        }

        $this->store_recording_event(
            array(
                'visitor_id' => $visitor_id,
                'session_id' => $session['session_id'],
                'event_type' => 'pageview',
                'path' => $page_path,
                'page_title' => $page_title,
                'element_label' => '',
                'element_href' => '',
                'referrer' => $referer,
                'device' => $device,
                'country' => $country,
                'payload' => array(
                    'timestamp_ms' => $this->current_timestamp_ms(),
                ),
                'created_at' => $now,
            )
        );
    }

    public function ajax_track_consent_visitor()
    {
        check_ajax_referer('brein_track_consent_visitor', 'nonce');

        if (!$this->has_tracking_consent()) {
            wp_send_json_error(array('message' => __('Consent required.', 'brein-plugin')));
        }

        $this->track_visitor(
            array(
                'path' => isset($_POST['path']) ? wp_unslash($_POST['path']) : '',
                'page_title' => isset($_POST['page_title']) ? wp_unslash($_POST['page_title']) : '',
                'referrer' => isset($_POST['referrer']) ? wp_unslash($_POST['referrer']) : '',
            )
        );
        wp_send_json_success();
    }

    public function ajax_track_recording_event()
    {
        check_ajax_referer('brein_track_recording_event', 'nonce');

        if ($this->is_admin_request() || $this->is_recording_preview_request()) {
            wp_send_json_success(array('ignored' => true));
        }

        if (!$this->has_tracking_consent()) {
            wp_send_json_error(array('message' => __('Consent required.', 'brein-plugin')));
        }

        $event_type = isset($_POST['event_type']) ? sanitize_key(wp_unslash($_POST['event_type'])) : '';
        if (!in_array($event_type, array('click', 'scroll', 'mousemove'), true)) {
            wp_send_json_error(array('message' => __('Unsupported event type.', 'brein-plugin')));
        }

        $visitor_id = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($visitor_id === '') {
            $visitor_id = md5($this->get_client_ip() . '|' . substr($user_agent, 0, 120));
            $this->set_visitor_cookie($visitor_id);
        }

        $geo = $this->lookup_geo($this->get_client_ip());
        $country = !empty($geo['country']) ? $geo['country'] : 'Unknown';
        $session = $this->get_session_state();

        $payload = array();
        if ($event_type === 'click') {
            $class_names = array();
            if (isset($_POST['class_names'])) {
                $decoded_classes = json_decode(wp_unslash($_POST['class_names']), true);
                if (is_array($decoded_classes)) {
                    foreach ($decoded_classes as $class_name) {
                        $class_name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class_name);
                        if ($class_name !== '') {
                            $class_names[] = $class_name;
                        }
                    }
                    $class_names = array_values(array_unique($class_names));
                }
            }

            $payload = array(
                'timestamp_ms' => isset($_POST['timestamp_ms']) ? max(0, intval(wp_unslash($_POST['timestamp_ms']))) : $this->current_timestamp_ms(),
                'selector' => isset($_POST['selector']) ? $this->sanitize_text_field_deep(wp_unslash($_POST['selector'])) : '',
                'class_names' => $class_names,
                'element_id' => isset($_POST['element_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST['element_id'])) : '',
                'viewport_width' => isset($_POST['viewport_width']) ? max(0, intval(wp_unslash($_POST['viewport_width']))) : 0,
                'viewport_height' => isset($_POST['viewport_height']) ? max(0, intval(wp_unslash($_POST['viewport_height']))) : 0,
                'x' => isset($_POST['x']) ? max(0, intval(wp_unslash($_POST['x']))) : 0,
                'y' => isset($_POST['y']) ? max(0, intval(wp_unslash($_POST['y']))) : 0,
            );
        } elseif ($event_type === 'scroll') {
            $payload = array(
                'timestamp_ms' => isset($_POST['timestamp_ms']) ? max(0, intval(wp_unslash($_POST['timestamp_ms']))) : $this->current_timestamp_ms(),
                'scroll_y' => isset($_POST['scroll_y']) ? max(0, intval(wp_unslash($_POST['scroll_y']))) : 0,
                'viewport_width' => isset($_POST['viewport_width']) ? max(0, intval(wp_unslash($_POST['viewport_width']))) : 0,
                'viewport_height' => isset($_POST['viewport_height']) ? max(0, intval(wp_unslash($_POST['viewport_height']))) : 0,
            );
        } elseif ($event_type === 'mousemove') {
            $payload = array(
                'timestamp_ms' => isset($_POST['timestamp_ms']) ? max(0, intval(wp_unslash($_POST['timestamp_ms']))) : $this->current_timestamp_ms(),
                'viewport_width' => isset($_POST['viewport_width']) ? max(0, intval(wp_unslash($_POST['viewport_width']))) : 0,
                'viewport_height' => isset($_POST['viewport_height']) ? max(0, intval(wp_unslash($_POST['viewport_height']))) : 0,
                'x' => isset($_POST['x']) ? max(0, intval(wp_unslash($_POST['x']))) : 0,
                'y' => isset($_POST['y']) ? max(0, intval(wp_unslash($_POST['y']))) : 0,
            );
        }

        $this->store_recording_event(
            array(
                'visitor_id' => $visitor_id,
                'session_id' => $session['session_id'],
                'event_type' => $event_type,
                'path' => isset($_POST['path']) ? $this->sanitize_text_field_deep(wp_unslash($_POST['path'])) : '',
                'page_title' => isset($_POST['page_title']) ? $this->sanitize_text_field_deep(wp_unslash($_POST['page_title'])) : '',
                'element_label' => isset($_POST['element_label']) ? $this->sanitize_text_field_deep(wp_unslash($_POST['element_label'])) : '',
                'element_href' => isset($_POST['element_href']) ? esc_url_raw(wp_unslash($_POST['element_href'])) : '',
                'referrer' => isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '',
                'device' => $this->detect_device($user_agent),
                'country' => $country,
                'payload' => $payload,
                'created_at' => current_time('mysql'),
            )
        );

        wp_send_json_success();
    }

    public function enqueue_click_tracking_assets()
    {
        if (is_admin() || $this->is_recording_preview_request()) {
            return;
        }

        wp_enqueue_script(
            'brein-click-tracker',
            plugins_url('assets/js/brein-click-tracker.js', BREIN_ANALYTICS_PLUGIN_FILE),
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            'brein-click-tracker',
            'breinClickTracker',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('brein_track_recording_event'),
            )
        );
    }

    private function has_tracking_consent()
    {
        $options = get_option('brein_cookie_compliance_options', array());
        $enabled = !empty($options['enabled']);
        if (!$enabled) {
            return true;
        }

        $cookie_name = 'brein_cookie_compliance';
        $consent = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';

        return $consent === 'accept';
    }

    private function set_visitor_cookie($visitor_id)
    {
        $expire = time() + (365 * DAY_IN_SECONDS);
        $secure = is_ssl();
        $httponly = true;

        if (!headers_sent()) {
            setcookie(self::COOKIE, $visitor_id, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, $httponly);
        }
        $_COOKIE[self::COOKIE] = $visitor_id;
    }

    private function get_session_state()
    {
        $now = time();
        $last = isset($_COOKIE[self::SESSION_COOKIE]) ? intval($_COOKIE[self::SESSION_COOKIE]) : 0;
        $is_new = ($now - $last) > self::SESSION_TTL;
        $session_id = isset($_COOKIE[self::RECORDING_SESSION_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::RECORDING_SESSION_COOKIE])) : '';

        if ($is_new || $session_id === '') {
            $session_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('brein_session_', true));
        }

        $expire = $now + self::SESSION_TTL;
        $secure = is_ssl();
        $httponly = true;

        if (!headers_sent()) {
            setcookie(self::SESSION_COOKIE, (string) $now, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, $httponly);
            setcookie(self::RECORDING_SESSION_COOKIE, $session_id, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, $httponly);
        }
        $_COOKIE[self::SESSION_COOKIE] = (string) $now;
        $_COOKIE[self::RECORDING_SESSION_COOKIE] = $session_id;

        return array(
            'is_new' => $is_new,
            'session_id' => $session_id,
        );
    }

    private function store_recording_event($event)
    {
        global $wpdb;

        $table = $wpdb->prefix . self::EVENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return false;
        }

        $event_type = isset($event['event_type']) ? sanitize_key($event['event_type']) : '';
        if ($event_type === '') {
            return false;
        }

        if (in_array($event_type, array('scroll', 'mousemove'), true)) {
            return $this->store_batched_recording_event($table, $event);
        }

        return (bool) $wpdb->insert(
            $table,
            array(
                'visitor_id' => isset($event['visitor_id']) ? sanitize_text_field($event['visitor_id']) : '',
                'session_id' => isset($event['session_id']) ? sanitize_text_field($event['session_id']) : '',
                'event_type' => $event_type,
                'path' => isset($event['path']) ? $this->sanitize_text_field_deep($event['path']) : '',
                'page_title' => isset($event['page_title']) ? $this->sanitize_text_field_deep($event['page_title']) : '',
                'element_label' => isset($event['element_label']) ? $this->sanitize_text_field_deep($event['element_label']) : '',
                'element_href' => isset($event['element_href']) ? esc_url_raw($event['element_href']) : '',
                'referrer' => isset($event['referrer']) ? ($event['referrer'] === 'direct' ? 'direct' : esc_url_raw($event['referrer'])) : '',
                'device' => isset($event['device']) ? sanitize_text_field($event['device']) : '',
                'country' => isset($event['country']) ? sanitize_text_field($event['country']) : '',
                'payload' => isset($event['payload']) ? wp_json_encode($event['payload']) : wp_json_encode(array()),
                'created_at' => isset($event['created_at']) ? sanitize_text_field($event['created_at']) : current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    private function store_batched_recording_event($table, $event)
    {
        global $wpdb;

        $event_type = sanitize_key($event['event_type']);
        $session_id = isset($event['session_id']) ? sanitize_text_field($event['session_id']) : '';
        $path = isset($event['path']) ? $this->sanitize_text_field_deep($event['path']) : '';
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : array();
        $timestamp_ms = isset($payload['timestamp_ms']) ? max(0, intval($payload['timestamp_ms'])) : $this->current_timestamp_ms();

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, payload
                 FROM {$table}
                 WHERE session_id = %s
                   AND event_type = %s
                   AND path = %s
                 ORDER BY id DESC
                 LIMIT 1",
                $session_id,
                $event_type,
                $path
            )
        );

        if ($existing) {
            $existing_payload = json_decode((string) $existing->payload, true);
            if (!is_array($existing_payload)) {
                $existing_payload = array();
            }

            $samples = isset($existing_payload['samples']) && is_array($existing_payload['samples'])
                ? $existing_payload['samples']
                : array();

            $last_sample = !empty($samples) ? end($samples) : array();
            $last_timestamp_ms = isset($last_sample['timestamp_ms']) ? intval($last_sample['timestamp_ms']) : 0;

            if (count($samples) < 80 && ($last_timestamp_ms === 0 || ($timestamp_ms - $last_timestamp_ms) <= 5000)) {
                $samples[] = $payload;
                $existing_payload['samples'] = array_values($samples);

                return false !== $wpdb->update(
                    $table,
                    array('payload' => wp_json_encode($existing_payload)),
                    array('id' => intval($existing->id)),
                    array('%s'),
                    array('%d')
                );
            }
        }

        return (bool) $wpdb->insert(
            $table,
            array(
                'visitor_id' => isset($event['visitor_id']) ? sanitize_text_field($event['visitor_id']) : '',
                'session_id' => $session_id,
                'event_type' => $event_type,
                'path' => $path,
                'page_title' => isset($event['page_title']) ? $this->sanitize_text_field_deep($event['page_title']) : '',
                'element_label' => isset($event['element_label']) ? $this->sanitize_text_field_deep($event['element_label']) : '',
                'element_href' => isset($event['element_href']) ? esc_url_raw($event['element_href']) : '',
                'referrer' => isset($event['referrer']) ? ($event['referrer'] === 'direct' ? 'direct' : esc_url_raw($event['referrer'])) : '',
                'device' => isset($event['device']) ? sanitize_text_field($event['device']) : '',
                'country' => isset($event['country']) ? sanitize_text_field($event['country']) : '',
                'payload' => wp_json_encode(array('samples' => array($payload))),
                'created_at' => isset($event['created_at']) ? sanitize_text_field($event['created_at']) : current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    private function current_path()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        return sanitize_text_field($path);
    }

    private function is_recording_preview_request()
    {
        $preview = isset($_REQUEST['brein_recording_preview']) ? wp_unslash($_REQUEST['brein_recording_preview']) : '';

        return $preview === '1';
    }

    private function is_admin_request()
    {
        if (is_admin() && !wp_doing_ajax()) {
            return true;
        }

        $referrer = isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';

        if (is_string($referrer) && strpos($referrer, '/wp-admin/') !== false) {
            return true;
        }

        return false;
    }

    private function current_page_title()
    {
        if (function_exists('wp_get_document_title')) {
            return sanitize_text_field(wp_get_document_title());
        }

        return '';
    }

    private function sanitize_text_field_deep($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = wp_strip_all_tags($value);

        return sanitize_text_field($value);
    }

    private function current_timestamp_ms()
    {
        return (int) round(microtime(true) * 1000);
    }

    private function detect_device($user_agent)
    {
        $ua = strtolower($user_agent);

        if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
            return 'tablet';
        }

        if (preg_match('/mobi|iphone|android|blackberry|phone/', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function lookup_geo($ip)
    {
        if (empty($ip) || !$this->is_public_ip($ip)) {
            $ip = '145.97.39.155';
        }

        $cache_key = 'brein_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->fetch_geo_ipapi($ip);
        if (!$data) {
            $data = $this->fetch_geo_ipapi_com($ip);
        }

        if (!$data) {
            return array();
        }

        $lat = isset($data['lat']) ? floatval($data['lat']) : null;
        $lon = isset($data['lon']) ? floatval($data['lon']) : null;
        $country = !empty($data['country']) ? sanitize_text_field($data['country']) : '';
        $country_code = !empty($data['country_code']) ? sanitize_text_field($data['country_code']) : '';
        if ($lat === null || $lon === null) {
            return array();
        }

        $result = array('lat' => $lat, 'lon' => $lon, 'country' => $country, 'country_code' => $country_code);
        set_transient($cache_key, $result, DAY_IN_SECONDS * 7);

        return $result;
    }

    private function is_public_ip($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        return false;
    }

    private function get_client_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            if (!empty($parts[0])) {
                return sanitize_text_field($parts[0]);
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return '';
    }

    private function fetch_geo_ipapi($ip)
    {
        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/';
        $response = wp_remote_get($url, array('timeout' => 3));
        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['error']) || !empty($data['bogon'])) {
            return null;
        }

        return array(
            'lat' => isset($data['latitude']) ? floatval($data['latitude']) : null,
            'lon' => isset($data['longitude']) ? floatval($data['longitude']) : null,
            'country' => isset($data['country_name']) ? $data['country_name'] : (isset($data['country']) ? $data['country'] : ''),
            'country_code' => isset($data['country_code']) ? $data['country_code'] : '',
        );
    }

    private function fetch_geo_ipapi_com($ip)
    {
        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,country,lat,lon';
        $response = wp_remote_get($url, array('timeout' => 3));
        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || (isset($data['status']) && $data['status'] !== 'success')) {
            return null;
        }

        return array(
            'lat' => isset($data['lat']) ? floatval($data['lat']) : null,
            'lon' => isset($data['lon']) ? floatval($data['lon']) : null,
            'country' => isset($data['country']) ? $data['country'] : '',
            'country_code' => isset($data['countryCode']) ? $data['countryCode'] : '',
        );
    }
}
