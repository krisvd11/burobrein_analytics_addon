<?php
/**
 * Funnel widget and custom post type.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Funnel_Widget
{
    const POST_TYPE = 'brein_funnel';
    const META_STEPS = '_brein_funnel_steps';

    private $required_capability;
    private $plugin_file;

    public function __construct($required_capability = 'manage_options')
    {
        $this->required_capability = $required_capability;
        $this->plugin_file = BREIN_ANALYTICS_PLUGIN_FILE;

        add_action('init', array($this, 'register_post_type'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'), 10, 2);
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_funnel'));
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'filter_posts_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'render_posts_column'), 10, 2);
        add_filter('post_row_actions', array($this, 'filter_row_actions'), 10, 2);
        add_action('wp_ajax_brein_funnel_widget_panel', array($this, 'ajax_widget_panel'));
    }

    public function register_post_type()
    {
        $labels = array(
            'name' => __('Funnels', 'brein-plugin'),
            'singular_name' => __('Funnel', 'brein-plugin'),
            'menu_name' => __('Funnels', 'brein-plugin'),
            'name_admin_bar' => __('Funnel', 'brein-plugin'),
            'add_new' => __('Add New', 'brein-plugin'),
            'add_new_item' => __('Add New Funnel', 'brein-plugin'),
            'edit_item' => __('Edit Funnel', 'brein-plugin'),
            'new_item' => __('New Funnel', 'brein-plugin'),
            'view_item' => __('View Funnel', 'brein-plugin'),
            'search_items' => __('Search Funnels', 'brein-plugin'),
            'not_found' => __('No funnels found.', 'brein-plugin'),
            'not_found_in_trash' => __('No funnels found in Trash.', 'brein-plugin'),
        );

        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => $labels,
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => 'brein-analytics',
                'show_in_admin_bar' => false,
                'show_in_nav_menus' => false,
                'exclude_from_search' => true,
                'publicly_queryable' => false,
                'query_var' => false,
                'rewrite' => false,
                'menu_position' => 31,
                'menu_icon' => 'dashicons-filter',
                'supports' => array('title'),
                'capabilities' => $this->get_post_type_capabilities(),
                'map_meta_cap' => false,
            )
        );
    }

    public function enqueue_assets($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php', 'edit.php'), true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_style(
            'brein-widgets',
            plugins_url('assets/css/widgets.css', $this->plugin_file),
            array(),
            '1.0.0'
        );

        wp_enqueue_script('jquery-ui-sortable');
    }

    public function register_meta_boxes($post_type, $post)
    {
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        add_meta_box(
            'brein-funnel-settings',
            __('Funnel Steps', 'brein-plugin'),
            array($this, 'render_settings_meta_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'brein-funnel-results',
            __('Funnel Results', 'brein-plugin'),
            array($this, 'render_results_meta_box'),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_settings_meta_box($post)
    {
        $steps = $this->get_funnel_steps($post->ID);
        if (empty($steps)) {
            $steps = array(
                array('label' => 'Landing page', 'type' => 'pageview', 'value' => '/'),
                array('label' => 'CTA click', 'type' => 'click_class', 'value' => 'cta-button'),
            );
        }

        wp_nonce_field('brein_save_funnel', 'brein_funnel_nonce');

        ob_start();
        ?>
        <div class="brein-funnel-builder">
            <p class="description"><?php echo esc_html__('Create a funnel with multiple steps. A step can be a page view, any click, a CSS class click, or an element ID click.', 'brein-plugin'); ?></p>

            <div class="brein-funnel-steps" id="brein-funnel-steps">
                <?php foreach ($steps as $index => $step) : ?>
                    <?php $this->render_step_row($step, $index); ?>
                <?php endforeach; ?>
            </div>

            <p>
                <button type="button" class="button button-secondary" id="brein-add-funnel-step"><?php echo esc_html__('Add Step', 'brein-plugin'); ?></button>
            </p>
        </div>

        <script type="text/html" id="tmpl-brein-funnel-step">
            <?php
            $this->render_step_row(
                array(
                    'label' => '',
                    'type' => 'pageview',
                    'value' => '',
                ),
                '__index__'
            );
            ?>
        </script>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var container = document.getElementById('brein-funnel-steps');
                var addButton = document.getElementById('brein-add-funnel-step');
                var template = document.getElementById('tmpl-brein-funnel-step');

                if (!container || !addButton || !template) {
                    return;
                }

                function getPlaceholder(type) {
                    if (type === 'click_class') {
                        return 'cta-button';
                    }

                    if (type === 'click_id') {
                        return 'hero-button';
                    }

                    if (type === 'pageview') {
                        return '/contact/';
                    }

                    return '';
                }

                function getHelp(type) {
                    if (type === 'click_class') {
                        return <?php echo wp_json_encode(__('Track clicks on links or buttons with this CSS class.', 'brein-plugin')); ?>;
                    }

                    if (type === 'click_id') {
                        return <?php echo wp_json_encode(__('Track clicks on a link or button with this element ID.', 'brein-plugin')); ?>;
                    }

                    if (type === 'click_any') {
                        return <?php echo wp_json_encode(__('Track any captured click on a button or link.', 'brein-plugin')); ?>;
                    }

                    return <?php echo wp_json_encode(__('Track a page view by path, like /pricing/ or /contact/.', 'brein-plugin')); ?>;
                }

                function updateStep(step) {
                    var index = Array.prototype.indexOf.call(container.children, step);
                    var number = step.querySelector('.brein-funnel-step__number');
                    var typeField = step.querySelector('.brein-funnel-step__type');
                    var valueField = step.querySelector('.brein-funnel-step__value');
                    var pageWrap = step.querySelector('.brein-funnel-step__page-wrap');
                    var pageField = pageWrap ? pageWrap.querySelector('select') : null;
                    var help = step.querySelector('.brein-funnel-step__help');

                    if (number) {
                        number.textContent = index + 1;
                    }

                    if (!typeField || !valueField || !help || !pageWrap) {
                        return;
                    }

                    var type = typeField.value || 'pageview';
                    valueField.placeholder = getPlaceholder(type);
                    valueField.style.display = (type === 'click_any' || type === 'pageview') ? 'none' : '';
                    pageWrap.style.display = type === 'pageview' ? '' : 'none';

                    if (type === 'click_any') {
                        valueField.value = '';
                    }

                    if (type !== 'pageview' && pageField) {
                        pageField.selectedIndex = 0;
                    }

                    help.textContent = getHelp(type);
                }

                function updateAllSteps() {
                    Array.prototype.forEach.call(container.children, updateStep);
                }

                function bindStep(step) {
                    if (!step) {
                        return;
                    }

                    var removeButton = step.querySelector('.brein-funnel-step__remove');
                    var typeField = step.querySelector('.brein-funnel-step__type');

                    if (removeButton) {
                        removeButton.addEventListener('click', function () {
                            step.remove();
                            updateAllSteps();
                        });
                    }

                    if (typeField) {
                        typeField.addEventListener('change', function () {
                            updateStep(step);
                        });
                    }

                    updateStep(step);
                }

                Array.prototype.forEach.call(container.children, bindStep);

                addButton.addEventListener('click', function () {
                    var index = container.children.length;
                    var html = template.innerHTML.replace(/__index__/g, String(index));
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;

                    var step = wrapper.firstElementChild;
                    if (!step) {
                        return;
                    }

                    container.appendChild(step);
                    bindStep(step);
                    updateAllSteps();
                });

                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sortable) {
                    window.jQuery(container).sortable({
                        items: '.brein-funnel-step',
                        handle: '.brein-funnel-step__drag',
                        stop: updateAllSteps
                    });
                }
            });
        </script>
        <?php
        echo ob_get_clean();
    }

    public function render_results_meta_box($post)
    {
        global $wpdb;

        $steps = $this->get_funnel_steps($post->ID);
        if (count($steps) < 2) {
            echo '<p>' . esc_html__('Add at least two funnel steps and save to start reporting conversions.', 'brein-plugin') . '</p>';
            return;
        }

        $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            echo '<p>' . esc_html__('Tracking events table is not initialized yet.', 'brein-plugin') . '</p>';
            return;
        }

        $report = $this->get_funnel_report($table, $steps);

        ob_start();
        ?>
        <div class="brein-funnel-report">
            <div class="brein-funnel-summary">
                <div class="brein-funnel-summary__card">
                    <span><?php echo esc_html__('Started sessions', 'brein-plugin'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($report['started_sessions'])); ?></strong>
                </div>
                <div class="brein-funnel-summary__card">
                    <span><?php echo esc_html__('Completed sessions', 'brein-plugin'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($report['completed_sessions'])); ?></strong>
                </div>
                <div class="brein-funnel-summary__card">
                    <span><?php echo esc_html__('Completion rate', 'brein-plugin'); ?></span>
                    <strong><?php echo esc_html($report['completion_rate']); ?></strong>
                </div>
                <div class="brein-funnel-summary__card">
                    <span><?php echo esc_html__('Unique visitors', 'brein-plugin'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($report['unique_visitors'])); ?></strong>
                </div>
            </div>

            <?php echo $this->render_funnel_flow_graph($report, $post->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="brein-funnel-steps-report">
                <?php foreach ($report['steps'] as $index => $step_report) : ?>
                    <div class="brein-funnel-step-report">
                        <div class="brein-funnel-step-report__top">
                            <div>
                                <strong><?php echo esc_html(sprintf(__('Step %d', 'brein-plugin'), $index + 1)); ?></strong>
                                <div><?php echo esc_html($step_report['label']); ?></div>
                            </div>
                            <div class="brein-funnel-step-report__stats">
                                <strong><?php echo esc_html(number_format_i18n($step_report['sessions'])); ?></strong>
                                <span><?php echo esc_html($step_report['conversion']); ?></span>
                            </div>
                        </div>
                        <div class="brein-funnel-step-report__bar">
                            <span style="width:<?php echo esc_attr($step_report['bar_width']); ?>%;"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }

    public function save_funnel($post_id)
    {
        if (!isset($_POST['brein_funnel_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['brein_funnel_nonce'])), 'brein_save_funnel')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can($this->required_capability)) {
            return;
        }

        $raw_steps = isset($_POST['brein_funnel_steps']) ? wp_unslash($_POST['brein_funnel_steps']) : array();

        if (is_array($raw_steps)) {
            foreach ($raw_steps as $index => $step) {
                if (!is_array($step)) {
                    continue;
                }

                $type = isset($step['type']) ? $this->sanitize_step_type($step['type']) : 'pageview';
                if ($type !== 'pageview') {
                    continue;
                }

                $page_id = isset($step['page_id']) ? absint($step['page_id']) : 0;
                $raw_steps[$index]['value'] = $page_id > 0 ? $this->get_tracking_path_from_page_id($page_id) : '';
            }
        }

        $steps = $this->sanitize_steps($raw_steps);

        if (empty($steps)) {
            delete_post_meta($post_id, self::META_STEPS);
            return;
        }

        update_post_meta($post_id, self::META_STEPS, $steps);
    }

    public function filter_posts_columns($columns)
    {
        return array(
            'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
            'title' => __('Title', 'brein-plugin'),
            'brein_funnel_steps' => __('Steps', 'brein-plugin'),
            'brein_funnel_completion' => __('Completion', 'brein-plugin'),
        );
    }

    public function render_posts_column($column, $post_id)
    {
        if ($column === 'brein_funnel_steps') {
            $steps = $this->get_funnel_steps($post_id);
            echo esc_html(number_format_i18n(count($steps)));
            return;
        }

        if ($column === 'brein_funnel_completion') {
            global $wpdb;

            $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
                echo '-';
                return;
            }

            $steps = $this->get_funnel_steps($post_id);
            if (count($steps) < 2) {
                echo esc_html__('Not enough steps', 'brein-plugin');
                return;
            }

            $report = $this->get_funnel_report($table, $steps);
            echo esc_html($report['completion_rate']);
        }
    }

    public function filter_row_actions($actions, $post)
    {
        if (!($post instanceof WP_Post) || $post->post_type !== self::POST_TYPE) {
            return $actions;
        }

        unset($actions['view']);

        return $actions;
    }

    public function render_widget()
    {
        global $wpdb;

        $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if ($exists !== $table) {
            echo '<p>' . esc_html__('Tracking events table is not initialized yet.', 'brein-plugin') . '</p>';
            return;
        }

        $funnels = $this->get_funnels();
        if (empty($funnels)) {
            echo '<div class="brein-funnel-widget-empty">';
            echo '<p>' . esc_html__('Create a funnel to measure how visitors move from one tracked step to the next.', 'brein-plugin') . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)) . '">' . esc_html__('Add Funnel', 'brein-plugin') . '</a></p>';
            echo '</div>';
            return;
        }

        $funnel_reports = array();
        foreach ($funnels as $funnel) {
            $steps = $this->get_funnel_steps($funnel->ID);
            if (count($steps) < 2) {
                continue;
            }

            $funnel_reports[] = array(
                'funnel' => $funnel,
                'steps' => $steps,
                'report' => $this->get_funnel_report($table, $steps),
            );
        }

        if (empty($funnel_reports)) {
            echo '<div class="brein-funnel-widget-empty">';
            echo '<p>' . esc_html__('Create a funnel with at least two steps to preview it here.', 'brein-plugin') . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)) . '">' . esc_html__('Add Funnel', 'brein-plugin') . '</a></p>';
            echo '</div>';
            return;
        }

        $nonce = wp_create_nonce('brein_funnel_widget_panel');
        ob_start();
        ?>
        <div class="brein-funnel-widget" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="brein-funnel-widget__toolbar">
                <label class="screen-reader-text" for="brein-funnel-widget-select"><?php echo esc_html__('Select funnel', 'brein-plugin'); ?></label>
                <select id="brein-funnel-widget-select" class="brein-funnel-widget__select">
                    <?php foreach ($funnel_reports as $index => $entry) : ?>
                        <option value="<?php echo esc_attr($entry['funnel']->ID); ?>" <?php selected($index, 0); ?>>
                            <?php echo esc_html(get_the_title($entry['funnel']->ID)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="brein-funnel-widget__viewer">
                <?php foreach ($funnel_reports as $index => $entry) : ?>
                    <div class="brein-funnel-widget__panel" data-funnel-id="<?php echo esc_attr($entry['funnel']->ID); ?>" data-report-signature="<?php echo esc_attr($this->get_report_signature($entry['report'])); ?>" data-report="<?php echo esc_attr(wp_json_encode($this->prepare_widget_report_payload($entry['report']))); ?>" data-scale-ceiling="<?php echo esc_attr(isset($entry['report']['scale_ceiling']) ? $entry['report']['scale_ceiling'] : 10); ?>" style="<?php echo $index === 0 ? '' : 'display:none;'; ?>">
                        <?php echo $this->render_widget_panel($entry['funnel'], $entry['steps'], $entry['report']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="brein-tracking-form__row">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)); ?>"><?php echo esc_html__('Add Funnel', 'brein-plugin'); ?></a>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var select = document.getElementById('brein-funnel-widget-select');
                if (!select) {
                    return;
                }

                var storageKey = 'brein-analytics-selected-funnel';
                var widget = document.querySelector('.brein-funnel-widget');
                var panels = document.querySelectorAll('.brein-funnel-widget__panel');
                var pollTimer = null;
                var pollInFlight = false;

                function showSelectedPanel(selectedId) {
                    panels.forEach(function (panel) {
                        panel.style.display = panel.getAttribute('data-funnel-id') === selectedId ? '' : 'none';
                    });
                }

                function getVisiblePanel() {
                    var selectedId = select.value;
                    for (var i = 0; i < panels.length; i++) {
                        if (panels[i].getAttribute('data-funnel-id') === selectedId) {
                            return panels[i];
                        }
                    }

                    return null;
                }

                function parseReport(panel) {
                    if (!panel) {
                        return null;
                    }

                    var raw = panel.getAttribute('data-report') || '';
                    if (!raw) {
                        return null;
                    }

                    try {
                        return JSON.parse(raw);
                    } catch (e) {
                        return null;
                    }
                }

                function setPanelReport(panel, report) {
                    if (!panel || !report) {
                        return;
                    }

                    panel.setAttribute('data-report', JSON.stringify(report));
                }

                function getLiveIndicatorMarkup(type) {
                    if (type === 'pageview') {
                        return '<span class="brein-funnel-flow-card__live-indicator-icon brein-funnel-flow-card__live-indicator-icon--eyes"><svg viewBox="0 0 200 100" aria-hidden="true"><ellipse cx="68" cy="50" rx="30" ry="22" fill="#ffffff" stroke="#111827" stroke-width="3"></ellipse><circle class="pupil" cx="68" cy="50" r="8" fill="#111827"></circle><ellipse cx="132" cy="50" rx="30" ry="22" fill="#ffffff" stroke="#111827" stroke-width="3"></ellipse><circle class="pupil" cx="132" cy="50" r="8" fill="#111827"></circle></svg></span>';
                    }

                    return '<span class="brein-funnel-flow-card__live-indicator-icon"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="ring" cx="26" cy="18" r="10" fill="none" stroke="#2563eb" stroke-width="2.5"></circle><circle class="ring second" cx="26" cy="18" r="10" fill="none" stroke="#60a5fa" stroke-width="2"></circle><circle class="spark" cx="26" cy="18" r="4.5" fill="#93c5fd"></circle><g class="cursor"><path d="M26 18L26 84L43 67L54 94L64 89L53 63L76 63L26 18Z" fill="#ffffff" stroke="#111827" stroke-width="3" stroke-linejoin="round"></path></g></svg></span>';
                }

                function triggerLiveIndicator(panel, stepIndex, stepType) {
                    if (!panel) {
                        return;
                    }

                    var stepNode = panel.querySelector('.brein-funnel-flow-card__header-step[data-step-index="' + stepIndex + '"]');
                    if (!stepNode) {
                        return;
                    }

                    var indicator = stepNode.querySelector('[data-step-live]');
                    if (!indicator) {
                        return;
                    }

                    indicator.innerHTML = getLiveIndicatorMarkup(stepType || 'click_class');
                    indicator.classList.remove('is-live');
                    void indicator.offsetWidth;
                    indicator.classList.add('is-live');
                }

                function formatPercent(value) {
                    var number = Number(value || 0);
                    return number.toLocaleString(undefined, {
                        minimumFractionDigits: 1,
                        maximumFractionDigits: 1
                    }) + '%';
                }

                function formatDropoff(value) {
                    var number = Number(value || 0);
                    var prefix = number > 0 ? '+' : '';
                    return prefix + number.toLocaleString(undefined, {
                        minimumFractionDigits: 1,
                        maximumFractionDigits: 1
                    }) + '%';
                }

                function getPanelScaleCeiling(panel, report) {
                    var current = Number(panel.getAttribute('data-scale-ceiling') || 0);
                    var next = Number(report && report.scale_ceiling ? report.scale_ceiling : 0);
                    var ceiling = Math.max(current || 0, next || 0, 10);
                    panel.setAttribute('data-scale-ceiling', String(ceiling));
                    return ceiling;
                }

                function getStepIndicatorTop(report, index, ceiling) {
                    var centerY = 145;
                    var maxHalfHeight = 76;
                    var effectiveCeiling = Math.max(Number(ceiling || 0), 10);
                    var step = report && report.steps && report.steps[index] ? report.steps[index] : null;
                    var sessions = step ? Number(step.sessions || 0) : 0;
                    var ratio = Math.max(0, Math.min(1, sessions / effectiveCeiling));
                    var halfHeight = Math.max(10, ratio * maxHalfHeight);

                    return Math.max(40, centerY - halfHeight - 28);
                }

                function buildPath(report, ceiling) {
                    if (!report || !report.steps || report.steps.length < 2) {
                        return '';
                    }

                    var width = 1000;
                    var height = 250;
                    var centerY = 145;
                    var maxHalfHeight = 76;
                    var stepCount = report.steps.length;
                    var columnWidth = width / stepCount;
                    var effectiveCeiling = Math.max(Number(ceiling || 0), 10);
                    var points = [];

                    report.steps.forEach(function (step, index) {
                        var ratio = Math.max(0, Math.min(1, Number(step.sessions || 0) / effectiveCeiling));
                        var halfHeight = Math.max(10, ratio * maxHalfHeight);
                        points.push({
                            x: index === 0 ? 0 : columnWidth * index,
                            halfHeight: halfHeight
                        });
                    });

                    points.push({
                        x: width,
                        halfHeight: points.length ? points[points.length - 1].halfHeight : 10
                    });

                    var path = '';
                    var first = points[0];
                    path += 'M ' + first.x.toFixed(2) + ' ' + (centerY - first.halfHeight).toFixed(2);

                    for (var i = 1; i < points.length; i++) {
                        var previous = points[i - 1];
                        var current = points[i];
                        var midX = ((previous.x + current.x) / 2).toFixed(2);
                        path += ' C ' + midX + ' ' + (centerY - previous.halfHeight).toFixed(2) + ', ';
                        path += midX + ' ' + (centerY - current.halfHeight).toFixed(2) + ', ';
                        path += current.x.toFixed(2) + ' ' + (centerY - current.halfHeight).toFixed(2);
                    }

                    var last = points[points.length - 1];
                    path += ' L ' + last.x.toFixed(2) + ' ' + (centerY + last.halfHeight).toFixed(2);

                    for (var j = points.length - 2; j >= 0; j--) {
                        var currentPoint = points[j];
                        var nextPoint = points[j + 1];
                        var lowerMidX = ((currentPoint.x + nextPoint.x) / 2).toFixed(2);
                        path += ' C ' + lowerMidX + ' ' + (centerY + nextPoint.halfHeight).toFixed(2) + ', ';
                        path += lowerMidX + ' ' + (centerY + currentPoint.halfHeight).toFixed(2) + ', ';
                        path += currentPoint.x.toFixed(2) + ' ' + (centerY + currentPoint.halfHeight).toFixed(2);
                    }

                    path += ' Z';

                    return path;
                }

                function animateNumber(from, to, progress) {
                    return from + ((to - from) * progress);
                }

                function updatePanelDom(panel, report) {
                    var panelScaleCeiling = getPanelScaleCeiling(panel, report);

                    panel.querySelectorAll('[data-step-index]').forEach(function (node) {
                        var index = Number(node.getAttribute('data-step-index'));
                        var step = report.steps[index];
                        if (!step) {
                            return;
                        }

                        var countNode = node.querySelector('[data-step-count]');
                        if (countNode) {
                            countNode.textContent = Math.round(Number(step.sessions || 0)).toLocaleString();
                        }

                        var indicatorNode = node.querySelector('[data-step-live]');
                        if (indicatorNode) {
                            indicatorNode.style.top = getStepIndicatorTop(report, index, panelScaleCeiling).toFixed(2) + 'px';
                        }

                        if (node.hasAttribute('data-step-dropoff')) {
                            var dropoffNode = node.querySelector('.brein-funnel-flow-card__dropoff-value');
                            if (dropoffNode) {
                                dropoffNode.textContent = formatDropoff(step.dropoff || 0);
                            }
                        }
                    });

                    var started = panel.querySelector('[data-metric="started"]');
                    var completed = panel.querySelector('[data-metric="completed"]');
                    var rate = panel.querySelector('[data-metric="rate"]');

                    if (started) {
                        started.textContent = Math.round(Number(report.started_sessions || 0)).toLocaleString() + ' started';
                    }

                    if (completed) {
                        completed.textContent = Math.round(Number(report.completed_sessions || 0)).toLocaleString() + ' completed';
                    }

                    if (rate) {
                        rate.textContent = formatPercent(report.completion_rate_value || 0);
                    }

                    var areaPath = panel.querySelector('.brein-funnel-flow-card__area');
                    var clipPath = panel.querySelector('.brein-funnel-flow-card__clip-shape');
                    var outlinePath = panel.querySelector('.brein-funnel-flow-card__outline');
                    var nextPath = buildPath(report, panelScaleCeiling);

                    if (areaPath) {
                        areaPath.setAttribute('d', nextPath);
                    }

                    if (clipPath) {
                        clipPath.setAttribute('d', nextPath);
                    }

                    if (outlinePath) {
                        outlinePath.setAttribute('d', nextPath);
                    }
                }

                function animatePanelReport(panel, nextReport) {
                    var currentReport = parseReport(panel);
                    if (!currentReport || !nextReport || !currentReport.steps || !nextReport.steps || currentReport.steps.length !== nextReport.steps.length) {
                        setPanelReport(panel, nextReport);
                        updatePanelDom(panel, nextReport);
                        return;
                    }

                    var startTime = null;
                    var duration = 700;

                    var changedSteps = [];
                    currentReport.steps.forEach(function (step, index) {
                        var nextStep = nextReport.steps[index];
                        if (!nextStep) {
                            return;
                        }

                        if (Number(step.sessions || 0) !== Number(nextStep.sessions || 0)) {
                            changedSteps.push({
                                index: index,
                                type: nextStep.type || 'click_class'
                            });
                        }
                    });

                    function frame(timestamp) {
                        if (!startTime) {
                            startTime = timestamp;
                        }

                        var progress = Math.min(1, (timestamp - startTime) / duration);
                        var eased = 1 - Math.pow(1 - progress, 3);
                        var interpolated = {
                            started_sessions: animateNumber(currentReport.started_sessions || 0, nextReport.started_sessions || 0, eased),
                            completed_sessions: animateNumber(currentReport.completed_sessions || 0, nextReport.completed_sessions || 0, eased),
                            completion_rate_value: animateNumber(currentReport.completion_rate_value || 0, nextReport.completion_rate_value || 0, eased),
                            scale_ceiling: nextReport.scale_ceiling || currentReport.scale_ceiling || 10,
                            steps: []
                        };

                        currentReport.steps.forEach(function (step, index) {
                            var nextStep = nextReport.steps[index];
                            interpolated.steps.push({
                                sessions: animateNumber(step.sessions || 0, nextStep.sessions || 0, eased),
                                dropoff: index === 0 ? null : animateNumber(step.dropoff || 0, nextStep.dropoff || 0, eased),
                                type: nextStep.type || step.type || 'click_class'
                            });
                        });

                        updatePanelDom(panel, interpolated);

                        if (progress < 1) {
                            window.requestAnimationFrame(frame);
                            return;
                        }

                        setPanelReport(panel, nextReport);
                        updatePanelDom(panel, nextReport);
                        changedSteps.forEach(function (stepChange) {
                            triggerLiveIndicator(panel, stepChange.index, stepChange.type);
                        });
                    }

                    window.requestAnimationFrame(frame);
                }

                function refreshSelectedPanel() {
                    if (pollInFlight || !widget || typeof window.fetch !== 'function') {
                        return;
                    }

                    var panel = getVisiblePanel();
                    if (!panel) {
                        return;
                    }

                    var selectedId = select.value;
                    var nonce = widget.getAttribute('data-nonce') || '';
                    if (!selectedId || !nonce || typeof ajaxurl === 'undefined') {
                        return;
                    }

                    var body = new window.URLSearchParams();
                    body.append('action', 'brein_funnel_widget_panel');
                    body.append('nonce', nonce);
                    body.append('funnel_id', selectedId);
                    body.append('_t', String(Date.now()));

                    pollInFlight = true;
                    window.fetch(ajaxurl, {
                        method: 'POST',
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString()
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (response) {
                            if (!response || !response.success || !response.data || !response.data.report) {
                                return;
                            }

                            var nextSignature = response.data.signature ? String(response.data.signature) : '';
                            var currentSignature = panel.getAttribute('data-report-signature') || '';
                            if (nextSignature && currentSignature && nextSignature === currentSignature) {
                                return;
                            }

                            animatePanelReport(panel, response.data.report);
                            if (nextSignature) {
                                panel.setAttribute('data-report-signature', nextSignature);
                            }
                        })
                        .catch(function () {})
                        .finally(function () {
                            pollInFlight = false;
                        });
                }

                try {
                    var savedId = window.localStorage ? window.localStorage.getItem(storageKey) : '';
                    if (savedId) {
                        var savedOption = select.querySelector('option[value="' + savedId + '"]');
                        if (savedOption) {
                            select.value = savedId;
                        }
                    }
                } catch (e) {}

                showSelectedPanel(select.value);
                refreshSelectedPanel();

                select.addEventListener('change', function () {
                    var selectedId = select.value;
                    showSelectedPanel(selectedId);
                    refreshSelectedPanel();

                    try {
                        if (window.localStorage) {
                            window.localStorage.setItem(storageKey, selectedId);
                        }
                    } catch (e) {}
                });

                window.addEventListener('focus', refreshSelectedPanel);
                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'visible') {
                        refreshSelectedPanel();
                    }
                });

                pollTimer = window.setInterval(refreshSelectedPanel, 3000);
            });
        </script>
        <?php
        echo ob_get_clean();
    }

    public function ajax_widget_panel()
    {
        check_ajax_referer('brein_funnel_widget_panel', 'nonce');

        if (!current_user_can($this->required_capability)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'brein-plugin')));
        }

        $funnel_id = isset($_POST['funnel_id']) ? absint(wp_unslash($_POST['funnel_id'])) : 0;
        if ($funnel_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid funnel.', 'brein-plugin')));
        }

        global $wpdb;
        $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            wp_send_json_error(array('message' => __('Tracking events table is not initialized yet.', 'brein-plugin')));
        }

        $funnel = get_post($funnel_id);
        if (!($funnel instanceof WP_Post) || $funnel->post_type !== self::POST_TYPE) {
            wp_send_json_error(array('message' => __('Funnel not found.', 'brein-plugin')));
        }

        $steps = $this->get_funnel_steps($funnel_id);
        if (count($steps) < 2) {
            wp_send_json_error(array('message' => __('Not enough steps.', 'brein-plugin')));
        }

        $report = $this->get_funnel_report($table, $steps);

        wp_send_json_success(
            array(
                'report' => $this->prepare_widget_report_payload($report),
                'signature' => $this->get_report_signature($report),
            )
        );
    }

    private function render_widget_panel($funnel, $steps, $report)
    {
        ob_start();
        ?>
        <div class="brein-funnel-widget__head">
            <strong><?php echo esc_html(get_the_title($funnel->ID)); ?></strong>
            <a href="<?php echo esc_url(get_edit_post_link($funnel->ID, '')); ?>"><?php echo esc_html__('Edit funnel', 'brein-plugin'); ?></a>
        </div>
        <div class="brein-funnel-widget__meta">
            <span><?php echo esc_html(sprintf(__('%d steps', 'brein-plugin'), count($steps))); ?></span>
            <span data-metric="started"><?php echo esc_html(sprintf(__('%d started', 'brein-plugin'), $report['started_sessions'])); ?></span>
            <span data-metric="completed"><?php echo esc_html(sprintf(__('%d completed', 'brein-plugin'), $report['completed_sessions'])); ?></span>
            <span data-metric="rate"><?php echo esc_html($report['completion_rate']); ?></span>
        </div>
        <?php echo $this->render_funnel_flow_graph($report, $funnel->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php

        return ob_get_clean();
    }

    private function get_report_signature($report)
    {
        return md5(wp_json_encode($report));
    }

    private function prepare_widget_report_payload($report)
    {
        $steps = isset($report['steps']) && is_array($report['steps']) ? $report['steps'] : array();
        $payload_steps = array();

        foreach ($steps as $step) {
            $payload_steps[] = array(
                'sessions' => isset($step['sessions']) ? (int) $step['sessions'] : 0,
                'dropoff' => array_key_exists('dropoff', $step) && $step['dropoff'] !== null ? (float) $step['dropoff'] : null,
                'height_ratio' => isset($step['height_ratio']) ? (float) $step['height_ratio'] : 0,
                'type' => isset($step['type']) ? (string) $step['type'] : 'click_class',
            );
        }

        return array(
            'started_sessions' => isset($report['started_sessions']) ? (int) $report['started_sessions'] : 0,
            'completed_sessions' => isset($report['completed_sessions']) ? (int) $report['completed_sessions'] : 0,
            'completion_rate_value' => isset($report['completion_rate_value']) ? (float) $report['completion_rate_value'] : 0,
            'scale_ceiling' => isset($report['scale_ceiling']) ? (int) $report['scale_ceiling'] : 10,
            'steps' => $payload_steps,
        );
    }

    private function render_step_row($step, $index)
    {
        $selected_page_id = $this->get_page_id_from_path(isset($step['value']) ? $step['value'] : '');
        $page_dropdown = wp_dropdown_pages(
            array(
                'name' => 'brein_funnel_steps[' . $index . '][page_id]',
                'id' => 'brein-funnel-page-' . $index,
                'echo' => 0,
                'show_option_none' => __('Select a page', 'brein-plugin'),
                'option_none_value' => '',
                'selected' => $selected_page_id,
            )
        );

        ?>
        <div class="brein-funnel-step">
            <div class="brein-funnel-step__drag" aria-hidden="true">⋮⋮</div>
            <div class="brein-funnel-step__body">
                <div class="brein-funnel-step__header">
                    <strong><?php echo esc_html__('Step', 'brein-plugin'); ?> <span class="brein-funnel-step__number"><?php echo esc_html(is_numeric($index) ? ((int) $index + 1) : 1); ?></span></strong>
                    <button type="button" class="button-link-delete brein-funnel-step__remove"><?php echo esc_html__('Remove', 'brein-plugin'); ?></button>
                </div>

                <div class="brein-funnel-step__grid">
                    <p>
                        <label><strong><?php echo esc_html__('Label', 'brein-plugin'); ?></strong></label><br>
                        <input type="text" name="brein_funnel_steps[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr(isset($step['label']) ? $step['label'] : ''); ?>" class="regular-text">
                    </p>
                    <p>
                        <label><strong><?php echo esc_html__('Step type', 'brein-plugin'); ?></strong></label><br>
                        <select name="brein_funnel_steps[<?php echo esc_attr($index); ?>][type]" class="brein-funnel-step__type">
                            <?php foreach ($this->get_step_type_options() as $type => $label) : ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected(isset($step['type']) ? $step['type'] : 'pageview', $type); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label><strong><?php echo esc_html__('Match value', 'brein-plugin'); ?></strong></label><br>
                        <input type="text" name="brein_funnel_steps[<?php echo esc_attr($index); ?>][value]" value="<?php echo esc_attr(isset($step['value']) ? $step['value'] : ''); ?>" class="regular-text brein-funnel-step__value" style="<?php echo (isset($step['type']) ? $step['type'] : 'pageview') === 'pageview' ? 'display:none;' : ''; ?>">
                        <span class="brein-funnel-step__page-wrap" style="<?php echo (isset($step['type']) ? $step['type'] : 'pageview') === 'pageview' ? '' : 'display:none;'; ?>">
                            <?php echo $page_dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    </p>
                </div>

                <p class="description brein-funnel-step__help"></p>
            </div>
        </div>
        <?php
    }

    private function get_funnels()
    {
        return get_posts(
            array(
                'post_type' => self::POST_TYPE,
                'post_status' => array('publish', 'draft'),
                'posts_per_page' => 20,
                'orderby' => 'date',
                'order' => 'DESC',
            )
        );
    }

    private function get_funnel_steps($post_id)
    {
        $steps = get_post_meta($post_id, self::META_STEPS, true);
        if (!is_array($steps)) {
            return array();
        }

        return $this->sanitize_steps($steps);
    }

    private function sanitize_steps($steps)
    {
        if (!is_array($steps)) {
            return array();
        }

        $clean_steps = array();
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $type = isset($step['type']) ? $this->sanitize_step_type($step['type']) : 'pageview';
            $label = isset($step['label']) ? sanitize_text_field((string) $step['label']) : '';
            $value = isset($step['value']) ? $this->sanitize_step_value($type, $step['value']) : '';

            if ($type !== 'click_any' && $value === '') {
                continue;
            }

            if ($label === '') {
                $label = $this->get_default_step_label(
                    array(
                        'type' => $type,
                        'value' => $value,
                    )
                );
            }

            $clean_steps[] = array(
                'label' => $label,
                'type' => $type,
                'value' => $value,
            );
        }

        return array_values($clean_steps);
    }

    private function sanitize_step_type($value)
    {
        $value = sanitize_key((string) $value);
        if (!isset($this->get_step_type_options()[$value])) {
            return 'pageview';
        }

        return $value;
    }

    private function sanitize_step_value($type, $value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);

        if ($type === 'click_class' || $type === 'click_id') {
            return preg_replace('/[^A-Za-z0-9_-]/', '', ltrim($value, '.#'));
        }

        if ($type === 'pageview') {
            if ($value === '') {
                return '';
            }

            $parsed = wp_parse_url($value, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                $value = $parsed;
            }

            if ($value[0] !== '/') {
                $value = '/' . ltrim($value, '/');
            }

            return sanitize_text_field($value);
        }

        return '';
    }

    private function get_page_id_from_path($path)
    {
        $path = $this->sanitize_step_value('pageview', $path);
        if ($path === '') {
            return 0;
        }

        $normalized = trim($path, '/');
        if ($normalized === '') {
            $front_page_id = (int) get_option('page_on_front');
            return $front_page_id > 0 ? $front_page_id : 0;
        }

        $page = get_page_by_path($normalized, OBJECT, 'page');

        return $page instanceof WP_Post ? (int) $page->ID : 0;
    }

    private function get_tracking_path_from_page_id($page_id)
    {
        $page_id = absint($page_id);
        if ($page_id <= 0) {
            return '';
        }

        $path = wp_parse_url(get_permalink($page_id), PHP_URL_PATH);

        return is_string($path) ? $this->sanitize_step_value('pageview', $path) : '';
    }

    private function get_step_type_options()
    {
        return array(
            'pageview' => __('Page view', 'brein-plugin'),
            'click_any' => __('Any click', 'brein-plugin'),
            'click_class' => __('CSS class click', 'brein-plugin'),
            'click_id' => __('Element ID click', 'brein-plugin'),
        );
    }

    private function get_funnel_report($table, $steps)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT session_id, visitor_id, event_type, path, payload, created_at
             FROM {$table}
             WHERE event_type IN ('pageview', 'click')
             ORDER BY session_id ASC, created_at ASC
             LIMIT 10000"
        );

        $sessions = array();
        foreach ($rows as $row) {
            $session_id = (string) $row->session_id;
            if ($session_id === '') {
                continue;
            }

            if (!isset($sessions[$session_id])) {
                $sessions[$session_id] = array();
            }

            $sessions[$session_id][] = array(
                'visitor_id' => (string) $row->visitor_id,
                'event_type' => (string) $row->event_type,
                'path' => (string) $row->path,
                'payload' => json_decode((string) $row->payload, true),
                'created_at' => (string) $row->created_at,
            );
        }

        $step_sessions = array_fill(0, count($steps), 0);
        $step_visitors = array_fill(0, count($steps), array());

        foreach ($sessions as $events) {
            $step_index = 0;

            foreach ($events as $event) {
                if (!isset($steps[$step_index])) {
                    break;
                }

                if (!$this->event_matches_step($event, $steps[$step_index])) {
                    continue;
                }

                $step_sessions[$step_index]++;
                if (!empty($event['visitor_id'])) {
                    $step_visitors[$step_index][$event['visitor_id']] = true;
                }

                $step_index++;
            }
        }

        $started_sessions = isset($step_sessions[0]) ? (int) $step_sessions[0] : 0;
        $completed_sessions = !empty($step_sessions) ? (int) $step_sessions[count($step_sessions) - 1] : 0;
        $max_sessions = max(1, $started_sessions);
        $report_steps = array();

        foreach ($steps as $index => $step) {
            $sessions_count = isset($step_sessions[$index]) ? (int) $step_sessions[$index] : 0;
            $conversion = $started_sessions > 0 ? round(($sessions_count / $started_sessions) * 100, 1) : 0;
            $previous_sessions = $index > 0 && isset($step_sessions[$index - 1]) ? (int) $step_sessions[$index - 1] : 0;
            $dropoff = null;
            if ($index > 0) {
                $dropoff = $previous_sessions > 0
                    ? round((($sessions_count - $previous_sessions) / $previous_sessions) * 100, 1)
                    : 0;
            }
            $report_steps[] = array(
                'label' => $step['label'],
                'short_label' => $this->shorten_label($step['label']),
                'type' => isset($step['type']) ? $step['type'] : 'click_class',
                'sessions' => $sessions_count,
                'conversion' => $conversion . '%',
                'bar_width' => round(($sessions_count / $max_sessions) * 100, 2),
                'height_ratio' => $max_sessions > 0 ? ($sessions_count / $max_sessions) : 0,
                'dropoff' => $dropoff,
            );
        }

        return array(
            'started_sessions' => $started_sessions,
            'completed_sessions' => $completed_sessions,
            'completion_rate_value' => $started_sessions > 0 ? round(($completed_sessions / $started_sessions) * 100, 1) : 0,
            'completion_rate' => ($started_sessions > 0 ? round(($completed_sessions / $started_sessions) * 100, 1) : 0) . '%',
            'scale_ceiling' => $this->get_graph_scale_ceiling($started_sessions),
            'unique_visitors' => !empty($step_visitors) ? count($step_visitors[count($step_visitors) - 1]) : 0,
            'steps' => $report_steps,
        );
    }

    private function render_funnel_flow_graph($report, $post_id)
    {
        $steps = isset($report['steps']) && is_array($report['steps']) ? $report['steps'] : array();
        if (count($steps) < 2) {
            return '';
        }

        $graph_id = 'brein-funnel-graph-' . absint($post_id);
        $width = 1000;
        $height = 250;
        $center_y = 145;
        $max_half_height = 76;
        $step_count = count($steps);
        $column_width = $width / $step_count;
        $scale_ceiling = isset($report['scale_ceiling']) ? max(10, (int) $report['scale_ceiling']) : 10;

        $points = array();
        foreach ($steps as $index => $step) {
            $sessions = isset($step['sessions']) ? (int) $step['sessions'] : 0;
            $ratio = max(0, min(1, $sessions / $scale_ceiling));
            $half_height = max(10, $ratio * $max_half_height);
            $points[] = array(
                'x' => $index === 0 ? 0 : $column_width * $index,
                'half_height' => $half_height,
            );
        }

        $points[] = array(
            'x' => $width,
            'half_height' => isset($points[$step_count - 1]['half_height']) ? $points[$step_count - 1]['half_height'] : 10,
        );

        $path = $this->build_funnel_area_path($points, $center_y);

        ob_start();
        ?>
        <div class="brein-funnel-flow-card">
            <div class="brein-funnel-flow-card__canvas">
                <svg viewBox="0 0 <?php echo esc_attr($width); ?> <?php echo esc_attr($height); ?>" role="img" aria-label="<?php echo esc_attr__('Funnel flow graph', 'brein-plugin'); ?>" preserveAspectRatio="none">
                    <defs>
                        <clipPath id="<?php echo esc_attr($graph_id); ?>-clip">
                            <path class="brein-funnel-flow-card__clip-shape" d="<?php echo esc_attr($path); ?>"></path>
                        </clipPath>
                    </defs>

                    <rect width="<?php echo esc_attr($width); ?>" height="<?php echo esc_attr($height); ?>" fill="#f7f8fa"></rect>

                    <?php for ($i = 1; $i < $step_count; $i++) : ?>
                        <line
                            x1="<?php echo esc_attr(round($column_width * $i, 2)); ?>"
                            y1="0"
                            x2="<?php echo esc_attr(round($column_width * $i, 2)); ?>"
                            y2="<?php echo esc_attr($height); ?>"
                            stroke="rgba(15,46,51,0.08)"
                            stroke-width="1"
                        ></line>
                    <?php endfor; ?>

                    <path class="brein-funnel-flow-card__area" d="<?php echo esc_attr($path); ?>" fill="#DBEAFE" opacity="0.98"></path>

                    <g clip-path="url(#<?php echo esc_attr($graph_id); ?>-clip)">
                        <?php foreach ($steps as $index => $step) : ?>
                            <rect
                                x="<?php echo esc_attr(round($column_width * $index, 2)); ?>"
                                y="0"
                                width="<?php echo esc_attr(round($column_width, 2)); ?>"
                                height="<?php echo esc_attr($height); ?>"
                                fill="<?php echo esc_attr($this->hex_to_rgba($this->get_funnel_step_color($index, $step_count), 0.16)); ?>"
                            ></rect>
                        <?php endforeach; ?>
                    </g>

                    <path class="brein-funnel-flow-card__outline" d="<?php echo esc_attr($path); ?>" fill="none" stroke="rgba(126,182,235,0.18)" stroke-width="3"></path>
                </svg>

                <div class="brein-funnel-flow-card__header" style="grid-template-columns:repeat(<?php echo esc_attr($step_count); ?>, minmax(0,1fr));">
                    <?php foreach ($steps as $index => $step) : ?>
                        <?php
                        $indicator_sessions = isset($step['sessions']) ? (int) $step['sessions'] : 0;
                        $indicator_ratio = max(0, min(1, $indicator_sessions / $scale_ceiling));
                        $indicator_half_height = max(10, $indicator_ratio * $max_half_height);
                        $indicator_top = max(40, $center_y - $indicator_half_height - 18);
                        ?>
                        <div class="brein-funnel-flow-card__header-step" data-step-index="<?php echo esc_attr($index); ?>">
                            <span class="brein-funnel-flow-card__header-label"><?php echo esc_html($step['short_label']); ?></span>
                            <strong data-step-count><?php echo esc_html(number_format_i18n($step['sessions'])); ?></strong>
                            <span class="brein-funnel-flow-card__live-indicator" data-step-live aria-hidden="true" style="top:<?php echo esc_attr(round($indicator_top, 2)); ?>px;"></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($steps as $index => $step) : ?>
                    <?php if ($index === 0 || !isset($step['dropoff'])) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <div
                        class="brein-funnel-flow-card__dropoff"
                        data-step-index="<?php echo esc_attr($index); ?>"
                        data-step-dropoff
                        style="left:<?php echo esc_attr(round(($column_width * $index) / $width * 100, 2)); ?>%;top:<?php echo esc_attr(round(($center_y / $height) * 100, 2)); ?>%;"
                    >
                        <span class="brein-funnel-flow-card__dropoff-value"><?php echo esc_html($this->format_percentage_label($step['dropoff'])); ?></span> <span>&rarr;</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    private function build_funnel_area_path($points, $center_y)
    {
        if (count($points) < 2) {
            return '';
        }

        $path = '';
        $first = $points[0];
        $path .= 'M ' . round($first['x'], 2) . ' ' . round($center_y - $first['half_height'], 2);

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $previous = $points[$i - 1];
            $current = $points[$i];
            $mid_x = round(($previous['x'] + $current['x']) / 2, 2);
            $path .= ' C ' . $mid_x . ' ' . round($center_y - $previous['half_height'], 2) . ', ';
            $path .= $mid_x . ' ' . round($center_y - $current['half_height'], 2) . ', ';
            $path .= round($current['x'], 2) . ' ' . round($center_y - $current['half_height'], 2);
        }

        $last = $points[count($points) - 1];
        $path .= ' L ' . round($last['x'], 2) . ' ' . round($center_y + $last['half_height'], 2);

        for ($i = count($points) - 2; $i >= 0; $i--) {
            $current = $points[$i];
            $next = $points[$i + 1];
            $mid_x = round(($current['x'] + $next['x']) / 2, 2);
            $path .= ' C ' . $mid_x . ' ' . round($center_y + $next['half_height'], 2) . ', ';
            $path .= $mid_x . ' ' . round($center_y + $current['half_height'], 2) . ', ';
            $path .= round($current['x'], 2) . ' ' . round($center_y + $current['half_height'], 2);
        }

        $path .= ' Z';

        return $path;
    }

    private function format_percentage_label($value)
    {
        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format_i18n((float) $value, 1) . '%';
    }

    private function get_funnel_step_color($index, $step_count)
    {
        $step_count = max(1, (int) $step_count);
        $index = max(0, min((int) $index, $step_count - 1));
        $ratio = $step_count > 1 ? $index / ($step_count - 1) : 1;

        return $this->interpolate_hex_color('#EFF6FF', '#3B82F6', $ratio);
    }

    private function interpolate_hex_color($start_hex, $end_hex, $ratio)
    {
        $ratio = max(0, min(1, (float) $ratio));
        $start = $this->hex_to_rgb($start_hex);
        $end = $this->hex_to_rgb($end_hex);

        $red = (int) round($start[0] + (($end[0] - $start[0]) * $ratio));
        $green = (int) round($start[1] + (($end[1] - $start[1]) * $ratio));
        $blue = (int) round($start[2] + (($end[2] - $start[2]) * $ratio));

        return sprintf('#%02X%02X%02X', $red, $green, $blue);
    }

    private function hex_to_rgb($hex)
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            return array(239, 246, 255);
        }

        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    private function hex_to_rgba($hex, $alpha)
    {
        $rgb = $this->hex_to_rgb($hex);
        $alpha = max(0, min(1, (float) $alpha));

        return sprintf('rgba(%d, %d, %d, %.3F)', $rgb[0], $rgb[1], $rgb[2], $alpha);
    }

    private function get_graph_scale_ceiling($started_sessions)
    {
        $started_sessions = max(0, (int) $started_sessions);

        if ($started_sessions <= 10) {
            return 10;
        }

        if ($started_sessions <= 25) {
            return 25;
        }

        if ($started_sessions <= 50) {
            return 50;
        }

        if ($started_sessions <= 100) {
            return 100;
        }

        return (int) (ceil($started_sessions / 50) * 50);
    }

    private function event_matches_step($event, $step)
    {
        $type = isset($step['type']) ? $step['type'] : 'pageview';
        $value = isset($step['value']) ? $step['value'] : '';
        $event_type = isset($event['event_type']) ? $event['event_type'] : '';
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : array();

        if ($type === 'pageview') {
            return $event_type === 'pageview' && isset($event['path']) && $event['path'] === $value;
        }

        if ($event_type !== 'click') {
            return false;
        }

        if ($type === 'click_any') {
            return true;
        }

        if ($type === 'click_id') {
            $element_id = isset($payload['element_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payload['element_id']) : '';
            return $element_id === $value;
        }

        $class_names = isset($payload['class_names']) && is_array($payload['class_names']) ? $payload['class_names'] : array();
        return in_array($value, $class_names, true);
    }

    private function get_default_step_label($step)
    {
        if ($step['type'] === 'click_any') {
            return __('Any click', 'brein-plugin');
        }

        if ($step['type'] === 'click_class') {
            return '.' . $step['value'];
        }

        if ($step['type'] === 'click_id') {
            return '#' . $step['value'];
        }

        return $step['value'];
    }

    private function shorten_label($label)
    {
        $label = sanitize_text_field((string) $label);
        if (strlen($label) <= 24) {
            return $label;
        }

        return substr($label, 0, 21) . '...';
    }

    private function get_post_type_capabilities()
    {
        $cap = $this->required_capability;

        return array(
            'edit_post' => $cap,
            'read_post' => $cap,
            'delete_post' => $cap,
            'edit_posts' => $cap,
            'edit_others_posts' => $cap,
            'publish_posts' => $cap,
            'read_private_posts' => $cap,
            'delete_posts' => $cap,
            'delete_private_posts' => $cap,
            'delete_published_posts' => $cap,
            'delete_others_posts' => $cap,
            'edit_private_posts' => $cap,
            'edit_published_posts' => $cap,
            'create_posts' => $cap,
        );
    }
}
