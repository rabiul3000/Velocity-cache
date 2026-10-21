<?php
namespace VelocityCache;

defined( 'ABSPATH' ) || exit;

/** Decides which cached pages to delete when content or site settings change. */
class Purger {

	/** Options that change how every page looks. */
	const GLOBAL_OPTIONS = array(
		'sidebars_widgets',
		'permalink_structure',
		'category_base',
		'tag_base',
		'blogname',
		'blogdescription',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'date_format',
		'time_format',
		'stylesheet',
		'template',
	);

	private static $purged_all = false;

	public function boot(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );

		foreach ( array( 'comment_post', 'edit_comment', 'wp_set_comment_status', 'trashed_comment', 'untrashed_comment', 'deleted_comment', 'spammed_comment', 'unspammed_comment' ) as $hook ) {
			add_action( $hook, array( $this, 'on_comment' ) );
		}

		add_action( 'edited_term', array( $this, 'on_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'purge_all' ) );

		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'import_end' ) as $hook ) {
			add_action( $hook, array( $this, 'purge_all' ) );
		}
		add_action( 'updated_option', array( $this, 'on_option_updated' ) );
	}

	/* ---------- Event handlers ---------- */

	public function on_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return; // Drafts and other private states are never cached.
		}
		$this->purge_post( $post );
	}

	/** Slug or parent changed: the OLD URL must go too. */
	public function on_post_updated( $post_id, $after, $before ): void {
		if ( 'publish' !== $before->post_status || defined( 'WP_IMPORTING' ) ) {
			return;
		}
		$old = get_permalink( $before );
		if ( $old && get_permalink( $after ) !== $old ) {
			$this->purge_urls( array( $old ) );
		}
	}

	public function on_before_delete( $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status ) {
			$this->purge_post( $post ); // Permanent delete that skipped the trash.
		}
	}

	public function on_comment( $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			$post = get_post( (int) $comment->comment_post_ID );
			if ( $post ) {
				$this->purge_urls( array( (string) get_permalink( $post ) ) );
			}
		}
	}

	public function on_term_edited( $term_id, $tt_id, $taxonomy ): void {
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->purge_urls( array( $link ) );
		}
	}

	public function on_option_updated( $option ): void {
		if ( in_array( $option, self::GLOBAL_OPTIONS, true )
			|| 0 === strpos( (string) $option, 'widget_' )
			|| 0 === strpos( (string) $option, 'theme_mods_' ) ) {
			$this->purge_all();
		}
	}

	/* ---------- Actions ---------- */

	public function purge_post( $post ): void {
		$post = get_post( $post );
		if ( ! $post || defined( 'WP_IMPORTING' ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		$this->purge_urls( $this->related_urls( $post ) );
	}

	/** @param string[] $urls */
	public function purge_urls( array $urls ): void {
		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			CacheStore::purge_url( $url );
			CacheStore::purge_pagination( $url ); // /page/2/, /page/3/ ... of archives.
		}
		do_action( 'velocity_cache_purged_urls', $urls );
	}

	/** Clear everything. Runs at most once per request, however many hooks fire. */
	public function purge_all(): void {
		if ( self::$purged_all ) {
			return;
		}
		self::$purged_all = true;
		CacheStore::purge_all();
		do_action( 'velocity_cache_purged_all' );
	}

	/* ---------- Which URLs does a post affect? ---------- */

	/** @return string[] */
	public function related_urls( \WP_Post $post ): array {
		$urls       = array( (string) get_permalink( $post ), home_url( '/' ) );
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page ) {
			$urls[] = (string) get_permalink( $posts_page );
		}
		if ( $post->post_parent ) {
			$urls[] = (string) get_permalink( $post->post_parent );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}
		$urls[] = get_author_posts_url( (int) $post->post_author );
		$time   = strtotime( $post->post_date );
		if ( $time ) {
			$y = (int) gmdate( 'Y', $time );
			$m = (int) gmdate( 'n', $time );
			$d = (int) gmdate( 'j', $time );
			array_push( $urls, get_year_link( $y ), get_month_link( $y, $m ), get_day_link( $y, $m, $d ) );
		}
		/**
		 * Filter the list of URLs purged when a post changes.
		 *
		 * @param string[] $urls URLs.
		 * @param \WP_Post $post The changed post.
		 */
		return (array) apply_filters( 'velocity_cache_related_urls', $urls, $post );
	}
}