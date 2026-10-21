<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/** Filesystem layer: path mapping, atomic writes, purging. */
class CacheStore {

	/** Cache file for a URL + variant, using the drop-in's own mapper (single source of truth). */
	public static function path_for_url( string $url, string $variant ): ?string {
		self::load_mapper();
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}
		$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return velocity_cache_path_for( VELOCITY_CACHE_BASE, $host, $parts['path'] ?? '/', $variant );
	}

	private static function load_mapper(): void {
		if ( ! function_exists( 'velocity_cache_path_for' ) ) {
			// This constant makes the drop-in define its helper functions without serving a page.
			if ( ! defined( 'VELOCITY_CACHE_LOADING_MAPPER' ) ) {
				define( 'VELOCITY_CACHE_LOADING_MAPPER', true );
			}
			require_once VELOCITY_CACHE_DIR . 'dropin/advanced-cache.php';
		}
	}

	/** Atomic write: temp file in same dir, then rename. */
	public static function write_file( string $file, string $contents ): bool {
		$dir = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		self::protect_root();
		$tmp = $dir . '/.tmp-' . wp_generate_password( 12, false );
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		Fs::chmod( $tmp, 0644 );
		if ( ! Fs::move( $tmp, $file ) ) {
			Fs::delete( $tmp );
			return false;
		}
		return true;
	}

	private static function protect_root(): void {
		$index = VELOCITY_CACHE_BASE . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/** Delete every cached variant for one URL (not its children). */
	public static function purge_url( string $url ): void {
		foreach ( array( 'http', 'https', 'http-mobile', 'https-mobile' ) as $variant ) {
			$file = self::path_for_url( $url, $variant );
			if ( $file && is_file( $file ) ) {
				Fs::delete( $file );
			}
		}
	}

	/** Delete the /page/N/ children of a URL (paginated archives). */
	public static function purge_pagination( string $url ): void {
		$file = self::path_for_url( $url, 'https' );
		if ( ! $file ) {
			return;
		}
		$dir = dirname( $file ) . '/page';
		if ( is_dir( $dir ) ) {
			self::rrmdir( $dir );
		}
	}

	/** Delete everything (pages only; config.php is preserved). */
	public static function purge_all(): void {
		if ( ! is_dir( VELOCITY_CACHE_BASE ) ) {
			return;
		}
		foreach ( new \DirectoryIterator( VELOCITY_CACHE_BASE ) as $item ) {
			if ( $item->isDot() || ! $item->isDir() ) {
				continue;
			}
			self::rrmdir( $item->getPathname() );
		}
	}

	/**
	 * Delete cached pages older than $ttl seconds, and stale temp files.
	 *
	 * @return int Number of pages removed.
	 */
	public static function purge_expired( int $ttl ): int {
		if ( $ttl <= 0 || ! is_dir( VELOCITY_CACHE_BASE ) ) {
			return 0;
		}
		$cutoff  = time() - $ttl;
		$removed = 0;
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( VELOCITY_CACHE_BASE, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$name = $f->getFilename();
			if ( preg_match( '/^index-(https?)(-mobile)?\.html$/', $name ) && $f->getMTime() < $cutoff ) {
				if ( Fs::delete( $f->getPathname() ) ) {
					++$removed;
				}
			} elseif ( 0 === strpos( $name, '.tmp-' ) && $f->getMTime() < time() - HOUR_IN_SECONDS ) {
				Fs::delete( $f->getPathname() );
			}
		}
		return $removed;
	}

	/** Remove the whole cache tree incl. config (used on uninstall). */
	public static function destroy(): void {
		if ( is_dir( VELOCITY_CACHE_BASE ) ) {
			self::rrmdir( VELOCITY_CACHE_BASE );
		}
	}

	private static function rrmdir( string $dir ): void {
		if ( is_link( $dir ) ) {
			Fs::delete( $dir ); // Never follow symlinks out of the cache tree.
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				Fs::rmdir( $f->getPathname() );
			} else {
				Fs::delete( $f->getPathname() );
			}
		}
		Fs::rmdir( $dir );
	}

	/** @return array{files:int,bytes:int} */
	public static function stats(): array {
		$files = 0;
		$bytes = 0;
		if ( is_dir( VELOCITY_CACHE_BASE ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( VELOCITY_CACHE_BASE, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( '.html' === substr( $f->getFilename(), -5 ) ) {
					++$files;
					$bytes += $f->getSize();
				}
			}
		}
		return compact( 'files', 'bytes' );
	}
}