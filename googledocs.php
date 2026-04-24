<?php
/*
Plugin Name: Gravity Forms Google Docs Add-On
Plugin URI: https://gravityforms.com
Description: Create Google Docs automatically from Gravity Forms submissions
Version: 1.0.0
Author: Gravity Forms
Author URI: https://gravityforms.com
License: GPL-3.0+
Text Domain: gravityformsgoogledocs
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2016-2026 Rocketgenius Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see http://www.gnu.org/licenses.

*/

defined('ABSPATH') || die();

// Define the current version of the Google Docs Add-On
define('GF_GOOGLE_DOCS_VERSION', '1.0.0');

// Define the minimum version of Gravity Forms required
define('GF_GOOGLE_DOCS_MIN_GF_VERSION', '2.7');

// Define debug mode (set to true to enable verbose logging)
if (!defined('GF_GOOGLE_DOCS_DEBUG')) {
    define('GF_GOOGLE_DOCS_DEBUG', false);
}

// Define minimum WordPress version
if (!defined('GF_GOOGLE_DOCS_MIN_WP_VERSION')) {
    define('GF_GOOGLE_DOCS_MIN_WP_VERSION', '5.0');
}

// Define minimum PHP version
if (!defined('GF_GOOGLE_DOCS_MIN_PHP_VERSION')) {
    define('GF_GOOGLE_DOCS_MIN_PHP_VERSION', '7.4');
}

/**
 * Migrate data from legacy plugin slugs/keys (GR / google_docs) to GF / gravityformsgoogledocs.
 */
function gf_googledocs_migrate_legacy_data() {
    if (get_option('gf_googledocs_legacy_migrated')) {
        return;
    }

    global $wpdb;

    $new_slug = 'gravityformsgoogledocs';
    $new_settings_key = 'gravityformsaddon_' . $new_slug . '_settings';
    $current_settings = get_option($new_settings_key, null);
    if (empty($current_settings) || !is_array($current_settings)) {
        $legacy_settings_keys = array(
            'gravityformsaddon_google_docs_settings',
            'gravityformsaddon_gr-google-docs_settings',
        );
        foreach ($legacy_settings_keys as $legacy_key) {
            $old = get_option($legacy_key, array());
            if (!empty($old) && is_array($old)) {
                update_option($new_settings_key, $old);
                break;
            }
        }
    }

    if (!get_option('gf_googledocs_access_token', false)) {
        $old_token = get_option('gr_google_docs_access_token', false);
        if ($old_token) {
            update_option('gf_googledocs_access_token', $old_token);
        }
    }

    $feeds_table = $wpdb->prefix . 'gf_addon_feed';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $feeds_table)) === $feeds_table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "UPDATE `{$feeds_table}` SET `addon_slug` = 'gravityformsgoogledocs' WHERE `addon_slug` IN ( 'google_docs', 'gr-google-docs' )"
        );
    }

    $entry_meta_table = $wpdb->prefix . 'gf_entry_meta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entry_meta_table)) === $entry_meta_table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "UPDATE `{$entry_meta_table}` SET `meta_key` = 'gfgoogledocs_doc_id' WHERE `meta_key` = 'gr_google_doc_id'"
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "UPDATE `{$entry_meta_table}` SET `meta_key` = 'gfgoogledocs_doc_url' WHERE `meta_key` = 'gr_google_doc_url'"
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "UPDATE `{$entry_meta_table}` SET `meta_key` = 'gfgoogledocs_error' WHERE `meta_key` = 'gr_google_docs_error'"
        );
    }

    update_option('gf_googledocs_legacy_migrated', time(), false);
}
add_action('plugins_loaded', 'gf_googledocs_migrate_legacy_data', 1);

/**
 * Map legacy add-on capabilities to the new names (one-time).
 */
function gf_googledocs_migrate_role_capabilities() {
    if (get_option('gf_googledocs_caps_migrated')) {
        return;
    }

    global $wp_roles;
    if (!isset($wp_roles)) {
        $wp_roles = new WP_Roles();
    }
    foreach (array_keys($wp_roles->roles) as $role_name) {
        $role = get_role($role_name);
        if (!$role) {
            continue;
        }
        if ($role->has_cap('gravityforms_google_docs') && !$role->has_cap('gravityforms_googledocs')) {
            $role->add_cap('gravityforms_googledocs');
        }
        if ($role->has_cap('gravityforms_google_docs_uninstall') && !$role->has_cap('gravityforms_googledocs_uninstall')) {
            $role->add_cap('gravityforms_googledocs_uninstall');
        }
    }
    update_option('gf_googledocs_caps_migrated', time(), false);
}
add_action('admin_init', 'gf_googledocs_migrate_role_capabilities', 1);

// Check for Composer autoloader
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// After Gravity Forms is loaded, load the Add-On
// Check compatibility before loading
add_action('plugins_loaded', 'gf_googledocs_check_compatibility', 2);

/**
 * Check plugin compatibility requirements.
 */
function gf_googledocs_check_compatibility() {
    $errors = array();

    // Check PHP version
    if (version_compare(PHP_VERSION, GF_GOOGLE_DOCS_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires PHP %s or higher. You are running PHP %s.', 'gravityformsgoogledocs'),
            GF_GOOGLE_DOCS_MIN_PHP_VERSION,
            PHP_VERSION
        );
    }

    // Check WordPress version
    if (version_compare(get_bloginfo('version'), GF_GOOGLE_DOCS_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires WordPress %s or higher. You are running WordPress %s.', 'gravityformsgoogledocs'),
            GF_GOOGLE_DOCS_MIN_WP_VERSION,
            get_bloginfo('version')
        );
    }

    // Check if Gravity Forms is active
    if (!class_exists('GFForms')) {
        $errors[] = __('Google Docs Add-On requires Gravity Forms to be installed and activated.', 'gravityformsgoogledocs');
    } elseif (version_compare(GFForms::$version, GF_GOOGLE_DOCS_MIN_GF_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires Gravity Forms %s or higher. You are running Gravity Forms %s.', 'gravityformsgoogledocs'),
            GF_GOOGLE_DOCS_MIN_GF_VERSION,
            GFForms::$version
        );
    }

    // Display errors and deactivate plugin if incompatible
    if (!empty($errors)) {
        add_action('admin_notices', function() use ($errors) {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p><strong>' .
                     esc_html__('Google Docs Add-On Compatibility Error:', 'gravityformsgoogledocs') .
                     '</strong></p><ul>';
                foreach ($errors as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
            }
        });

        // Deactivate this plugin
        add_action('admin_init', function() {
            deactivate_plugins(plugin_basename(__FILE__));
        });

        return;
    }
}

add_action('gform_loaded', array('GF_GoogleDocs_Bootstrap', 'load_addon'), 5);

/**
 * Loads the Gravity Forms Google Docs Add-On.
 *
 * Includes the main class and registers it with GFAddOn.
 *
 * @since 1.0
 */
class GF_GoogleDocs_Bootstrap {

    /**
     * Loads the required files.
     *
     * @since  1.0
     */
    public static function load_addon() {
        // Check for required dependencies
        if (!class_exists('Google_Client')) {
            add_action('admin_notices', array('GF_GoogleDocs_Bootstrap', 'missing_composer_dependencies'));
            return;
        }

        // Requires the class file
        require_once plugin_dir_path(__FILE__) . '/includes/class-gf-googledocs.php';

        // Registers the class name with GFAddOn
        GFAddOn::register('GFGoogleDocs');
    }

    /**
     * Display admin notice for missing Composer dependencies.
     *
     * @since  1.0
     */
    public static function missing_composer_dependencies() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Google Docs for Gravity Forms requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'gravityformsgoogledocs'); ?></p>
        </div>
        <?php
    }
}

/**
 * Returns an instance of the GFGoogleDocs class
 *
 * @since  1.0
 * @return GFGoogleDocs|bool An instance of the GFGoogleDocs class
 */
function gf_googledocs() {
    return class_exists('GFGoogleDocs') ? GFGoogleDocs::get_instance() : false;
}

// Register activation hook
register_activation_hook(__FILE__, function() {
    // Check for Composer dependencies
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        wp_die(
            esc_html__('Google Docs for Gravity Forms requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'gravityformsgoogledocs'),
            esc_html__('Plugin Activation Error', 'gravityformsgoogledocs'),
            array('back_link' => true)
        );
    }
});
