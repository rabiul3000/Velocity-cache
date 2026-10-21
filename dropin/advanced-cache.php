<?php
/**
 * Velocity Cache drop-in (advanced-cache.php).
 *
 * Runs very early in wp-settings.php, before plugins load. It is deliberately
 * self-contained: it must keep working (and fail safe) even if the plugin
 * folder is missing or broken.
 *
 * VELOCITY_CACHE_DROPIN_VERSION: 0.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'VELOCITY_CACHE_DROPIN_VERSION' ) ) {
	define( 'VELOCITY_CACHE_DROPIN_VERSION', '0.1.0' );
}

if ( ! function_exists( 'velocity_cache_path_for' ) ) {

	/**
	 * Map host + URL path + variant to a cache file. Returns null when the
	 * URL cannot be mapped safely (never guess: collisions serve wrong pages).
	 */
	function velocity_cache_path_for( string $base, string $host, string $path, string $variant ): ?string {
		$host = strtolower( $host );
		if ( ! preg_match( '/^[a-z0-9.\-]+(:[0-9]+)?$/', $host ) ) {
			return null;
		}
		$host = str_replace( ':', '_', $host );

		// $path is the raw (still percent-encoded) URL path. Normalise hex case so
		// /caf%c3%a9/ and /caf%C3%A9/ share one file.
		$path = preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			$path
		);
		if ( strlen( $path ) > 600 ) {
			return null;
		}
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			// 200 chars: encoded non-Latin slugs are ~9 bytes per letter; filesystem limit is 255.
			if ( '.' === $segment || '..' === $segment || ! preg_match( '/^[A-Za-z0-9._~%\-]{1,200}$/', $segment ) ) {
				return null;
			}
			$segments[] = $segment;
		}
		if ( ! in_array( $variant, array( 'http', 'https', 'http-mobile', 'https-mobile' ), true ) ) {
			return null;
		}

		$dir = rtrim( $base, '/' ) . '/' . $host . ( $segments ? '/' . implode( '/', $segments ) : '' );
		return $dir . '/index-' . $variant . '.html';
	}

	/**
	 * Decide whether this request must bypass the cache.
	 * Returns a short reason string, or null if cacheable.
	 */
	function velocity_cache_bypass_reason( array $server, array $cookies, array $config ): ?string {
		if ( 'GET' !== ( $server['REQUEST_METHOD'] ?? '' ) ) {
			return 'method';
		}
		$uri = (string) ( $server['REQUEST_URI'] ?? '' );
		if ( '' === $uri || '/' !== $uri[0] ) {
			return 'uri';
		}

		$parts = explode( '?', $uri, 2 );
		$path  = rawurldecode( $parts[0] );
		$query = $parts[1] ?? '';

		if ( '' !== $query ) {
			parse_str( $query, $args );
			$ignored = '/^(utm_[a-z_]+|fbclid|gclid|msclkid|mc_cid|mc_eid|_ga|ref)$/i';
			foreach ( array_keys( $args ) as $key ) {
				if ( ! preg_match( $ignored, (string) $key ) ) {
					return 'query-string';
				}
			}
		}

		$always = array( '/wp-admin', '/wp-json', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-comments-post.php', '/wp-signup.php', '/wp-activate.php' );
		foreach ( $always as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return 'system-url';
			}
		}
		if ( preg_match( '#/feed(/|$)#', $path ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'feed-or-ajax';
		}

		foreach ( (array) ( $config['exclude_urls'] ?? array() ) as $pattern ) {
			if ( '' !== $pattern && fnmatch( $pattern, $path ) ) {
				return 'excluded-url';
			}
		}

		$prefixes = array_merge(
			array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'edd_items_in_cart' ),
			(array) ( $config['exclude_cookies'] ?? array() )
		);
		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( (string) $name, $prefix ) ) {
					return 'cookie';
				}
			}
		}
		return null;
	}

	function velocity_cache_variant( array $server, array $config ): string {
		$https  = ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		$https  = $https || ( '443' === (string) ( $server['SERVER_PORT'] ?? '' ) );
		$https  = $https || ( 'https' === strtolower( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) );
		$mobile = ! empty( $config['separate_mobile'] )
			&& preg_match( '/Mobile|Android|iPhone|iPad|Silk|Opera Mini/i', (string) ( $server['HTTP_USER_AGENT'] ?? '' ) );
		return ( $https ? 'https' : 'http' ) . ( $mobile ? '-mobile' : '' );
	}

	/**
	 * Read one $_SERVER value as a clean string ('' if missing).
	 *
	 * This file runs before WordPress loads, so wp_unslash() and sanitize_*() do
	 * not exist yet. Control characters are stripped here, and the value is then
	 * strictly validated by velocity_cache_path_for() before it touches the disk.
	 */
	function velocity_cache_server_value( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- see the docblock above.
		$value = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : '';
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
	}

	function velocity_cache_dropin_run(): void {
		if ( 'cli' === PHP_SAPI || ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}
		$base   = WP_CONTENT_DIR . '/cache/velocity-cache';
		$config = @include $base . '/config.php';
		if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
			return;
		}
		if ( null !== velocity_cache_bypass_reason( $_SERVER, $_COOKIE, $config ) ) {
			return;
		}

		$uri  = explode( '?', velocity_cache_server_value( 'REQUEST_URI' ), 2 )[0];
		$host = velocity_cache_server_value( 'HTTP_HOST' );
		$file = velocity_cache_path_for( $base, $host, $uri, velocity_cache_variant( $_SERVER, $config ) );
		if ( null === $file ) {
			return;
		}

		// Tell the plugin (loaded later) where to write this page if it is a miss.
		$GLOBALS['velocity_cache_target'] = $file;

		$mtime = is_file( $file ) ? (int) @filemtime( $file ) : 0;
		$ttl   = (int) ( $config['ttl'] ?? 0 );
		if ( ! $mtime || ( $ttl > 0 && ( time() - $mtime ) > $ttl ) ) {
			header( 'X-Velocity-Cache: MISS' );
			return;
		}

		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$ims           = velocity_cache_server_value( 'HTTP_IF_MODIFIED_SINCE' );
		if ( '' !== $ims && @strtotime( $ims ) >= $mtime ) {
			header( 'X-Velocity-Cache: HIT' );
			header( 'Last-Modified: ' . $last_modified );
			http_response_code( 304 );
			exit;
		}

		// Read before sending headers: if the file vanished (a purge just ran), fall through to WordPress.
		$html = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $html ) {
			header( 'X-Velocity-Cache: MISS' );
			return;
		}
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Velocity-Cache: HIT' );
		header( 'Last-Modified: ' . $last_modified );
		header( 'Cache-Control: no-cache, must-revalidate' ); // Browser always revalidates; 304s are cheap.
		header( 'Content-Length: ' . strlen( $html ) );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- this is a saved copy of a page WordPress already rendered and escaped.
		exit;
	}
}

// The plugin includes this file only to reuse the helper functions above.
if ( ! defined( 'VELOCITY_CACHE_LOADING_MAPPER' ) ) {
	velocity_cache_dropin_run();
}