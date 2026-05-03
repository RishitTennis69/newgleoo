<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves API keys from (1) wp-config constants, (2) plugin .env file, (3) plugin settings.
 * Constants win so production deployments can keep keys out of the database.
 */
class Gleo_Env {
	private static $loaded = false;
	private static $env_cache = [];

	public static function get( $key, $default = '' ) {
		$const = strtoupper( $key );
		if ( defined( $const ) ) {
			return constant( $const );
		}

		self::load_env_file();
		if ( isset( self::$env_cache[ $key ] ) && self::$env_cache[ $key ] !== '' ) {
			return self::$env_cache[ $key ];
		}

		$settings = get_option( 'gleo_settings', [] );
		$opt_key  = strtolower( $key );
		if ( ! empty( $settings[ $opt_key ] ) ) {
			return $settings[ $opt_key ];
		}

		return $default;
	}

	public static function gemini_key() {
		return self::get( 'GLEO_GEMINI_API_KEY', self::get( 'gemini_api_key' ) );
	}

	public static function tavily_key() {
		return self::get( 'GLEO_TAVILY_API_KEY', self::get( 'tavily_api_key' ) );
	}

	private static function load_env_file() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		$path = GLEO_PLUGIN_DIR . '.env';
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return;
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$parts = explode( '=', $line, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$k = trim( $parts[0] );
			$v = trim( $parts[1] );
			$v = trim( $v, "\"'" );
			self::$env_cache[ $k ]                   = $v;
			self::$env_cache[ strtolower( $k ) ]     = $v;
		}
	}
}
