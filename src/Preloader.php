<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/**
 * Background cache warming + expiry cleanup, both on WP-Cron.
 *
 * Preloading never runs inside a visitor's or editor's request: purges only set
 * a flag and schedule a cron event. The sitemap is fetched and pages are
 * requested a few at a time, so shared hosting is not flooded.
 */
class Preloader {

	const BATCH_HOOK    = 'velocity_cache_preload_batch';
	const CLEANUP_HOOK  = 'velocity_cache_cleanup';
	const QUEUE_OPTION  = 'velocity_cache_preload_queue';
	const PENDING       = 'velocity_cache_preload_pending';
	const LOCK          = 'velocity_cache_preload_lock';
	const BATCH_SIZE    = 5;
	const MAX_URLS      = 1000;
	const MAX_SITEMAPS  = 30;
	const MAX_XML_BYTES = 2097152; // 2 MB per sitemap.

	public function boot(): void {
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'velocity_cache_purged_all', array( $this, 'start' ) );
		add_action( 'velocity_cache_purged_urls', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'ensure_cleanup_scheduled' ) );
	}

	/** Called on deactivation. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		delete_option( self::QUEUE_OPTION );
		delete_option( self::PENDING );
		delete_transient( self::LOCK );
	}

	private function enabled(): bool {
		$s = Settings::get();
		return ! empty( $s['enabled'] ) && ! empty( $s['preload'] );
	}

	/** Request a full preload. Cheap: only sets a flag and schedules cron. */
	public function start(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		update_option( self::PENDING, 1, false );
		$this->schedule( 10 );
	}

	/**
	 * Put specific URLs at the front of the queue (used after a post purge).
	 *
	 * @param string[] $urls URLs just purged.
	 */
	public function enqueue( $urls ): void {
		if ( ! $this->enabled() || ! is_array( $urls ) ) {
			return;
		}
		$urls  = self::same_host( array_map( 'strval', $urls ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		$queue = array_slice( array_values( array_unique( array_merge( $urls, $queue ) ) ), 0, self::MAX_URLS );
		update_option( self::QUEUE_OPTION, $queue, false );
		$this->schedule( 10 );
	}

	private function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::BATCH_HOOK );
		}
	}

	/** Cron callback: build the queue if requested, then warm a few pages. */
	public function run_batch(): void {
		if ( ! $this->enabled() ) {
			delete_option( self::QUEUE_OPTION );
			delete_option( self::PENDING );
			return;
		}
		if ( get_transient( self::LOCK ) ) {
			return; // Another batch is running.
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		try {
			if ( get_option( self::PENDING ) ) {
				delete_option( self::PENDING );
				update_option( self::QUEUE_OPTION, $this->discover(), false );
			}
			$queue    = (array) get_option( self::QUEUE_OPTION, array() );
			$deadline = time() + 20;
			$done     = 0;

			while ( $queue && $done < self::BATCH_SIZE && time() < $deadline ) {
				$url = (string) array_shift( $queue );
				if ( $this->is_fresh( $url ) ) {
					continue;
				}
				$this->fetch( $url );
				++$done;
				usleep( 200000 ); // Be polite: 0.2 s between requests.
			}
			update_option( self::QUEUE_OPTION, $queue, false );
			if ( $queue ) {
				$this->schedule( 15 );
			}
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/** Remove expired cache files so deleted pages do not pile up on disk. */
	public function cleanup(): void {
		$s = Settings::get();
		if ( (int) $s['ttl_hours'] > 0 ) {
			CacheStore::purge_expired( (int) $s['ttl_hours'] * HOUR_IN_SECONDS );
		}
	}

	public function ensure_cleanup_scheduled(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/* ---------- Requests ---------- */

	private function is_fresh( string $url ): bool {
		$variant = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 'https' : 'http';
		$file    = CacheStore::path_for_url( $url, $variant );
		if ( ! $file || ! is_file( $file ) ) {
			return false;
		}
		$ttl = (int) Settings::get()['ttl_hours'] * HOUR_IN_SECONDS;
		return 0 === $ttl || ( time() - (int) filemtime( $file ) ) < $ttl;
	}

	/** Anonymous GET with no cookies, so the drop-in treats it as a normal visitor. */
	private function fetch( string $url ): void {
		wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'velocity_cache_preload_sslverify', false ), // Same default core uses for self-requests.
				'user-agent'  => 'VelocityCachePreload/' . VELOCITY_CACHE_VERSION,
			)
		);
	}

	/* ---------- Discovery ---------- */

	/** @return string[] URLs to warm, same host only, capped. */
	public function discover(): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array();

		$sitemaps = (array) apply_filters(
			'velocity_cache_sitemaps',
			array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ) )
		);
		foreach ( $sitemaps as $sitemap ) {
			$budget = self::MAX_SITEMAPS;
			$urls   = $this->read_sitemap( (string) $sitemap, $host, 0, $budget );
			if ( $urls ) {
				break;
			}
		}
		if ( ! $urls ) {
			$urls = $this->fallback_urls();
		}
		array_unshift( $urls, home_url( '/' ) );

		$limit = (int) apply_filters( 'velocity_cache_preload_limit', self::MAX_URLS );
		return array_slice( self::same_host( array_values( array_unique( $urls ) ), $host ), 0, max( 1, $limit ) );
	}

	/** @return string[] */
	private function read_sitemap( string $url, string $host, int $depth, int &$budget ): array {
		if ( $budget <= 0 || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return array();
		}
		--$budget;
		$res = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'sslverify'           => (bool) apply_filters( 'velocity_cache_preload_sslverify', false ),
				'limit_response_size' => self::MAX_XML_BYTES,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$parsed = self::parse_sitemap( (string) wp_remote_retrieve_body( $res ) );
		if ( 'urlset' === $parsed['type'] ) {
			return $parsed['urls'];
		}
		$urls = array();
		if ( 'index' === $parsed['type'] && $depth < 2 ) {
			foreach ( $parsed['urls'] as $child ) {
				$urls = array_merge( $urls, $this->read_sitemap( $child, $host, $depth + 1, $budget ) );
				if ( count( $urls ) >= self::MAX_URLS ) {
					break;
				}
			}
		}
		return $urls;
	}

	/** @return string[] */
	private function fallback_urls(): array {
		$ids  = get_posts(
			array(
				'post_type'     => array_values( get_post_types( array( 'public' => true ) ) ),
				'post_status'   => 'publish',
				'numberposts'   => 50,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$urls = array();
		foreach ( $ids as $id ) {
			$urls[] = (string) get_permalink( $id );
		}
		return $urls;
	}

	/**
	 * Pure: read a sitemap or sitemap index.
	 *
	 * @return array{type:string,urls:string[]} type is 'urlset', 'index' or 'none'.
	 */
	public static function parse_sitemap( string $xml ): array {
		$none = array(
			'type' => 'none',
			'urls' => array(),
		);
		if ( '' === trim( $xml ) ) {
			return $none;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $doc ) {
			return $none;
		}
		$root = $doc->getName();
		$urls = array();
		if ( 'urlset' === $root ) {
			foreach ( $doc->url as $entry ) {
				$urls[] = trim( (string) $entry->loc );
			}
			$type = 'urlset';
		} elseif ( 'sitemapindex' === $root ) {
			foreach ( $doc->sitemap as $entry ) {
				$urls[] = trim( (string) $entry->loc );
			}
			$type = 'index';
		} else {
			return $none;
		}
		$urls = array_values( array_filter( $urls, static function ( $u ) {
			return (bool) preg_match( '#^https?://#i', $u );
		} ) );
		return array(
			'type' => $type,
			'urls' => $urls,
		);
	}

	/**
	 * Pure: keep only http(s) URLs on our own host (blocks SSRF via a hostile sitemap).
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	public static function same_host( array $urls, string $host ): array {
		$out = array();
		foreach ( $urls as $u ) {
			$p = wp_parse_url( $u );
			if ( ! empty( $p['host'] ) && ! empty( $p['scheme'] ) && in_array( $p['scheme'], array( 'http', 'https' ), true ) && strtolower( $p['host'] ) === strtolower( $host ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}
}