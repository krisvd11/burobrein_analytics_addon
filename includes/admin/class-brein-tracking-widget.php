<?php
/**
 * Tracking module widget and post type.
 *
 * @package BreinPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Tracking_Widget
{
    const POST_TYPE = 'brein_tracking_item';
    const META_TYPE = '_brein_tracking_type';
    const META_VALUE = '_brein_tracking_value';
    const LEGACY_OPTION_KEY = 'brein_tracking_module_classes';

    private $required_capability;
    private $plugin_file;

    public function __construct($required_capability = 'manage_options')
    {
        $this->required_capability = $required_capability;
        $this->plugin_file = BREIN_ANALYTICS_PLUGIN_FILE;

        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'remove_default_post_type_supports'), 20);
        add_action('admin_init', array($this, 'maybe_migrate_legacy_tracking_classes'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'), 10, 2);
        add_action('admin_head-post.php', array($this, 'hide_title_field'));
        add_action('admin_head-post-new.php', array($this, 'hide_title_field'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_tracking_item'));
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'filter_posts_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'render_posts_column'), 10, 2);
        add_filter('post_row_actions', array($this, 'filter_row_actions'), 10, 2);
    }

    public function register_post_type()
    {
        $labels = array(
            'name' => __('Tracking Modules', 'brein-plugin'),
            'singular_name' => __('Tracking Module', 'brein-plugin'),
            'menu_name' => __('Tracking Modules', 'brein-plugin'),
            'name_admin_bar' => __('Tracking Module', 'brein-plugin'),
            'add_new' => __('Add New', 'brein-plugin'),
            'add_new_item' => __('Add New Tracking Module', 'brein-plugin'),
            'edit_item' => __('Edit Tracking Module', 'brein-plugin'),
            'new_item' => __('New Tracking Module', 'brein-plugin'),
            'view_item' => __('View Tracking Module', 'brein-plugin'),
            'search_items' => __('Search Tracking Modules', 'brein-plugin'),
            'not_found' => __('No tracking modules found.', 'brein-plugin'),
            'not_found_in_trash' => __('No tracking modules found in Trash.', 'brein-plugin'),
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
                'menu_position' => 30,
                'menu_icon' => 'dashicons-chart-line',
                'supports' => array(),
                'capabilities' => $this->get_post_type_capabilities(),
                'map_meta_cap' => false,
            )
        );
    }

    public function remove_default_post_type_supports()
    {
        remove_post_type_support(self::POST_TYPE, 'title');
        remove_post_type_support(self::POST_TYPE, 'editor');
        remove_post_type_support(self::POST_TYPE, 'excerpt');
        remove_post_type_support(self::POST_TYPE, 'thumbnail');
        remove_post_type_support(self::POST_TYPE, 'custom-fields');
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

        wp_enqueue_script(
            'brein-weekly-visitors',
            plugins_url('assets/js/brein-weekly-visitors.js', $this->plugin_file),
            array(),
            '1.0.1',
            true
        );

        if ($hook === 'edit.php') {
            wp_add_inline_style(
                'brein-widgets',
                '.column-brein_tracking_graph{width:180px}.brein-tracking-table-graph{width:160px}.brein-tracking-table-graph .brein-weekly-chart{height:44px}.brein-tracking-table-graph .brein-weekly-hoverline,.brein-tracking-table-graph .brein-weekly-dot,.brein-tracking-table-graph .brein-weekly-tooltip,.brein-tracking-table-graph .brein-weekly-labels,.brein-tracking-table-graph .brein-weekly-title,.brein-tracking-table-graph .brein-weekly-number,.brein-tracking-table-graph .brein-weekly-sub{display:none}.brein-tracking-table-graph .brein-weekly-card{padding:0;background:transparent}'
            );
        }
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

        $items = $this->get_tracking_items();
        $suggestions = $this->get_class_suggestions($table);
        $item_rows = array();
        $max_clicks = 0;

        foreach ($items as $item) {
            $tracking = $this->get_tracking_config($item->ID);
            $report = $tracking['value'] !== '' ? $this->get_tracking_report($table, $tracking) : $this->empty_report();
            $clicks = (int) $report['total_clicks'];
            $dedupe_key = $tracking['type'] . ':' . $tracking['value'];

            if ($tracking['value'] !== '' && isset($item_rows[$dedupe_key])) {
                if ($clicks > $item_rows[$dedupe_key]['report']['total_clicks']) {
                    $item_rows[$dedupe_key] = array(
                        'item' => $item,
                        'tracking' => $tracking,
                        'report' => $report,
                    );
                }
                continue;
            }

            $max_clicks = max($max_clicks, $clicks);
            $item_rows[$tracking['value'] !== '' ? $dedupe_key : 'post_' . $item->ID] = array(
                'item' => $item,
                'tracking' => $tracking,
                'report' => $report,
            );
        }

        usort($item_rows, array($this, 'sort_item_rows_by_clicks'));

        foreach ($item_rows as $row) {
            $max_clicks = max($max_clicks, (int) $row['report']['total_clicks']);
        }

        $max_clicks = max(1, $max_clicks);
        ob_start();
        ?>
        <div class="brein-tracking-module">
            <?php if (empty($items)) : ?>
                <?php $this->render_empty_state($suggestions); ?>
            <?php else : ?>
                <div class="brein-tracking-results">
                    <div class="brein-tracking-block">
                        <h3><?php echo esc_html__('Tracking modules', 'brein-plugin'); ?></h3>
                        <div class="brein-weekly-sources brein-tracking-sources">
                            <?php foreach ($item_rows as $row) : ?>
                                <?php
                                $item = $row['item'];
                                $tracking = $row['tracking'];
                                $report = $row['report'];
                                $clicks = (int) $report['total_clicks'];
                                $width = round(($clicks / $max_clicks) * 100, 2);
                                $label = $this->format_tracking_label($tracking);
                                ?>
                                <a class="brein-tracking-source-link" href="<?php echo esc_url(get_edit_post_link($item->ID, '')); ?>">
                                    <div class="brein-weekly-source brein-tracking-source">
                                    
                                    <div class="brein-weekly-source-name brein-tracking-source-name">
                                    <img src="<?php echo get_site_icon_url(); ?>" alt="Site Icon" class="site_icon_tracking">

                                    <?php

                                    if ($tracking['type'] === 'page') {
                                        echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-book-open-icon lucide-book-open"><path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg>';
                                    }
                                 elseif ($tracking['type'] === 'class') {
                                        echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-dot-icon lucide-dot"><circle cx="12.1" cy="12.1" r="1"/></svg>';
                                    } else {
                                        echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-hash-icon lucide-hash"><line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/></svg>';
                                    }

?>

                                            <span><?php echo esc_html($label); ?></span>
                                        </div>
                                        <div class="brein-weekly-right">
                                            <div class="brein-weekly-source-bar">
                                                <div class="brein-weekly-source-fill" style="width:<?php echo esc_attr($width); ?>%;"></div>
                                            </div>
                                            <div class="brein-number"><?php echo esc_html(number_format_i18n($clicks)); ?></div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="brein-tracking-form__row">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)); ?>"><?php echo esc_html__('Add Tracking Module', 'brein-plugin'); ?></a>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }

    public function register_meta_boxes($post_type, $post)
    {
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        $tracking = $post instanceof WP_Post ? $this->get_tracking_config($post->ID) : array(
            'type' => 'class',
            'value' => '',
        );

        if ($tracking['value'] === '') {
            add_meta_box(
                'brein-tracking-settings',
                __('Tracking Settings', 'brein-plugin'),
                array($this, 'render_settings_meta_box'),
                self::POST_TYPE,
                'normal',
                'high'
            );
        }

        add_meta_box(
            'brein-tracking-results',
            __('Tracking Results', 'brein-plugin'),
            array($this, 'render_results_meta_box'),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_settings_meta_box($post)
    {
        $tracking = $this->get_tracking_config($post->ID);
        wp_nonce_field('brein_save_tracking_item', 'brein_tracking_item_nonce');

        $selected_page_id = $tracking['type'] === 'page' ? $this->get_page_id_from_path($tracking['value']) : 0;
        $page_dropdown = wp_dropdown_pages(
            array(
                'name' => 'brein_tracking_page',
                'id' => 'brein-tracking-page',
                'echo' => 0,
                'show_option_none' => __('Select a page', 'brein-plugin'),
                'option_none_value' => '',
                'selected' => $selected_page_id,
            )
        );

        ob_start();
        ?>
        <p>
            <label for="brein-tracking-type"><strong><?php echo esc_html__('Tracking type', 'brein-plugin'); ?></strong></label>
        </p>
        <p>
            <select id="brein-tracking-type" name="brein_tracking_type">
                <?php foreach ($this->get_tracking_type_options() as $type => $label) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($tracking['type'], $type); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="brein-tracking-value"><strong><?php echo esc_html__('Tracking value', 'brein-plugin'); ?></strong></label>
        </p>
        <input
            type="text"
            id="brein-tracking-value"
            name="brein_tracking_value"
            class="regular-text"
            placeholder="<?php echo esc_attr($this->get_tracking_placeholder($tracking['type'])); ?>"
            value="<?php echo esc_attr($tracking['type'] === 'page' ? '' : $this->format_tracking_value_for_input($tracking)); ?>"
            data-placeholder-class="<?php echo esc_attr($this->get_tracking_placeholder('class')); ?>"
            data-placeholder-id="<?php echo esc_attr($this->get_tracking_placeholder('id')); ?>"
            style="<?php echo $tracking['type'] === 'page' ? 'display:none;' : ''; ?>"
        >
        <div id="brein-tracking-page-wrap" style="<?php echo $tracking['type'] === 'page' ? '' : 'display:none;'; ?>">
            <?php echo $page_dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <p class="description" id="brein-tracking-value-help"><?php echo esc_html($this->get_tracking_help_text($tracking['type'])); ?></p>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var typeField = document.getElementById('brein-tracking-type');
                var valueField = document.getElementById('brein-tracking-value');
                var pageWrap = document.getElementById('brein-tracking-page-wrap');
                var helpField = document.getElementById('brein-tracking-value-help');

                if (!typeField || !valueField || !pageWrap || !helpField) {
                    return;
                }

                var helpTexts = {
                    "class": <?php echo wp_json_encode($this->get_tracking_help_text('class')); ?>,
                    "id": <?php echo wp_json_encode($this->get_tracking_help_text('id')); ?>,
                    "page": <?php echo wp_json_encode($this->get_tracking_help_text('page')); ?>
                };

                function updateTrackingCopy() {
                    var selectedType = typeField.value || 'class';

                    if (selectedType === 'page') {
                        valueField.style.display = 'none';
                        pageWrap.style.display = '';
                    } else {
                        valueField.style.display = '';
                        pageWrap.style.display = 'none';
                        var placeholder = valueField.getAttribute('data-placeholder-' + selectedType);
                        if (placeholder) {
                            valueField.setAttribute('placeholder', placeholder);
                        }
                    }

                    if (helpTexts[selectedType]) {
                        helpField.textContent = helpTexts[selectedType];
                    }
                }

                typeField.addEventListener('change', updateTrackingCopy);
                updateTrackingCopy();
            });
        </script>
        <?php
        echo ob_get_clean();
    }

    public function render_results_meta_box($post)
    {
        global $wpdb;

        $tracking = $this->get_tracking_config($post->ID);
        if ($tracking['value'] === '') {
            echo '<p>' . esc_html__('Save a tracking type and value above to start tracking and see results here.', 'brein-plugin') . '</p>';
            return;
        }

        $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            echo '<p>' . esc_html__('Tracking events table is not initialized yet.', 'brein-plugin') . '</p>';
            return;
        }

        $report = $this->get_tracking_report($table, $tracking);

        ob_start();
        ?>
        <style>
            .brein-tracking-metabox-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
            .brein-tracking-metabox-stat { padding: 20px 0px; }
            .brein-tracking-metabox-stat span { display: block; font-size: 12px; color: #4f5b62; margin-bottom: 6px; }
            .brein-tracking-metabox-stat strong { font-size: 20px; color: #0f2e33; }
            .brein-tracking-metabox-grid { display: grid; gap: 16px; }
            .brein-tracking-metabox-list { margin: 0; }
            .brein-tracking-metabox-list li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
            .brein-tracking-metabox-list li:last-child { border-bottom: 0; }
            .brein-tracking-click-card { width: 100%; max-width: none; }
            .brein-tracking-click-card .brein-weekly-chart { height: 280px; }
            .brein-tracking-click-card .analytics-badge-bg { background: #a8efcf; }
            .brein-tracking-click-card .brein-weekly-dot { border-color: #56e39f; }
            .brein-tracking-click-card .brein-weekly-tooltip { border-color: #d8f8e8; }
            .brein-tracking-click-card .brein-weekly-tooltip-swatch { background: #56e39f; }
        </style>

        <div class="brein-tracking-metabox-summary">
            <div class="brein-tracking-metabox-stat"><span><?php echo esc_html__('Tracking target', 'brein-plugin'); ?></span><strong><?php echo esc_html($this->format_tracking_label($tracking)); ?></strong></div>
            <div class="brein-tracking-metabox-stat"><span><?php echo esc_html($tracking['type'] === 'page' ? __('Total visits', 'brein-plugin') : __('Total clicks', 'brein-plugin')); ?></span><strong><?php echo esc_html(number_format_i18n($report['total_clicks'])); ?></strong></div>
            <div class="brein-tracking-metabox-stat"><span><?php echo esc_html__('Unique visitors', 'brein-plugin'); ?></span><strong><?php echo esc_html(number_format_i18n($report['unique_visitors'])); ?></strong></div>
            <div class="brein-tracking-metabox-stat"><span><?php echo esc_html($tracking['type'] === 'page' ? __('Last visit', 'brein-plugin') : __('Last click', 'brein-plugin')); ?></span><strong><?php echo esc_html($report['last_click']); ?></strong></div>
        </div>

        <div class="brein-tracking-metabox-grid">
            <?php if (!empty($report['top_pages'])) : ?>
                <div>
                    <h4><?php echo esc_html__('Top pages', 'brein-plugin'); ?></h4>
                    <ul class="brein-tracking-metabox-list">
                        <?php foreach ($report['top_pages'] as $page) : ?>
                            <li><span><?php echo esc_html($page['label']); ?></span><strong><?php echo esc_html(number_format_i18n($page['count'])); ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($report['top_targets'])) : ?>
                <div>
                    <h4><?php echo esc_html($tracking['type'] === 'page' ? __('Top sources', 'brein-plugin') : __('Top clicked items', 'brein-plugin')); ?></h4>
                    <ul class="brein-tracking-metabox-list">
                        <?php foreach ($report['top_targets'] as $target) : ?>
                            <li><span><?php echo esc_html($target['label']); ?></span><strong><?php echo esc_html(number_format_i18n($target['count'])); ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div>
                <h4><?php echo esc_html($tracking['type'] === 'page' ? __('Recent visits', 'brein-plugin') : __('Recent clicks', 'brein-plugin')); ?></h4>
                <?php echo $this->render_click_chart_card($report['daily_clicks'], $report['daily_days'], $tracking['type'] === 'page' ? __('Visits', 'brein-plugin') : __('Clicks', 'brein-plugin')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }

    public function save_tracking_item($post_id)
    {
        if (!isset($_POST['brein_tracking_item_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['brein_tracking_item_nonce'])), 'brein_save_tracking_item')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can($this->required_capability)) {
            return;
        }

        $tracking_type = isset($_POST['brein_tracking_type']) ? $this->sanitize_tracking_type(wp_unslash($_POST['brein_tracking_type'])) : 'class';
        if ($tracking_type === 'page') {
            $tracking_value = $this->get_tracking_path_from_page_id(isset($_POST['brein_tracking_page']) ? wp_unslash($_POST['brein_tracking_page']) : '');
        } else {
            $tracking_value = isset($_POST['brein_tracking_value']) ? $this->sanitize_tracking_value_by_type($tracking_type, wp_unslash($_POST['brein_tracking_value'])) : '';
        }

        update_post_meta($post_id, self::META_TYPE, $tracking_type);
        if ($tracking_value === '') {
            delete_post_meta($post_id, self::META_VALUE);
        } else {
            update_post_meta($post_id, self::META_VALUE, $tracking_value);
        }

        $desired_title = $tracking_value !== '' ? $this->format_tracking_label(array('type' => $tracking_type, 'value' => $tracking_value)) : __('Tracking Module', 'brein-plugin');
        if (get_the_title($post_id) !== $desired_title) {
            remove_action('save_post_' . self::POST_TYPE, array($this, 'save_tracking_item'));
            wp_update_post(
                array(
                    'ID' => $post_id,
                    'post_title' => $desired_title,
                    'post_name' => sanitize_title($desired_title),
                )
            );
            add_action('save_post_' . self::POST_TYPE, array($this, 'save_tracking_item'));
        }
    }

    public function filter_posts_columns($columns)
    {
        $columns = array(
            'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
            'title' => __('Title', 'brein-plugin'),
            'brein_tracking_clicks' => __('Activity', 'brein-plugin'),
            'brein_tracking_graph' => __('Graph', 'brein-plugin'),
        );

        return $columns;
    }

    public function render_posts_column($column, $post_id)
    {
        if ($column === 'brein_tracking_class') {
            $tracking = $this->get_tracking_config($post_id);
            echo esc_html($tracking['value'] !== '' ? $this->format_tracking_label($tracking) : __('No target set', 'brein-plugin'));
            return;
        }

        if ($column === 'brein_tracking_clicks') {
            global $wpdb;

            $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
                echo '0';
                return;
            }

            $tracking = $this->get_tracking_config($post_id);
            $report = $tracking['value'] !== '' ? $this->get_tracking_report($table, $tracking) : $this->empty_report();
            echo esc_html(number_format_i18n($report['total_clicks']));
            return;
        }

        if ($column === 'brein_tracking_graph') {
            global $wpdb;

            $table = $wpdb->prefix . Brein_Visitor_Tracker::EVENTS_TABLE;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
                echo '-';
                return;
            }

            $tracking = $this->get_tracking_config($post_id);
            $report = $tracking['value'] !== '' ? $this->get_tracking_report($table, $tracking) : $this->empty_report();

            echo $this->render_table_graph($report['daily_clicks'], $report['daily_days']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

    public function maybe_migrate_legacy_tracking_classes()
    {
        $legacy = get_option(self::LEGACY_OPTION_KEY, null);
        if (!is_array($legacy) || empty($legacy)) {
            return;
        }

        foreach ($legacy as $class_name) {
            $class_name = $this->sanitize_tracking_class($class_name);
            if ($class_name === '' || $this->tracking_item_exists('class', $class_name)) {
                continue;
            }

            $post_id = wp_insert_post(
                array(
                    'post_type' => self::POST_TYPE,
                    'post_status' => 'publish',
                    'post_title' => '.' . $class_name,
                ),
                true
            );

            if (!is_wp_error($post_id) && $post_id) {
                update_post_meta($post_id, self::META_TYPE, 'class');
                update_post_meta($post_id, self::META_VALUE, $class_name);
            }
        }

        delete_option(self::LEGACY_OPTION_KEY);
    }

    public function hide_title_field()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        echo '<style>#titlediv,#postdivrich,#postexcerpt,.editor-post-post-content,.block-editor-writing-flow{display:none !important;}</style>';
    }

    private function render_empty_state($suggestions)
    {
        echo '<div class="brein-tracking-empty">';
        echo '<p>' . esc_html__('Create a tracking module to track page visits, CSS classes, or IDs and open its own edit screen for results.', 'brein-plugin') . '</p>';

        if (empty($suggestions)) {
            echo '<p class="description">' . esc_html__('No tracked button or link clicks have been recorded yet.', 'brein-plugin') . '</p>';
            echo '</div>';
            return;
        }

        echo '<p class="description">' . esc_html__('Popular tracked classes seen so far:', 'brein-plugin') . '</p>';
        echo '<ul class="brein-tracking-list">';
        foreach ($suggestions as $class_name => $count) {
            echo '<li><span>.' . esc_html($class_name) . '</span><strong>' . esc_html(number_format_i18n($count)) . '</strong></li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    private function get_tracking_items()
    {
        return get_posts(
            array(
                'post_type' => self::POST_TYPE,
                'post_status' => array('publish', 'draft'),
                'posts_per_page' => 50,
                'orderby' => 'date',
                'order' => 'DESC',
            )
        );
    }

    private function get_tracking_config($post_id)
    {
        $type = get_post_meta($post_id, self::META_TYPE, true);
        $value = get_post_meta($post_id, self::META_VALUE, true);

        if ($type === '' && $value === '') {
            $legacy_class = get_post_meta($post_id, '_brein_tracking_class', true);
            if ($legacy_class !== '') {
                $type = 'class';
                $value = $legacy_class;
            }
        }

        $type = $this->sanitize_tracking_type($type);
        $value = $this->sanitize_tracking_value_by_type($type, $value);

        return array(
            'type' => $type,
            'value' => $value,
        );
    }

    private function sanitize_tracking_class($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = ltrim(trim($value), '.#');

        return preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }

    private function sanitize_tracking_id($value)
    {
        return $this->sanitize_tracking_class($value);
    }

    private function sanitize_tracking_path($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);
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

    private function sanitize_tracking_type($value)
    {
        $value = sanitize_key((string) $value);
        if (!in_array($value, array('class', 'id', 'page'), true)) {
            return 'class';
        }

        return $value;
    }

    private function sanitize_tracking_value_by_type($type, $value)
    {
        if ($type === 'id') {
            return $this->sanitize_tracking_id($value);
        }

        if ($type === 'page') {
            return $this->sanitize_tracking_path($value);
        }

        return $this->sanitize_tracking_class($value);
    }

    private function tracking_item_exists($type, $value)
    {
        $posts = get_posts(
            array(
                'post_type' => self::POST_TYPE,
                'post_status' => array('publish', 'draft'),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_TYPE,
                        'value' => $type,
                    ),
                    array(
                        'key' => self::META_VALUE,
                        'value' => $value,
                    ),
                ),
            )
        );

        return !empty($posts);
    }

    private function get_tracking_type_options()
    {
        return array(
            'class' => __('CSS class', 'brein-plugin'),
            'id' => __('Element ID', 'brein-plugin'),
            'page' => __('Page visits', 'brein-plugin'),
        );
    }

    private function format_tracking_value_for_input($tracking)
    {
        if ($tracking['value'] === '') {
            return '';
        }

        if ($tracking['type'] === 'page') {
            return $tracking['value'];
        }

        if ($tracking['type'] === 'class') {
            return '.' . $tracking['value'];
        }

        return $tracking['value'];
    }

    private function get_page_id_from_path($path)
    {
        $path = $this->sanitize_tracking_path($path);
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

        return is_string($path) ? $this->sanitize_tracking_path($path) : '';
    }

    private function get_tracking_placeholder($type)
    {
        if ($type === 'id') {
            return 'hero-button';
        }

        if ($type === 'page') {
            return '/contact/';
        }

        return '.cta-button';
    }

    private function get_tracking_help_text($type)
    {
        if ($type === 'id') {
            return __('Enter the element ID you want to track, like the ID on a button or link.', 'brein-plugin');
        }

        if ($type === 'page') {
            return __('Enter the page path you want to track visits for.', 'brein-plugin');
            
        }

        return __('Enter the CSS class you want to track on buttons or links.', 'brein-plugin');
    }

    private function format_tracking_label($tracking)
    {
        if (!is_array($tracking) || empty($tracking['value'])) {
            return __('Tracking Module', 'brein-plugin');
        }

        if ($tracking['type'] === 'page') {
            return (string) $tracking['value'];
        }

        if ($tracking['type'] === 'id') {
            return '#' . (string) $tracking['value'];
        }

        return '.' . (string) $tracking['value'];
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

    private function get_class_suggestions($table)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payload
                 FROM {$table}
                 WHERE event_type = %s
                 ORDER BY created_at DESC
                 LIMIT 300",
                'click'
            )
        );

        $counts = array();
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (empty($payload['class_names']) || !is_array($payload['class_names'])) {
                continue;
            }

            foreach ($payload['class_names'] as $class_name) {
                $class_name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class_name);
                if ($class_name === '') {
                    continue;
                }

                if (!isset($counts[$class_name])) {
                    $counts[$class_name] = 0;
                }
                $counts[$class_name]++;
            }
        }

        arsort($counts);

        return array_slice($counts, 0, 10, true);
    }

    private function get_tracking_report($table, $tracking)
    {
        global $wpdb;

        $type = isset($tracking['type']) ? $this->sanitize_tracking_type($tracking['type']) : 'class';
        $value = isset($tracking['value']) ? $this->sanitize_tracking_value_by_type($type, $tracking['value']) : '';
        if ($value === '') {
            return $this->empty_report();
        }

        if ($type === 'page') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT visitor_id, path, page_title, element_label, element_href, referrer, payload, created_at
                     FROM {$table}
                     WHERE event_type = %s
                       AND path = %s
                     ORDER BY created_at DESC
                     LIMIT 500",
                    'pageview',
                    $value
                )
            );
        } elseif ($type === 'id') {
            $like_value = '%' . $wpdb->esc_like('"element_id":"' . $value . '"') . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT visitor_id, path, page_title, element_label, element_href, referrer, payload, created_at
                     FROM {$table}
                     WHERE event_type = %s
                       AND payload LIKE %s
                     ORDER BY created_at DESC
                     LIMIT 500",
                    'click',
                    $like_value
                )
            );
        } else {
            $like_value = '%' . $wpdb->esc_like('"' . $value . '"') . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT visitor_id, path, page_title, element_label, element_href, referrer, payload, created_at
                     FROM {$table}
                     WHERE event_type = %s
                       AND payload LIKE %s
                     ORDER BY created_at DESC
                     LIMIT 500",
                    'click',
                    $like_value
                )
            );
        }

        $total_clicks = 0;
        $visitors = array();
        $pages = array();
        $targets = array();
        $recent_clicks = array();
        $never_label = __('Never', 'brein-plugin');
        $last_click = $never_label;
        $daily_days = array();
        for ($i = 6; $i >= 0; $i--) {
            $daily_days[] = wp_date('Y-m-d', strtotime(current_time('mysql') . " -{$i} days"));
        }
        $daily_clicks = array_fill_keys($daily_days, 0);

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if ($type === 'class') {
                $class_names = isset($payload['class_names']) && is_array($payload['class_names']) ? $payload['class_names'] : array();
                if (!in_array($value, $class_names, true)) {
                    continue;
                }
            } elseif ($type === 'id') {
                $element_id = isset($payload['element_id']) ? $this->sanitize_tracking_id($payload['element_id']) : '';
                if ($element_id !== $value) {
                    continue;
                }
            }

            $total_clicks++;
            if (!empty($row->visitor_id)) {
                $visitors[$row->visitor_id] = true;
            }

            $created_day = substr((string) $row->created_at, 0, 10);
            if (isset($daily_clicks[$created_day])) {
                $daily_clicks[$created_day]++;
            }

            $page_label = $row->page_title ? $row->page_title : $row->path;
            if ($page_label === '') {
                $page_label = '/';
            }
            if (!isset($pages[$page_label])) {
                $pages[$page_label] = 0;
            }
            $pages[$page_label]++;

            if ($type === 'page') {
                $target_label = $this->format_referer_label($row->referrer);
            } else {
                $target_label = $row->element_label ? $row->element_label : $this->shorten_url($row->element_href);
            }
            if ($target_label === '') {
                $target_label = $type === 'page' ? __('Direct/None', 'brein-plugin') : __('Unnamed element', 'brein-plugin');
            }
            if (!isset($targets[$target_label])) {
                $targets[$target_label] = 0;
            }
            $targets[$target_label]++;

            if ($last_click === $never_label) {
                $last_click = (string) $row->created_at;
            }

            if (count($recent_clicks) < 25) {
                $recent_clicks[] = array(
                    'created_at' => (string) $row->created_at,
                    'page' => $page_label,
                    'element' => $target_label,
                    'href' => $row->element_href ? $this->shorten_url($row->element_href) : '-',
                );
            }
        }

        arsort($pages);
        arsort($targets);

        return array(
            'total_clicks' => $total_clicks,
            'unique_visitors' => count($visitors),
            'last_click' => $last_click,
            'daily_days' => $daily_days,
            'daily_clicks' => array_values($daily_clicks),
            'top_pages' => $this->format_count_list($pages, 8),
            'top_targets' => $this->format_count_list($targets, 8),
            'recent_clicks' => $recent_clicks,
        );
    }

    private function empty_report()
    {
        return array(
            'total_clicks' => 0,
            'unique_visitors' => 0,
            'last_click' => __('Never', 'brein-plugin'),
            'daily_days' => array(),
            'daily_clicks' => array(),
            'top_pages' => array(),
            'top_targets' => array(),
            'recent_clicks' => array(),
        );
    }

    private function render_click_chart_card($values, $days, $series_label = null)
    {
        if (empty($days)) {
            $days = array();
            for ($i = 6; $i >= 0; $i--) {
                $days[] = wp_date('Y-m-d', strtotime(current_time('mysql') . " -{$i} days"));
            }
        }

        if (empty($values)) {
            $values = array_fill(0, count($days), 0);
        }

        $total = array_sum($values);
        if ($series_label === null || $series_label === '') {
            $series_label = __('Clicks', 'brein-plugin');
        }

        ob_start();
        ?>
        <div class="brein-weekly-card brein-tracking-click-card">
            <p class="brein-weekly-title"><?php echo esc_html($series_label); ?></p>
            <div class="brein-weekly-number">
                <p class="brein-weekly-total"><?php echo esc_html(number_format_i18n($total)); ?></p>
                <div class="analytics-icon">
                    <span class="analytics-badge-dot-new" aria-hidden="true"></span>
                    <span class="analytics-badge-bg" aria-hidden="true"></span>
                </div>
            </div>
            <p class="brein-weekly-sub"><?php echo esc_html__('Laatste 7 dagen', 'brein-plugin'); ?></p>
            <?php echo $this->render_chart_svg($values, $days, $series_label); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="brein-weekly-hoverline" aria-hidden="true"></div>
            <div class="brein-weekly-dot" aria-hidden="true"></div>
            <div class="brein-weekly-tooltip" aria-hidden="true"></div>
            <?php echo $this->render_day_labels($days); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_table_graph($values, $days)
    {
        ob_start();
        ?>
        <div class="brein-tracking-table-graph">
            <div class="brein-weekly-card brein-tracking-click-card">
                <?php echo $this->render_chart_svg($values, $days, __('Clicks', 'brein-plugin')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="brein-weekly-hoverline" aria-hidden="true"></div>
                <div class="brein-weekly-dot" aria-hidden="true"></div>
                <div class="brein-weekly-tooltip" aria-hidden="true"></div>
                <?php echo $this->render_day_labels($days); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_chart_svg($values, $days, $series_label)
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
        foreach ($values as $index => $value) {
            $x = round($index * $step, 2);
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

        return '<svg class="brein-weekly-chart" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__('Weekly clicks graph', 'brein-plugin') . '" data-series-label="' . esc_attr($series_label) . '" data-values="' . esc_attr(wp_json_encode($values)) . '" data-labels="' . esc_attr(wp_json_encode($labels)) . '" data-dates="' . esc_attr(wp_json_encode($dates)) . '">' .
            '<path d="' . esc_attr($area) . '" fill="#e6fff3" />' .
            '<path class="brein-weekly-line" d="' . esc_attr($line) . '" fill="none" stroke="#56e39f" stroke-width="0.5" />' .
            '</svg>';
    }

    private function render_day_labels($days)
    {
        $html = '<div class="brein-weekly-labels">';
        foreach ($days as $day) {
            $html .= '<span>' . esc_html(date_i18n('D', strtotime($day))) . '</span>';
        }
        $html .= '</div>';

        return $html;
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
            substr($line, 1) .
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

    private function sort_item_rows_by_clicks($left, $right)
    {
        $left_clicks = isset($left['report']['total_clicks']) ? (int) $left['report']['total_clicks'] : 0;
        $right_clicks = isset($right['report']['total_clicks']) ? (int) $right['report']['total_clicks'] : 0;

        if ($left_clicks === $right_clicks) {
            $left_class = isset($left['tracking']['value']) ? (string) $left['tracking']['value'] : '';
            $right_class = isset($right['tracking']['value']) ? (string) $right['tracking']['value'] : '';

            return strcmp($left_class, $right_class);
        }

        return $right_clicks <=> $left_clicks;
    }

    private function format_count_list($items, $limit)
    {
        $formatted = array();
        $index = 0;
        foreach ($items as $label => $count) {
            $formatted[] = array(
                'label' => (string) $label,
                'count' => (int) $count,
            );
            $index++;

            if ($index >= $limit) {
                break;
            }
        }

        return $formatted;
    }

    private function shorten_url($url)
    {
        $url = (string) $url;
        if ($url === '') {
            return '';
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$host) {
            return $url;
        }

        return $host . ($path ? $path : '');
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
