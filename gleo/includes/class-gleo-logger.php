<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gleo_Logger {
	public static function log( $message, $context = [] ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$line = '[Gleo] ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line );
	}
}
