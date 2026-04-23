<?php
/**
 * Analytics admin page (dashboard-only).
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Analytics_Page
{
    private $role_access_manager;
    private $map_widget;
    private $visitor_widget;
    private $weekly_widget;
    private $tracking_widget;
    private $funnel_widget;
    private $screen_hook;

    public function __construct($role_access_manager, $map_widget, $visitor_widget, $weekly_widget, $tracking_widget = null, $funnel_widget = null)
    {
        if (!is_admin()) {
            return;
        }

        $this->role_access_manager = $role_access_manager;
        $this->map_widget = $map_widget;
        $this->visitor_widget = $visitor_widget;
        $this->weekly_widget = $weekly_widget;
        $this->tracking_widget = $tracking_widget;
        $this->funnel_widget = $funnel_widget;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_menu', array($this, 'reorder_submenu_items'), 100);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('brein_analytics_widget_hooks', array($this, 'register_widget_hooks'));
        add_action('wp_ajax_brein_clear_visitors', array($this, 'ajax_clear_visitors'));
        add_action('wp_ajax_brein_seed_visitors', array($this, 'ajax_seed_visitors'));
    }

    public function register_menu()
    {
        $capability = $this->get_required_capability();

        $this->screen_hook = add_menu_page(
            __('Analytics', 'brein-plugin'),
            __('Analytics', 'brein-plugin'),
            $capability,
            'brein-analytics',
            array($this, 'render_page'),
            'dashicons-analytics',
            25
        );

        add_submenu_page(
            'brein-analytics',
            __('Analytics', 'brein-plugin'),
            __('Overview', 'brein-plugin'),
            $capability,
            'brein-analytics',
            array($this, 'render_page')
        );
    }

    private function get_required_capability()
    {
        if ($this->role_access_manager && $this->role_access_manager->user_can_access_section('technical')) {
            return 'edit_pages';
        }
        return 'manage_options';
    }

    public function enqueue_assets($hook)
    {
        if (!$this->screen_hook || $hook !== $this->screen_hook) {
            return;
        }

        wp_enqueue_style('dashboard');
        wp_enqueue_script('jquery-ui-sortable');
    }

    public function register_widget_hooks($hooks)
    {
        if ($this->screen_hook) {
            $hooks[] = $this->screen_hook;
        }
        return array_values(array_unique($hooks));
    }

    public function reorder_submenu_items()
    {
        global $submenu;

        if (!isset($submenu['brein-analytics']) || !is_array($submenu['brein-analytics'])) {
            return;
        }

        $items = $submenu['brein-analytics'];
        $ordered = array();
        $remaining = array();

        foreach ($items as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';

            if ($slug === 'brein-analytics') {
                $ordered['overview'] = $item;
                continue;
            }

            if ($slug === 'edit.php?post_type=brein_tracking_item') {
                $ordered['tracking_modules'] = $item;
                continue;
            }

            if ($slug === 'edit.php?post_type=brein_funnel') {
                $ordered['funnels'] = $item;
                continue;
            }

            $remaining[] = $item;
        }

        $submenu['brein-analytics'] = array_values(array_filter(
            array_merge(
                isset($ordered['overview']) ? array($ordered['overview']) : array(),
                isset($ordered['tracking_modules']) ? array($ordered['tracking_modules']) : array(),
                isset($ordered['funnels']) ? array($ordered['funnels']) : array(),
                $remaining
            )
        ));
    }

    public function render_page()
    {
        if (!current_user_can($this->get_required_capability())) {
            wp_die(__('You do not have permission to access this page.', 'brein-plugin'));
        }

        $clear_nonce = wp_create_nonce('brein_analytics_actions');
        ob_start();
        ?>
        <div class="wrap">
            <div class="brein-analytics-header">
                <h1><?php echo esc_html__('Analytics', 'brein-plugin'); ?></h1>
                <div class="brein-analytics-actions">
                    <button type="button" class="button button-secondary" id="brein-analytics-clear" data-nonce="<?php echo esc_attr($clear_nonce); ?>"><?php echo esc_html__('Clear Users', 'brein-plugin'); ?></button>
                    <button type="button" class="button button-secondary" id="brein-analytics-seed" data-nonce="<?php echo esc_attr($clear_nonce); ?>"><?php echo esc_html__('Generate Dummy Users', 'brein-plugin'); ?></button>
                </div>
            </div>

            <style>
                .brein-analytics-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
                .brein-analytics-actions { display: inline-flex; gap: 8px; }
                .brein-weekly-card { padding: 16px 16px; }
                .brein-analytics-overview { display: grid; grid-template-columns: repeat(12, 1fr); gap: 16px; padding: 16px 0; }
                .brein-analytics-box { background: #ffffff; border: 1px solid #e6e6e6; border-radius: 12px; overflow: hidden; }
                .brein-analytics-box--full { grid-column: 1 / -1; }
                .brein-analytics-box--half { grid-column: span 6; min-width: 0; }
                .brein-analytics-box__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f0f0f0; background: #fbfbfb; }
                .brein-analytics-box__title { margin: 0; font-size: 13px; font-weight: 600; color: #1d2327; }
                .brein-analytics-box__actions { display: inline-flex; flex-direction: column; gap: 6px; }
                .brein-analytics-box__btn { border: 1px solid #d8d8d8; background: #ffffff; color: #1d2327; border-radius: 8px; width: 30px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; cursor: pointer; }
                .brein-analytics-box__btn:hover { background: #f5f5f5; }
                .brein-analytics-box__btn .dashicons { font-size: 16px; width: 16px; height: 16px; }
                .brein-analytics-box[data-collapsed="true"] .brein-analytics-box__body { display: none; }
                .brein-analytics-box[data-collapsed="true"] .brein-analytics-box__toggle .dashicons:before { content: "\f132"; }
                .brein-analytics-box[data-collapsed="false"] .brein-analytics-box__toggle .dashicons:before { content: "\f460"; }
                .brein-analytics-box__header { cursor: move; min-width: 0; }
                .brein-analytics-placeholder { border: 2px dashed #cfcfcf; border-radius: 12px; background: #fafafa; min-height: 120px; }
                .brein-analytics-box { min-width: 0; }
                .brein-analytics-box * { min-width: 0; }
                @media (max-width: 1200px) {
                    .brein-analytics-overview { grid-template-columns: 1fr; }
                    .brein-analytics-box--half { grid-column: 1 / -1; }
                }
            </style>

            <div class="brein-analytics-overview" style="display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;">
                <?php $this->render_panel('brein-analytics-map', __('Live Visitor Map (Last 5 Minutes)', 'brein-plugin'), 'brein-analytics-map-body', 'half', array($this->map_widget, 'render_map_widget')); ?>
                <?php $this->render_panel('brein-analytics-weekly-visitors', __('Weekly Visitors', 'brein-plugin'), 'brein-analytics-weekly-visitors-body', 'half', array($this->weekly_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-tracking-module', __('Tracking Module', 'brein-plugin'), 'brein-analytics-tracking-module-body', 'half', array($this->tracking_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-funnel-module', __('Funnels', 'brein-plugin'), 'brein-analytics-funnel-module-body', 'half', array($this->funnel_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-live-visitors', __('Live Visitors', 'brein-plugin'), 'brein-analytics-live-visitors-body', 'full', array($this->visitor_widget, 'render_widget')); ?>
            </div>
        </div>

        <script>
        <?php echo "
            jQuery(function($){
                function runAction(action, nonce, label) {
                    var confirmText = action === 'brein_clear_visitors'
                        ? 'Are you sure you want to clear all tracked visitors?'
                        : null;
                    if (confirmText && !window.confirm(confirmText)) {
                        return;
                    }
                    var \$btn = action === 'brein_clear_visitors' ? $('#brein-analytics-clear') : $('#brein-analytics-seed');
                    var originalText = \$btn.text();
                    \$btn.prop('disabled', true).text(label);
                    $.post(ajaxurl, { action: action, nonce: nonce })
                        .done(function(resp){
                            if (resp && resp.success) {
                                window.location.reload();
                            } else {
                                var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Something went wrong.';
                                alert(msg);
                            }
                        })
                        .fail(function(){
                            alert('Request failed.');
                        })
                        .always(function(){
                            \$btn.prop('disabled', false).text(originalText);
                        });
                }

                $('#brein-analytics-clear').on('click', function(){
                    var nonce = $(this).data('nonce');
                    runAction('brein_clear_visitors', nonce, 'Clearing...');
                });

                $('#brein-analytics-seed').on('click', function(){
                    var nonce = $(this).data('nonce');
                    runAction('brein_seed_visitors', nonce, 'Generating...');
                });

                function toggleBox(\$box) {
                    var collapsed = \$box.attr('data-collapsed') === 'true';
                    var nextCollapsed = !collapsed;
                    \$box.attr('data-collapsed', nextCollapsed ? 'true' : 'false');
                    var \$btn = \$box.find('.brein-analytics-box__toggle').first();
                    \$btn.attr('aria-expanded', nextCollapsed ? 'false' : 'true');
                }

                $('.brein-analytics-box__toggle').on('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    toggleBox($(this).closest('.brein-analytics-box'));
                });

                var \$grid = $('.brein-analytics-overview');
                if (\$grid.length && \$.fn.sortable) {
                    var storageKey = 'brein-analytics-order';
                    var saved = [];
                    try { saved = JSON.parse(localStorage.getItem(storageKey) || '[]'); } catch (e) { saved = []; }
                    if (saved.length) {
                        saved.forEach(function(id){
                            var \$item = $('#' + id);
                            if (\$item.length) { \$grid.append(\$item); }
                        });
                    }

                    \$grid.sortable({
                        items: '.brein-analytics-box',
                        handle: '.brein-analytics-box__header',
                        cancel: '.brein-analytics-box__toggle',
                        placeholder: 'brein-analytics-placeholder',
                        forcePlaceholderSize: true,
                        tolerance: 'pointer',
                        stop: function(){
                            var order = [];
                            \$grid.find('.brein-analytics-box').each(function(){
                                order.push(this.id);
                            });
                            localStorage.setItem(storageKey, JSON.stringify(order));
                        }
                    });
                }
            });
        "; ?>
        </script>
        <?php
        echo ob_get_clean();
    }

    private function render_panel($section_id, $title, $body_id, $size, $callback)
    {
        $section_class = $size === 'full'
            ? 'brein-analytics-box brein-analytics-box--full'
            : 'brein-analytics-box brein-analytics-box--half';
        $section_style = $size === 'full' ? 'grid-column:1 / -1;' : 'grid-column:span 6;';
        $toggle_label = sprintf(
            /* translators: %s: panel title */
            __('Toggle panel: %s', 'brein-plugin'),
            $title
        );
        ?>
        <section class="<?php echo esc_attr($section_class); ?>" id="<?php echo esc_attr($section_id); ?>" data-collapsed="false" style="<?php echo esc_attr($section_style); ?>">
            <div class="brein-analytics-box__header">
                <h2 class="brein-analytics-box__title"><?php echo esc_html($title); ?></h2>
                <div class="brein-analytics-box__actions">
                    <button type="button" class="brein-analytics-box__btn brein-analytics-box__toggle" aria-expanded="true" aria-controls="<?php echo esc_attr($body_id); ?>">
                        <span class="dashicons"></span>
                        <span class="screen-reader-text"><?php echo esc_html($toggle_label); ?></span>
                    </button>
                </div>
            </div>
            <div class="brein-analytics-box__body" id="<?php echo esc_attr($body_id); ?>">
                <?php
                if (is_array($callback) && isset($callback[0]) && $callback[0] && is_callable($callback)) {
                    call_user_func($callback);
                }
                ?>
            </div>
        </section>
        <?php
    }

    public function ajax_clear_visitors()
    {
        if (!current_user_can($this->get_required_capability())) {
            wp_send_json_error(array('message' => __('Permission denied.', 'brein-plugin')));
        }

        check_ajax_referer('brein_analytics_actions', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;
        $result = $wpdb->query("TRUNCATE TABLE {$table}");

        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to clear visitors.', 'brein-plugin')));
        }

        wp_send_json_success(array('message' => __('Visitors cleared.', 'brein-plugin')));
    }

    public function ajax_seed_visitors()
    {
        if (!current_user_can($this->get_required_capability())) {
            wp_send_json_error(array('message' => __('Permission denied.', 'brein-plugin')));
        }

        check_ajax_referer('brein_analytics_actions', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;
        $now = current_time('mysql');

        $countries = array(
            array('country' => 'Netherlands', 'code' => 'NL', 'lat' => 52.3702, 'lon' => 4.8952),
            array('country' => 'United Kingdom', 'code' => 'GB', 'lat' => 51.5074, 'lon' => -0.1278),
            array('country' => 'United States', 'code' => 'US', 'lat' => 40.7128, 'lon' => -74.0060),
            array('country' => 'Germany', 'code' => 'DE', 'lat' => 52.52, 'lon' => 13.4050),
            array('country' => 'France', 'code' => 'FR', 'lat' => 48.8566, 'lon' => 2.3522),
            array('country' => 'Spain', 'code' => 'ES', 'lat' => 40.4168, 'lon' => -3.7038),
            array('country' => 'Italy', 'code' => 'IT', 'lat' => 41.9028, 'lon' => 12.4964),
            array('country' => 'Canada', 'code' => 'CA', 'lat' => 43.6532, 'lon' => -79.3832),
        );

        $user_agents = array(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
        );

        $referers = array(
            'direct',
            'https://www.google.com/',
            'https://www.linkedin.com/',
            'https://www.facebook.com/',
            'https://www.instagram.com/',
        );

        $inserted = 0;
        for ($i = 0; $i < 25; $i++) {
            $seed = wp_generate_password(12, false);
            $visitor_id = md5($seed . '|' . microtime(true));
            $country = $countries[array_rand($countries)];
            $ua = $user_agents[array_rand($user_agents)];
            $device = (strpos(strtolower($ua), 'mobile') !== false || strpos(strtolower($ua), 'iphone') !== false || strpos(strtolower($ua), 'android') !== false) ? 'mobile' : 'desktop';
            $avatar_url = 'https://api.dicebear.com/7.x/open-peeps/svg?seed=' . rawurlencode($visitor_id);

            $lat = $country['lat'] + (mt_rand(-500, 500) / 10000);
            $lon = $country['lon'] + (mt_rand(-500, 500) / 10000);
            $last_seen = gmdate('Y-m-d H:i:s', strtotime($now) - mt_rand(0, 300));

            $result = $wpdb->insert(
                $table,
                array(
                    'visitor_id' => $visitor_id,
                    'avatar_url' => $avatar_url,
                    'ip_address' => '203.0.113.' . mt_rand(1, 254),
                    'country' => $country['country'],
                    'country_code' => $country['code'],
                    'referer' => $referers[array_rand($referers)],
                    'user_agent' => $ua,
                    'device' => $device,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'first_seen' => $now,
                    'last_seen' => $last_seen,
                    'visit_count' => mt_rand(1, 6),
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d')
            );

            if ($result) {
                $inserted++;
            }
        }

        wp_send_json_success(array('message' => sprintf(__('Inserted %d dummy visitors.', 'brein-plugin'), $inserted)));
    }
}
