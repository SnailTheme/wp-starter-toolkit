<?php
/**
 * Isolated image helper regressions. No WordPress, database or network required.
 *
 * Attachment stubs record API arguments, not WordPress's image generation or
 * sanitization internals. Only a private temporary theme is created or removed.
 *
 * @package ST_WP_Starter_Toolkit
 */

declare(strict_types=1);

// This standalone CLI harness has no WordPress filesystem API to call.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.unlink_unlink

$image_test_root        = realpath( sys_get_temp_dir() ) . '/st-image-helpers-' . bin2hex( random_bytes( 8 ) );
$image_test_created     = false;
$image_test_assertions  = 0;
$image_test_calls       = array();
$image_test_attachments = array(
	101 => array(
		'sources' => array(
			'full'   => array( 'https://example.test/photo.jpg', 1200, 800, false ),
			'medium' => array( 'https://example.test/photo-600.jpg', 600, 400, true ),
		),
		'alt'     => 'A "wide" & <bright> view',
		'mime'    => 'image/jpeg',
		'srcset'  => 'https://example.test/photo-600.jpg 600w, https://example.test/photo.jpg 1200w',
		'sizes'   => '(max-width: 600px) 100vw, 600px',
	),
	102 => array(
		'sources' => array( 'full' => array( 'https://example.test/logo.svg', 48, 48, false ) ),
		'alt'     => 'Logo & mark',
		'mime'    => 'image/svg+xml',
	),
	103 => array(
		'sources' => array( 'full' => array( 'https://example.test/small.png', 30, 20, false ) ),
		'alt'     => '',
		'mime'    => 'image/png',
		'srcset'  => false,
		'sizes'   => '100vw',
	),
);

/**
 * Assert independently of PHP's configurable assert() setting.
 *
 * @param bool   $condition Whether the contract holds.
 * @param string $message   Failure diagnostic for the CLI runner.
 * @throws RuntimeException When a contract fails.
 */
function image_test_assert( bool $condition, string $message ): void {
	++$GLOBALS['image_test_assertions'];
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI diagnostic, not HTML.
	}
}

/**
 * Capture output while ensuring failed callbacks cannot leak an output buffer.
 *
 * @param callable $callback Helper invocation to capture.
 * @return array{return:mixed,output:string} Returned value and emitted bytes.
 */
function image_test_capture( callable $callback ): array {
	ob_start();
	try {
		$result = $callback();
		return array(
			'return' => $result,
			'output' => ob_get_contents(),
		);
	} finally {
		ob_end_clean();
	}
}

/**
 * Render through the packaged helper and enforce its return-only contract.
 *
 * @param mixed  $attachment Attachment ID or ACF array under test.
 * @param string $size       Requested WordPress attachment size.
 * @param string $priority   Fetch priority attribute value.
 * @param string $classes    Additional figure classes.
 * @return string Returned markup or an empty string.
 */
function image_test_render( mixed $attachment, string $size = 'full', string $priority = 'low', string $classes = '' ): string {
	$GLOBALS['image_test_calls'] = array();
	$result                      = image_test_capture( static fn () => st_wp_core_generate_img( $attachment, $size, $priority, $classes ) );
	image_test_assert( '' === $result['output'], 'Image generation must never echo markup.' );
	image_test_assert( is_string( $result['return'] ), 'Image generation must return a string.' );
	return $result['return'];
}

/** Resolve project overrides only inside the disposable fixture. */
function get_template_directory(): string {
	return $GLOBALS['image_test_root'];
}

/** Supply the sprite URL without consulting a real theme. */
function get_stylesheet_directory_uri(): string {
	return 'https://example.test/fixture-theme';
}

/**
 * Return fixture sources; malformed or absent attachment IDs have no source.
 *
 * @param mixed  $attachment_id Attachment identifier passed by the helper.
 * @param string $size          Requested attachment size.
 * @return array|false Source tuple or a simulated failed WordPress lookup.
 */
function wp_get_attachment_image_src( mixed $attachment_id, string $size ): array|false {
	$GLOBALS['image_test_calls'][] = array( 'source', $attachment_id, $size );
	if ( ! is_int( $attachment_id ) && ! is_string( $attachment_id ) ) {
		return false;
	}
	return $GLOBALS['image_test_attachments'][ $attachment_id ]['sources'][ $size ] ?? false;
}

/**
 * Record the alt metadata lookup, including its single-value flag.
 *
 * @param int    $attachment_id Fixture attachment ID.
 * @param string $key           Metadata key requested by the helper.
 * @param bool   $single        Whether one metadata value was requested.
 * @return string Fixture alt text.
 */
function get_post_meta( int $attachment_id, string $key, bool $single ): string {
	$GLOBALS['image_test_calls'][] = array( 'meta', $attachment_id, $key, $single );
	return $GLOBALS['image_test_attachments'][ $attachment_id ]['alt'];
}

/**
 * Select the raster or vector branch using fixture MIME data.
 *
 * @param int $attachment_id Fixture attachment ID.
 * @return string Fixture MIME type.
 */
function get_post_mime_type( int $attachment_id ): string {
	$GLOBALS['image_test_calls'][] = array( 'mime', $attachment_id );
	return $GLOBALS['image_test_attachments'][ $attachment_id ]['mime'];
}

/**
 * Return responsive candidates supplied by the fixture, never synthesize them.
 *
 * @param int    $attachment_id Fixture attachment ID.
 * @param string $size          Requested attachment size.
 * @return string|false Fixture candidates, or no responsive sources.
 */
function wp_get_attachment_image_srcset( int $attachment_id, string $size ): string|false {
	$GLOBALS['image_test_calls'][] = array( 'srcset', $attachment_id, $size );
	return $GLOBALS['image_test_attachments'][ $attachment_id ]['srcset'] ?? false;
}

/**
 * Return the responsive size expression supplied by the fixture.
 *
 * @param int    $attachment_id Fixture attachment ID.
 * @param string $size          Requested attachment size.
 * @return string|false Fixture sizes, or no size expression.
 */
function wp_get_attachment_image_sizes( int $attachment_id, string $size ): string|false {
	$GLOBALS['image_test_calls'][] = array( 'sizes', $attachment_id, $size );
	return $GLOBALS['image_test_attachments'][ $attachment_id ]['sizes'] ?? false;
}

/**
 * Model attribute entity escaping without double-encoding existing entities.
 *
 * @param mixed $value Attribute value to encode.
 * @return string Encoded attribute value.
 */
function esc_attr( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
}

/**
 * Fixtures use trusted HTTPS URLs; this stub only models attribute escaping.
 *
 * @param string $url Trusted fixture URL.
 * @return string Attribute-safe fixture URL.
 */
function esc_url( string $url ): string {
	return esc_attr( $url );
}

/**
 * Record the sprite sanitizer contract, not WordPress's sanitizer internals.
 *
 * @param string $html         Sprite markup passed to WordPress.
 * @param array  $allowed_html Elements and attributes permitted by the helper.
 * @return string Unchanged markup for return/echo comparisons.
 */
function wp_kses( string $html, array $allowed_html ): string {
	$GLOBALS['image_test_calls'][] = array( 'kses', $allowed_html );
	return $html;
}

/**
 * Remove only this run's private temp root, without following directory links.
 *
 * @throws RuntimeException When the root fails the fixture ownership checks.
 */
function image_test_cleanup(): void {
	$root = $GLOBALS['image_test_root'];
	if ( ! $GLOBALS['image_test_created'] ) {
		return;
	}
	if ( is_link( $root ) || dirname( $root ) !== realpath( sys_get_temp_dir() ) || ! preg_match( '/^st-image-helpers-[a-f0-9]{16}$/D', basename( $root ) ) ) {
		throw new RuntimeException( 'Refusing cleanup outside the owned image-helper fixture.' );
	}
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $files as $file ) {
		if ( $file->isDir() && ! $file->isLink() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $root );
}

try {
	if ( ! mkdir( $image_test_root, 0700 ) ) {
		throw new RuntimeException( 'Could not create a private image-helper fixture.' );
	}
	$image_test_created = true;
	require dirname( __DIR__ ) . '/resources/core/files/core/template-tags.php';

	$html = image_test_render( 101 );
	image_test_assert( str_starts_with( $html, '<figure class="wp-block-image size-full"><img ' ), 'Numeric IDs produce figure/image markup with default classes.' );
	foreach ( array(
		'fetchpriority="low"',
		'decoding="async"',
		'width="1200"',
		'height="800"',
		'src="https://example.test/photo.jpg"',
		'class="wp-image-101"',
		'alt="A &quot;wide&quot; &amp; &lt;bright&gt; view"',
		'srcset="https://example.test/photo-600.jpg 600w, https://example.test/photo.jpg 1200w"',
		'sizes="(max-width: 600px) 100vw, 600px"',
	) as $attribute ) {
		image_test_assert( str_contains( $html, $attribute ), 'Raster output contains ' . $attribute );
	}
	image_test_assert( str_ends_with( $html, ' /></figure>' ), 'Image markup closes the figure.' );
	image_test_assert(
		array(
			array( 'source', 101, 'full' ),
			array( 'meta', 101, '_wp_attachment_image_alt', true ),
			array( 'mime', 101 ),
			array( 'srcset', 101, 'full' ),
			array( 'sizes', 101, 'full' ),
		) === $image_test_calls,
		'Numeric IDs resolve source, alt metadata and responsive data through WordPress APIs.'
	);
	$id_calls = $image_test_calls;
	$acf      = array(
		'ID'     => 101,
		'url'    => 'https://example.test/stale.jpg',
		'alt'    => 'Stale ACF alt',
		'width'  => 1,
		'height' => 1,
		'sizes'  => array( 'medium' => 'https://example.test/stale-medium.jpg' ),
	);
	$acf_html = image_test_render( $acf );
	image_test_assert( $html === $acf_html, 'ACF arrays use their ID, not cached URL, alt, dimensions or sizes.' );
	image_test_assert( $id_calls === $image_test_calls, 'ACF arrays perform the same WordPress lookups as numeric IDs.' );
	$acf_html = image_test_render( array( 'ID' => 101 ) );
	image_test_assert( $html === $acf_html, 'An ACF array needs only its ID.' );

	$html = image_test_render( $acf, 'medium', 'high', 'hero-image extra"class' );
	image_test_assert( str_contains( $html, 'class="wp-block-image size-medium hero-image extra&quot;class"' ), 'Custom classes are escaped on the figure.' );
	image_test_assert( str_contains( $html, 'fetchpriority="high"' ), 'Explicit fetch priority is preserved.' );
	image_test_assert( str_contains( $html, 'src="https://example.test/photo-600.jpg"' ) && str_contains( $html, 'width="600" height="400"' ), 'Requested size uses WordPress source dimensions.' );
	foreach ( array( 'source', 'srcset', 'sizes' ) as $api ) {
		image_test_assert( in_array( array( $api, 101, 'medium' ), $image_test_calls, true ), 'Requested size reaches the ' . $api . ' API.' );
	}

	foreach ( array( 0, null, false, '', array(), array( 'ID' => 0 ) ) as $empty ) {
		image_test_assert( '' === image_test_render( $empty ), 'Empty IDs return an empty string.' );
		image_test_assert( array() === $image_test_calls, 'Empty IDs do not request attachment data.' );
	}
	foreach ( array( 999, array( 'ID' => 999 ), array( 'url' => 'https://example.test/no-id.jpg' ) ) as $missing ) {
		image_test_assert( '' === image_test_render( $missing ), 'Missing attachments or ACF IDs cannot produce markup.' );
		image_test_assert( array( 'source' ) === array_column( $image_test_calls, 0 ), 'Missing sources stop before metadata and responsive lookups.' );
	}
	image_test_assert( '' === image_test_render( 101, 'unavailable' ), 'A failed source lookup for a requested size returns an empty string.' );
	image_test_assert( array( array( 'source', 101, 'unavailable' ) ) === $image_test_calls, 'Unavailable sizes stop at the source lookup.' );

	$html = image_test_render( 103 );
	image_test_assert( str_contains( $html, 'alt=""' ), 'Empty attachment alt text is preserved without a fallback.' );
	image_test_assert( str_contains( $html, 'width="30" height="20"' ), 'Rasters without responsive candidates retain dimensions.' );
	image_test_assert( ! str_contains( $html, 'srcset=' ) && ! str_contains( $html, 'sizes=' ), 'No srcset means neither responsive attribute is emitted.' );

	foreach ( array(
		102,
		array(
			'ID'  => 102,
			'alt' => 'Ignored ACF alt',
		),
	) as $svg ) {
		$html = image_test_render( $svg, 'full', 'high', 'brand' );
		foreach ( array( 'class="wp-block-image size-full brand"', 'fetchpriority="high"', 'decoding="async"', 'src="https://example.test/logo.svg"', 'alt="Logo &amp; mark"', 'class="wp-image-102"' ) as $attribute ) {
			image_test_assert( str_contains( $html, $attribute ), 'SVG attachments retain ' . $attribute );
		}
		foreach ( array( 'width=', 'height=', 'srcset=', 'sizes=' ) as $attribute ) {
			image_test_assert( ! str_contains( $html, $attribute ), 'SVG attachments omit ' . $attribute );
		}
		image_test_assert( array( 'source', 'meta', 'mime' ) === array_column( $image_test_calls, 0 ), 'SVG attachments skip responsive APIs.' );
	}

	// Sprite filemtime must also resolve inside this run's disposable theme.
	if ( ! mkdir( $image_test_root . '/assets/images/icons', 0700, true ) || false === file_put_contents( $image_test_root . '/assets/images/icons/icons.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ) ) {
		throw new RuntimeException( 'Could not create the sprite fixture.' );
	}
	$result = image_test_capture( static fn () => st_wp_core_generate_sprite_svg( 'search', 32, 16, '/assets/images/icons/icons.svg', true ) );
	image_test_assert( '' === $result['output'] && is_string( $result['return'] ), 'Sprite return mode emits nothing and returns markup.' );
	$sprite = $result['return'];
	image_test_assert( str_contains( $sprite, 'class="svg-icon search" width="32" height="16" aria-hidden="true"' ), 'Decorative sprites retain dimensions and accessibility attributes.' );
	image_test_assert( str_contains( $sprite, '?ver=' . filemtime( $image_test_root . '/assets/images/icons/icons.svg' ) . '#search' ), 'Sprite references include the fixture timestamp and icon fragment.' );
	$result = image_test_capture( static fn () => st_wp_core_generate_sprite_svg( 'search', 32, 16 ) );
	image_test_assert( null === $result['return'] && $sprite === $result['output'], 'Default sprite mode echoes the same markup and returns null.' );
	$result = image_test_capture( static fn () => st_wp_core_generate_sprite_svg( 'search', 24, 24, '/assets/images/icons/icons.svg', true, false, 'Search & find' ) );
	image_test_assert( str_contains( $result['return'], 'role="img" aria-label="Search &amp; find"' ) && ! str_contains( $result['return'], 'aria-hidden=' ), 'Meaningful sprites use an escaped accessible label instead of aria-hidden.' );
	$last_call = end( $image_test_calls );
	image_test_assert( 'kses' === $last_call[0] && isset( $last_call[1]['svg']['aria-label'], $last_call[1]['use']['xlink:href'] ), 'Sprite markup passes through the expected WordPress sanitizer allowlist.' );
	try {
		image_test_capture( static fn () => st_wp_core_generate_sprite_svg( 'search', 24, 24, '/invalid/icons.svg', true ) );
		throw new RuntimeException( 'Invalid sprite source was accepted.' );
	} catch ( Exception $exception ) {
		image_test_assert( 'Sprite Source Dir Incorrect! Upload to /assets/images/icons/' === $exception->getMessage(), 'Invalid sprite paths retain their documented exception.' );
	}
} finally {
	image_test_cleanup();
}

image_test_assert( ! file_exists( $image_test_root ), 'The disposable theme is removed after the tests.' );
echo 'Image helpers passed (' . $image_test_assertions . ' assertions).' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI assertion count, not HTML.
