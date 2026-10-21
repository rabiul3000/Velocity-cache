<?php
/**
 * Plugin Name:       Velocity Cache
 * Description:       Free, open-source page caching for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Md Rabiul Islam
 * Author URI:        https://github.com/rabiul3000
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       velocity-cache
 */

defined( 'ABSPATH' ) || exit;

define( 'VELOCITY_CACHE_VERSION', '0.1.0' );
define( 'VELOCITY_CACHE_FILE', __FILE__ );
define( 'VELOCITY_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VELOCITY_CACHE_BASE', WP_CONTENT_DIR . '/cache/velocity-cache' );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'VelocityCache\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = VELOCITY_CACHE_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \VelocityCache\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \VelocityCache\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new \VelocityCache\Plugin() )->boot();
	}
);