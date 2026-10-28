<?php
/**
 * WP-CLI stubs for PHPStan analysis (loaded via scanFiles).
 *
 * The php-stubs/wp-cli-stubs package cannot be installed alongside wordpress-stubs 7.x,
 * so only the WP_CLI methods src/Cli/Command.php calls are declared here.
 * Not executed at runtime.
 *
 * @package Coldstash
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.CodeAnalysis.UnusedFunctionParameter -- Stub of WP-CLI's own class: it must keep WP-CLI's name and signatures.

/**
 * Stub of WP-CLI's static facade.
 */
class WP_CLI {
	/**
	 * Registers a command.
	 *
	 * @param string                 $name    Command name.
	 * @param callable|object|string $command Command implementation.
	 * @param array<string, mixed>   $args    Registration arguments.
	 * @return bool
	 */
	public static function add_command( $name, $command, $args = array() ) {
		return true;
	}

	/**
	 * Prints a line to STDOUT.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public static function line( $message = '' ) {
	}

	/**
	 * Prints a success message.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public static function success( $message ) {
	}

	/**
	 * Prints a warning to STDERR.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public static function warning( $message ) {
	}

	/**
	 * Prints an error and, by default, exits.
	 *
	 * @param string   $message Message to print.
	 * @param bool|int $exit_code    Exit code, or false to keep running.
	 * @return never
	 */
	public static function error( $message, $exit_code = true ) {
		exit( 1 );
	}
}
