<?php
/**
 * Cookie Compliance Settings
 *
 * Settings page for the Cookie Compliance popup.
 *
 * @package BreinAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Cookie_Compliance_Settings
{
    /** @var string */
    private $option_name = 'brein_cookie_compliance_options';

    /** @var string|null */
    private $page_hook_suffix = null;

    /** @var Brein_Role_Access_Manager|null */
    private $role_access_manager;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'), 50);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function set_role_access_manager($role_access_manager)
    {
        $this->role_access_manager = $role_access_manager;
    }

    public function register_menu()
    {
        if ($this->role_access_manager && !$this->role_access_manager->should_show_menu_item('brein-cookie-compliance')) {
            return;
        }

        $capability = $this->get_required_capability();

        $this->page_hook_suffix = add_submenu_page(
            'brein-analytics',
            __('Cookie Compliance', 'brein-plugin'),
            __('Cookie Compliance', 'brein-plugin'),
            $capability,
            'brein-cookie-compliance',
            array($this, 'render_settings_page'),
            2
        );
    }

    private function get_required_capability()
    {
        if ($this->role_access_manager && $this->role_access_manager->user_can_access_section('simple_settings')) {
            return 'edit_pages';
        }

        return 'manage_options';
    }

    public function register_settings()
    {
        $capability = $this->get_required_capability();

        register_setting(
            'brein_cookie_compliance_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_options'),
                'default' => array(),
                'capability' => $capability,
            )
        );

        add_settings_section(
            'brein_cookie_compliance_section',
            __('Popup instellingen', 'brein-plugin'),
            '__return_false',
            'brein-cookie-compliance'
        );

        add_settings_field(
            'brein_cookie_compliance_enabled',
            __('Popup inschakelen', 'brein-plugin'),
            array($this, 'render_checkbox_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'enabled')
        );

        add_settings_field(
            'brein_cookie_compliance_content',
            __('Toestemming', 'brein-plugin'),
            array($this, 'render_editor_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'content')
        );

        add_settings_field(
            'brein_cookie_compliance_privacy_policy_url',
            __('Privacybeleid URL', 'brein-plugin'),
            array($this, 'render_url_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'privacy_policy_url')
        );

        add_settings_field(
            'brein_cookie_compliance_data_retention_days',
            __('Bewaartermijn analytics (dagen)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'data_retention_days', 'min' => 1, 'max' => 3650)
        );

        add_settings_field(
            'brein_cookie_compliance_title_image_url',
            __('Titel afbeelding URL', 'brein-plugin'),
            array($this, 'render_image_uploader_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'title_image_url')
        );

        add_settings_field(
            'brein_cookie_compliance_accept_label',
            __('Accept button', 'brein-plugin'),
            array($this, 'render_text_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'accept_label')
        );

        add_settings_field(
            'brein_cookie_compliance_decline_label',
            __('Decline button', 'brein-plugin'),
            array($this, 'render_text_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'decline_label')
        );

        add_settings_field(
            'brein_cookie_compliance_show_decline',
            __('Toon decline knop', 'brein-plugin'),
            array($this, 'render_checkbox_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'show_decline')
        );

        add_settings_field(
            'brein_cookie_compliance_expire_days',
            __('Opslagduur (dagen)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'expire_days', 'min' => 1, 'max' => 3650)
        );

        add_settings_field(
            'brein_cookie_compliance_delay_seconds',
            __('Wachttijd (seconden)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'delay_seconds', 'min' => 0, 'max' => 3600)
        );

        add_settings_field(
            'brein_cookie_compliance_overlay_color',
            __('Achtergrondkleur overlay', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'overlay_color')
        );

        add_settings_field(
            'brein_cookie_compliance_overlay_opacity',
            __('Achtergrond opacity', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'overlay_opacity', 'min' => 0, 'max' => 1, 'step' => '0.05')
        );

        add_settings_field(
            'brein_cookie_compliance_max_width',
            __('Max popup breedte (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'max_width', 'min' => 320, 'max' => 1200)
        );

        add_settings_field(
            'brein_cookie_compliance_position',
            __('Popup positie', 'brein-plugin'),
            array($this, 'render_select_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array(
                'key' => 'position',
                'options' => array(
                    'bottom_right' => __('Rechtsonder', 'brein-plugin'),
                    'bottom_left' => __('Linksonder', 'brein-plugin'),
                    'bottom_center' => __('Onder midden', 'brein-plugin'),
                    'center_center' => __('Midden scherm', 'brein-plugin'),
                ),
            )
        );

        add_settings_field(
            'brein_cookie_compliance_bottom_spacing',
            __('Afstand onderkant (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'bottom_spacing', 'min' => 0, 'max' => 120)
        );

        add_settings_field(
            'brein_cookie_compliance_side_spacing',
            __('Afstand zijkant (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'side_spacing', 'min' => 0, 'max' => 120)
        );

        add_settings_field(
            'brein_cookie_compliance_border_radius',
            __('Popup border radius (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'border_radius', 'min' => 0, 'max' => 40)
        );

        add_settings_field(
            'brein_cookie_compliance_popup_bg',
            __('Popup achtergrond', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'popup_bg')
        );

        add_settings_field(
            'brein_cookie_compliance_heading_color',
            __('Heading kleur', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'heading_color')
        );

        add_settings_field(
            'brein_cookie_compliance_body_text_color',
            __('Tekstkleur inhoud', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'body_text_color')
        );

        add_settings_field(
            'brein_cookie_compliance_tab_bg',
            __('Tab achtergrond', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'tab_bg')
        );

        add_settings_field(
            'brein_cookie_compliance_check_bg',
            __('Tab achtergrond', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'check_bg')
        );


        add_settings_field(
            'brein_cookie_compliance_tab_text_color',
            __('Tab tekstkleur', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'tab_text_color')
        );

        add_settings_field(
            'brein_cookie_compliance_tab_active_text_color',
            __('Actieve tab tekstkleur', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'tab_active_text_color')
        );

        add_settings_field(
            'brein_cookie_compliance_tab_active_accent_color',
            __('Actieve tab accentkleur', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'tab_active_accent_color')
        );

        add_settings_field(
            'brein_cookie_compliance_tab_border_color',
            __('Tab randkleur', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'tab_border_color')
        );

        add_settings_field(
            'brein_cookie_compliance_title_font_size',
            __('Titel grootte (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'title_font_size', 'min' => 16, 'max' => 48)
        );

        add_settings_field(
            'brein_cookie_compliance_button_radius',
            __('Button radius (px)', 'brein-plugin'),
            array($this, 'render_number_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'button_radius', 'min' => 0, 'max' => 999)
        );

        add_settings_field(
            'brein_cookie_compliance_accept_bg',
            __('Accept button bg', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'accept_bg')
        );

        add_settings_field(
            'brein_cookie_compliance_accept_text',
            __('Accept button tekst', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'accept_text')
        );

        add_settings_field(
            'brein_cookie_compliance_decline_bg',
            __('Decline button bg', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'decline_bg')
        );

        add_settings_field(
            'brein_cookie_compliance_decline_text',
            __('Decline button tekst', 'brein-plugin'),
            array($this, 'render_color_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'decline_text')
        );

        add_settings_field(
            'brein_cookie_compliance_always_show',
            __('Altijd tonen (negeer keuze)', 'brein-plugin'),
            array($this, 'render_checkbox_field'),
            'brein-cookie-compliance',
            'brein_cookie_compliance_section',
            array('key' => 'always_show')
        );
    }

    public function sanitize_options($input)
    {
        if ($this->role_access_manager && !$this->role_access_manager->user_can_access_section('simple_settings')) {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to update these settings.', 'brein-plugin'));
            }
        }

        $output = array();
        if (!is_array($input)) {
            return $output;
        }

        $output['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $output['content'] = isset($input['content']) ? wp_kses_post($input['content']) : '';
        $output['privacy_policy_url'] = isset($input['privacy_policy_url']) ? esc_url_raw($input['privacy_policy_url']) : '';
        $output['data_retention_days'] = isset($input['data_retention_days']) ? max(1, (int) $input['data_retention_days']) : 180;
        $output['title'] = isset($input['title']) ? sanitize_text_field($input['title']) : '';
        $output['title_image_url'] = isset($input['title_image_url']) ? esc_url_raw($input['title_image_url']) : '';
        $output['accept_label'] = isset($input['accept_label']) ? sanitize_text_field($input['accept_label']) : '';
        $output['decline_label'] = isset($input['decline_label']) ? sanitize_text_field($input['decline_label']) : '';
        $output['show_decline'] = !empty($input['show_decline']) ? 1 : 0;
        $output['expire_days'] = isset($input['expire_days']) ? max(1, (int) $input['expire_days']) : 180;
        $output['delay_seconds'] = isset($input['delay_seconds']) ? max(0, (int) $input['delay_seconds']) : 0;
        $output['overlay_color'] = isset($input['overlay_color']) ? sanitize_hex_color($input['overlay_color']) : '';

        $opacity = isset($input['overlay_opacity']) ? (float) $input['overlay_opacity'] : 0.5;
        if ($opacity < 0) {
            $opacity = 0;
        } elseif ($opacity > 1) {
            $opacity = 1;
        }
        $output['overlay_opacity'] = $opacity;

        $output['max_width'] = isset($input['max_width']) ? max(320, (int) $input['max_width']) : 720;
        $allowed_positions = array('bottom_right', 'bottom_left', 'bottom_center', 'center_center');
        $output['position'] = (isset($input['position']) && in_array($input['position'], $allowed_positions, true))
            ? $input['position']
            : 'bottom_right';
        $output['bottom_spacing'] = isset($input['bottom_spacing']) ? max(0, (int) $input['bottom_spacing']) : 24;
        $output['side_spacing'] = isset($input['side_spacing']) ? max(0, (int) $input['side_spacing']) : 24;
        $output['border_radius'] = isset($input['border_radius']) ? max(0, (int) $input['border_radius']) : 16;
        $output['popup_bg'] = isset($input['popup_bg']) ? sanitize_hex_color($input['popup_bg']) : '';
        $output['heading_color'] = isset($input['heading_color']) ? sanitize_hex_color($input['heading_color']) : '';
        $output['body_text_color'] = isset($input['body_text_color']) ? sanitize_hex_color($input['body_text_color']) : '';
        $output['tab_bg'] = isset($input['tab_bg']) ? sanitize_hex_color($input['tab_bg']) : '';
        $output['check_bg'] = isset($input['check_bg']) ? sanitize_hex_color($input['check_bg']) : '';

        $output['tab_text_color'] = isset($input['tab_text_color']) ? sanitize_hex_color($input['tab_text_color']) : '';
        $output['tab_active_text_color'] = isset($input['tab_active_text_color']) ? sanitize_hex_color($input['tab_active_text_color']) : '';
        $output['tab_active_accent_color'] = isset($input['tab_active_accent_color']) ? sanitize_hex_color($input['tab_active_accent_color']) : '';
        $output['tab_border_color'] = isset($input['tab_border_color']) ? sanitize_hex_color($input['tab_border_color']) : '';
        $output['title_font_size'] = isset($input['title_font_size']) ? max(16, (int) $input['title_font_size']) : 22;
        $output['button_radius'] = isset($input['button_radius']) ? max(0, (int) $input['button_radius']) : 999;
        $output['accept_bg'] = isset($input['accept_bg']) ? sanitize_hex_color($input['accept_bg']) : '';
        $output['accept_text'] = isset($input['accept_text']) ? sanitize_hex_color($input['accept_text']) : '';
        $output['decline_bg'] = isset($input['decline_bg']) ? sanitize_hex_color($input['decline_bg']) : '';
        $output['decline_text'] = isset($input['decline_text']) ? sanitize_hex_color($input['decline_text']) : '';
        $output['always_show'] = !empty($input['always_show']) ? 1 : 0;

        return $output;
    }

    public function render_checkbox_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $checked = !empty($options[$key]);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>" value="1" <?php checked($checked); ?> />
        </label>
        <?php
    }

    public function render_text_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        ?>
        <input
            type="text"
            class="regular-text"
            name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>"
            value="<?php echo esc_attr($value); ?>" />
        <?php
    }
    

    public function render_url_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        ?>
        <input
            type="url"
            class="regular-text"
            name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr(get_privacy_policy_url()); ?>" />
        <?php
    }

    public function render_image_uploader_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        ?>
        <div class="brein-image-uploader" data-target-key="<?php echo esc_attr($key); ?>">
            <input
                type="hidden"
                class="brein-image-uploader__input"
                name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>"
                value="<?php echo esc_attr($value); ?>" />
            <button type="button" class="button brein-image-uploader__select">
                <?php esc_html_e('Selecteer afbeelding', 'brein-plugin'); ?>
            </button>
            <button type="button" class="button-link-delete brein-image-uploader__remove" <?php echo empty($value) ? 'style="display:none;"' : ''; ?>>
                <?php esc_html_e('Verwijderen', 'brein-plugin'); ?>
            </button>
            <div class="brein-image-uploader__preview" style="margin-top:10px;">
                <?php if (!empty($value)): ?>
                    <img src="<?php echo esc_url($value); ?>" alt="" style="max-height:60px;width:auto;" />
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_number_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? $options[$key] : '';
        $min = isset($args['min']) ? (int) $args['min'] : 1;
        $max = isset($args['max']) ? (int) $args['max'] : 3650;
        $step = isset($args['step']) ? (string) $args['step'] : '1';
        ?>
        <input
            type="number"
            class="small-text"
            name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>"
            value="<?php echo esc_attr($value); ?>"
            min="<?php echo esc_attr($min); ?>"
            max="<?php echo esc_attr($max); ?>"
            step="<?php echo esc_attr($step); ?>" />
        <?php
    }

    public function render_select_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        $select_options = isset($args['options']) && is_array($args['options']) ? $args['options'] : array();
        ?>
        <select name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>">
            <?php foreach ($select_options as $option_value => $label): ?>
                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, (string) $option_value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_color_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        if ($value === '') {
            $value = '#000000';
        }
        ?>

        <input
            type="color"
            name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>"
            value="<?php echo esc_attr($value); ?>" />
        <?php
    }

    public function render_editor_field($args)
    {
        $options = $this->get_options();
        $key = isset($args['key']) ? $args['key'] : '';
        $value = isset($options[$key]) ? (string) $options[$key] : '';
        wp_editor(
            $value,
            'brein_cookie_compliance_content',
            array(
                'textarea_name' => $this->option_name . '[' . $key . ']',
                'textarea_rows' => 10,
                'media_buttons' => true,
                'teeny' => false,
            )
        );
    }

    private function get_options()
    {
        $defaults = array(
            'enabled' => 1,
            'content' => '',
            'privacy_policy_url' => get_privacy_policy_url(),
            'data_retention_days' => 180,
            'title' => __('Cookies', 'brein-plugin'),
            'title_image_url' => '',
            'accept_label' => __('Accepteren', 'brein-plugin'),
            'decline_label' => __('Weigeren', 'brein-plugin'),
            'show_decline' => 1,
            'expire_days' => 180,
            'delay_seconds' => 0,
            'overlay_color' => '#000000',
            'overlay_opacity' => 0.5,
            'max_width' => 720,
            'position' => 'bottom_right',
            'bottom_spacing' => 24,
            'side_spacing' => 24,
            'border_radius' => 16,
            'popup_bg' => '#ffffff',
            'heading_color' => '#1d2327',
            'body_text_color' => '#1d2327',
            'tab_bg' => '#ffffff',
            'check_bg' => '#56E3A0',
            'tab_text_color' => '#1d2327',
            'tab_active_text_color' => '#1d4dff',
            'tab_active_accent_color' => '#1d4dff',
            'tab_border_color' => '#e6e6e6',
            'title_font_size' => 22,
            'button_radius' => 999,
            'accept_bg' => '#1d2327',
            'accept_text' => '#ffffff',
            'decline_bg' => '#ffffff',
            'decline_text' => '#1d2327',
            'always_show' => 0,
        );

        $saved = get_option($this->option_name, array());

        return wp_parse_args($saved, $defaults);
    }

    public function render_settings_page()
    {
        if ($this->role_access_manager && !$this->role_access_manager->user_can_access_section('simple_settings')) {
            wp_die(__('You do not have permission to access this page.', 'brein-plugin'));
        }

        if (!current_user_can('edit_pages')) {
            wp_die(__('You do not have permission to access this page.', 'brein-plugin'));
        }
        $this->render_settings_interface(false);
    }

    public function render_embedded_settings()
    {
        if ($this->role_access_manager && !$this->role_access_manager->user_can_access_section('simple_settings')) {
            echo '<p>' . esc_html__('You do not have permission to access this section.', 'brein-plugin') . '</p>';
            return;
        }

        if (!current_user_can('edit_pages')) {
            echo '<p>' . esc_html__('You do not have permission to access this section.', 'brein-plugin') . '</p>';
            return;
        }

        $this->render_settings_interface(true);
    }

    private function render_settings_interface($embedded = false)
    {
        ?>
        <div class="<?php echo $embedded ? 'quick-action-menu brein-cookie-embedded' : 'wrap quick-action-menu'; ?>">
            <?php if (!$embedded): ?>
            <h1><?php esc_html_e('Cookie Compliance', 'brein-plugin'); ?></h1>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('brein_cookie_compliance_group');
                ?>
                <div class="brein-qam-tabs brein-cookie-tabs">
                    <h2><?php esc_html_e('Content', 'brein-plugin'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            
                            <?php $this->render_settings_row('Popup inschakelen', array($this, 'render_checkbox_field'), array('key' => 'enabled')); ?>
                            <?php $this->render_settings_row('Titel afbeelding', array($this, 'render_image_uploader_field'), array('key' => 'title_image_url')); ?>
                            <?php $this->render_settings_row('Toestemming inhoud', array($this, 'render_editor_field'), array('key' => 'content')); ?>
                            <?php $this->render_settings_row('Privacybeleid URL', array($this, 'render_url_field'), array('key' => 'privacy_policy_url')); ?>
                        </tbody>
                    </table>

                    <h2><?php esc_html_e('Buttons', 'brein-plugin'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_settings_row('Accept button', array($this, 'render_text_field'), array('key' => 'accept_label')); ?>
                            <?php $this->render_settings_row('Decline button', array($this, 'render_text_field'), array('key' => 'decline_label')); ?>
                            <?php $this->render_settings_row('Toon decline knop', array($this, 'render_checkbox_field'), array('key' => 'show_decline')); ?>
                            <?php $this->render_settings_row('Accept button bg', array($this, 'render_color_field'), array('key' => 'accept_bg')); ?>
                            <?php $this->render_settings_row('Accept button tekst', array($this, 'render_color_field'), array('key' => 'accept_text')); ?>
                            <?php $this->render_settings_row('Decline button bg', array($this, 'render_color_field'), array('key' => 'decline_bg')); ?>
                            <?php $this->render_settings_row('Decline button tekst', array($this, 'render_color_field'), array('key' => 'decline_text')); ?>
                            <?php $this->render_settings_row('Button radius (px)', array($this, 'render_number_field'), array('key' => 'button_radius', 'min' => 0, 'max' => 999)); ?>
                        </tbody>
                    </table>

                    <h2><?php esc_html_e('Behaviour', 'brein-plugin'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_settings_row('Opslagduur (dagen)', array($this, 'render_number_field'), array('key' => 'expire_days', 'min' => 1, 'max' => 3650)); ?>
                            <?php $this->render_settings_row('Bewaartermijn analytics (dagen)', array($this, 'render_number_field'), array('key' => 'data_retention_days', 'min' => 1, 'max' => 3650)); ?>
                            <?php $this->render_settings_row('Wachttijd (seconden)', array($this, 'render_number_field'), array('key' => 'delay_seconds', 'min' => 0, 'max' => 3600)); ?>
                            <?php $this->render_settings_row('Altijd tonen (negeer keuze)', array($this, 'render_checkbox_field'), array('key' => 'always_show')); ?>
                        </tbody>
                    </table>

                    <h2><?php esc_html_e('Layout', 'brein-plugin'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_settings_row('Popup positie', array($this, 'render_select_field'), array('key' => 'position', 'options' => array('bottom_right' => __('Rechtsonder', 'brein-plugin'), 'bottom_left' => __('Linksonder', 'brein-plugin'), 'bottom_center' => __('Onder midden', 'brein-plugin'), 'center_center' => __('Midden scherm', 'brein-plugin')))); ?>
                            <?php $this->render_settings_row('Max popup breedte (px)', array($this, 'render_number_field'), array('key' => 'max_width', 'min' => 320, 'max' => 1200)); ?>
                            <?php $this->render_settings_row('Afstand onderkant (px)', array($this, 'render_number_field'), array('key' => 'bottom_spacing', 'min' => 0, 'max' => 120)); ?>
                            <?php $this->render_settings_row('Afstand zijkant (px)', array($this, 'render_number_field'), array('key' => 'side_spacing', 'min' => 0, 'max' => 120)); ?>
                            <?php $this->render_settings_row('Popup border radius (px)', array($this, 'render_number_field'), array('key' => 'border_radius', 'min' => 0, 'max' => 40)); ?>
                            <?php $this->render_settings_row('Titel grootte (px)', array($this, 'render_number_field'), array('key' => 'title_font_size', 'min' => 16, 'max' => 48)); ?>
                        </tbody>
                    </table>

                    <h2><?php esc_html_e('Colors', 'brein-plugin'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->render_settings_row('Achtergrondkleur overlay', array($this, 'render_color_field'), array('key' => 'overlay_color')); ?>
                            <?php $this->render_settings_row('Achtergrond opacity', array($this, 'render_number_field'), array('key' => 'overlay_opacity', 'min' => 0, 'max' => 1, 'step' => '0.05')); ?>
                            <?php $this->render_settings_row('Popup achtergrond', array($this, 'render_color_field'), array('key' => 'popup_bg')); ?>
                            <?php $this->render_settings_row('Heading kleur', array($this, 'render_color_field'), array('key' => 'heading_color')); ?>
                            <?php $this->render_settings_row('Tekstkleur inhoud', array($this, 'render_color_field'), array('key' => 'body_text_color')); ?>
                            <?php $this->render_settings_row('Tab achtergrond', array($this, 'render_color_field'), array('key' => 'tab_bg')); ?>
                            <?php $this->render_settings_row('Check achtergrond', array($this, 'render_color_field'), array('key' => 'check_bg')); ?>
                            <?php $this->render_settings_row('Tab tekstkleur', array($this, 'render_color_field'), array('key' => 'tab_text_color')); ?>
                            <?php $this->render_settings_row('Actieve tab tekstkleur', array($this, 'render_color_field'), array('key' => 'tab_active_text_color')); ?>
                            <?php $this->render_settings_row('Actieve tab accentkleur', array($this, 'render_color_field'), array('key' => 'tab_active_accent_color')); ?>
                            <?php $this->render_settings_row('Tab randkleur', array($this, 'render_color_field'), array('key' => 'tab_border_color')); ?>
                        </tbody>
                    </table>

                    <?php submit_button(); ?>
                </div>
            </form>
            <style>
.quick-action-menu h2.tab-heading {
  display: inline-block;
  padding: 10px 15px;
  margin: 0 5px -1px 0;
  background: #56e39f;
  cursor: pointer;
  border: 1px solid #ccc;
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  font-size: 14px;
  transition: 0.3s;
}

.quick-action-menu h2.tab-heading.active {
  background: #fff;
  border-bottom: 1px solid #fff;
  font-weight: 600;
}

.quick-action-menu .brein-qam-tabs {
  border: 1px solid #ccc;
  background: #fff;
  padding: 15px;
  margin-top: 10px;
}

.quick-action-menu .brein-qam-tabs .form-table {
  border: 0;
  background: transparent;
  padding: 0;
  margin-top: 0;
}

.brein-cookie-tabs .form-table th {
  width: 240px;
  padding-top: 18px;
}

.brein-cookie-tabs .form-table td {
  padding-top: 14px;
  padding-bottom: 14px;
}

.brein-cookie-embedded {
  margin: 0;
}

.brein-cookie-embedded .brein-qam-tabs {
  border: 0;
  padding: 0;
  margin-top: 0;
}
            </style>
        </div>
        <?php
    }

    private function render_settings_row($label, $callback, $args)
    {
        ?>
        <tr>
            <th scope="row">
                <label><?php echo esc_html($label); ?></label>
            </th>
            <td>
                <?php call_user_func($callback, $args); ?>
            </td>
        </tr>
        <?php
    }

    public function enqueue_assets($hook)
    {
        if ($this->page_hook_suffix === null || $hook !== $this->page_hook_suffix) {
            return;
        }

        wp_enqueue_media();
        wp_register_script('brein-cookie-tabs', false, array(), '1.0.0', true);
        wp_enqueue_script('brein-cookie-tabs');
        wp_add_inline_script(
            'brein-cookie-tabs',
            "document.addEventListener('DOMContentLoaded', function () {
                const qamWrapper = document.querySelector('.quick-action-menu .brein-qam-tabs');
                if (!qamWrapper) {
                    return;
                }
                const headings = qamWrapper.querySelectorAll('h2');
                const tables = qamWrapper.querySelectorAll('.form-table');
                const nav = document.createElement('div');
                qamWrapper.insertBefore(nav, qamWrapper.firstChild);
                headings.forEach((heading) => {
                    nav.appendChild(heading);
                });
                tables.forEach((table, index) => {
                    if (index !== 0) table.style.display = 'none';
                });
                headings.forEach((heading, index) => {
                    heading.classList.add('tab-heading');
                    if (index === 0) heading.classList.add('active');
                    heading.addEventListener('click', function () {
                        headings.forEach((h) => h.classList.remove('active'));
                        tables.forEach((t) => (t.style.display = 'none'));
                        heading.classList.add('active');
                        tables[index].style.display = 'table';
                    });
                });

                const imageUploaders = document.querySelectorAll('.brein-image-uploader');
                imageUploaders.forEach((uploader) => {
                    const input = uploader.querySelector('.brein-image-uploader__input');
                    const selectButton = uploader.querySelector('.brein-image-uploader__select');
                    const removeButton = uploader.querySelector('.brein-image-uploader__remove');
                    const preview = uploader.querySelector('.brein-image-uploader__preview');
                    if (!input || !selectButton || !preview) {
                        return;
                    }

                    let frame;
                    selectButton.addEventListener('click', function () {
                        if (frame) {
                            frame.open();
                            return;
                        }
                        frame = wp.media({
                            title: 'Selecteer titel afbeelding',
                            button: { text: 'Gebruik afbeelding' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function () {
                            const attachment = frame.state().get('selection').first().toJSON();
                            if (!attachment || !attachment.url) {
                                return;
                            }
                            input.value = attachment.url;
                            preview.innerHTML = '<img src=\"' + attachment.url + '\" alt=\"\" style=\"max-height:60px;width:auto;\" />';
                            if (removeButton) {
                                removeButton.style.display = '';
                            }
                        });
                        frame.open();
                    });

                    if (removeButton) {
                        removeButton.addEventListener('click', function () {
                            input.value = '';
                            preview.innerHTML = '';
                            removeButton.style.display = 'none';
                        });
                    }
                });
            });"
        );
    }
}
