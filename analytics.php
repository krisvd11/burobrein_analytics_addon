<?php
/**
 * Plugin Name: Buro Brein Analytics
 * Description: Standalone analytics and cookie compliance tools for WordPress.
 * Version: 1.0.0
 * Author: Buro Brein
 *
 * @package BreinAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BREIN_ANALYTICS_PLUGIN_FILE', __FILE__);

require_once plugin_dir_path(__FILE__) . 'includes/class-brein-visitor-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-map-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-visitor-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-weekly-visitors-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-analytics-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-tracking-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-brein-funnel-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-cookie-compliance-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-cookie-compliance-setup.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/class-cookie-compliance-frontend.php';

register_activation_hook(__FILE__, array('Brein_Visitor_Tracker', 'activate'));

function run_brein_analytics_plugin()
{
    new Brein_Visitor_Tracker();
    new Brein_Cookie_Compliance_Frontend();

    if (!is_admin()) {
        return;
    }

    $tracking_capability = 'manage_options';

    $map_widget = new Brein_Map_Widget();
    $visitor_widget = new Brein_Visitor_Widget();
    $weekly_widget = new Brein_Weekly_Visitors_Widget();
    $tracking_widget = new Brein_Tracking_Widget($tracking_capability);
    $funnel_widget = new Brein_Funnel_Widget($tracking_capability);
    $cookie_settings = new Brein_Cookie_Compliance_Settings();

    new Brein_Cookie_Compliance_Setup();

    new Brein_Analytics_Page($map_widget, $visitor_widget, $weekly_widget, $tracking_widget, $funnel_widget);
}

run_brein_analytics_plugin();
