<?php
/**
 * Cookie Compliance Frontend Output
 *
 * Always renders the Cookie Compliance page content on the frontend.
 *
 * @package BreinAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Cookie_Compliance_Frontend
{
    private $option_name = 'brein_cookie_compliance_options';
    private $cookie_name = 'brein_cookie_compliance';
    private $analytics_cookies = array(
        'brein_visitor_id',
        'brein_visitor_session',
        'brein_recording_session_id',
    );
    private $consent_categories = array(
        'necessary' => array(
            'label' => 'Noodzakelijk',
            'description' => 'Altijd actief. Nodig om je cookiekeuze en basisfunctionaliteit te bewaren.',
            'required' => true,
        ),
        'analytics' => array(
            'label' => 'Analytics',
            'description' => 'Meet paginaweergaven, herkomst, apparaat en benaderde locatie om de website te verbeteren.',
            'required' => false,
        ),
        'recordings' => array(
            'label' => 'Recordings',
            'description' => 'Legt klik-, scroll- en muisgedrag vast voor sessieanalyse en UX-optimalisatie.',
            'required' => false,
        ),
    );

    public function __construct()
    {
        add_action('wp_footer', array($this, 'render'), 20);
    }

    public function render()
    {
        if (is_admin()) {
            return;
        }

        $options = $this->get_options();
        if (empty($options['enabled'])) {
            return;
        }

        $content = !empty($options['content']) ? (string) $options['content'] : $this->get_default_content($options);
        $content = apply_filters('the_content', $content);
        $privacy_policy_url = !empty($options['privacy_policy_url']) ? $options['privacy_policy_url'] : get_privacy_policy_url();

        $title = !empty($options['title']) ? $options['title'] : __('Cookies', 'brein-plugin');
        $title_image_url = !empty($options['title_image_url']) ? $options['title_image_url'] : '';
        $accept_label = !empty($options['accept_label']) ? $options['accept_label'] : __('Accepteren', 'brein-plugin');
        $decline_label = !empty($options['decline_label']) ? $options['decline_label'] : __('Weigeren', 'brein-plugin');
        $show_decline = !empty($options['show_decline']);
        $expire_days = isset($options['expire_days']) ? (int) $options['expire_days'] : 180;
        $delay_seconds = isset($options['delay_seconds']) ? (int) $options['delay_seconds'] : 0;
        $always_show = !empty($options['always_show']);
        $overlay_color = !empty($options['overlay_color']) ? $options['overlay_color'] : '#000000';
        $overlay_opacity = isset($options['overlay_opacity']) ? (float) $options['overlay_opacity'] : 0.5;
        if ($overlay_opacity < 0) {
            $overlay_opacity = 0;
        } elseif ($overlay_opacity > 1) {
            $overlay_opacity = 1;
        }
        $max_width = isset($options['max_width']) ? (int) $options['max_width'] : 720;
        $position = !empty($options['position']) ? $options['position'] : 'bottom_right';
        if (!in_array($position, array('bottom_right', 'bottom_left', 'bottom_center', 'center_center'), true)) {
            $position = 'bottom_right';
        }
        $bottom_spacing = isset($options['bottom_spacing']) ? max(0, (int) $options['bottom_spacing']) : 24;
        $side_spacing = isset($options['side_spacing']) ? max(0, (int) $options['side_spacing']) : 24;
        $border_radius = isset($options['border_radius']) ? (int) $options['border_radius'] : 16;
        $popup_bg = !empty($options['popup_bg']) ? $options['popup_bg'] : '#ffffff';
        $heading_color = !empty($options['heading_color']) ? $options['heading_color'] : '#1d2327';
        $body_text_color = !empty($options['body_text_color']) ? $options['body_text_color'] : '#1d2327';
        $tab_bg = !empty($options['tab_bg']) ? $options['tab_bg'] : '#ffffff';
        $check_bg = !empty($options['check_bg']) ? $options['check_bg'] : '#ffffff';
        $tab_text_color = !empty($options['tab_text_color']) ? $options['tab_text_color'] : '#1d2327';
        $tab_active_text_color = !empty($options['tab_active_text_color']) ? $options['tab_active_text_color'] : '#1d4dff';
        $tab_active_accent_color = !empty($options['tab_active_accent_color']) ? $options['tab_active_accent_color'] : '#1d4dff';
        $tab_border_color = !empty($options['tab_border_color']) ? $options['tab_border_color'] : '#e6e6e6';
        $title_font_size = isset($options['title_font_size']) ? max(16, (int) $options['title_font_size']) : 22;
        $button_radius = isset($options['button_radius']) ? max(0, (int) $options['button_radius']) : 999;
        $accept_bg = !empty($options['accept_bg']) ? $options['accept_bg'] : '#1d2327';
        $accept_text = !empty($options['accept_text']) ? $options['accept_text'] : '#ffffff';
        $decline_bg = !empty($options['decline_bg']) ? $options['decline_bg'] : '#ffffff';
        $decline_text = !empty($options['decline_text']) ? $options['decline_text'] : '#1d2327';

        $overlay_rgba = $this->hex_to_rgba($overlay_color, $overlay_opacity);
        $justify_content = 'flex-end';
        $align_items = 'flex-end';
        if ($position === 'bottom_left') {
            $justify_content = 'flex-start';
        } elseif ($position === 'bottom_center') {
            $justify_content = 'center';
        } elseif ($position === 'center_center') {
            $justify_content = 'center';
            $align_items = 'center';
        }

        ?>
        <style>
            .brein-cookie-popup p, li {
                font-size: 14px !important;
            }
            .brein-cookie-popup {
                position: fixed;
                inset: 0;
                display: flex;
                align-items: <?php echo esc_html($align_items); ?>;
                justify-content: <?php echo esc_html($justify_content); ?>;
                background: <?php echo esc_html($overlay_rgba); ?>;
                z-index: 99999;
                padding: <?php echo esc_html($bottom_spacing); ?>px <?php echo esc_html($side_spacing); ?>px;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.3s ease;
            }
            .brein-cookie-popup.is-visible {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }
            .brein-cookie-popup__dialog {
                background: <?php echo esc_html($popup_bg); ?>;
                max-width: <?php echo esc_html($max_width); ?>px;
                width: 100%;
                border-radius: <?php echo esc_html($border_radius); ?>px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
                overflow: hidden;
                transform: translateY(12px);
                transition: transform 0.3s ease;
            }
            .brein-cookie-popup.is-visible .brein-cookie-popup__dialog {
                transform: translateY(0);
            }
            .brein-cookie-popup__header {
                padding: 12px;
            }
            .brein-cookie-popup__title {
                margin: 0;
                font-size: <?php echo esc_html($title_font_size); ?>px;
                line-height: 1.2;
                color: <?php echo esc_html($heading_color); ?>;
                padding: 10px !important;
            }
            .brein-cookie-popup__title-image {
                display: block;
                max-width: 100%;
                width: auto;
                max-height: 46px;
                height: auto;
            }
            .brein-cookie-popup__body {
                padding: 12px;
                color: <?php echo esc_html($body_text_color); ?>;
            }
            .brein-cookie-popup__decline-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 0;
                border: 0;
                background: transparent;
                color: <?php echo esc_html($decline_text); ?>;
                font-size: 13px;
                font-weight: 500;
                text-decoration: underline;
                cursor: pointer;
            }
            .brein-cookie-popup__inline-links {
                display: flex;
                flex-wrap: wrap;
                gap: 14px;
                align-items: center;
                margin-top: 10px;
            }
            .brein-cookie-popup__policy-link {
                color: <?php echo esc_html($decline_text); ?>;
                font-size: 13px;
                font-weight: 500;
                text-decoration: underline;
            }
            .brein-cookie-popup__actions {
                display: flex;
                gap: 12px;
                justify-content: flex-end;
                padding: 12px 20px 22px 20px;
            }
            .brein-cookie-popup__tabs {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                align-items: stretch;
                border-top: 1px solid <?php echo esc_html($tab_border_color); ?>;
                border-bottom: 1px solid <?php echo esc_html($tab_border_color); ?>;
                background: <?php echo esc_html($tab_bg); ?>;
            }
            .brein-cookie-popup__tab {
                appearance: none;
                position: relative;
                border: 0;
                border-radius: 0;
                background: transparent;
                color: <?php echo esc_html($tab_text_color); ?>;
                padding: 20px 16px 18px;
                font-size: 13px;
                font-weight: 700;
                text-align: center;
                cursor: pointer;
            }
            .brein-cookie-popup__tab::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                height: 3px;
                background: transparent;
                transition: background 0.2s ease;
            }
            .brein-cookie-popup__tab.is-active {
                color: <?php echo esc_html($tab_active_text_color); ?>;
            }
            .brein-cookie-popup__tab.is-active::after {
                background: <?php echo esc_html($tab_active_accent_color); ?>;
            }
            .brein-cookie-popup__panels {
                padding-bottom: 4px;
            }
            .brein-cookie-popup__panel {
                display: none;
            }
            .brein-cookie-popup__panel.is-active {
                display: block;
            }
            .brein-cookie-popup__preferences {
                padding: 0 20px 4px;
                margin-top: 4px;
            }
            .brein-cookie-popup__preferences-title {
                margin: 16px 0 4px;
                font-size: 14px;
                font-weight: 600;
                color: <?php echo esc_html($heading_color); ?>;
            }
            .brein-cookie-popup__preferences-copy {
                margin: 0 0 12px;
                font-size: 13px;
                color: #55606d;
            }
            .brein-cookie-popup__category {
                border: 1px solid #e2e7ec;
                background: #ffffff;
                margin-top: 12px;
                overflow: hidden;
            }
            .brein-cookie-popup__category:first-of-type {
                margin-top: 0;
            }
            .brein-cookie-popup__category-toggle {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 16px;
                border: 0;
                background: transparent;
                text-align: left;
                cursor: pointer;
            }
            .brein-cookie-popup__category-main {
                min-width: 0;
                flex: 1 1 auto;
            }
            .brein-cookie-popup__category-right {
                display: inline-flex;
                align-items: center;
                gap: 12px;
                flex: 0 0 auto;
            }
            .brein-cookie-popup__category-label {
                display: block;
                font-size: 14px;
                font-weight: 600;
                color: <?php echo esc_html($heading_color); ?>;
                margin-bottom: 0;
            }
            .brein-cookie-popup__category-chevron {
                width: 10px;
                height: 10px;
                border-right: 1.5px solid #5f6b77;
                border-bottom: 1.5px solid #5f6b77;
                transform: rotate(45deg);
                transition: transform 0.2s ease;
                margin-right: 2px;
            }
            .brein-cookie-popup__category[data-expanded="true"] .brein-cookie-popup__category-chevron {
                transform: rotate(-135deg);
            }
            .brein-cookie-popup__category-body {
                display: none;
                padding: 0 16px 14px;
            }
            .brein-cookie-popup__category[data-expanded="true"] .brein-cookie-popup__category-body {
                display: block;
            }
            .brein-cookie-popup__category-text {
                margin: 0;
                font-size: 12px;
                line-height: 1.5;
                color: #55606d;
            }
            .brein-cookie-popup__category[data-expanded="true"] .brein-cookie-popup__category-toggle {
                padding-bottom: 10px;
            }
            .brein-cookie-popup__switch {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 48px;
                height: 28px;
                flex: 0 0 auto;
            }
            .brein-cookie-popup__switch input {
                position: absolute;
                opacity: 0;
                width: 0;
                height: 0;
            }
            .brein-cookie-popup__slider {
                position: absolute;
                inset: 0;
                border-radius: 999px;
                background: #cfd6dd;
                transition: background 0.2s ease;
                cursor: pointer;
            }
            .brein-cookie-popup__slider::before {
                content: '';
                position: absolute;
                top: 3px;
                left: 3px;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: #ffffff;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                transition: transform 0.2s ease;
            }
            .brein-cookie-popup__switch input:checked + .brein-cookie-popup__slider {
                background: <?php echo esc_html($check_bg); ?>;                ;
            }
            .brein-cookie-popup__switch input:checked + .brein-cookie-popup__slider::before {
                transform: translateX(20px);
            }
            .brein-cookie-popup__switch input:disabled + .brein-cookie-popup__slider {
                background: <?php echo esc_html($check_bg); ?>;                ;
                cursor: default;
                opacity: 0.30;
            }
            .brein-cookie-popup__footer {
                padding: 0 20px 20px;
                font-size: 12px;
                color: #55606d;
            }
            .brein-cookie-popup__footer a {
                color: inherit;
                text-decoration: underline;
            }
            .brein-cookie-popup__btn {
                border-radius: <?php echo esc_html($button_radius); ?>px;
                padding: 10px 20px;
                border: 1px solid <?php echo esc_html($accept_bg); ?>;
                background: <?php echo esc_html($accept_bg); ?>;
                color: <?php echo esc_html($accept_text); ?>;
                cursor: pointer;
            }
            .brein-cookie-popup__btn--ghost {
                border: 1px solid <?php echo esc_html($decline_text); ?>;
                background: <?php echo esc_html($decline_bg); ?>;
                color: <?php echo esc_html($decline_text); ?>;
            }
            @media (max-width: 600px) {
                .brein-cookie-popup {
                    padding: 16px;
                    align-items: flex-end;
                    justify-content: stretch;
                }
                .brein-cookie-popup__dialog {
                    max-width: none;
                    border-radius: 12px;
                }
                .brein-cookie-popup__actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                .brein-cookie-popup__tab {
                    padding: 16px 10px 14px;
                    font-size: 12px;
                }
            }
        </style>

        <div
            class="brein-cookie-popup"
            data-cookie-name="<?php echo esc_attr($this->cookie_name); ?>"
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-ajax-nonce="<?php echo esc_attr(wp_create_nonce('brein_track_consent_visitor')); ?>"
            data-expire-days="<?php echo esc_attr($expire_days); ?>"
            data-delay-seconds="<?php echo esc_attr($delay_seconds); ?>"
            data-always-show="<?php echo $always_show ? '1' : '0'; ?>">
            <div class="brein-cookie-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="brein-cookie-popup-title">
                <div class="brein-cookie-popup__header">
                    <h2 class="brein-cookie-popup__title" id="brein-cookie-popup-title">
                        <?php if (!empty($title_image_url)): ?>
                            <img class="brein-cookie-popup__title-image" src="<?php echo esc_url($title_image_url); ?>" alt="" aria-hidden="true" />
                            <span class="screen-reader-text"><?php echo esc_html($title); ?></span>
                        <?php else: ?>
                            <?php echo esc_html($title); ?>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="brein-cookie-popup__tabs" role="tablist" aria-label="<?php esc_attr_e('Cookie tabs', 'brein-plugin'); ?>">
                    <button type="button" class="brein-cookie-popup__tab is-active" data-cookie-tab="summary" role="tab" aria-selected="true"><?php esc_html_e('Toestemming', 'brein-plugin'); ?></button>
                    <button type="button" class="brein-cookie-popup__tab" data-cookie-tab="preferences" role="tab" aria-selected="false"><?php esc_html_e('Voorkeuren', 'brein-plugin'); ?></button>
                    <button type="button" class="brein-cookie-popup__tab" data-cookie-tab="about" role="tab" aria-selected="false"><?php esc_html_e('Over', 'brein-plugin'); ?></button>
                </div>
                <div class="brein-cookie-popup__panels">
                    <div class="brein-cookie-popup__panel is-active" data-cookie-panel="summary" role="tabpanel">
                        <div class="brein-cookie-popup__body">
                            <?php echo $content; ?>
                            <div class="brein-cookie-popup__inline-links">
                                <?php if ($show_decline): ?>
                                    <button type="button" class="brein-cookie-popup__decline-link" data-cookie-action="decline">
                                        <?php echo esc_html($decline_label); ?>
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($privacy_policy_url)): ?>
                                    <a class="brein-cookie-popup__policy-link" href="<?php echo esc_url($privacy_policy_url); ?>">
                                        <?php esc_html_e('Privacybeleid', 'brein-plugin'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="brein-cookie-popup__panel" data-cookie-panel="preferences" role="tabpanel">
                        <div class="brein-cookie-popup__preferences">
                            <h3 class="brein-cookie-popup__preferences-title"><?php esc_html_e('Voorkeuren', 'brein-plugin'); ?></h3>
                            <p class="brein-cookie-popup__preferences-copy"><?php esc_html_e('Kies per categorie welke cookies je wilt toestaan.', 'brein-plugin'); ?></p>
                            <?php foreach ($this->consent_categories as $key => $category): ?>
                                <div class="brein-cookie-popup__category" data-cookie-category-row="<?php echo esc_attr($key); ?>" data-expanded="<?php echo !empty($category['required']) ? 'true' : 'false'; ?>">
                                    <button type="button" class="brein-cookie-popup__category-toggle" data-cookie-category-toggle="<?php echo esc_attr($key); ?>" aria-expanded="<?php echo !empty($category['required']) ? 'true' : 'false'; ?>">
                                        <div class="brein-cookie-popup__category-main">
                                            <span class="brein-cookie-popup__category-label"><?php echo esc_html($category['label']); ?></span>
                                        </div>
                                        <div class="brein-cookie-popup__category-right">
                                            <label class="brein-cookie-popup__switch">
                                                <input
                                                    type="checkbox"
                                                    data-cookie-category="<?php echo esc_attr($key); ?>"
                                                    <?php checked(!empty($category['required'])); ?>
                                                    <?php disabled(!empty($category['required'])); ?>>
                                                <span class="brein-cookie-popup__slider" aria-hidden="true"></span>
                                            </label>
                                            <span class="brein-cookie-popup__category-chevron" aria-hidden="true"></span>
                                        </div>
                                    </button>
                                    <div class="brein-cookie-popup__category-body">
                                        <p class="brein-cookie-popup__category-text"><?php echo esc_html($category['description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="brein-cookie-popup__panel" data-cookie-panel="about" role="tabpanel">
                        <div class="brein-cookie-popup__body">
                            <p><?php esc_html_e('Deze voorkeuren geven je controle over welke niet-noodzakelijke cookies we mogen plaatsen.', 'brein-plugin'); ?></p>
                            <p><strong><?php esc_html_e('Noodzakelijk:', 'brein-plugin'); ?></strong> <?php esc_html_e('vereist om je cookiekeuze te bewaren en basisfunctionaliteit te laten werken.', 'brein-plugin'); ?></p>
                            <p><strong><?php esc_html_e('Analytics:', 'brein-plugin'); ?></strong> <?php esc_html_e('helpt ons begrijpen welke pagina’s worden gebruikt en hoe bezoekers de website vinden.', 'brein-plugin'); ?></p>
                            <p><strong><?php esc_html_e('Recordings:', 'brein-plugin'); ?></strong> <?php esc_html_e('legt interacties zoals klikken en scrollen vast om gebruikservaring te verbeteren.', 'brein-plugin'); ?></p>
                            <p><?php esc_html_e('Je kunt je keuze op elk moment aanpassen via Cookie-instellingen.', 'brein-plugin'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="brein-cookie-popup__actions">
                    <button type="button" class="brein-cookie-popup__btn brein-cookie-popup__btn--ghost" data-cookie-action="save-preferences">
                        <?php esc_html_e('Bewaar voorkeuren', 'brein-plugin'); ?>
                    </button>
                    <button type="button" class="brein-cookie-popup__btn" data-cookie-action="accept">
                        <?php echo esc_html($accept_label); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
            (function () {




                let popup = document.querySelector('.brein-cookie-popup');
                if (!popup) {
                    return;
                }
                let cookieName = popup.getAttribute('data-cookie-name') || 'brein_cookie_compliance';
                let ajaxUrl = popup.getAttribute('data-ajax-url') || '';
                let ajaxNonce = popup.getAttribute('data-ajax-nonce') || '';
                let expireDays = parseInt(popup.getAttribute('data-expire-days') || '180', 10);
                let alwaysShow = popup.getAttribute('data-always-show') === '1';
                let delaySeconds = parseInt(popup.getAttribute('data-delay-seconds') || '0', 10);
                let analyticsCookies = <?php echo wp_json_encode(array_values($this->analytics_cookies)); ?>;
                let categoryInputs = popup.querySelectorAll('[data-cookie-category]');
                let categoryToggles = popup.querySelectorAll('[data-cookie-category-toggle]');
                let tabButtons = popup.querySelectorAll('[data-cookie-tab]');
                let tabPanels = popup.querySelectorAll('[data-cookie-panel]');

                let getCookie = function (name) {
                    let value = '; ' + document.cookie;
                    let parts = value.split('; ' + name + '=');
                    if (parts.length === 2) {
                        return parts.pop().split(';').shift();
                    }
                    return '';
                };

                let setCookie = function (name, value, days) {
                    let date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    let expires = 'expires=' + date.toUTCString();
                    let secure = window.location.protocol === 'https:' ? ';secure' : '';
                  document.cookie = name + '=' + value + ';' + expires + ';path=/;samesite=lax' + secure;
                };

                let clearCookie = function (name) {
                    let secure = window.location.protocol === 'https:' ? ';secure' : '';
                    document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;samesite=lax' + secure;
                };

                let getDefaultPreferences = function () {
                    return {
                        necessary: true,
                        analytics: false,
                        recordings: false
                    };
                };

                let normalizePreferences = function (value) {
                    let defaults = getDefaultPreferences();
                    if (!value) {
                        return defaults;
                    }
                    if (value === 'accept') {
                        defaults.analytics = true;
                        defaults.recordings = true;
                        return defaults;
                    }
                    if (value === 'decline') {
                        return defaults;
                    }

                    try {
                        let parsed = JSON.parse(decodeURIComponent(value));
                        if (parsed && typeof parsed === 'object') {
                            defaults.analytics = !!parsed.analytics;
                            defaults.recordings = !!parsed.recordings;
                        }
                    } catch (e) {}

                    return defaults;
                };

                let serializePreferences = function (preferences) {
                    return encodeURIComponent(JSON.stringify({
                        necessary: true,
                        analytics: !!preferences.analytics,
                        recordings: !!preferences.recordings
                    }));
                };

                let getSelectedPreferences = function () {
                    let preferences = getDefaultPreferences();
                    categoryInputs.forEach(function (input) {
                        let key = input.getAttribute('data-cookie-category');
                        if (!key || key === 'necessary') {
                            return;
                        }
                        preferences[key] = !!input.checked;
                    });
                    return preferences;
                };

                let setCategoryExpanded = function (key, expanded) {
                    if (!key) {
                        return;
                    }
                    let row = popup.querySelector('[data-cookie-category-row="' + key + '"]');
                    let toggle = popup.querySelector('[data-cookie-category-toggle="' + key + '"]');
                    if (!row || !toggle) {
                        return;
                    }
                    row.setAttribute('data-expanded', expanded ? 'true' : 'false');
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                };

                let applyPreferencesToUI = function (preferences) {
                    categoryInputs.forEach(function (input) {
                        let key = input.getAttribute('data-cookie-category');
                        if (!key) {
                            return;
                        }
                        input.checked = key === 'necessary' ? true : !!preferences[key];
                        if (key === 'necessary') {
                            setCategoryExpanded(key, true);
                        } else if (preferences[key]) {
                            setCategoryExpanded(key, true);
                        }
                    });
                };

                let notifyConsentChange = function (preferences) {
                    document.dispatchEvent(new window.CustomEvent('breinCookieConsentChanged', {
                        detail: {
                            value: serializePreferences(preferences),
                            preferences: preferences
                        }
                    }));
                };

                let closePopup = function () {
                    popup.classList.remove('is-visible');
                };

                let openPopup = function () {
                    popup.classList.add('is-visible');
                };

                let setActiveTab = function (name) {
                    tabButtons.forEach(function (button) {
                        let active = button.getAttribute('data-cookie-tab') === name;
                        button.classList.toggle('is-active', active);
                        button.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    tabPanels.forEach(function (panel) {
                        panel.classList.toggle('is-active', panel.getAttribute('data-cookie-panel') === name);
                    });
                };

                let persistPreferences = function (preferences) {
                    setCookie(cookieName, serializePreferences(preferences), expireDays);
                    closePopup();
                    if (!preferences.analytics && !preferences.recordings) {
                        analyticsCookies.forEach(clearCookie);
                    }
                    notifyConsentChange(preferences);
                };

                let trackConsentChoice = function (action, preferences) {
                    if (!ajaxUrl) {
                        return;
                    }

                    try {
                        let body = new URLSearchParams();
                        body.append('action', 'brein_track_cookie_consent');
                        body.append('nonce', ajaxNonce);
                        body.append('consent_action', action);
                        body.append('preferences', serializePreferences(preferences));
                        body.append('path', window.location.pathname + (window.location.search || ''));
                        body.append('page_title', document.title || '');
                        body.append('referrer', document.referrer || 'direct');
                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: body.toString()
                        });
                    } catch (e) {
                        // Silent fail; consent UI should still work.
                    }
                };

                if (!alwaysShow) {
                  let existing = getCookie(cookieName);
                  if (existing) {
                    let existingPreferences = normalizePreferences(existing);
                    applyPreferencesToUI(existingPreferences);
                    notifyConsentChange(existingPreferences);
                    return;
                  }
                }

                applyPreferencesToUI(getDefaultPreferences());

                if (delaySeconds > 0) {
                    window.setTimeout(openPopup, delaySeconds * 1000);
                } else {
                    openPopup();
                }

                tabButtons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        setActiveTab(button.getAttribute('data-cookie-tab') || 'summary');
                    });
                });

                categoryToggles.forEach(function (toggle) {
                    toggle.addEventListener('click', function (event) {
                        if (event.target && event.target.closest('.brein-cookie-popup__switch')) {
                            return;
                        }
                        let key = toggle.getAttribute('data-cookie-category-toggle');
                        let row = popup.querySelector('[data-cookie-category-row="' + key + '"]');
                        if (!row) {
                            return;
                        }
                        let expanded = row.getAttribute('data-expanded') !== 'true';
                        setCategoryExpanded(key, expanded);
                    });
                });

                categoryInputs.forEach(function (input) {
                    input.addEventListener('click', function (event) {
                        event.stopPropagation();
                    });
                    input.addEventListener('change', function () {
                        let key = input.getAttribute('data-cookie-category');
                        if (!key) {
                            return;
                        }
                        setCategoryExpanded(key, true);
                    });
                });

                popup.addEventListener('click', function (event) {
                    if (!event.target) {
                        return;
                    }
                    let action = event.target.getAttribute('data-cookie-action');
                    if (!action) {
                      return;
                    }
                    let preferences = getSelectedPreferences();
                    if (action === 'accept') {
                        preferences.analytics = true;
                        preferences.recordings = true;
                        applyPreferencesToUI(preferences);
                    } else if (action === 'decline') {
                        preferences.analytics = false;
                        preferences.recordings = false;
                        applyPreferencesToUI(preferences);
                    }

                    persistPreferences(preferences);
                    trackConsentChoice(action, preferences);

                    if ((action === 'accept' || action === 'save-preferences') && preferences.analytics && ajaxUrl) {
                        try {
                            let body = new URLSearchParams();
                            body.append('action', 'brein_track_consent_visitor');
                            body.append('nonce', ajaxNonce);
                            body.append('path', window.location.pathname + (window.location.search || ''));
                            body.append('page_title', document.title || '');
                            body.append('referrer', document.referrer || '');
                            fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                                body: body.toString()
                            });
                        } catch (e) {
                            // Silent fail; tracking will still occur on next page load.
                        }
                    }
                });
            })();
        </script>
        <?php
    }

    private function get_options()
    {
        $defaults = array(
            'enabled' => 1,
            'content' => '',
            'privacy_policy_url' => get_privacy_policy_url(),
            'data_retention_days' => 180,
            'title' => __('Cookies', 'brein-plugin'),
            'accept_label' => __('Accepteren', 'brein-plugin'),
            'decline_label' => __('Weigeren', 'brein-plugin'),
            'show_decline' => 1,
            'expire_days' => 180,
            'delay_seconds' => 0,
            'overlay_color' => '#000000',
            'overlay_opacity' => 0.5,
            'max_width' => 720,
            'border_radius' => 16,
            'heading_color' => '#1d2327',
            'accept_bg' => '#1d2327',
            'accept_text' => '#ffffff',
            'decline_bg' => '#ffffff',
            'decline_text' => '#1d2327',
            'always_show' => 0,
        );

        $saved = get_option($this->option_name, array());

        return wp_parse_args($saved, $defaults);
    }

    private function get_default_content($options)
    {
        $retention_days = isset($options['data_retention_days']) ? max(1, (int) $options['data_retention_days']) : 180;

        return sprintf(
            wp_kses_post(
                __(
                    '<p>Met jouw toestemming meten we hoe de site wordt gebruikt zodat we prestaties en inhoud kunnen verbeteren.</p><ul><li>paginaweergaven en bezochte URL&apos;s</li><li>klikgedrag op links en knoppen</li><li>verwijzende website</li><li>apparaattype en samengevatte browserinformatie</li><li>land en benaderde locatie op basis van IP</li></ul><p>We plaatsen analyticscookies pas na akkoord. We bewaren deze analyticsgegevens maximaal %d dagen.</p>',
                    'brein-plugin'
                )
            ),
            $retention_days
        );
    }

    private function hex_to_rgba($hex, $opacity)
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            $hex = '000000';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $opacity = max(0, min(1, (float) $opacity));

        return sprintf('rgba(%d, %d, %d, %.3f)', $r, $g, $b, $opacity);
    }
}
