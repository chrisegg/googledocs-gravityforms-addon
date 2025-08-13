<?php
/*
Plugin Name: Google Docs Add-On for Gravity Forms
Plugin URI: https://gravityranger.com/plugins/googledocs
Description: Create Google Docs automatically from Gravity Forms submissions
Version: 1.0.0
Author: Chris Eggleston
Author URI: https://gravityranger.com
License: GPL-3.0+
Text Domain: gr-google-docs
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2025 Chris Eggleston

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
define('GR_GOOGLE_DOCS_VERSION', '1.0.0');

// Define the minimum version of Gravity Forms required
define('GR_GOOGLE_DOCS_MIN_GF_VERSION', '2.7');

// Define debug mode (set to true to enable verbose logging)
if (!defined('GR_GOOGLE_DOCS_DEBUG')) {
    define('GR_GOOGLE_DOCS_DEBUG', false);
}

// Define minimum WordPress version
if (!defined('GR_GOOGLE_DOCS_MIN_WP_VERSION')) {
    define('GR_GOOGLE_DOCS_MIN_WP_VERSION', '5.0');
}

// Define minimum PHP version
if (!defined('GR_GOOGLE_DOCS_MIN_PHP_VERSION')) {
    define('GR_GOOGLE_DOCS_MIN_PHP_VERSION', '7.4');
}

// Check for Composer autoloader
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// After Gravity Forms is loaded, load the Add-On
// Check compatibility before loading
add_action('plugins_loaded', 'gr_google_docs_check_compatibility');

/**
 * Check plugin compatibility requirements.
 */
function gr_google_docs_check_compatibility() {
    $errors = array();

    // Check PHP version
    if (version_compare(PHP_VERSION, GR_GOOGLE_DOCS_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires PHP %s or higher. You are running PHP %s.', 'gr-google-docs'),
            GR_GOOGLE_DOCS_MIN_PHP_VERSION,
            PHP_VERSION
        );
    }

    // Check WordPress version
    if (version_compare(get_bloginfo('version'), GR_GOOGLE_DOCS_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires WordPress %s or higher. You are running WordPress %s.', 'gr-google-docs'),
            GR_GOOGLE_DOCS_MIN_WP_VERSION,
            get_bloginfo('version')
        );
    }

    // Check if Gravity Forms is active
    if (!class_exists('GFForms')) {
        $errors[] = __('Google Docs Add-On requires Gravity Forms to be installed and activated.', 'gr-google-docs');
    } elseif (version_compare(GFForms::$version, GR_GOOGLE_DOCS_MIN_GF_VERSION, '<')) {
        $errors[] = sprintf(
            __('Google Docs Add-On requires Gravity Forms %s or higher. You are running Gravity Forms %s.', 'gr-google-docs'),
            GR_GOOGLE_DOCS_MIN_GF_VERSION,
            GFForms::$version
        );
    }

    // Display errors and deactivate plugin if incompatible
    if (!empty($errors)) {
        add_action('admin_notices', function() use ($errors) {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p><strong>' . 
                     esc_html__('Google Docs Add-On Compatibility Error:', 'gr-google-docs') . 
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

add_action('gform_loaded', array('GR_Google_Docs_Bootstrap', 'load_addon'), 5);

/**
 * Loads the Gravity Forms Google Docs Add-On.
 *
 * Includes the main class and registers it with GFAddOn.
 *
 * @since 1.0
 */
class GR_Google_Docs_Bootstrap {

    /**
     * Loads the required files.
     *
     * @since  1.0
     */
    public static function load_addon() {
        // Check for required dependencies
        if (!class_exists('Google_Client')) {
            add_action('admin_notices', array('GR_Google_Docs_Bootstrap', 'missing_composer_dependencies'));
            return;
        }

        // Requires the class file
        require_once plugin_dir_path(__FILE__) . '/includes/class-gr-google-docs.php';

        // Registers the class name with GFAddOn
        GFAddOn::register('GR_Google_Docs');
    }

    /**
     * Display admin notice for missing Composer dependencies.
     *
     * @since  1.0
     */
    public static function missing_composer_dependencies() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Google Docs for Gravity Forms requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'gr-google-docs'); ?></p>
        </div>
        <?php
    }
}

/**
 * Returns an instance of the GR_Google_Docs class
 *
 * @since  1.0
 * @return GR_Google_Docs|bool An instance of the GR_Google_Docs class
 */
function gr_google_docs() {
    return class_exists('GR_Google_Docs') ? GR_Google_Docs::get_instance() : false;
}

// Register activation hook
register_activation_hook(__FILE__, function() {
    // Check for Composer dependencies
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        wp_die(
            esc_html__('Google Docs for Gravity Forms requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'gr-google-docs'),
            esc_html__('Plugin Activation Error', 'gr-google-docs'),
            array('back_link' => true)
        );
    }
});
