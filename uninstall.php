<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package GFGoogleDocs
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('gf_googledocs_access_token');
delete_option('gf_googledocs_legacy_migrated');
delete_option('gf_googledocs_caps_migrated');
delete_option('gravityformsaddon_gravityformsgoogledocs_settings');
delete_transient('gf_googledocs_account_info');
