<?php
/**
 * Conservative path checks for bundle sources and theme writes.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;

final class BlockBundlePaths {
	public static function assertRelative( string $path ): void {
		if ( '' === $path || preg_match( '/[\\\\:\x00-\x1f]/', $path ) || str_starts_with( $path, '/' ) ) {
			throw new RuntimeException( 'Unsafe bundle path: ' . $path );
		}

		foreach ( explode( '/', $path ) as $part ) {
			if ( in_array( $part, array( '', '.', '..' ), true ) ) {
				throw new RuntimeException( 'Unsafe bundle path: ' . $path );
			}
		}
	}

	/**
	 * Reject links even when currently pointing inside the theme: they can change
	 * between confirmation and execution and are not package-owned regular files.
	 */
	public static function assertTarget( ThemeContext $theme, string $relative ): void {
		clearstatcache( true );
		self::assertRelative( $relative );
		if ( is_link( $theme->path ) || ! is_dir( $theme->path ) ) {
			throw new RuntimeException( 'Unsafe bundle theme root: ' . $theme->path );
		}
		$path = $theme->path;
		$parts = explode( '/', $relative );

		foreach ( $parts as $index => $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $path ) || ( file_exists( $path ) && ( $index === count( $parts ) - 1 ? ! is_file( $path ) : ! is_dir( $path ) ) ) ) {
				throw new RuntimeException( 'Unsafe bundle write target: ' . $relative );
			}
			if ( file_exists( $path ) && ! is_writable( $path ) ) {
				throw new RuntimeException( 'Bundle target is not writable: ' . $relative );
			}
		}

		$ancestor = dirname( $path );
		while ( ! file_exists( $ancestor ) ) {
			$ancestor = dirname( $ancestor );
		}
		if ( ! is_writable( $ancestor ) ) {
			throw new RuntimeException( 'Bundle target parent is not writable: ' . $relative );
		}
	}

	/** Read a fresh regular-file hash; unreadable files must never look absent. */
	public static function fileHash( string $path ): ?string {
		clearstatcache( true );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new RuntimeException( 'Unsafe bundle file: ' . $path );
		}
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$hash = @hash_file( 'sha256', $path );
		if ( false === $hash ) {
			throw new RuntimeException( 'Could not hash bundle file: ' . $path );
		}
		return $hash;
	}

	/** @return array<string,int|string> Detect replacement/redirect of the root. */
	public static function rootIdentity( ThemeContext $theme ): array {
		clearstatcache( true );
		$stat = @stat( $theme->path );
		$real = realpath( $theme->path );
		if ( false === $stat || false === $real || is_link( $theme->path ) || ! is_dir( $theme->path ) ) {
			throw new RuntimeException( 'Unsafe bundle theme root: ' . $theme->path );
		}
		return array( 'path' => $real, 'dev' => $stat['dev'], 'ino' => $stat['ino'] );
	}

	/** @return string[] Regular source files, including dotfiles. */
	public static function sourceFiles( string $root ): array {
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			throw new RuntimeException( 'Invalid bundle source directory: ' . $root );
		}
		$files = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $file ) {
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			self::assertRelative( $relative );
			if ( $file->isLink() || ( ! $file->isDir() && ! $file->isFile() ) ) {
				throw new RuntimeException( 'Unsafe bundle source: ' . $file->getPathname() );
			}
			if ( $file->isFile() ) {
				$files[] = $relative;
			}
		}
		sort( $files );
		return $files;
	}
}
