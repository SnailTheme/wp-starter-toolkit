<?php
/**
 * npm command runner.
 *
 * The starter theme uses npm and package-lock.json for V1. Block installation
 * uses this runner instead of shell strings so commands stay portable across
 * Linux, macOS, and Windows.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Process\Process;

/**
 * Runs npm commands in a target theme directory.
 */
final class NpmRunner {
	/**
	 * Install declared npm dependencies.
	 *
	 * @param array<string,string> $dependencies Package names to version constraints.
	 *
	 * @return array<string,mixed>
	 */
	public function installDependencies( ThemeContext $theme, array $dependencies ): array {
		if ( array() === $dependencies ) {
			return array(
				'status'  => 'skipped',
				'command' => 'dependencies already satisfied',
			);
		}

		return $this->run( $theme, array_merge( array( 'npm', 'install' ), $this->dependencyArguments( $dependencies ) ) );
	}

	/**
	 * Run the theme production asset build.
	 *
	 * @return array<string,mixed>
	 */
	public function build( ThemeContext $theme ): array {
		return $this->run( $theme, array( 'npm', 'run', 'build' ) );
	}

	/**
	 * Return the command users should run for missing dependencies.
	 *
	 * @param array<string,string> $dependencies Package names to version constraints.
	 */
	public function installCommand( array $dependencies ): string {
		return $this->commandString( array_merge( array( 'npm', 'install' ), $this->dependencyArguments( $dependencies ) ) );
	}

	/**
	 * Return the command users should run to rebuild assets.
	 */
	public function buildCommand(): string {
		return $this->commandString( array( 'npm', 'run', 'build' ) );
	}

	/**
	 * Convert dependency map to npm package arguments.
	 *
	 * @param array<string,string> $dependencies Package names to version constraints.
	 *
	 * @return string[]
	 */
	private function dependencyArguments( array $dependencies ): array {
		$args = array();

		foreach ( $dependencies as $name => $version ) {
			$args[] = $version ? $name . '@' . $version : $name;
		}

		return $args;
	}

	/**
	 * Run a process and return useful output for summaries and JSON.
	 *
	 * @param string[] $command Process command arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function run( ThemeContext $theme, array $command ): array {
		$process = new Process( $command, $theme->path );
		$process->setTimeout( null );
		$process->run();

		$result = array(
			'status'       => $process->isSuccessful() ? 'completed' : 'failed',
			'command'      => $this->commandString( $command ),
			'exit_code'    => $process->getExitCode(),
			'output'       => trim( $process->getOutput() ),
			'error_output' => trim( $process->getErrorOutput() ),
		);

		if ( ! $process->isSuccessful() ) {
			throw new RuntimeException(
				sprintf(
					"Command failed: %s\n%s",
					$result['command'],
					$result['error_output'] ?: $result['output']
				)
			);
		}

		return $result;
	}

	/**
	 * Build a readable command string for output.
	 *
	 * @param string[] $command Process command arguments.
	 */
	private function commandString( array $command ): string {
		return implode( ' ', $command );
	}
}
