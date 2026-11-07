<?php
namespace CacheShark;

defined( 'ABSPATH' ) || exit;

/** Settings page, status panel, "Clear cache" / "Preload now" actions and the admin-bar shortcut. */
class Admin {

	const PAGE       = 'cache-shark';
	const GROUP      = 'cache_shark';
	const CAPABILITY = 'manage_options';

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_cache_shark_purge', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_cache_shark_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CACHE_SHARK_FILE ), array( $this, 'action_links' ) );
	}

	/* ---------- Registration ---------- */

	public function add_page(): void {
		add_options_page(
			__( 'Cache Shark', 'cache-shark' ),
			__( 'Cache Shark', 'cache-shark' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cache-shark' ) . '</a>' );
		return $links;
	}

	/* ---------- Actions (admin-post.php) ---------- */

	public function handle_purge(): void {
		$this->guard( 'cache_shark_purge' );
		CacheStore::purge_all();
		do_action( 'cache_shark_purged_all' ); // Lets the preloader re-warm the cache.
		$this->back( 'purged' );
	}

	public function handle_preload(): void {
		$this->guard( 'cache_shark_preload' );
		( new Preloader() )->start();
		$this->back( 'preloading' );
	}

	/** Every state-changing request needs a capability check AND a nonce. */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cache-shark' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function back( string $notice ): void {
		$fallback = admin_url( 'options-general.php?page=' . self::PAGE );
		$target   = wp_get_referer() ? wp_get_referer() : $fallback;
		wp_safe_redirect( add_query_arg( 'cs_notice', $notice, $target ) );
		exit;
	}

	/* ---------- Admin bar ---------- */

	public function admin_bar( $bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'cache-shark',
				'title' => esc_html__( 'Clear cache', 'cache-shark' ),
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=cache_shark_purge' ), 'cache_shark_purge' ),
			)
		);
	}

	/* ---------- Notices ---------- */

	public function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$messages = array(
			'purged'     => __( 'Cache cleared.', 'cache-shark' ),
			'preloading' => __( 'Preloading started. Pages are warmed in the background a few at a time.', 'cache-shark' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only, whitelisted key, output escaped.
		$key = isset( $_GET['cs_notice'] ) ? sanitize_key( wp_unslash( $_GET['cs_notice'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_' . self::PAGE ), true ) ) {
			return;
		}
		foreach ( $this->problems() as $problem ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Cache Shark:', 'cache-shark' ) . '</strong> ' . wp_kses( $problem, array( 'code' => array() ) ) . '</p></div>';
		}
	}

	/** @return string[] Human-readable problems (may contain <code>). */
	private function problems(): array {
		$s     = Installer::status();
		$out   = array();
		if ( 'foreign' === $s['dropin'] ) {
			$out[] = esc_html__( 'Another plugin already uses wp-content/advanced-cache.php, so page caching is paused. Deactivate the other caching plugin, then deactivate and reactivate Cache Shark.', 'cache-shark' );
		} elseif ( 'missing' === $s['dropin'] ) {
			$out[] = esc_html__( 'The cache drop-in could not be installed. Make sure wp-content is writable, then deactivate and reactivate the plugin.', 'cache-shark' );
		}
		if ( ! $s['wp_cache'] ) {
			$out[] = esc_html__( 'WP_CACHE is not enabled. Add this line to wp-config.php, above the line that says "That\'s all, stop editing":', 'cache-shark' ) . " <code>define( 'WP_CACHE', true );</code>";
		}
		if ( ! $s['writable'] ) {
			$out[] = esc_html__( 'The cache folder wp-content/cache/cache-shark is not writable.', 'cache-shark' );
		}
		return $out;
	}

	/* ---------- Settings page ---------- */

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$o     = Settings::get();
		$name  = Settings::OPTION;
		$stats = CacheStore::stats();
		$s     = Installer::status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cache Shark', 'cache-shark' ); ?></h1>

			<h2><?php esc_html_e( 'Status', 'cache-shark' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><td><?php esc_html_e( 'Cache drop-in', 'cache-shark' ); ?></td><td><?php echo esc_html( $this->yes_no( 'ours' === $s['dropin'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'WP_CACHE enabled', 'cache-shark' ); ?></td><td><?php echo esc_html( $this->yes_no( $s['wp_cache'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Cache folder writable', 'cache-shark' ); ?></td><td><?php echo esc_html( $this->yes_no( $s['writable'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Server rules (GZIP, browser cache)', 'cache-shark' ); ?></td><td><?php echo esc_html( Htaccess::is_supported() ? __( 'Apache / LiteSpeed: active', 'cache-shark' ) : __( 'Not Apache: skipped', 'cache-shark' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Cached pages', 'cache-shark' ); ?></td><td><?php echo esc_html( number_format_i18n( $stats['files'] ) . ' (' . size_format( $stats['bytes'] ) . ')' ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<p class="description"><?php esc_html_e( 'WP-Cron is disabled on this site. Preloading and cleanup need a server cron job that requests wp-cron.php.', 'cache-shark' ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cache_shark_purge' ), 'cache_shark_purge' ) ); ?>"><?php esc_html_e( 'Clear all cache', 'cache-shark' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cache_shark_preload' ), 'cache_shark_preload' ) ); ?>"><?php esc_html_e( 'Preload now', 'cache-shark' ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Settings', 'cache-shark' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page caching', 'cache-shark' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( 1, (int) $o['enabled'] ); ?>> <?php esc_html_e( 'Enable page caching', 'cache-shark' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-ttl"><?php esc_html_e( 'Cache lifespan (hours)', 'cache-shark' ); ?></label></th>
						<td>
							<input id="vc-ttl" type="number" min="0" max="720" class="small-text" name="<?php echo esc_attr( $name ); ?>[ttl_hours]" value="<?php echo esc_attr( (string) (int) $o['ttl_hours'] ); ?>">
							<p class="description"><?php esc_html_e( 'Keep this under 12. WordPress security tokens (nonces) stop working after 12 to 24 hours, and a cached page can contain one. 0 means never expire.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preload', 'cache-shark' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[preload]" value="1" <?php checked( 1, (int) $o['preload'] ); ?>> <?php esc_html_e( 'Warm the cache from the sitemap in the background', 'cache-shark' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile', 'cache-shark' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[separate_mobile]" value="1" <?php checked( 1, (int) $o['separate_mobile'] ); ?>> <?php esc_html_e( 'Keep a separate cache for mobile devices', 'cache-shark' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only needed if your theme serves different HTML to phones. Responsive themes do not need it.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazy loading', 'cache-shark' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[lazy_images]" value="1" <?php checked( 1, (int) $o['lazy_images'] ); ?>> <?php esc_html_e( 'Lazy-load images', 'cache-shark' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[lazy_iframes]" value="1" <?php checked( 1, (int) $o['lazy_iframes'] ); ?>> <?php esc_html_e( 'Lazy-load iframes (embedded videos, maps)', 'cache-shark' ); ?></label>
							<p class="description"><?php esc_html_e( 'Offscreen images and embeds load only when the visitor scrolls near them. WordPress already does this for images in post content; this also covers images from your theme and widgets. Applied to cached pages only, never to logged-in users.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-skip"><?php esc_html_e( 'Skip the first images', 'cache-shark' ); ?></label></th>
						<td>
							<input id="vc-skip" type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr( $name ); ?>[lazy_skip_first]" value="<?php echo esc_attr( (string) (int) $o['lazy_skip_first'] ); ?>">
							<p class="description"><?php esc_html_e( 'Images at the top of the page must show immediately. The first images on each page (default 2) are never lazy-loaded.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-lazy-exclude"><?php esc_html_e( 'Never lazy-load these', 'cache-shark' ); ?></label></th>
						<td>
							<textarea id="vc-lazy-exclude" rows="4" class="large-text code" name="<?php echo esc_attr( $name ); ?>[lazy_exclude]"><?php echo esc_textarea( implode( "\n", (array) $o['lazy_exclude'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Any image or iframe whose HTML contains this text is skipped, for example a class name such as hero-image or a file name such as logo.png.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-urls"><?php esc_html_e( 'Never cache these URLs', 'cache-shark' ); ?></label></th>
						<td>
							<textarea id="vc-urls" rows="5" class="large-text code" name="<?php echo esc_attr( $name ); ?>[exclude_urls]"><?php echo esc_textarea( implode( "\n", (array) $o['exclude_urls'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Use * as a wildcard, for example /my-account/* or /checkout*.', 'cache-shark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-cookies"><?php esc_html_e( 'Never cache when these cookies exist', 'cache-shark' ); ?></label></th>
						<td>
							<textarea id="vc-cookies" rows="4" class="large-text code" name="<?php echo esc_attr( $name ); ?>[exclude_cookies]"><?php echo esc_textarea( implode( "\n", (array) $o['exclude_cookies'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One cookie name (or name prefix) per line. Login, comment-author and WooCommerce cart cookies are always excluded.', 'cache-shark' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function yes_no( bool $value ): string {
		return $value ? __( 'Yes', 'cache-shark' ) : __( 'No', 'cache-shark' );
	}
}
