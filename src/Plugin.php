<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/** Wires the components together. Holds no logic of its own beyond self-healing. */
class Plugin {

	const VERSION_OPTION = 'velocity_cache_version';

	/** @var Purger */
	private $purger;

	public function boot(): void {
		$this->purger = new Purger();
		$this->purger->boot();

		( new PageCache() )->boot();
		( new Preloader() )->boot();
		( new Admin() )->boot();

		// Settings saved: rebuild the drop-in's config and start from a clean cache.
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ) );

		add_action( 'admin_init', array( $this, 'self_heal' ) );
	}

	public function on_settings_saved(): void {
		Settings::write_config();
		$this->purger->purge_all();
	}

	/**
	 * Cheap checks on every admin page load.
	 *
	 * Hosts sometimes wipe wp-content/cache, and plugin updates may ship a newer
	 * drop-in or rules; both must be restored without the user doing anything.
	 */
	public function self_heal(): void {
		if ( ! is_file( VELOCITY_CACHE_BASE . '/config.php' ) ) {
			Settings::write_config();
		}
		if ( get_option( self::VERSION_OPTION ) !== VELOCITY_CACHE_VERSION ) {
			Installer::maybe_upgrade_dropin();
			Settings::write_config();
			Htaccess::insert();
			update_option( self::VERSION_OPTION, VELOCITY_CACHE_VERSION );
		}
	}
}