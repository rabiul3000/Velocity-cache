<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/**
 * Activation / deactivation: installs the advanced-cache.php drop-in and
 * switches WP_CACHE on in wp-config.php. Both are reversible and only ever
 * touch what this plugin put there.
 */
class Installer {

	const MARKER = '// Added by Velocity Cache';

	/* ---------- Lifecycle ---------- */

	public static function activate( $network_wide = false ): void {
		if ( $network_wide ) {
			deactivate_plugins( plugin_basename( VELOCITY_CACHE_FILE ) );
			wp_die(
				esc_html__( 'Velocity Cache does not support network activation yet. Please activate it on each site separately.', 'velocity-cache' ),
				'',
				array( 'back_link' => true )
			);
		}
		add_option( Settings::OPTION, Settings::defaults() );
		Settings::write_config();
		self::install_dropin();
		self::set_wp_cache( true );
		Htaccess::insert();
	}

	public static function deactivate(): void {
        Preloader::unschedule();
		Htaccess::remove();
		self::set_wp_cache( false );
		self::remove_dropin();
		CacheStore::purge_all();
	}

	/* ---------- Drop-in ---------- */

	public static function dropin_path(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/** @return string 'ours' | 'foreign' | 'missing' */
	public static function dropin_state(): string {
		$file = self::dropin_path();
		if ( ! file_exists( $file ) ) {
			return 'missing';
		}
		$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== strpos( $head, 'VELOCITY_CACHE_DROPIN_VERSION' ) ? 'ours' : 'foreign';
	}

	/** Copy the bundled drop-in into wp-content. Never overwrites another plugin's drop-in. */
	public static function install_dropin(): bool {
		$state = self::dropin_state();
		if ( 'foreign' === $state || ! is_writable( WP_CONTENT_DIR ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		$src  = VELOCITY_CACHE_DIR . 'dropin/advanced-cache.php';
		$dest = self::dropin_path();
		if ( 'ours' === $state && md5_file( $src ) === md5_file( $dest ) ) {
			return true; // Already current.
		}
		$tmp = $dest . '.tmp';
		if ( ! @copy( $src, $tmp ) || ! Fs::move( $tmp, $dest ) ) {
			Fs::delete( $tmp );
			return false;
		}
		return true;
	}

	public static function remove_dropin(): void {
		if ( 'ours' === self::dropin_state() ) {
			Fs::delete( self::dropin_path() );
		}
	}

	/** Refresh the drop-in after a plugin update (called on admin_init). */
	public static function maybe_upgrade_dropin(): void {
		if ( 'ours' === self::dropin_state() ) {
			self::install_dropin();
		}
	}

	/* ---------- wp-config.php ---------- */

	public static function wp_config_path(): ?string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH );
		if ( file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return null;
	}

	/**
	 * Pure: add our WP_CACHE line to wp-config source.
	 *
	 * @return array{0:string,1:string} [ 'added'|'already'|'conflict'|'unsafe', new source ]
	 */
	public static function config_with_cache( string $src ): array {
		if ( false !== strpos( $src, self::MARKER ) ) {
			return array( 'already', $src );
		}
		// Ignore commented-out defines when looking for an existing one.
		$code = preg_replace( array( '#/\*.*?\*/#s', '#^\s*(//|\#).*$#m' ), '', $src );
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*([^)]*)\)/i', $code, $m ) ) {
			$value = strtolower( trim( $m[1] ) );
			return in_array( $value, array( 'true', '1' ), true ) ? array( 'already', $src ) : array( 'conflict', $src );
		}
		if ( ! preg_match( '/^<\?php[ \t]*\r?\n/', $src, $open ) ) {
			return array( 'unsafe', $src );
		}
		$rest = substr( $src, strlen( $open[0] ) );
		if ( preg_match( '/^\s*(declare|namespace)\b/i', $rest ) ) {
			return array( 'unsafe', $src );
		}
		$eol = false !== strpos( $open[0], "\r\n" ) ? "\r\n" : "\n";
		return array( 'added', $open[0] . "define( 'WP_CACHE', true ); " . self::MARKER . $eol . $rest );
	}

	/** Pure: remove only the line this plugin added. */
	public static function config_without_cache( string $src ): string {
		return (string) preg_replace( '/^[^\r\n]*' . preg_quote( self::MARKER, '/' ) . '[^\r\n]*\R?/m', '', $src );
	}

	/** True if PHP can parse $src (guards against ever writing a broken wp-config). */
	public static function parses( string $src ): bool {
		if ( ! function_exists( 'token_get_all' ) ) {
			return false; // Cannot verify, so do not edit.
		}
		try {
			token_get_all( $src, TOKEN_PARSE );
			return true;
		} catch ( \ParseError $e ) {
			return false;
		}
	}

	/**
	 * Add or remove our WP_CACHE line.
	 *
	 * @return string 'added'|'removed'|'already'|'conflict'|'unsafe'|'not_writable'|'failed'|'missing'
	 */
	public static function set_wp_cache( bool $on ): string {
		$path = self::wp_config_path();
		if ( ! $path ) {
			return 'missing';
		}
		$src = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $src ) {
			return 'failed';
		}
		if ( $on ) {
			list( $status, $new ) = self::config_with_cache( $src );
			if ( 'added' !== $status ) {
				return $status;
			}
		} else {
			if ( false === strpos( $src, self::MARKER ) ) {
				return 'already';
			}
			$new    = self::config_without_cache( $src );
			$status = 'removed';
		}
		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'not_writable';
		}
		if ( ! self::parses( $new ) ) {
			return 'failed'; // Refuse rather than risk breaking the site.
		}
		$tmp = $path . '.vc-tmp';
		if ( false === file_put_contents( $tmp, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'failed';
		}
		Fs::chmod( $tmp, fileperms( $path ) & 0777 );
		if ( ! Fs::move( $tmp, $path ) ) {
			Fs::delete( $tmp );
			return 'failed';
		}
		return $status;
	}

	/* ---------- Status for the admin screen ---------- */

	public static function status(): array {
		return array(
			'dropin'    => self::dropin_state(),
			'wp_cache'  => defined( 'WP_CACHE' ) && WP_CACHE,
			'writable'  => wp_mkdir_p( VELOCITY_CACHE_BASE ) && is_writable( VELOCITY_CACHE_BASE ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			'config_ok' => is_file( VELOCITY_CACHE_BASE . '/config.php' ),
		);
	}
}