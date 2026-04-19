<?php
/**
 * Replacement engine.
 *
 * Replacements are intentionally conservative and manifest-driven. Stable core
 * helpers such as st_wp_core_* are not renamed, while generated-theme identifiers
 * are transformed through ST_WP_CORE_THEME_PATTERNS.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

/**
 * Applies theme and block replacement profiles to text files.
 */
final class ReplacementEngine {
	/**
	 * Starter theme source identifiers used by resources bundled with the toolkit.
	 *
	 * @var array<string,string>
	 */
	private const SOURCE_THEME_PATTERNS = array(
		'git_repo'         => 'snailtheme/wp-starter',
		'text_domain'      => 'st-wp-starter',
		'function_names'   => 'st_wp_starter_',
		'doc_block'        => 'ST_WP_Starter',
		'prefix_handlers'  => 'st-wp-starter-',
		'constants'        => 'ST_WP_STARTER_',
		'block_namespace'  => 'stwp',
		'block_category'   => 'st-wp-starter',
		'theme_name'       => 'ST WP Starter',
		'theme_uri'        => 'https://github.com/snailtheme/wp-starter/',
		'github_theme_uri' => 'snailtheme/wp-starter',
		'primary_branch'   => 'main',
		'author_name'      => 'SnailTheme',
		'author_uri'       => 'https://www.snailtheme.com/',
	);

	/**
	 * Apply theme-level whitelabel replacements.
	 *
	 * @param array<string,string> $targetPatterns Values from ST_WP_CORE_THEME_PATTERNS.
	 */
	public function applyThemePatterns( string $contents, array $targetPatterns ): string {
		$map = array();

		foreach ( self::SOURCE_THEME_PATTERNS as $key => $source ) {
			$target = $targetPatterns[ $key ] ?? $source;

			if ( $source !== $target ) {
				$map[ $source ] = $target;
			}
		}

		// Function bases appear without the trailing underscore in hook names.
		$sourceFunctionBase = rtrim( self::SOURCE_THEME_PATTERNS['function_names'], '_' );
		$targetFunctionBase = rtrim( $targetPatterns['function_names'] ?? self::SOURCE_THEME_PATTERNS['function_names'], '_' );

		if ( $sourceFunctionBase !== $targetFunctionBase ) {
			$map[ $sourceFunctionBase ] = $targetFunctionBase;
		}

		return $this->replaceLongestFirst( $contents, $map );
	}

	/**
	 * Apply block package replacements after theme replacements.
	 *
	 * @param array<string,mixed>  $manifest       Block manifest.
	 * @param array<string,string> $targetPatterns Values from ST_WP_CORE_THEME_PATTERNS.
	 */
	public function applyBlockPatterns(
		string $contents,
		array $manifest,
		string $destinationSlug,
		array $targetPatterns
	): string {
		$sourceSlug      = (string) ( $manifest['source_slug'] ?? '' );
		$sourceNamespace = (string) ( $manifest['source_namespace'] ?? 'stwp' );
		$sourceTitle     = (string) ( $manifest['default_title'] ?? $sourceSlug );
		$targetNamespace = $targetPatterns['block_namespace'] ?? $sourceNamespace;
		$targetCategory  = $targetPatterns['block_category'] ?? ( $manifest['source_category'] ?? 'st-wp-starter' );
		$targetTextDomain = $targetPatterns['text_domain'] ?? self::SOURCE_THEME_PATTERNS['text_domain'];
		$targetTitle     = $this->titleFromSlug( $destinationSlug );
		$sourceSlugSnake = str_replace( '-', '_', $sourceSlug );
		$targetSlugSnake = str_replace( '-', '_', $destinationSlug );

		$contents = $this->applyThemePatterns( $contents, $targetPatterns );

		$protectedMap = array(
			$sourceNamespace . '/' . $sourceSlug => '__ST_TOOLKIT_BLOCK_NAME__',
			$sourceNamespace . '\\/' . $sourceSlug => '__ST_TOOLKIT_BLOCK_JSON_NAME__',
			$sourceNamespace . '_' . $sourceSlugSnake => '__ST_TOOLKIT_BLOCK_SNAKE__',
			$sourceNamespace . '-' . $sourceSlug => '__ST_TOOLKIT_BLOCK_KEBAB__',
			$sourceSlug . '-'                  => '__ST_TOOLKIT_BLOCK_SLUG_HYPHEN__',
			$sourceSlug . '_'                  => '__ST_TOOLKIT_BLOCK_SLUG_UNDERSCORE__',
		);

		$contents = $this->replaceLongestFirst( $contents, $protectedMap );

		$map = array(
			"'" . $sourceNamespace . "/'"          => "'" . $targetNamespace . "/'",
			'"' . $sourceNamespace . '/"'       => '"' . $targetNamespace . '/"',
			'"category": "' . $targetTextDomain . '"' => '"category": "' . $targetCategory . '"',
			"'category' => '" . $targetTextDomain . "'" => "'category' => '" . $targetCategory . "'",
			(string) ( $manifest['source_category'] ?? 'st-wp-starter' ) => $targetCategory,
			$sourceTitle                        => $targetTitle . ( str_ends_with( $sourceTitle, ' Section' ) ? ' Section' : '' ),
			ucwords( str_replace( '-', ' ', $sourceSlug ) ) => $targetTitle,
		);

		$contents = $this->replaceLongestFirst( $contents, $map );

		return $this->replaceLongestFirst(
			$contents,
			array(
				'__ST_TOOLKIT_BLOCK_NAME__'            => $targetNamespace . '/' . $destinationSlug,
				'__ST_TOOLKIT_BLOCK_JSON_NAME__'       => $targetNamespace . '\\/' . $destinationSlug,
				'__ST_TOOLKIT_BLOCK_SNAKE__'           => $targetNamespace . '_' . $targetSlugSnake,
				'__ST_TOOLKIT_BLOCK_KEBAB__'           => $targetNamespace . '-' . $destinationSlug,
				'__ST_TOOLKIT_BLOCK_SLUG_HYPHEN__'     => $destinationSlug . '-',
				'__ST_TOOLKIT_BLOCK_SLUG_UNDERSCORE__' => $targetSlugSnake . '_',
			)
		);
	}

	/**
	 * Build a display title from a block slug.
	 */
	public function titleFromSlug( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Replace longer tokens first to avoid partial replacements.
	 *
	 * @param array<string,string> $map Replacement map.
	 */
	private function replaceLongestFirst( string $contents, array $map ): string {
		uksort(
			$map,
			static fn ( string $left, string $right ): int => strlen( $right ) <=> strlen( $left )
		);

		foreach ( $map as $search => $replace ) {
			if ( '' === $search || $search === $replace ) {
				continue;
			}

			$contents = str_replace( $search, $replace, $contents );
		}

		return $contents;
	}
}
