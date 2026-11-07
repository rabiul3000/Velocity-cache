<?php
namespace CacheShark;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the parts of an HTML page that must never be edited (comments, scripts,
 * styles, <noscript>, templates, textareas and <head>), then puts them back
 * exactly as they were. Optimizers work on the masked text, so they cannot
 * touch code that merely looks like markup.
 */
class HtmlMask {

	/** A comment, or one of the protected elements up to its matching closing tag. */
	const PATTERN = '#<!--[\s\S]*?-->|<(script|style|noscript|template|textarea|head)\b[^>]*+>[\s\S]*?</\1\s*>#i';

	/**
	 * Replace protected regions with placeholders.
	 *
	 * @param string               $html  Page HTML.
	 * @param array<string,string> $store Filled with placeholder => original text.
	 * @return string|null Masked HTML, or null if the regex engine gave up (caller must then leave the page alone).
	 */
	public static function hide( string $html, array &$store ): ?string {
		$token = self::unused_token( $html );
		$i     = 0;
		$out   = preg_replace_callback(
			self::PATTERN,
			static function ( $m ) use ( &$store, $token, &$i ) {
				$key           = $token . '_' . $i++ . '_';
				$store[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		return is_string( $out ) ? $out : null;
	}

	/** Put every protected region back. */
	public static function show( string $html, array $store ): string {
		return $store ? strtr( $html, $store ) : $html;
	}

	/** A marker that does not occur anywhere in the page. */
	private static function unused_token( string $html ): string {
		do {
			$token = 'vcmask' . bin2hex( random_bytes( 6 ) );
		} while ( false !== strpos( $html, $token ) );
		return $token;
	}
}
