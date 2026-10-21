<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * WordPress loads only this file (not the plugin), so it sets up what it needs
 * itself and reuses the same cleanup code as deactivation.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'VELOCITY_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VELOCITY_CACHE_BASE', WP_CONTENT_DIR . '/cache/velocity-cache' );

require_once VELOCITY_CACHE_DIR . 'src/Fs.php';
require_once VELOCITY_CACHE_DIR . 'src/Settings.php';
require_once VELOCITY_CACHE_DIR . 'src/CacheStore.php';
require_once VELOCITY_CACHE_DIR . 'src/Htaccess.php';
require_once VELOCITY_CACHE_DIR . 'src/Installer.php';
require_once VELOCITY_CACHE_DIR . 'src/Preloader.php';

/** Per-site data: options, transient, scheduled jobs. */
$velocity_cache_cleanup_site = static function () {
	\VelocityCache\Preloader::unschedule(); // Clears cron hooks, queue, flag and lock.
	delete_option( 'velocity_cache_settings' );
	delete_option( 'velocity_cache_version' );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $velocity_cache_site_id ) {
		switch_to_blog( (int) $velocity_cache_site_id );
		$velocity_cache_cleanup_site();
		restore_current_blog();
	}
} else {
	$velocity_cache_cleanup_site();
}

// Files on disk: each helper only removes what this plugin created.
\VelocityCache\Htaccess::remove();
\VelocityCache\Installer::set_wp_cache( false );
\VelocityCache\Installer::remove_dropin();
\VelocityCache\CacheStore::destroy();