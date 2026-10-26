<?php
/**
 * Maintainer-only checksum generation for packaged core resources.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Core;

use PhpToken;
use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use stdClass;

/**
 * Validates the complete resource tree before replacing only its checksum map.
 */
final class CoreManifestGenerator {
	private const OWNED_ROOTS = array( 'core/', 'assets/scss/core/', 'assets/scripts/core/', 'assets/css/core/', 'assets/js/core/' );
	private const LEGACY_REMOVALS = array(
		'assets/scss/core-admin.scss',
		'assets/scripts/core-admin.js',
		'assets/scripts/core-customizer.js',
		'assets/css/core-admin.min.css',
		'assets/js/core-admin.min.js',
		'assets/js/core-customizer.min.js',
	);

	private readonly string $resourcesRoot;

	/**
	 * @param string|null $resourcesRoot Explicit resources/core root for fixture tests.
	 */
	public function __construct( ?string $resourcesRoot = null ) {
		$this->resourcesRoot = rtrim( $resourcesRoot ?? ( new PackagePaths() )->resources( 'core' ), '/\\' );
	}

	/**
	 * Read-only comparison. Missing/extra/stale entries are all checksum drift.
	 * The opaque snapshot token binds subsequent writes to this exact approval.
	 *
	 * @return array<string,mixed>
	 */
	public function plan(): array {
		return $this->prepare()['result'];
	}

	/**
	 * Atomically replace a drifting checksum map; identical maps are true no-ops.
	 * Confirmation belongs to the caller, not this fixture-friendly service.
	 * Pass plan()['snapshot'] to bind a confirmed write to the reviewed bytes.
	 *
	 * @return array<string,mixed>
	 */
	public function generate( ?string $approvedSnapshot = null ): array {
		$prepared = $this->prepare();
		$result   = $prepared['result'];
		if ( null !== $approvedSnapshot && ! hash_equals( $approvedSnapshot, $result['snapshot'] ) ) {
			throw new RuntimeException( 'Core resources or manifest changed since approval; review a fresh plan.' );
		}
		$result['written'] = false;
		if ( ! $result['drift'] ) {
			return $result;
		}

		$path = $result['manifest'];
		$temp = null;
		try {
			// Exclusive creation in the same directory gives rename atomicity without
			// following a pre-existing temporary file or falling back to another disk.
			$temp = dirname( $path ) . '/.st-core-manifest-' . bin2hex( random_bytes( 12 ) );
			$handle = @fopen( $temp, 'x+b' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Could not create temporary core manifest.' );
			}
			try {
				$contents = $prepared['contents'];
				$offset = 0;
				while ( $offset < strlen( $contents ) ) {
					$written = @fwrite( $handle, substr( $contents, $offset ) );
					if ( false === $written || 0 === $written ) {
						throw new RuntimeException( 'Could not write temporary core manifest.' );
					}
					$offset += $written;
				}
				if ( ! @fflush( $handle ) || ! @fsync( $handle ) ) {
					throw new RuntimeException( 'Could not flush temporary core manifest.' );
				}
			} finally {
				fclose( $handle );
			}

			if ( ! @chmod( $temp, fileperms( $path ) & 0777 ) ) {
				throw new RuntimeException( 'Could not preserve core manifest permissions.' );
			}
			// Re-enumerate and hash the complete tree, not just the manifest. A
			// changed/new/deleted resource must invalidate even a standalone write.
			$current = $this->prepare();
			if ( ! hash_equals( $result['snapshot'], $current['result']['snapshot'] ) ) {
				throw new RuntimeException( 'Core resources or manifest changed during generation; retry.' );
			}
			if ( ! @rename( $temp, $path ) ) {
				throw new RuntimeException( 'Could not replace core manifest atomically.' );
			}
			$result['written'] = true;
			return $result;
		} finally {
			if ( null !== $temp && is_file( $temp ) ) {
				unlink( $temp );
			}
		}
	}

	/** @return array<string,mixed> Validated snapshot used by both operations. */
	private function prepare(): array {
		$this->assertSafePath( $this->resourcesRoot, true );
		$path = $this->resourcesRoot . '/st-wp-core-manifest.json';
		$this->assertSafePath( $path, false );
		$original = $this->read( $path );
		try {
			$data = json_decode( $original, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'Invalid core manifest JSON: ' . $exception->getMessage(), 0, $exception );
		}
		if ( ! $data instanceof stdClass || 1 !== ( $data->schema ?? null ) || 'st-wp-core' !== ( $data->name ?? null ) || 'files' !== ( $data->files_root ?? null ) ) {
			throw new RuntimeException( 'Invalid core manifest schema, name, or files_root (expected files).' );
		}
		if ( ! is_string( $data->version ?? null ) || ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $data->version ) ) {
			throw new RuntimeException( 'Invalid core manifest version.' );
		}
		foreach ( array( 'owned_files', 'removed_files', 'required_public_helpers' ) as $key ) {
			if ( ! is_array( $data->$key ?? null ) || ! array_is_list( $data->$key ) ) {
				throw new RuntimeException( 'Invalid core manifest list: ' . $key );
			}
			// Validate element types before comparison, which can emit native notices
			// for mixed objects/scalars and corrupt the command's JSON error output.
			foreach ( $data->$key as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					throw new RuntimeException( 'Invalid core manifest string in ' . $key );
				}
			}
			if ( count( array_unique( $data->$key, SORT_STRING ) ) !== count( $data->$key ) ) {
				throw new RuntimeException( 'Invalid core manifest list: ' . $key );
			}
			foreach ( $data->$key as $value ) {
				if ( 'required_public_helpers' === $key ) {
					if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value ) ) {
						throw new RuntimeException( 'Invalid required public helper: ' . $value );
					}
				} else {
					$this->assertRelativePath( $value, 'owned_files' === $key );
					$this->assertOwnershipBoundary( $value, 'removed_files' === $key );
				}
			}
		}
		if ( array() === $data->owned_files || ! ( $data->checksums ?? null ) instanceof stdClass ) {
			throw new RuntimeException( 'Invalid core manifest owned_files or checksums object.' );
		}
		$declared = get_object_vars( $data->checksums );
		foreach ( $declared as $relative => $hash ) {
			$this->assertRelativePath( (string) $relative );
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new RuntimeException( 'Invalid SHA-256 checksum: ' . $relative );
			}
			if ( ! $this->isOwned( (string) $relative, $data->owned_files ) ) {
				throw new RuntimeException( 'Checksum path is not owned: ' . $relative );
			}
		}

		$filesRoot = $this->resourcesRoot . '/files';
		$this->assertSafePath( $filesRoot, true );
		$files = array();
		$this->collectFiles( $filesRoot, '', $files );
		ksort( $files, SORT_STRING );
		if ( ! isset( $files['core/bootstrap.php'] ) ) {
			throw new RuntimeException( 'Missing packaged core/bootstrap.php.' );
		}
		foreach ( $data->removed_files as $removed ) {
			if ( file_exists( $filesRoot . '/' . $removed ) || is_link( $filesRoot . '/' . $removed ) ) {
				throw new RuntimeException( 'Removed path is still present: ' . $removed );
			}
		}

		$checksums = array();
		$helpers = array();
		$version = null;
		foreach ( $files as $relative => $absolute ) {
			if ( ! $this->isOwned( $relative, $data->owned_files ) ) {
				throw new RuntimeException( 'Resource path is not owned: ' . $relative );
			}
			$contents = $this->read( $absolute );
			$checksums[ $relative ] = hash( 'sha256', $contents );
			if ( str_ends_with( strtolower( $relative ), '.php' ) ) {
				$this->inspectPhp( $contents, $relative, $helpers, $version );
			}
		}
		if ( $version !== $data->version ) {
			throw new RuntimeException( 'Bootstrap ST_WP_CORE_VERSION must agree with the core manifest version; versions are not bumped automatically.' );
		}
		$missingHelpers = array_filter( $data->required_public_helpers, static fn ( string $helper ): bool => ! isset( $helpers[ strtolower( $helper ) ] ) );
		if ( array() !== $missingHelpers ) {
			throw new RuntimeException( 'Missing required public helpers: ' . implode( ', ', $missingHelpers ) );
		}

		$changes = array();
		$allPaths = array_unique( array_merge( array_keys( $checksums ), array_keys( $declared ) ) );
		sort( $allPaths, SORT_STRING );
		foreach ( $allPaths as $relative ) {
			if ( ( $checksums[ $relative ] ?? null ) === ( $declared[ $relative ] ?? null ) ) {
				continue;
			}
			$changes[] = array(
				'path' => $relative,
				'action' => ! isset( $declared[ $relative ] ) ? 'missing' : ( ! isset( $checksums[ $relative ] ) ? 'extra' : 'stale' ),
			);
		}
		$data->checksums = (object) $checksums;
		return array(
			'contents' => json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n",
			'result' => array(
				'snapshot' => hash( 'sha256', $original . "\0" . json_encode( $checksums, JSON_THROW_ON_ERROR ) ),
				'manifest' => $path,
				'version' => $data->version,
				'file_count' => count( $checksums ),
				'drift' => array() !== $changes,
				'changes' => $changes,
			),
		);
	}

	/** @param array<string,string> $files Collected regular files, including dotfiles. */
	private function collectFiles( string $root, string $relative, array &$files ): void {
		$directory = $root . ( '' === $relative ? '' : '/' . $relative );
		$entries = @scandir( $directory );
		if ( false === $entries ) {
			throw new RuntimeException( 'Could not read core resource directory: ' . $directory );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = '' === $relative ? $entry : $relative . '/' . $entry;
			$this->assertRelativePath( $child );
			$path = $root . '/' . $child;
			$this->assertSafePath( $path, is_dir( $path ) );
			if ( is_dir( $path ) ) {
				$this->collectFiles( $root, $child, $files );
			} else {
				$files[ $child ] = $path;
			}
		}
	}

	/** Reject links in every ancestor, not merely at the resource/file leaf. */
	private function assertSafePath( string $path, bool $directory ): void {
		clearstatcache();
		$normalized = str_replace( DIRECTORY_SEPARATOR, '/', $path );
		if ( ! preg_match( '#^(?:/|[A-Za-z]:/)#', $normalized ) || preg_match( '#(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f]#', $normalized ) ) {
			throw new RuntimeException( 'Unsafe core resource path: ' . $path );
		}
		$current = $path;
		while ( true ) {
			if ( is_link( $current ) ) {
				throw new RuntimeException( 'Unsafe symlink in core resource path: ' . $current );
			}
			$parent = dirname( $current );
			if ( $parent === $current ) {
				break;
			}
			$current = $parent;
		}
		if ( $directory ? ! is_dir( $path ) : ! is_file( $path ) ) {
			throw new RuntimeException( 'Missing or invalid core resource: ' . $path );
		}
	}

	private function assertRelativePath( string $path, bool $pattern = false ): void {
		if ( '' === $path || str_contains( $path, '\\' ) || preg_match( '/[\x00-\x1f\x7f:<>"|]/', $path ) || ( ! $pattern && strpbrk( $path, '*?[]' ) !== false ) ) {
			throw new RuntimeException( 'Unsafe core manifest path: ' . $path );
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || preg_match( '/[. ]$/D', $segment ) || preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment ) || strpbrk( $segment, '[]' ) !== false ) {
				throw new RuntimeException( 'Unsafe core manifest path: ' . $path );
			}
		}
	}

	/** Manifest metadata may narrow package ownership, never expand into project paths. */
	private function assertOwnershipBoundary( string $path, bool $removed ): void {
		foreach ( self::OWNED_ROOTS as $root ) {
			if ( str_starts_with( $path, $root ) && strlen( $path ) > strlen( $root ) ) {
				return;
			}
		}
		if ( $removed && in_array( $path, self::LEGACY_REMOVALS, true ) ) {
			return;
		}
		throw new RuntimeException( 'Core manifest path exceeds the package ownership boundary: ' . $path );
	}

	/** @param string[] $patterns Relative globs: * stays in one segment, ** crosses /. */
	private function isOwned( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$regex = strtr( preg_quote( $pattern, '#' ), array( '\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]' ) );
			if ( preg_match( '#^' . $regex . '$#D', $path ) ) {
				return true;
			}
		}
		return false;
	}

	private function read( string $path ): string {
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read core resource: ' . $path );
		}
		return $contents;
	}

	/**
	 * Tokenize rather than execute PHP. Comments, strings, methods and namespaced
	 * declarations must not masquerade as required global helper functions.
	 *
	 * @param array<string,bool> $helpers Global function declarations found so far.
	 */
	private function inspectPhp( string $contents, string $path, array &$helpers, ?string &$version ): void {
		try {
			$tokens = array_values( array_filter( PhpToken::tokenize( $contents, TOKEN_PARSE ), static fn ( PhpToken $token ): bool => ! $token->isIgnorable() ) );
		} catch ( \ParseError $exception ) {
			throw new RuntimeException( 'Invalid PHP core resource: ' . $path . ': ' . $exception->getMessage(), 0, $exception );
		}
		$namespace = '';
		$start = 0;
		$count = count( $tokens );
		// Imports belong to a namespace region, including when written after a call.
		// Keep bracketed regions separate so aliases cannot leak into another one.
		for ( $index = 0; $index < $count; ++$index ) {
			if ( T_NAMESPACE !== $tokens[ $index ]->id ) {
				continue;
			}
			$this->inspectPhpRegion( $tokens, $start, $index, $namespace, $path, $helpers, $version );
			$namespace = '';
			while ( ++$index < $count && ! in_array( $tokens[ $index ]->id, array( ord( ';' ), ord( '{' ), T_CLOSE_TAG ), true ) ) {
				$namespace .= $tokens[ $index ]->text;
			}
			if ( '{' === $tokens[ $index ]->text ) {
				$close = $this->phpDelimiterEnd( $tokens, $index );
				$this->inspectPhpRegion( $tokens, $index + 1, $close, $namespace, $path, $helpers, $version );
				$index = $close;
				$namespace = '';
			}
			$start = $index + 1;
		}
		$this->inspectPhpRegion( $tokens, $start, $count, $namespace, $path, $helpers, $version );
	}

	/**
	 * Inspect only immediate/global code in a namespace. Guarded blocks remain
	 * visible, but deferred declarations/expressions and class bodies are skipped.
	 * This is deliberately not control-flow analysis or execution of package PHP.
	 *
	 * @param PhpToken[] $tokens Parsed, non-ignorable tokens.
	 * @param array<string,bool> $helpers Global declarations collected across files.
	 */
	private function inspectPhpRegion( array $tokens, int $start, int $end, string $namespace, string $path, array &$helpers, ?string &$version ): void {
		$importsDefine = false;
		$defines = array();
		for ( $index = $start; $index < $end; ++$index ) {
			$token = $tokens[ $index ];
			if ( T_USE === $token->id ) {
				$importStart = $index;
				while ( $index < $end && ! in_array( $tokens[ $index ]->id, array( ord( ';' ), T_CLOSE_TAG ), true ) ) {
					++$index;
				}
				$importsDefine = $this->importsDefine( $tokens, $importStart, $index ) || $importsDefine;
				continue;
			}
			if ( T_ATTRIBUTE === $token->id ) {
				$index = $this->phpDelimiterEnd( $tokens, $index );
				continue;
			}
			if ( T_FUNCTION === $token->id ) {
				$nameIndex = $index + 1;
				if ( '&' === ( $tokens[ $nameIndex ]->text ?? '' ) ) {
					++$nameIndex;
				}
				if ( '' === $namespace && T_STRING === ( $tokens[ $nameIndex ]->id ?? null ) ) {
					$helpers[ strtolower( $tokens[ $nameIndex ]->text ) ] = true;
				}
				$index = $this->phpDeclarationEnd( $tokens, $index, $end );
				continue;
			}
			if ( in_array( $token->id, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) && T_DOUBLE_COLON !== ( $tokens[ $index - 1 ]->id ?? null ) ) {
				$index = $this->phpDeclarationEnd( $tokens, $index, $end );
				continue;
			}
			if ( T_FN === $token->id ) {
				$index = $this->phpArrowEnd( $tokens, $index, $end ) - 1;
				continue;
			}
			if ( 'core/bootstrap.php' === $path && in_array( $token->id, array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) && 'define' === strtolower( ltrim( $token->text, '\\' ) ) && ! in_array( $tokens[ $index - 1 ]->id ?? null, array( T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) && '(' === ( $tokens[ $index + 1 ]->text ?? '' ) && T_CONSTANT_ENCAPSED_STRING === ( $tokens[ $index + 2 ]->id ?? null ) && "'ST_WP_CORE_VERSION'" === str_replace( '"', "'", $tokens[ $index + 2 ]->text ) ) {
				$defines[] = $index;
			}
		}
		foreach ( $defines as $index ) {
			// A fully qualified call always names the builtin. Plain calls are only
			// certified in the global namespace with no function import shadowing it.
			if ( T_NAME_FULLY_QUALIFIED !== $tokens[ $index ]->id && ( '' !== $namespace || $importsDefine ) ) {
				continue;
			}
			$value = $tokens[ $index + 4 ] ?? null;
			if ( null !== $version || ',' !== ( $tokens[ $index + 3 ]->text ?? '' ) || null === $value || T_CONSTANT_ENCAPSED_STRING !== $value->id || ')' !== ( $tokens[ $index + 5 ]->text ?? '' ) ) {
				throw new RuntimeException( 'Invalid or duplicate bootstrap ST_WP_CORE_VERSION declaration.' );
			}
			$version = substr( $value->text, 1, -1 );
		}
	}

	/** @param PhpToken[] $tokens A use statement, including grouped/mixed imports. */
	private function importsDefine( array $tokens, int $start, int $end ): bool {
		$allFunctions = T_FUNCTION === ( $tokens[ $start + 1 ]->id ?? null );
		$isFunction = $allFunctions;
		$name = '';
		for ( $index = $start + 1; $index <= $end && isset( $tokens[ $index ] ); ++$index ) {
			$token = $tokens[ $index ];
			if ( T_FUNCTION === $token->id ) {
				$isFunction = true;
			} elseif ( in_array( $token->id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				// The last name is either the explicit alias or the imported basename.
				$name = $token->text;
			} elseif ( '{' === $token->text ) {
				$name = '';
			} elseif ( in_array( $token->id, array( ord( ',' ), ord( '}' ), ord( ';' ), T_CLOSE_TAG ), true ) ) {
				if ( $isFunction && 'define' === strtolower( basename( str_replace( '\\', '/', $name ) ) ) ) {
					return true;
				}
				$isFunction = $allFunctions;
				$name = '';
			}
		}
		return false;
	}

	/** @param PhpToken[] $tokens Skip an entire class/function, including its header. */
	private function phpDeclarationEnd( array $tokens, int $start, int $end ): int {
		for ( $index = $start + 1; $index < $end; ++$index ) {
			if ( ord( '{' ) === $tokens[ $index ]->id ) {
				return $this->phpDelimiterEnd( $tokens, $index );
			}
			if ( ';' === $tokens[ $index ]->text ) {
				return $index;
			}
			if ( $this->phpOpensDelimiter( $tokens[ $index ] ) ) {
				$index = $this->phpDelimiterEnd( $tokens, $index );
			}
		}
		return $end - 1;
	}

	/** @param PhpToken[] $tokens Skip a deferred arrow expression, not its enclosing delimiter. */
	private function phpArrowEnd( array $tokens, int $start, int $end ): int {
		for ( $index = $start + 1; $index < $end; ++$index ) {
			if ( in_array( $tokens[ $index ]->id, array( ord( ',' ), ord( ';' ), ord( ')' ), ord( ']' ), ord( '}' ), T_CLOSE_TAG ), true ) ) {
				return $index;
			}
			if ( $this->phpOpensDelimiter( $tokens[ $index ] ) ) {
				$index = $this->phpDelimiterEnd( $tokens, $index );
			}
		}
		return $end;
	}

	private function phpOpensDelimiter( PhpToken $token ): bool {
		return in_array( $token->id, array( ord( '(' ), ord( '[' ), ord( '{' ), T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true );
	}

	/** @param PhpToken[] $tokens Find the matching delimiter without interpreting expressions. */
	private function phpDelimiterEnd( array $tokens, int $start ): int {
		$depth = 1;
		$count = count( $tokens );
		for ( $index = $start + 1; $index < $count; ++$index ) {
			if ( $this->phpOpensDelimiter( $tokens[ $index ] ) ) {
				++$depth;
			} elseif ( in_array( $tokens[ $index ]->id, array( ord( ')' ), ord( ']' ), ord( '}' ) ), true ) && 0 === --$depth ) {
				return $index;
			}
		}
		throw new RuntimeException( 'Unbalanced PHP core resource delimiter.' );
	}
}
