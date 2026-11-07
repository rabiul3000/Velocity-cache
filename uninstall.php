<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * WordPress loads only this file (not the plugin), so it sets up what it needs
 * itself and reuses the same cleanup code as deactivation.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'CACHE_SHARK_DIR', plugin_dir_path( __FILE__ ) );
define( 'CACHE_SHARK_BASE', WP_CONTENT_DIR . '/cache/cache-shark' );

require_once CACHE_SHARK_DIR . 'src/Fs.php';
require_once CACHE_SHARK_DIR . 'src/Settings.php';
require_once CACHE_SHARK_DIR . 'src/CacheStore.php';
require_once CACHE_SHARK_DIR . 'src/Htaccess.php';
require_once CACHE_SHARK_DIR . 'src/Installer.php';
require_once CACHE_SHARK_DIR . 'src/Preloader.php';

/** Per-site data: options, transient, scheduled jobs. */
$cache_shark_cleanup_site = static function () {
	\CacheShark\Preloader::unschedule(); // Clears cron hooks, queue, flag and lock.
	delete_option( 'cache_shark_settings' );
	delete_option( 'cache_shark_version' );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $cache_shark_site_id ) {
		switch_to_blog( (int) $cache_shark_site_id );
		$cache_shark_cleanup_site();
		restore_current_blog();
	}
} else {
	$cache_shark_cleanup_site();
}

// Files on disk: each helper only removes what this plugin created.
\CacheShark\Htaccess::remove();
\CacheShark\Installer::set_wp_cache( false );
\CacheShark\Installer::remove_dropin();
\CacheShark\CacheStore::destroy();
