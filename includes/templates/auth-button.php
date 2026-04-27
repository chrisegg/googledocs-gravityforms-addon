<?php
/**
 * Auth Button Template
 *
 * @package GFGoogleDocs
 */

if (!defined('ABSPATH')) {
    exit;
}

$api = new GF_Google_Docs_API();

// Check if we have valid client credentials first
$client_id = $this->get_plugin_setting('client_id');
$client_secret = $this->get_plugin_setting('client_secret');

if (empty($client_id) || empty($client_secret)) {
    echo '<div class="alert alert-warning">' .
        esc_html__('Please enter your Google OAuth credentials above and save settings before connecting.', 'gravityformsgoogledocs') .
        '</div>';
} else {
    // Check if we're authenticated
    if ($api->is_authenticated()) {
        printf(
            '<a href="%s" class="gf-googledocs-auth-button">%s</a>',
            esc_url(add_query_arg(
                array(
                    'page' => 'gf_settings',
                    'subview' => 'gravityformsgoogledocs',
                    'action' => 'disconnect',
                    'nonce' => wp_create_nonce('gf_googledocs_disconnect'),
                ),
                admin_url('admin.php')
            )),
            esc_html__('Disconnect from Google', 'gravityformsgoogledocs')
        );

        echo '<div class="alert alert-success" style="margin-top: 10px;">' .
            esc_html__('Successfully connected to Google Docs!', 'gravityformsgoogledocs') .
            '</div>';
    } else {
        $auth_url = $api->get_auth_url();
        if (!empty($auth_url)) {
            printf(
                '<a href="%s" class="gf-googledocs-auth-button">%s</a>',
                esc_url($auth_url),
                esc_html__('Connect with Google', 'gravityformsgoogledocs')
            );
        } else {
            echo '<div class="alert alert-error">' .
                esc_html__('Unable to generate authentication URL. Please check your credentials.', 'gravityformsgoogledocs') .
                '</div>';
        }
    }
}
