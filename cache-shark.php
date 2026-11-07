<?php
/**
 * Plugin Name:       Cache Shark
 * Description:       Free, open-source page caching for WordPress.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Md Rabiul
 * Author URI:        https://github.com/rabiul3000
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cache-shark
 */

defined( 'ABSPATH' ) || exit;

define( 'CACHE_SHARK_VERSION', '0.2.0' );
define( 'CACHE_SHARK_FILE', __FILE__ );
define( 'CACHE_SHARK_DIR', plugin_dir_path( __FILE__ ) );
define( 'CACHE_SHARK_BASE', WP_CONTENT_DIR . '/cache/cache-shark' );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CacheShark\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = CACHE_SHARK_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \CacheShark\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CacheShark\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new \CacheShark\Plugin() )->boot();
	}
);
