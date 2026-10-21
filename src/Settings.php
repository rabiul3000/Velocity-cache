<?php
namespace VelocityCache;

defined('ABSPATH') || exit;

/** Option storage, sanitising, and the config file read by the drop-in. */
class Settings
{

	const OPTION = 'velocity_cache_settings';

	public static function defaults(): array
	{
		return array(
			'enabled' => 1,
			'ttl_hours' => 10, // Keep < 12h: WordPress nonces stay valid 12-24h.
			'separate_mobile' => 0,
			'preload' => 1,
			'exclude_urls' => array(),
			'exclude_cookies' => array(),
		);
	}

	public static function get(): array
	{
		return wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
	}

	/** register_setting() sanitize callback. Never trust input. */
	public static function sanitize($input): array
	{
		$input = is_array($input) ? $input : array();
		$lines = static function ($value) {
			if (is_array($value)) {
				$value = implode("\n", $value); // Already-sanitised input: sanitising twice must be harmless.
			}
			$out = array();
			foreach (preg_split('/\R/', (string) $value) as $line) {
				$line = trim(sanitize_text_field($line));
				if ('' !== $line) {
					$out[] = $line;
				}
			}
			return array_values(array_unique($out));
		};
		$urls = array_map(
			static function ($u) {
				return '/' === $u[0] ? $u : '/' . $u;
			},
			$lines($input['exclude_urls'] ?? '')
		);
		return array(
			'enabled' => empty($input['enabled']) ? 0 : 1,
			'ttl_hours' => max(0, min(720, (int) ($input['ttl_hours'] ?? 10))),
			'separate_mobile' => empty($input['separate_mobile']) ? 0 : 1,
			'preload' => empty($input['preload']) ? 0 : 1,
			'exclude_urls' => $urls,
			'exclude_cookies' => $lines($input['exclude_cookies'] ?? ''),
		);
	}

	/** Write config.php for the drop-in (atomic). */
	public static function write_config(): bool
	{
		$s = self::get();
		$cfg = array(
			'enabled' => (bool) $s['enabled'],
			'ttl' => (int) $s['ttl_hours'] * HOUR_IN_SECONDS,
			'separate_mobile' => (bool) $s['separate_mobile'],
			'exclude_urls' => array_values($s['exclude_urls']),
			'exclude_cookies' => array_values($s['exclude_cookies']),
		);
		$json = wp_json_encode($cfg);
		if (false === $json) {
			return false;
		}
		// Nowdoc: the JSON is never parsed as PHP, so no user-entered text can run as code.
		return CacheStore::write_file(
			VELOCITY_CACHE_BASE . '/config.php',
			"<?php\ndefined( 'ABSPATH' ) || exit;\nreturn json_decode(\n<<<'JSON'\n" . $json . "\nJSON\n, true );\n"
		);
	}
}