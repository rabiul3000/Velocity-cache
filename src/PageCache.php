<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-miss path: capture the rendered page and write it to disk.
 *
 * The drop-in already decided the *request* is cacheable and recorded where to
 * write it ($GLOBALS['velocity_cache_target']). This class adds the checks that
 * are only possible once WordPress has parsed the request and rendered the page.
 */
class PageCache {

	public function boot(): void {
		// Priority 100: after core's canonical redirect (10), so redirects are never buffered.
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 100 );
	}

	public function maybe_start(): void {
		$target = $GLOBALS['velocity_cache_target'] ?? '';
		if ( '' === $target || 0 !== strpos( $target, VELOCITY_CACHE_BASE . '/' ) ) {
			return; // Drop-in did not run, or target is outside our folder: do nothing.
		}
		if ( ! $this->request_is_cacheable() ) {
			return;
		}
		ob_start( array( $this, 'capture' ) );
	}

	private function request_is_cacheable(): bool {
		$ok = ! is_user_logged_in()
			&& ! is_404() && ! is_search() && ! is_feed() && ! is_preview()
			&& ! is_trackback() && ! is_robots() && ! is_embed() && ! is_customize_preview()
			&& ! is_admin() && ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE )
			&& 'utf-8' === strtolower( (string) get_bloginfo( 'charset' ) ); // Drop-in serves UTF-8.

		if ( $ok && is_singular() && post_password_required() ) {
			$ok = false;
		}
		// WooCommerce: pages that are personal by nature.
		if ( $ok && function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			$ok = false;
		}
		/**
		 * Filter: return false to keep the current page out of the cache.
		 *
		 * @param bool $ok Whether the page may be cached.
		 */
		return (bool) apply_filters( 'velocity_cache_should_cache', $ok );
	}

	/**
	 * Output-buffer callback. Must always return the buffer unchanged so the
	 * visitor sees the page even if caching fails.
	 *
	 * @param string $buffer Rendered page.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
	 */
	public function capture( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		try {
			if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
				return $buffer;
			}
			$target = $GLOBALS['velocity_cache_target'] ?? '';
			$status = (int) http_response_code();
			if ( '' !== $target && self::should_store( (string) $buffer, $status, headers_list() ) && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
				CacheStore::write_file(
					$target,
					$buffer . "\n<!-- Cached by Velocity Cache on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n"
				);
			}
		} catch ( \Throwable $e ) {
			// Never break the page because caching failed.
			unset( $e );
		}
		return $buffer;
	}

	/**
	 * Pure decision on the finished response (unit-testable).
	 *
	 * @param string   $buffer  Response body.
	 * @param int      $status  HTTP status code.
	 * @param string[] $headers Raw header lines, e.g. "Set-Cookie: a=b".
	 */
	public static function should_store( string $buffer, int $status, array $headers ): bool {
		if ( 200 !== $status || strlen( $buffer ) < 255 || false === stripos( $buffer, '</html' ) ) {
			return false;
		}
		foreach ( $headers as $line ) {
			$line = strtolower( $line );
			if ( 0 === strpos( $line, 'set-cookie:' ) || 0 === strpos( $line, 'location:' ) ) {
				return false; // Personalised or redirecting response.
			}
			if ( 0 === strpos( $line, 'cache-control:' ) && preg_match( '/no-store|private/', $line ) ) {
				return false;
			}
			if ( 0 === strpos( $line, 'content-type:' ) && false === strpos( $line, 'text/html' ) ) {
				return false;
			}
		}
		return true;
	}
}