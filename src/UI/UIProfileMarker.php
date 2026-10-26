<?php
/**
 * The managed marker is data, not a general-purpose Sass integration module.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\UI;

/**
 * Accept comments and one literal assignment, not examples or later overrides.
 */
final class UIProfileMarker {
	public function matches( string $contents, string $profile ): bool {
		// Preserve quoted strings: comment-like text inside them is real Sass data.
		$comments = <<<'REGEX'
~"(?:\\.|[^"\\])*"(*SKIP)(*F)|'(?:\\.|[^'\\])*'(*SKIP)(*F)|/\*.*?\*/|//[^\r\n]*~s
REGEX;
		$literal = preg_replace( $comments, ' ', $contents );

		return is_string( $literal ) && 1 === preg_match(
			'/\A\s*\$ui-profile\s*:\s*([\'"])' . preg_quote( $profile, '/' ) . '\1\s*(?:!default\s*)?;\s*\z/',
			$literal
		);
	}
}
