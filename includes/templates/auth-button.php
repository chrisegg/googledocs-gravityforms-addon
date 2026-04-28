<?php
/**
 * Auth button markup for plugin settings (same pattern as Gravity Forms Google Sheets add-on).
 *
 * Variables are set by GFGoogleDocs::settings_auth_button() before this file is included.
 *
 * @package Gravity_Forms\Gravity_Forms_GoogleDocs
 *
 * @var bool   $has_credentials   Whether Client ID and Client Secret are non-empty.
 * @var bool   $is_authenticated  Whether Google OAuth tokens are present (requires credentials).
 * @var string $disconnect_url    Disconnect URL (already passed through esc_url()).
 * @var string $auth_url           Connect URL from Google (pass through esc_url() when printing).
 * @var string $site_name          Site label for connected state (pass through esc_html() when printing).
 * @var string $account_email      Google account email when known (may be empty).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!$has_credentials) {
    echo '<div class="alert alert-warning">' .
        esc_html__('Please enter your Google OAuth credentials above and save settings before connecting.', 'gravityformsgoogledocs') .
        '</div>';
} elseif ($is_authenticated) {
    printf(
        '<p class="gf-googledocs-auth-primary-action"><a href="%s" class="button button-secondary gf-googledocs-auth-button">%s</a></p>',
        $disconnect_url,
        esc_html__('Disconnect from Google', 'gravityformsgoogledocs')
    );
    $detail_label = '';
    $detail_value = '';
    if (!empty($account_email)) {
        /* translators: label before Google account email on settings screen */
        $detail_label = esc_html__('Account', 'gravityformsgoogledocs');
        $detail_value = esc_html($account_email);
    } else {
        /* translators: label before WordPress site name on settings screen */
        $detail_label = esc_html__('Site', 'gravityformsgoogledocs');
        $detail_value = esc_html($site_name);
    }
    printf(
        '<div class="alert gforms_note_success">%s<br>%s: %s</div>',
        esc_html__('Successfully connected to Google Docs.', 'gravityformsgoogledocs'),
        $detail_label,
        $detail_value
    );
} elseif ('' !== $auth_url) {
    printf(
        '<p class="gf-googledocs-auth-primary-action"><a href="%s" class="button button-primary gf-googledocs-auth-button">%s</a></p>',
        esc_url($auth_url),
        esc_html__('Connect with Google', 'gravityformsgoogledocs')
    );
} else {
    echo '<div class="alert alert-error">' .
        esc_html__('Unable to generate authentication URL. Please check your credentials.', 'gravityformsgoogledocs') .
        '</div>';
}
