<?php
/**
 * Plain-PHP test runner (no dependencies):  php tests/run.php
 * Exits with status 1 if any check fails.
 */
define( 'ABSPATH', __DIR__ . '/' );
foreach ( array( 'HtmlMask', 'LazyLoad', 'Optimizer' ) as $c ) {
	require __DIR__ . '/../src/' . $c . '.php';
}
use VelocityCache\LazyLoad as L;
use VelocityCache\Optimizer as O;

$failed = 0; $total = 0;
function check( string $name, bool $ok ): void {
	global $failed, $total;
	++$total;
	if ( ! $ok ) { ++$failed; }
	echo ( $ok ? 'PASS' : 'FAIL' ) . " $name\n";
}
function on( string $html, array $o = array() ): string {
	return L::apply( $html, $o + array( 'images' => true, 'iframes' => true, 'skip_first' => 0, 'exclude' => array() ) );
}
$A = ' loading="lazy"';

// --- basics
check( 'adds attribute right after the tag name', on( '<p><img src="a.jpg"></p>' ) === '<p><img' . $A . ' src="a.jpg"></p>' );
check( 'skip_first=2 leaves the first two alone', on( '<img src=a><img src=b><img src=c>', array( 'skip_first' => 2 ) ) === '<img src=a><img src=b><img' . $A . ' src=c>' );
check( 'nothing enabled returns the page unchanged', L::apply( '<img src=a>', array() ) === '<img src=a>' );
check( 'page without images is returned identically', on( '<html><body><p>hi</p></body></html>' ) === '<html><body><p>hi</p></body></html>' );

// --- tag syntax
check( 'alt text containing > is handled', on( '<img alt="a > b" src="x.jpg">' ) === '<img' . $A . ' alt="a > b" src="x.jpg">' );
check( 'single-quoted attributes', on( "<img alt='x > y' src='x.jpg'>" ) === '<img' . $A . " alt='x > y' src='x.jpg'>" );
check( 'upper-case tag keeps its case', on( '<IMG SRC=x.jpg>' ) === '<IMG' . $A . ' SRC=x.jpg>' );
check( 'self-closing tag', on( '<img src="x.jpg" />' ) === '<img' . $A . ' src="x.jpg" />' );
check( 'multi-line tag', on( "<img\n  src=\"x.jpg\"\n  alt=\"y\">" ) === '<img' . $A . "\n  src=\"x.jpg\"\n  alt=\"y\">" );
check( 'picture element: only the img changes', on( '<picture><source srcset="a.webp"><img src="a.jpg"></picture>' ) === '<picture><source srcset="a.webp"><img' . $A . ' src="a.jpg"></picture>' );

// --- must be skipped
foreach ( array(
	'existing loading=lazy'  => '<img loading="lazy" src=a>',
	'existing loading=eager' => '<img src=a loading=eager>',
	'fetchpriority high'     => '<img src=a fetchpriority="high">',
	'data-src lazy script'   => '<img data-src="a.jpg" src="p.gif">',
	'data-lazy-src'          => '<img data-lazy-src="a.jpg">',
	'skip-lazy class'        => '<img class="hero skip-lazy" src=a>',
	'no-lazy class'          => '<img class="no-lazy" src=a>',
	'1x1 tracking pixel'     => '<img width="1" height="1" src="t.gif">',
	'1x1 pixel, height first' => '<img height=1 width=1 src="t.gif">',
) as $name => $html ) {
	check( "skips: $name", on( $html ) === $html );
}
check( '10x10 image is NOT treated as a pixel', on( '<img width="10" height="10" src=a>' ) === '<img' . $A . ' width="10" height="10" src=a>' );
check( 'exclude list (class)', on( '<img class="hero-image" src=a>', array( 'exclude' => array( 'hero-image' ) ) ) === '<img class="hero-image" src=a>' );
check( 'exclude list (file name, case-insensitive)', on( '<img src="/Logo.PNG">', array( 'exclude' => array( 'logo.png' ) ) ) === '<img src="/Logo.PNG">' );
check( 'images off: images untouched, iframes still done', on( '<img src=a><iframe src=b></iframe>', array( 'images' => false ) ) === '<img src=a><iframe' . $A . ' src=b></iframe>' );

// --- protected regions
$protected = '<!doctype html><html><head><title>t</title><img src="in-head.jpg"><script>var s="<img src=x>";document.write(\'<img src=y>\');</script>'
	. '<style>.a{background:url(x.png)}</style></head><body><!-- <img src="in-comment.jpg"> --><!--[if IE]><img src="ie.jpg"><![endif]-->'
	. '<noscript><img src="ns.jpg"></noscript><template><img src="tpl.jpg"></template><textarea><img src="ta.jpg"></textarea></body></html>';
check( 'head, script, style, comments, noscript, template, textarea are never edited', on( $protected ) === $protected );
check( 'images in <head> do not count as "first images"', on( '<head><img src=h></head><body><img src=a><img src=b><img src=c></body>', array( 'skip_first' => 2 ) ) === '<head><img src=h></head><body><img src=a><img src=b><img' . $A . ' src=c></body>' );
check( 'img written inside another tag\'s attribute is left alone', on( '<a data-content="<img src=x>">t</a>' ) === '<a data-content="<img src=x>">t</a>' );
check( 'img inside a single-quoted JSON attribute is left alone', on( '<div data-x=\'{"h":"<img src=\\"x\\">"}\'></div>' ) === '<div data-x=\'{"h":"<img src=\\"x\\">"}\'></div>' );
check( 'two adjacent images are both handled', on( '<img src=a><img src=b>' ) === '<img' . $A . ' src=a><img' . $A . ' src=b>' );

// --- iframes
check( 'iframe gets the attribute', on( '<iframe src="https://www.youtube.com/embed/x"></iframe>' ) === '<iframe' . $A . ' src="https://www.youtube.com/embed/x"></iframe>' );
check( 'iframe with loading is untouched', on( '<iframe loading="eager" src=x></iframe>' ) === '<iframe loading="eager" src=x></iframe>' );
check( 'iframe with data-src is untouched', on( '<iframe data-src="x"></iframe>' ) === '<iframe data-src="x"></iframe>' );
check( 'iframes ignore skip_first (it is for images)', on( '<iframe src=x></iframe>', array( 'skip_first' => 5 ) ) === '<iframe' . $A . ' src=x></iframe>' );

// --- safety
$page = '<html><body>' . str_repeat( '<p>Bengali বাংলা text — “quotes” €</p><img src="a.jpg" alt="বাংলা">', 5 ) . '</body></html>';
$out  = on( $page );
check( 'idempotent: running twice equals running once', on( $out ) === $out );
check( 'only the attribute was added (UTF-8 preserved)', str_replace( $A, '', $out ) === $page && $out !== $page );
$bad = '<html><body><script>document.write("<img src=x>")<img src=a></body></html>';
check( 'unbalanced <script> => page left alone', on( $bad ) === $bad );
check( 'unbalanced <noscript> => page left alone', on( '<img src=a><noscript><img src=b>' ) === '<img src=a><noscript><img src=b>' );
check( 'oversized tag is left alone', on( '<img alt="' . str_repeat( 'x', 9000 ) . '" src=a>' ) === '<img alt="' . str_repeat( 'x', 9000 ) . '" src=a>' );
check( 'a marker-like string in the page cannot confuse the mask', ( function () use ( $A ) { $h = '<p>vcmask000000000000_0_</p><script>x</script><img src=a>'; return on( $h ) === '<p>vcmask000000000000_0_</p><script>x</script><img' . $A . ' src=a>'; } )() );

// --- regex engine failure must fall back to the original page
ini_set( 'pcre.jit', '0' );
$old = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '50' );
$big = '<html><body><script>' . str_repeat( 'a', 5000 ) . '</script><img src=a><img src=b></body></html>';
check( 'regex engine failure => original page returned, no warnings', on( $big ) === $big );
ini_set( 'pcre.backtrack_limit', $old );
ini_set( 'pcre.jit', '1' );

// --- performance on a big page
$huge = '<html><head><script>' . str_repeat( 'var x = "<img src=q>";' . "\n", 40000 ) . '</script></head><body>'
	. str_repeat( '<div class="c"><img src="/wp-content/uploads/x.jpg" alt="x" width="300" height="200"><p>text text text</p></div>', 4000 ) . '</body></html>';
$t = microtime( true );
$r = on( $huge, array( 'skip_first' => 2 ) );
$ms = ( microtime( true ) - $t ) * 1000;
check( sprintf( 'big page (%.1f MB, 4000 images) is fast (%.0f ms)', strlen( $huge ) / 1048576, $ms ), $ms < 1500 && substr_count( $r, $A ) === 3998 );

// --- Optimizer wrapper
check( 'Optimizer: all off => identical string', O::process( '<img src=a>', array() ) === '<img src=a>' );
check( 'Optimizer: images on', O::process( '<img src=a><img src=b><img src=c>', array( 'lazy_images' => 1, 'lazy_skip_first' => 2 ) ) === '<img src=a><img src=b><img' . $A . ' src=c>' );
check( 'Optimizer: exclude list is passed through', O::process( '<img class="k" src=a>', array( 'lazy_images' => 1, 'lazy_skip_first' => 0, 'lazy_exclude' => array( 'k' ) ) ) === '<img class="k" src=a>' );
check( 'Optimizer::enabled', O::enabled( array( 'lazy_iframes' => 1 ) ) && ! O::enabled( array( 'lazy_images' => 0 ) ) );

echo "\n" . ( $total - $failed ) . " / $total passed\n";
exit( $failed ? 1 : 0 );