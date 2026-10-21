=== Velocity Cache ===
Contributors: mdraabiul
Tags: cache, page cache, performance, speed, preload
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, free page caching for WordPress with automatic cache clearing and background preloading from your sitemap.

== Description ==

Velocity Cache saves a static copy of each public page and serves it to visitors before WordPress, your theme and your plugins have to run. Pages load faster and your server does less work.

**What it does**

* **Page caching.** Visitors get a saved copy of the page, served by a lightweight drop-in that loads before WordPress.
* **Automatic cache clearing.** When you publish, update, trash or comment on a post, only the affected pages are cleared: the post, the home page, its categories, tags, author page and date archives. Changing your theme, menus, widgets or permalinks clears everything.
* **Preloading.** After the cache is cleared, pages are warmed in the background from your sitemap, a few at a time, so visitors rarely hit an uncached page.
* **GZIP compression and browser caching** rules for Apache and LiteSpeed servers.
* **Expired page cleanup** keeps the cache folder from growing forever.

**Safe by default**

Pages are never cached for logged-in users, commenters, password-protected posts, search results, 404 pages, feeds, the REST API, AJAX requests, or any request with a query string (marketing parameters such as utm_source are ignored). WooCommerce cart, checkout and account pages are never cached, and visitors with items in their cart are always served live pages. You can add your own excluded URLs and cookies.

**Not included yet**

CSS and JavaScript minification, delayed JavaScript, lazy loading, critical CSS, CDN and database cleanup are planned for later releases. Multisite network activation is not supported yet.

**Privacy**

Velocity Cache does not collect data, does not use cookies and does not contact any external service. Preloading only requests pages from your own site.

== Installation ==

1. Upload the `velocity-cache` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin.
3. Open **Settings > Velocity Cache** and check that the status table shows "Yes" for every row.

If `wp-config.php` is not writable, the plugin shows the one line to add yourself: `define( 'WP_CACHE', true );`

== Frequently Asked Questions ==

= How do I know it is working? =

Load a page twice while logged out and look at the response headers. The first request shows `X-Velocity-Cache: MISS` and the second shows `X-Velocity-Cache: HIT`. You can also view the page source: cached pages end with an HTML comment saying when they were cached.

= Can I use it with another caching plugin? =

No. Two page caching plugins both want `wp-content/advanced-cache.php`. If another plugin already owns that file, Velocity Cache does not overwrite it and shows a warning. Deactivate the other plugin first.

= Does it work with WooCommerce? =

Yes. Cart, checkout and account pages are never cached, and anyone with items in their cart gets live pages.

= Why is the cache lifespan only 10 hours by default? =

WordPress security tokens (nonces) expire after 12 to 24 hours, and a cached page can contain one. Keeping the lifespan under 12 hours prevents "link expired" errors.

= Does it work on Nginx? =

Page caching works on any server. The GZIP and browser caching rules are added to `.htaccess`, so they apply to Apache and LiteSpeed only. On Nginx, add equivalent rules to your server configuration.

= Preloading is slow or not running. =

Preloading uses WP-Cron, which only runs when someone visits your site. On quiet sites, or if `DISABLE_WP_CRON` is set, add a server cron job that requests `wp-cron.php` every few minutes.

= What happens when I deactivate or delete the plugin? =

Deactivating removes the drop-in, the line it added to `wp-config.php`, its `.htaccess` rules and the cached files. Deleting the plugin also removes its settings. A drop-in belonging to another plugin is never touched.

= A page I changed still shows old content. =

Use **Clear all cache** on the settings page or in the admin bar. If a page shows content from another post (for example a related-posts list), it refreshes when its cache lifespan ends. Developers can add more URLs to clear with the `velocity_cache_related_urls` filter, or keep a page out of the cache with the `velocity_cache_should_cache` filter.

== Changelog ==

= 0.1.0 =
* First release: page caching, automatic purging, sitemap preloading, expired page cleanup, GZIP and browser caching rules for Apache and LiteSpeed, settings page.

== Upgrade Notice ==

= 0.1.0 =
First release.