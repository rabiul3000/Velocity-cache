<?php
namespace CacheShark;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-miss path: capture the rendered page and write it to disk.
 *
 * The drop-in already decided the *request* is cacheable and recorded where to
 * write it ($GLOBALS['cache_shark_target']). This class adds the checks that
 * are only possible once WordPress has parsed the request and rendered the page.
 */
class PageCache {

	public function boot(): void {
		// Priority 100: after core's canonical redirect (10), so redirects are never captured.
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 100 );
	}

	public function maybe_start(): void {
		$target = $GLOBALS['cache_shark_target'] ?? '';
		if ( '' === $target || 0 !== strpos( $target, CACHE_SHARK_BASE . '/' ) ) {
			return; // Drop-in did not run, or target is outside our folder: do nothing.
		}
		if ( ! $this->request_is_cacheable() ) {
			return;
		}
		// WordPress 6.9+ owns the output buffer and hands us the finished page. We never open or close one ourselves.
		add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'capture' ), 1000 );
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
		return (bool) apply_filters( 'cache_shark_should_cache', $ok );
	}

	/**
	 * Filter callback for the finished page. It always returns the page
	 * unchanged, so the visitor sees it even if caching fails.
	 *
	 * @param string $buffer Rendered page.
	 * @return string
	 */
	public function capture( $buffer ) {
		try {
			$target = $GLOBALS['cache_shark_target'] ?? '';
			$status = (int) http_response_code();
			if ( '' !== $target && self::should_store( (string) $buffer, $status, headers_list() ) && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
				// Optimise only pages that are being cached. The visitor who triggers the
				// cache write gets exactly the page every later visitor will get.
				$out = Optimizer::run( (string) $buffer );
				CacheStore::write_file(
					$target,
					$out . "\n<!-- Cached by Cache Shark on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n"
				);
				return $out;
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
