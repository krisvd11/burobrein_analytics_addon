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
            __('Overzicht', 'brein-plugin'),
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

            if ($slug === 'brein-cookie-compliance') {
                $ordered['cookie_compliance'] = $item;
                continue;
            }

            $remaining[] = $item;
        }

        $submenu['brein-analytics'] = array_values(array_filter(
            array_merge(
                isset($ordered['overview']) ? array($ordered['overview']) : array(),
                isset($ordered['cookie_compliance']) ? array($ordered['cookie_compliance']) : array(),
                isset($ordered['tracking_modules']) ? array($ordered['tracking_modules']) : array(),
                isset($ordered['funnels']) ? array($ordered['funnels']) : array(),
                $remaining
            )
        ));
    }

    public function render_page()
    {
        if (!current_user_can($this->get_required_capability())) {
            wp_die(__('Je hebt geen toestemming om deze pagina te bekijken.', 'brein-plugin'));
        }

        $clear_nonce = wp_create_nonce('brein_analytics_actions');
        ob_start();
        ?>
        <div class="wrap">
            <div class="brein-analytics-header">
                <h1><?php echo esc_html__('Analytics', 'brein-plugin'); ?></h1>
                <div class="brein-analytics-actions">
                    <button type="button" class="button button-secondary" id="brein-analytics-clear" data-nonce="<?php echo esc_attr($clear_nonce); ?>"><?php echo esc_html__('Gebruikers wissen', 'brein-plugin'); ?></button>
                    <button type="button" class="button button-secondary" id="brein-analytics-seed" data-nonce="<?php echo esc_attr($clear_nonce); ?>"><?php echo esc_html__('Dummygebruikers genereren', 'brein-plugin'); ?></button>
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
                .brein-top-pages { padding: 16px; }
                .brein-top-pages__list { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
                .brein-top-pages__item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0; }
                .brein-top-pages__left { min-width: 0; flex: 1 1 58%; display: flex; align-items: center; gap: 10px; }
                .brein-top-pages__icon { width: 22px; height: 22px; border-radius: 6px; background: #f3f4f6; color: #374151; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }
                .brein-top-pages__icon .dashicons { width: 14px; height: 14px; font-size: 14px; }
                .brein-top-pages__link { display: block; min-width: 0; color: inherit; text-decoration: none; }
                .brein-top-pages__link:hover .brein-top-pages__title,
                .brein-top-pages__link:focus .brein-top-pages__title { color: #2271b1; text-decoration: underline; }
                .brein-top-pages__title { margin: 0; font-size: 13px; font-weight: 500; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .brein-top-pages__right { display: flex; align-items: center; gap: 8px; flex: 0 0 42%; min-width: 120px; }
                .brein-top-pages__bar { width: 100%; height: 8px; border-radius: 999px; background: rgba(15, 23, 42, 0.08); overflow: hidden; }
                .brein-top-pages__fill { height: 100%; border-radius: 999px; background: #cbd5e1; }
                .brein-top-pages__count { width: 28px; text-align: right; color: #374151; font-size: 12px; font-weight: 600; flex: 0 0 auto; }
                .brein-top-pages__empty { padding: 16px; color: #6b7280; font-size: 13px; }
                .brein-consent-rate { padding: 20px 16px 18px; display: grid; place-items: center; gap: 18px; }
                .brein-consent-rate__chart { width: 220px; height: 220px; position: relative; display: grid; place-items: center; }
                .brein-consent-rate__svg { width: 100%; height: 100%; transform: rotate(-90deg); overflow: visible; }
                .brein-consent-rate__track { fill: none; stroke: #eef2f6; stroke-width: 18; }
                .brein-consent-rate__segment { fill: none; stroke-width: 18; stroke-linecap: butt; cursor: pointer; transition: opacity 0.18s ease, transform 0.18s ease; transform-origin: 60px 60px; }
                .brein-consent-rate__segment:hover,
                .brein-consent-rate__segment.is-active { opacity: 0.9; transform: scale(1.02); }
                .brein-consent-rate__overlay { position: fixed; left: 0; top: 0; width: 188px; transform: translate(-50%, calc(-100% - 14px)); background: rgba(255, 255, 255, 0.98); border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.16); padding: 14px 14px 12px; opacity: 0; pointer-events: none; transition: opacity 0.18s ease; z-index: 999999; }
                .brein-consent-rate__overlay.is-visible { opacity: 1; }
                .brein-consent-rate__overlay-title { margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #111827; }
                .brein-consent-rate__overlay-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
                .brein-consent-rate__overlay-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; color: #374151; }
                .brein-consent-rate__overlay-source { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .brein-consent-rate__overlay-count { color: #111827; font-weight: 700; flex: 0 0 auto; }
                .brein-consent-rate__overlay-empty { margin: 0; font-size: 12px; color: #6b7280; }
                .brein-consent-rate__legend { width: 100%; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
                .brein-consent-rate__card { background: #f8fafc; border: 1px solid #edf2f7; border-radius: 12px; padding: 12px; display: grid; gap: 6px; }
                .brein-consent-rate__card-head { display: flex; align-items: center; gap: 8px; min-width: 0; }
                .brein-consent-rate__swatch { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 auto; }
                .brein-consent-rate__name { font-size: 12px; font-weight: 600; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .brein-consent-rate__value { font-size: 20px; line-height: 1; font-weight: 700; color: #111827; }
                .brein-consent-rate__percent { font-size: 12px; color: #6b7280; }
                .brein-consent-rate__empty { padding: 16px; color: #6b7280; font-size: 13px; }
                @media (max-width: 1200px) {
                    .brein-analytics-overview { grid-template-columns: 1fr; }
                    .brein-analytics-box--half { grid-column: 1 / -1; }
                }
                @media (max-width: 782px) {
                    .brein-consent-rate__legend { grid-template-columns: 1fr; }
                }
            </style>

            <div class="brein-analytics-overview" style="display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;">
                <?php $this->render_panel('brein-analytics-map', __('Live bezoekerskaart (laatste 5 minuten)', 'brein-plugin'), 'brein-analytics-map-body', 'half', array($this->map_widget, 'render_map_widget')); ?>
                <?php $this->render_panel('brein-analytics-weekly-visitors', __('Wekelijkse bezoekers', 'brein-plugin'), 'brein-analytics-weekly-visitors-body', 'half', array($this->weekly_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-top-pages', __('Top 10 paginabezoeken', 'brein-plugin'), 'brein-analytics-top-pages-body', 'half', array($this, 'render_top_pages_widget')); ?>
                <?php $this->render_panel('brein-analytics-consent-rate', __('Toestemmingsratio', 'brein-plugin'), 'brein-analytics-consent-rate-body', 'half', array($this, 'render_consent_rate_widget')); ?>
                <?php $this->render_panel('brein-analytics-tracking-module', __('Trackingmodules', 'brein-plugin'), 'brein-analytics-tracking-module-body', 'half', array($this->tracking_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-funnel-module', __('Trechters', 'brein-plugin'), 'brein-analytics-funnel-module-body', 'half', array($this->funnel_widget, 'render_widget')); ?>
                <?php $this->render_panel('brein-analytics-live-visitors', __('Live bezoekers', 'brein-plugin'), 'brein-analytics-live-visitors-body', 'full', array($this->visitor_widget, 'render_widget')); ?>
            </div>
        </div>

        <script>
        <?php echo "
            jQuery(function($){
                function runAction(action, nonce, label) {
                    var confirmText = action === 'brein_clear_visitors'
                        ? 'Weet je zeker dat je alle gemeten bezoekers wilt wissen?'
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
                                var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Er is iets misgegaan.';
                                alert(msg);
                            }
                        })
                        .fail(function(){
                            alert('Aanvraag mislukt.');
                        })
                        .always(function(){
                            \$btn.prop('disabled', false).text(originalText);
                        });
                }

                $('#brein-analytics-clear').on('click', function(){
                    var nonce = $(this).data('nonce');
                    runAction('brein_clear_visitors', nonce, 'Wissen...');
                });

                $('#brein-analytics-seed').on('click', function(){
                    var nonce = $(this).data('nonce');
                    runAction('brein_seed_visitors', nonce, 'Genereren...');
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

                $('.brein-consent-rate__chart').each(function(){
                    var chart = this;
                    var overlay = chart.querySelector('.brein-consent-rate__overlay');
                    var title = overlay ? overlay.querySelector('.brein-consent-rate__overlay-title') : null;
                    var list = overlay ? overlay.querySelector('.brein-consent-rate__overlay-list') : null;
                    var empty = overlay ? overlay.querySelector('.brein-consent-rate__overlay-empty') : null;
                    var segments = chart.querySelectorAll('.brein-consent-rate__segment');

                    if (!overlay || !title || !list || !empty || !segments.length) {
                        return;
                    }

                    function hideOverlay() {
                        overlay.classList.remove('is-visible');
                        segments.forEach(function(segment){
                            segment.classList.remove('is-active');
                        });
                    }

                    function positionOverlay(event) {
                        if (!event) {
                            return;
                        }

                        var overlayRect = overlay.getBoundingClientRect();
                        var x = event.clientX;
                        var y = event.clientY;
                        var minX = (overlayRect.width / 2) + 8;
                        var maxX = window.innerWidth - (overlayRect.width / 2) - 8;
                        var minY = overlayRect.height + 16;
                        var maxY = window.innerHeight - 8;

                        x = Math.min(Math.max(x, minX), maxX);
                        y = Math.min(Math.max(y, minY), maxY);

                        overlay.style.left = x + 'px';
                        overlay.style.top = y + 'px';
                    }

                    function showOverlay(segment, event) {
                        var label = segment.getAttribute('data-consent-label') || '';
                        var referrersRaw = segment.getAttribute('data-consent-referrers') || '[]';
                        var referrers = [];

                        try {
                            referrers = JSON.parse(referrersRaw);
                        } catch (e) {
                            referrers = [];
                        }

                        title.textContent = label ? (label + ' herkomstbronnen') : 'Top herkomstbronnen';
                        list.innerHTML = '';
                        empty.hidden = referrers.length > 0;

                        referrers.forEach(function(referrer){
                            var item = document.createElement('li');
                            item.className = 'brein-consent-rate__overlay-item';

                            var source = document.createElement('span');
                            source.className = 'brein-consent-rate__overlay-source';
                            source.textContent = referrer && referrer.label ? referrer.label : 'Onbekend';

                            var count = document.createElement('span');
                            count.className = 'brein-consent-rate__overlay-count';
                            count.textContent = referrer && typeof referrer.count !== 'undefined' ? referrer.count : '0';

                            item.appendChild(source);
                            item.appendChild(count);
                            list.appendChild(item);
                        });

                        segments.forEach(function(item){
                            item.classList.toggle('is-active', item === segment);
                        });
                        positionOverlay(event);
                        overlay.classList.add('is-visible');
                    }

                    segments.forEach(function(segment){
                        segment.addEventListener('mouseenter', function(event){
                            showOverlay(segment, event);
                        });
                        segment.addEventListener('mousemove', function(event){
                            showOverlay(segment, event);
                        });
                        segment.addEventListener('focus', function(event){
                            showOverlay(segment, event);
                        });
                    });

                    chart.addEventListener('mouseleave', hideOverlay);
                    chart.addEventListener('focusout', function(event){
                        if (!chart.contains(event.relatedTarget)) {
                            hideOverlay();
                        }
                    });
                });
            });
        "; ?>
        </script>
        <?php
        echo ob_get_clean();
    }

    public function render_top_pages_widget()
    {
        global $wpdb;

        $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
        $rows = $wpdb->get_results(
            "SELECT path, MAX(page_title) AS page_title, COUNT(*) AS visits, MAX(created_at) AS last_seen
             FROM {$table}
             WHERE event_type = 'pageview'
               AND path <> ''
             GROUP BY path
             ORDER BY visits DESC, last_seen DESC
             LIMIT 10"
        );

        if (empty($rows)) {
            echo '<div class="brein-top-pages__empty">' . esc_html__('Er zijn nog geen paginabezoeken gemeten.', 'brein-plugin') . '</div>';
            return;
        }

        $max_visits = 1;
        foreach ($rows as $row) {
            $max_visits = max($max_visits, isset($row->visits) ? (int) $row->visits : 0);
        }
        ?>
        <div class="brein-top-pages">
            <ul class="brein-top-pages__list">
                <?php foreach ($rows as $index => $row): ?>
                    <?php
                    $path = isset($row->path) ? (string) $row->path : '';
                    $title = $this->resolve_top_page_label($path, isset($row->page_title) ? (string) $row->page_title : '');
                    $edit_link = $this->get_top_page_edit_link($path);
                    $visits = isset($row->visits) ? (int) $row->visits : 0;
                    $width = round(($visits / $max_visits) * 100, 2);
                    ?>
                    <li class="brein-top-pages__item">
                        <div class="brein-top-pages__left">
                            <span class="brein-top-pages__icon" aria-hidden="true">
                                <span class="dashicons dashicons-admin-page"></span>
                            </span>
                            <?php if ($edit_link) : ?>
                                <a class="brein-top-pages__link" href="<?php echo esc_url($edit_link); ?>">
                                    <p class="brein-top-pages__title"><?php echo esc_html($title); ?></p>
                                </a>
                            <?php else : ?>
                                <p class="brein-top-pages__title"><?php echo esc_html($title); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="brein-top-pages__right">
                            <div class="brein-top-pages__bar" aria-hidden="true">
                                <div class="brein-top-pages__fill" style="width: <?php echo esc_attr((string) $width); ?>%;"></div>
                            </div>
                            <span class="brein-top-pages__count"><?php echo esc_html(number_format_i18n($visits)); ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public function render_consent_rate_widget()
    {
        global $wpdb;

        $table = $wpdb->prefix . Brein_Visitor_Tracker::CONSENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            echo '<div class="brein-consent-rate__empty">' . esc_html__('Toestemmingsdata is nog niet beschikbaar.', 'brein-plugin') . '</div>';
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT choice, COUNT(*) AS total
             FROM {$table}
             GROUP BY choice"
        );

        $referrer_rows = $wpdb->get_results(
            "SELECT choice, referer, COUNT(*) AS total
             FROM {$table}
             GROUP BY choice, referer
             ORDER BY choice ASC, total DESC"
        );

        if (empty($rows)) {
            echo '<div class="brein-consent-rate__empty">' . esc_html__('Er zijn nog geen keuzes in de cookiebanner geregistreerd.', 'brein-plugin') . '</div>';
            return;
        }

        $segments = array(
            'accept_all' => array(
                'label' => __('Alles accepteren', 'brein-plugin'),
                'color' => '#56E3A0',
                'count' => 0,
            ),
            'personalized' => array(
                'label' => __('Persoonlijk', 'brein-plugin'),
                'color' => '#DFECFD',
                'count' => 0,
            ),
            'reject' => array(
                'label' => __('Weigeren', 'brein-plugin'),
                'color' => '#fc2864',
                'count' => 0,
            ),
        );

        foreach ($rows as $row) {
            $choice = isset($row->choice) ? sanitize_key($row->choice) : '';
            if (!isset($segments[$choice])) {
                continue;
            }
            $segments[$choice]['count'] = isset($row->total) ? (int) $row->total : 0;
        }

        $top_referrers = array(
            'accept_all' => array(),
            'personalized' => array(),
            'reject' => array(),
        );
        foreach ($referrer_rows as $row) {
            $choice = isset($row->choice) ? sanitize_key($row->choice) : '';
            if (!isset($top_referrers[$choice]) || count($top_referrers[$choice]) >= 3) {
                continue;
            }
            $top_referrers[$choice][] = array(
                'label' => $this->format_consent_referrer_label(isset($row->referer) ? (string) $row->referer : ''),
                'count' => isset($row->total) ? (int) $row->total : 0,
            );
        }

        $total = array_sum(array_column($segments, 'count'));
        if ($total < 1) {
            echo '<div class="brein-consent-rate__empty">' . esc_html__('Er zijn nog geen keuzes in de cookiebanner geregistreerd.', 'brein-plugin') . '</div>';
            return;
        }

        $radius = 46;
        $circumference = 2 * M_PI * $radius;
        $gap_length = 4;
        $offset = 0.0;
        $chart_segments = array();

        foreach ($segments as $segment_key => $segment) {
            $segment_count = (int) $segment['count'];
            if ($segment_count <= 0) {
                continue;
            }
            $raw_length = ($segment_count / $total) * $circumference;
            $visible_length = max($raw_length - $gap_length, 4);
            $chart_segments[] = array(
                'choice' => isset($segment_key) ? $segment_key : '',
                'label' => $segment['label'],
                'color' => $segment['color'],
                'dasharray' => round($visible_length, 2) . ' ' . round(max($circumference - $visible_length, 0), 2),
                'dashoffset' => round(-$offset, 2),
                'referrers' => isset($top_referrers[$segment_key]) ? $top_referrers[$segment_key] : array(),
            );
            $offset += $raw_length;
        }
        ?>
        <div class="brein-consent-rate">
            <div class="brein-consent-rate__chart">
                <svg class="brein-consent-rate__svg" viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="brein-consent-rate__track" cx="60" cy="60" r="<?php echo esc_attr((string) $radius); ?>"></circle>
                    <?php foreach ($chart_segments as $chart_segment) : ?>
                        <circle
                            class="brein-consent-rate__segment"
                            data-consent-choice="<?php echo esc_attr($chart_segment['choice']); ?>"
                            data-consent-label="<?php echo esc_attr($chart_segment['label']); ?>"
                            data-consent-referrers="<?php echo esc_attr(wp_json_encode($chart_segment['referrers'])); ?>"
                            cx="60"
                            cy="60"
                            r="<?php echo esc_attr((string) $radius); ?>"
                            stroke="<?php echo esc_attr($chart_segment['color']); ?>"
                            stroke-dasharray="<?php echo esc_attr($chart_segment['dasharray']); ?>"
                            stroke-dashoffset="<?php echo esc_attr((string) $chart_segment['dashoffset']); ?>"></circle>
                    <?php endforeach; ?>
                </svg>
                <div class="brein-consent-rate__overlay" aria-hidden="true">
                    <h4 class="brein-consent-rate__overlay-title"></h4>
                    <ul class="brein-consent-rate__overlay-list"></ul>
                    <p class="brein-consent-rate__overlay-empty" hidden><?php echo esc_html__('Er zijn nog geen herkomstbronnen geregistreerd.', 'brein-plugin'); ?></p>
                </div>
            </div>
            <div class="brein-consent-rate__legend">
                <?php foreach ($segments as $segment) : ?>
                    <?php $percent = $total > 0 ? round(($segment['count'] / $total) * 100) : 0; ?>
                    <div class="brein-consent-rate__card">
                        <div class="brein-consent-rate__card-head">
                            <span class="brein-consent-rate__swatch" style="background: <?php echo esc_attr($segment['color']); ?>;"></span>
                            <span class="brein-consent-rate__name"><?php echo esc_html($segment['label']); ?></span>
                        </div>
                        <div class="brein-consent-rate__value"><?php echo esc_html(number_format_i18n($segment['count'])); ?></div>
                        <div class="brein-consent-rate__percent"><?php echo esc_html(sprintf(__('%d%% van de keuzes', 'brein-plugin'), $percent)); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function resolve_top_page_label($path, $fallback_title = '')
    {
        $normalized_path = $this->normalize_top_page_path($path);

        if ($normalized_path === '/' || $normalized_path === '') {
            return __('Startpagina', 'brein-plugin');
        }

        $post_id = url_to_postid(home_url($normalized_path));
        if ($post_id) {
            $title = get_the_title($post_id);
            if (is_string($title) && $title !== '') {
                return $title;
            }
        }

        if ($fallback_title !== '') {
            return $fallback_title;
        }

        $segments = array_values(array_filter(explode('/', trim($normalized_path, '/'))));
        $last_segment = !empty($segments) ? end($segments) : $normalized_path;
        $last_segment = is_string($last_segment) ? $last_segment : $normalized_path;
        $last_segment = str_replace(array('-', '_'), ' ', $last_segment);

        return ucwords($last_segment);
    }

    private function format_consent_referrer_label($referrer)
    {
        $referrer = is_string($referrer) ? trim($referrer) : '';
        if ($referrer === '' || strtolower($referrer) === 'direct') {
            return __('Direct', 'brein-plugin');
        }

        $host = wp_parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $referrer;
        }

        $host = preg_replace('/^www\./', '', strtolower($host));

        return $host !== '' ? $host : $referrer;
    }

    private function get_top_page_edit_link($path)
    {
        $normalized_path = $this->normalize_top_page_path($path);
        if ($normalized_path === '') {
            return '';
        }

        $post_id = url_to_postid(home_url($normalized_path));
        if (!$post_id) {
            return '';
        }

        $edit_link = get_edit_post_link($post_id, '');

        return is_string($edit_link) ? $edit_link : '';
    }

    private function normalize_top_page_path($path)
    {
        $path = is_string($path) ? trim($path) : '';
        if ($path === '') {
            return '';
        }

        $parsed_path = wp_parse_url($path, PHP_URL_PATH);
        if (is_string($parsed_path) && $parsed_path !== '') {
            $path = $parsed_path;
        }

        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        return untrailingslashit($path) === '' ? '/' : untrailingslashit($path);
    }

    private function render_panel($section_id, $title, $body_id, $size, $callback)
    {
        $section_class = $size === 'full'
            ? 'brein-analytics-box brein-analytics-box--full'
            : 'brein-analytics-box brein-analytics-box--half';
        $section_style = $size === 'full' ? 'grid-column:1 / -1;' : 'grid-column:span 6;';
        $toggle_label = sprintf(
            /* translators: %s: panel title */
            __('Paneel tonen/verbergen: %s', 'brein-plugin'),
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
            wp_send_json_error(array('message' => __('Toegang geweigerd.', 'brein-plugin')));
        }

        check_ajax_referer('brein_analytics_actions', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::TABLE;
        $result = $wpdb->query("TRUNCATE TABLE {$table}");

        if ($result === false) {
            wp_send_json_error(array('message' => __('Bezoekers wissen is mislukt.', 'brein-plugin')));
        }

        wp_send_json_success(array('message' => __('Bezoekers zijn gewist.', 'brein-plugin')));
    }

    public function ajax_seed_visitors()
    {
        if (!current_user_can($this->get_required_capability())) {
            wp_send_json_error(array('message' => __('Toegang geweigerd.', 'brein-plugin')));
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

        wp_send_json_success(array('message' => sprintf(__('%d dummybezoekers toegevoegd.', 'brein-plugin'), $inserted)));
    }
}
