# Velocity Cache

Free, open-source page caching for WordPress. Safe by default, with automatic cache clearing and background preloading from your sitemap.

## Features
- Page caching through an `advanced-cache.php` drop-in, served before WordPress loads
- Targeted purging when you publish, update, trash or comment
- Sitemap preloading and expired-page cleanup on WP-Cron
- GZIP and browser-caching rules for Apache and LiteSpeed
- Never caches logged-in users, carts, checkout, search, feeds, REST or AJAX

## Install
Download the latest zip, then in WordPress go to Plugins > Add New > Upload Plugin and activate it. Open Settings > Velocity Cache to check the status table.

## Requirements
WordPress 6.0+, PHP 7.4+

## Contributing
Issues and pull requests are welcome. Please describe your server (Apache, Nginx, LiteSpeed) and PHP version when reporting a bug.

## License
GPL-2.0-or-later