<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/** Settings page, status panel, "Clear cache" / "Preload now" actions and the admin-bar shortcut. */
class Admin {

	const PAGE       = 'velocity-cache';
	const GROUP      = 'velocity_cache';
	const CAPABILITY = 'manage_options';

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_velocity_cache_purge', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_velocity_cache_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VELOCITY_CACHE_FILE ), array( $this, 'action_links' ) );
	}

	/* ---------- Registration ---------- */

	public function add_page(): void {
		add_options_page(
			__( 'Velocity Cache', 'velocity-cache' ),
			__( 'Velocity Cache', 'velocity-cache' ),
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
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'velocity-cache' ) . '</a>' );
		return $links;
	}

	/* ---------- Actions (admin-post.php) ---------- */

	public function handle_purge(): void {
		$this->guard( 'velocity_cache_purge' );
		CacheStore::purge_all();
		do_action( 'velocity_cache_purged_all' ); // Lets the preloader re-warm the cache.
		$this->back( 'purged' );
	}

	public function handle_preload(): void {
		$this->guard( 'velocity_cache_preload' );
		( new Preloader() )->start();
		$this->back( 'preloading' );
	}

	/** Every state-changing request needs a capability check AND a nonce. */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'velocity-cache' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function back( string $notice ): void {
		$fallback = admin_url( 'options-general.php?page=' . self::PAGE );
		$target   = wp_get_referer() ? wp_get_referer() : $fallback;
		wp_safe_redirect( add_query_arg( 'vc_notice', $notice, $target ) );
		exit;
	}

	/* ---------- Admin bar ---------- */

	public function admin_bar( $bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'velocity-cache',
				'title' => esc_html__( 'Clear cache', 'velocity-cache' ),
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=velocity_cache_purge' ), 'velocity_cache_purge' ),
			)
		);
	}

	/* ---------- Notices ---------- */

	public function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$messages = array(
			'purged'     => __( 'Cache cleared.', 'velocity-cache' ),
			'preloading' => __( 'Preloading started. Pages are warmed in the background a few at a time.', 'velocity-cache' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only, whitelisted key, output escaped.
		$key = isset( $_GET['vc_notice'] ) ? sanitize_key( wp_unslash( $_GET['vc_notice'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_' . self::PAGE ), true ) ) {
			return;
		}
		foreach ( $this->problems() as $problem ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Velocity Cache:', 'velocity-cache' ) . '</strong> ' . wp_kses( $problem, array( 'code' => array() ) ) . '</p></div>';
		}
	}

	/** @return string[] Human-readable problems (may contain <code>). */
	private function problems(): array {
		$s     = Installer::status();
		$out   = array();
		if ( 'foreign' === $s['dropin'] ) {
			$out[] = esc_html__( 'Another plugin already uses wp-content/advanced-cache.php, so page caching is paused. Deactivate the other caching plugin, then deactivate and reactivate Velocity Cache.', 'velocity-cache' );
		} elseif ( 'missing' === $s['dropin'] ) {
			$out[] = esc_html__( 'The cache drop-in could not be installed. Make sure wp-content is writable, then deactivate and reactivate the plugin.', 'velocity-cache' );
		}
		if ( ! $s['wp_cache'] ) {
			$out[] = esc_html__( 'WP_CACHE is not enabled. Add this line to wp-config.php, above the line that says "That\'s all, stop editing":', 'velocity-cache' ) . " <code>define( 'WP_CACHE', true );</code>";
		}
		if ( ! $s['writable'] ) {
			$out[] = esc_html__( 'The cache folder wp-content/cache/velocity-cache is not writable.', 'velocity-cache' );
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
			<h1><?php esc_html_e( 'Velocity Cache', 'velocity-cache' ); ?></h1>

			<h2><?php esc_html_e( 'Status', 'velocity-cache' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><td><?php esc_html_e( 'Cache drop-in', 'velocity-cache' ); ?></td><td><?php echo esc_html( $this->yes_no( 'ours' === $s['dropin'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'WP_CACHE enabled', 'velocity-cache' ); ?></td><td><?php echo esc_html( $this->yes_no( $s['wp_cache'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Cache folder writable', 'velocity-cache' ); ?></td><td><?php echo esc_html( $this->yes_no( $s['writable'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Server rules (GZIP, browser cache)', 'velocity-cache' ); ?></td><td><?php echo esc_html( Htaccess::is_supported() ? __( 'Apache / LiteSpeed: active', 'velocity-cache' ) : __( 'Not Apache: skipped', 'velocity-cache' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Cached pages', 'velocity-cache' ); ?></td><td><?php echo esc_html( number_format_i18n( $stats['files'] ) . ' (' . size_format( $stats['bytes'] ) . ')' ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<p class="description"><?php esc_html_e( 'WP-Cron is disabled on this site. Preloading and cleanup need a server cron job that requests wp-cron.php.', 'velocity-cache' ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=velocity_cache_purge' ), 'velocity_cache_purge' ) ); ?>"><?php esc_html_e( 'Clear all cache', 'velocity-cache' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=velocity_cache_preload' ), 'velocity_cache_preload' ) ); ?>"><?php esc_html_e( 'Preload now', 'velocity-cache' ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Settings', 'velocity-cache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page caching', 'velocity-cache' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( 1, (int) $o['enabled'] ); ?>> <?php esc_html_e( 'Enable page caching', 'velocity-cache' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-ttl"><?php esc_html_e( 'Cache lifespan (hours)', 'velocity-cache' ); ?></label></th>
						<td>
							<input id="vc-ttl" type="number" min="0" max="720" class="small-text" name="<?php echo esc_attr( $name ); ?>[ttl_hours]" value="<?php echo esc_attr( (string) (int) $o['ttl_hours'] ); ?>">
							<p class="description"><?php esc_html_e( 'Keep this under 12. WordPress security tokens (nonces) stop working after 12 to 24 hours, and a cached page can contain one. 0 means never expire.', 'velocity-cache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preload', 'velocity-cache' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[preload]" value="1" <?php checked( 1, (int) $o['preload'] ); ?>> <?php esc_html_e( 'Warm the cache from the sitemap in the background', 'velocity-cache' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile', 'velocity-cache' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[separate_mobile]" value="1" <?php checked( 1, (int) $o['separate_mobile'] ); ?>> <?php esc_html_e( 'Keep a separate cache for mobile devices', 'velocity-cache' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only needed if your theme serves different HTML to phones. Responsive themes do not need it.', 'velocity-cache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-urls"><?php esc_html_e( 'Never cache these URLs', 'velocity-cache' ); ?></label></th>
						<td>
							<textarea id="vc-urls" rows="5" class="large-text code" name="<?php echo esc_attr( $name ); ?>[exclude_urls]"><?php echo esc_textarea( implode( "\n", (array) $o['exclude_urls'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Use * as a wildcard, for example /my-account/* or /checkout*.', 'velocity-cache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vc-cookies"><?php esc_html_e( 'Never cache when these cookies exist', 'velocity-cache' ); ?></label></th>
						<td>
							<textarea id="vc-cookies" rows="4" class="large-text code" name="<?php echo esc_attr( $name ); ?>[exclude_cookies]"><?php echo esc_textarea( implode( "\n", (array) $o['exclude_cookies'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One cookie name (or name prefix) per line. Login, comment-author and WooCommerce cart cookies are always excluded.', 'velocity-cache' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function yes_no( bool $value ): string {
		return $value ? __( 'Yes', 'velocity-cache' ) : __( 'No', 'velocity-cache' );
	}
}