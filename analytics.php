<?php
/**
 * Plugin Name: Buro Brein Analytics
 * Description: Analytics add-on for the Buro Brein plugin.
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

register_activation_hook(__FILE__, array('Brein_Visitor_Tracker', 'activate'));

function run_brein_analytics_plugin()
{
    new Brein_Visitor_Tracker();

    if (!is_admin()) {
        return;
    }

    global $brein_role_access_manager;
    $role_access_manager = isset($brein_role_access_manager) ? $brein_role_access_manager : null;
    $tracking_capability = ($role_access_manager && $role_access_manager->user_can_access_section('technical'))
        ? 'edit_pages'
        : 'manage_options';

    $map_widget = new Brein_Map_Widget();
    $visitor_widget = new Brein_Visitor_Widget();
    $weekly_widget = new Brein_Weekly_Visitors_Widget();
    $tracking_widget = new Brein_Tracking_Widget($tracking_capability);

    new Brein_Analytics_Page($role_access_manager, $map_widget, $visitor_widget, $weekly_widget, $tracking_widget);
}

run_brein_analytics_plugin();
