<?php
/**
 * Cookie Compliance Setup Wizard
 *
 * Creates a dedicated Cookie Compliance page on first admin visit.
 *
 * @package BreinAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brein_Cookie_Compliance_Setup
{
    private $setup_option = 'brein_cookie_compliance_setup_done';

    public function __construct()
    {
        add_action('admin_init', array($this, 'maybe_redirect_to_setup'));
    }

    public function maybe_redirect_to_setup()
    {
        if (!is_admin()) {
            return;
        }

        if ($this->is_setup_done()) {
            return;
        }

        if (!current_user_can('edit_pages')) {
            return;
        }

        if ($this->is_setup_request()) {
            return;
        }

        update_option($this->setup_option, 1);
        wp_safe_redirect(admin_url('admin.php?page=brein-cookie-compliance'));
        exit;
    }

    private function is_setup_request()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($current_page === 'brein-cookie-compliance') {
            return true;
        }

        $script = isset($_SERVER['PHP_SELF']) ? wp_basename($_SERVER['PHP_SELF']) : '';
        if (in_array($script, array('admin-post.php', 'async-upload.php', 'admin-ajax.php'), true)) {
            return true;
        }

        return false;
    }

    private function is_setup_done()
    {
        return (bool) get_option($this->setup_option, false);
    }
}
