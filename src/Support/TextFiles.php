<?php
/**
 * Text file helper.
 *
 * Replacement rules should only run against files that are meant to be edited
 * as text. Images and other binary assets are copied as-is.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

/**
 * Detects whether a resource should receive token replacements.
 */
final class TextFiles {
	/**
	 * Extensions that are safe to transform as UTF-8-ish project text.
	 *
	 * @var string[]
	 */
	private const TEXT_EXTENSIONS = array(
		'css',
		'dist',
		'html',
		'js',
		'json',
		'map',
		'md',
		'php',
		'pot',
		'scss',
		'txt',
		'xml',
	);

	/**
	 * Return true when a file is a supported text file.
	 */
	public function isTextFile( string $path ): bool {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::TEXT_EXTENSIONS, true );
	}
}
