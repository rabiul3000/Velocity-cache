<?php
namespace CacheShark;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the enabled page optimizations on a page that is about to be cached.
 *
 * Every optimization is off until the site owner turns it on, runs only for
 * pages that are being cached (never for logged-in users), and can never break
 * a page: any failure returns the original HTML.
 */
class Optimizer {

	/** Settings-aware entry point used by PageCache. */
	public static function run( string $html ): string {
		/**
		 * Filter: return false to leave the current page unoptimized.
		 *
		 * @param bool $optimize Whether to optimize this page.
		 */
		if ( ! apply_filters( 'cache_shark_optimize', true ) ) {
			return $html;
		}
		return self::process( $html, Settings::get() );
	}

	/** Pure version (unit-testable): HTML + settings in, HTML out. */
	public static function process( string $html, array $settings ): string {
		if ( ! self::enabled( $settings ) ) {
			return $html;
		}
		try {
			$out = LazyLoad::apply(
				$html,
				array(
					'images'     => ! empty( $settings['lazy_images'] ),
					'iframes'    => ! empty( $settings['lazy_iframes'] ),
					'skip_first' => (int) ( $settings['lazy_skip_first'] ?? 2 ),
					'exclude'    => (array) ( $settings['lazy_exclude'] ?? array() ),
				)
			);
		} catch ( \Throwable $e ) {
			return $html;
		}
		// A page that had a closing </html> must still have one.
		if ( false !== stripos( $html, '</html' ) && false === stripos( $out, '</html' ) ) {
			return $html;
		}
		return $out;
	}

	public static function enabled( array $settings ): bool {
		return ! empty( $settings['lazy_images'] ) || ! empty( $settings['lazy_iframes'] );
	}
}
