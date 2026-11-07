<?php
namespace CacheShark;

defined( 'ABSPATH' ) || exit;

/**
 * Adds loading="lazy" to images and iframes.
 *
 * The only change this class ever makes is inserting that one attribute, and it
 * proves it: before returning, it removes every ` loading="lazy"` from both the
 * original and the result and requires the two to be identical. If anything
 * else changed, or the regex engine failed, the original page is returned.
 */
class LazyLoad {

	// Possessive quantifiers: a tag is "name, then any mix of plain text and quoted values, then >".
	const IMG    = '#<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i';
	const IFRAME = '#<iframe\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i';

	const MAX_TAG_LENGTH = 8192;
	const ATTRIBUTE      = ' loading="lazy"';

	/**
	 * @param string $html Page HTML.
	 * @param array  $opts images (bool), iframes (bool), skip_first (int), exclude (string[]).
	 */
	public static function apply( string $html, array $opts ): string {
		$opts = $opts + array(
			'images'     => false,
			'iframes'    => false,
			'skip_first' => 2,
			'exclude'    => array(),
		);
		if ( ( ! $opts['images'] && ! $opts['iframes'] ) || ! self::balanced( $html ) ) {
			return $html;
		}

		$store  = array();
		$masked = HtmlMask::hide( $html, $store );
		if ( null === $masked ) {
			return $html;
		}

		if ( $opts['images'] ) {
			$masked = self::tags( $masked, self::IMG, 4, $opts, true ); // strlen( '<img' ).
		}
		if ( null !== $masked && $opts['iframes'] ) {
			$masked = self::tags( $masked, self::IFRAME, 7, $opts, false ); // strlen( '<iframe' ).
		}
		if ( null === $masked ) {
			return $html;
		}

		$result = HtmlMask::show( $masked, $store );
		return self::only_attribute_added( $html, $result ) ? $result : $html;
	}

	/** @return string|null Null if the regex engine failed. */
	private static function tags( string $masked, string $pattern, int $name_length, array $opts, bool $count_position ): ?string {
		$position = 0;
		$out      = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( &$position, $opts, $count_position, $name_length, $masked ) {
				$tag    = $m[0][0];
				$offset = $m[0][1];
				++$position;
				if ( strlen( $tag ) > self::MAX_TAG_LENGTH ) {
					return $tag;
				}
				if ( $count_position && $position <= (int) $opts['skip_first'] ) {
					return $tag; // Above the fold: must load immediately.
				}
				if ( self::inside_another_tag( $masked, $offset ) || self::should_skip( $tag, (array) $opts['exclude'] ) ) {
					return $tag;
				}
				// Insert right after the tag name, so quotes and the rest of the tag are never touched.
				return substr( $tag, 0, $name_length ) . self::ATTRIBUTE . substr( $tag, $name_length );
			},
			$masked,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
		return is_string( $out ) ? $out : null;
	}

	/**
	 * True when the match sits inside the attribute of another tag, as in
	 * data-content="<img src=x>". Adding quotes there would break that attribute.
	 */
	private static function inside_another_tag( string $masked, int $offset ): bool {
		$start  = max( 0, $offset - 2048 );
		$before = substr( $masked, $start, $offset - $start );
		$lt     = strrpos( $before, '<' );
		$gt     = strrpos( $before, '>' );
		return false !== $lt && ( false === $gt || $lt > $gt );
	}

	private static function should_skip( string $tag, array $exclude ): bool {
		if ( preg_match( '/\sloading\s*=/i', $tag ) ) {
			return true; // Already decided (by WordPress core, the theme or another plugin).
		}
		if ( preg_match( '/\sfetchpriority\s*=\s*["\']?high/i', $tag ) ) {
			return true; // Marked as the main image of the page.
		}
		if ( preg_match( '/\sdata-(?:src|srcset|lazy[a-z-]*|original)\s*=/i', $tag ) ) {
			return true; // Another lazy-load script is already handling it.
		}
		if ( preg_match( '/[\s"\'](?:no-lazy|skip-lazy|data-no-lazy)(?:[\s"\'=\/>]|$)/i', $tag ) ) {
			return true;
		}
		if ( preg_match( '/\swidth\s*=\s*["\']?1(?![0-9])["\']?(?=[\s\/>])/i', $tag ) && preg_match( '/\sheight\s*=\s*["\']?1(?![0-9])["\']?(?=[\s\/>])/i', $tag ) ) {
			return true; // 1x1 tracking pixel.
		}
		foreach ( $exclude as $needle ) {
			if ( '' !== $needle && false !== stripos( $tag, (string) $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Every opening tag must have a closing tag; otherwise the page is malformed and we do not touch it. */
	private static function balanced( string $html ): bool {
		foreach ( array( 'script', 'style', 'noscript', 'textarea', 'template' ) as $tag ) {
			$open  = preg_match_all( '#<' . $tag . '\b#i', $html );
			$close = preg_match_all( '#</' . $tag . '\s*>#i', $html );
			if ( false === $open || false === $close || $open !== $close ) {
				return false;
			}
		}
		return true;
	}

	/** The safety proof: the two pages differ only by the attribute we add. */
	private static function only_attribute_added( string $before, string $after ): bool {
		return strlen( $after ) >= strlen( $before )
			&& str_replace( self::ATTRIBUTE, '', $after ) === str_replace( self::ATTRIBUTE, '', $before );
	}
}
