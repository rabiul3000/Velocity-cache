<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/**
 * The few raw filesystem calls a page cache cannot avoid, in one place.
 *
 * Why not WP_Filesystem? A cache is written on ordinary front-end requests and
 * from cron, where WP_Filesystem cannot ask for FTP credentials, and it has no
 * atomic rename: visitors could read half-written pages. Deleting uses core's
 * wp_delete_file(). Each remaining raw call carries one annotated exception.
 */
class Fs {

	/** Atomic replace: the destination is either the old file or the complete new one. */
	public static function move( string $from, string $to ): bool {
		return @rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic replace, see class docblock.
	}

	public static function chmod( string $path, int $mode ): void {
		@chmod( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- keeps a file's original permissions after an atomic replace.
	}

	public static function rmdir( string $dir ): bool {
		return @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- removes our own empty cache folders; WP_Filesystem needs credentials.
	}

	/** Delete a file or symlink. Returns true if it is gone afterwards. */
	public static function delete( string $file ): bool {
		if ( ! file_exists( $file ) && ! is_link( $file ) ) {
			return true;
		}
		wp_delete_file( $file );
		return ! file_exists( $file ) && ! is_link( $file );
	}
}