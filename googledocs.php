<?php
/**
 * Plugin Name: Gravity Forms Google Docs Add-On
 * Plugin URI: https://gravityforms.com
 * Description: Create Google Docs automatically from Gravity Forms submissions
 * Version: 1.0.0
 * Author: Gravity Forms
 * Author URI: https://gravityforms.com
 * License: GPL-3.0+
 * Text Domain: gravityformsgoogledocs
 * Domain Path: /languages
 *
 * ------------------------------------------------------------------------
 * Copyright 2016-2026 Rocketgenius Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://www.gnu.org/licenses.
 *
 * @package Gravity_Forms\Gravity_Forms_GoogleDocs
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! defined( 'GF_GOOGLE_DOCS_PLUGIN_FILE' ) ) {
	define( 'GF_GOOGLE_DOCS_PLUGIN_FILE', __FILE__ );
}

define( 'GF_GOOGLE_DOCS_VERSION', '1.0.0' );
define( 'GF_GOOGLE_DOCS_MIN_GF_VERSION', '2.9.24' );

if ( ! defined( 'GF_GOOGLE_DOCS_DEBUG' ) ) {
	define( 'GF_GOOGLE_DOCS_DEBUG', false );
}

if ( ! defined( 'GF_GOOGLE_DOCS_ASYNC_FEEDS' ) ) {
	define( 'GF_GOOGLE_DOCS_ASYNC_FEEDS', false );
}

if ( ! defined( 'GF_GOOGLE_DOCS_MIN_WP_VERSION' ) ) {
	define( 'GF_GOOGLE_DOCS_MIN_WP_VERSION', '5.0' );
}

if ( ! defined( 'GF_GOOGLE_DOCS_MIN_PHP_VERSION' ) ) {
	define( 'GF_GOOGLE_DOCS_MIN_PHP_VERSION', '7.4' );
}

/**
 * Path to bundled Composer autoload (Google API client). Shipped in release zips; developers may run `composer install`.
 *
 * @return string
 */
function gf_googledocs_get_vendor_autoload_path() {
	return dirname( __FILE__ ) . '/vendor/autoload.php';
}

$gf_googledocs_autoload = gf_googledocs_get_vendor_autoload_path();
if ( is_readable( $gf_googledocs_autoload ) ) {
	require_once $gf_googledocs_autoload;
}

add_action( 'plugins_loaded', 'gf_googledocs_check_compatibility', 2 );

/**
 * Check plugin compatibility requirements.
 */
function gf_googledocs_check_compatibility() {
	$errors = array();

	if ( version_compare( PHP_VERSION, GF_GOOGLE_DOCS_MIN_PHP_VERSION, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: Required PHP version, 2: Current PHP version */
			__( 'Google Docs Add-On requires PHP %1$s or higher. You are running PHP %2$s.', 'gravityformsgoogledocs' ),
			GF_GOOGLE_DOCS_MIN_PHP_VERSION,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), GF_GOOGLE_DOCS_MIN_WP_VERSION, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: Required WordPress version, 2: Current WordPress version */
			__( 'Google Docs Add-On requires WordPress %1$s or higher. You are running WordPress %2$s.', 'gravityformsgoogledocs' ),
			GF_GOOGLE_DOCS_MIN_WP_VERSION,
			get_bloginfo( 'version' )
		);
	}

	if ( ! class_exists( 'GFForms' ) ) {
		$errors[] = __( 'Google Docs Add-On requires Gravity Forms to be installed and activated.', 'gravityformsgoogledocs' );
	} elseif ( version_compare( GFForms::$version, GF_GOOGLE_DOCS_MIN_GF_VERSION, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: Required Gravity Forms version, 2: Current Gravity Forms version */
			__( 'Google Docs Add-On requires Gravity Forms %1$s or higher. You are running Gravity Forms %2$s.', 'gravityformsgoogledocs' ),
			GF_GOOGLE_DOCS_MIN_GF_VERSION,
			GFForms::$version
		);
	}

	if ( ! empty( $errors ) ) {
		add_action(
			'admin_notices',
			function () use ( $errors ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error"><p><strong>' .
						esc_html__( 'Google Docs Add-On Compatibility Error:', 'gravityformsgoogledocs' ) .
						'</strong></p><ul>';
					foreach ( $errors as $error ) {
						echo '<li>' . esc_html( $error ) . '</li>';
					}
					echo '</ul></div>';
				}
			}
		);

		add_action(
			'admin_init',
			function () {
				deactivate_plugins( plugin_basename( __FILE__ ) );
			}
		);
	}
}

add_action( 'gform_loaded', array( 'GF_GoogleDocs_Bootstrap', 'load_addon' ), 5 );

/**
 * Loads the Gravity Forms Google Docs Add-On.
 *
 * @since 1.0
 */
class GF_GoogleDocs_Bootstrap {

	/**
	 * Loads the required files.
	 *
	 * @since 1.0
	 */
	public static function load_addon() {
		if ( ! class_exists( 'Google_Client' ) ) {
			add_action( 'admin_notices', array( 'GF_GoogleDocs_Bootstrap', 'missing_google_client' ) );
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/class-gf-googledocs-api.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-gf-googledocs.php';

		GFAddOn::register( \Gravity_Forms\Gravity_Forms_GoogleDocs\GFGoogleDocs::class );
	}

	/**
	 * Admin notice when the bundled Google API client is missing.
	 *
	 * @since 1.0
	 */
	public static function missing_google_client() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo esc_html__(
					'Google Docs for Gravity Forms requires the bundled Google API libraries. If you are developing the plugin, run `composer install` in the plugin directory and include the `vendor` folder in your deployment.',
					'gravityformsgoogledocs'
				);
				?>
			</p>
		</div>
		<?php
	}
}

/**
 * Returns the Google Docs add-on instance.
 *
 * @since 1.0
 *
 * @return \Gravity_Forms\Gravity_Forms_GoogleDocs\GFGoogleDocs|false
 */
function gf_googledocs() {
	return class_exists( \Gravity_Forms\Gravity_Forms_GoogleDocs\GFGoogleDocs::class )
		? \Gravity_Forms\Gravity_Forms_GoogleDocs\GFGoogleDocs::get_instance()
		: false;
}

register_activation_hook(
	__FILE__,
	function () {
		if ( ! is_readable( gf_googledocs_get_vendor_autoload_path() ) ) {
			wp_die(
				esc_html__(
					'Google Docs for Gravity Forms requires the `vendor` directory from this package. Run `composer install` in the plugin directory or use a full release zip.',
					'gravityformsgoogledocs'
				),
				esc_html__( 'Plugin Activation Error', 'gravityformsgoogledocs' ),
				array( 'back_link' => true )
			);
		}
	}
);
