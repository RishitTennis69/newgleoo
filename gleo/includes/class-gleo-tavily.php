<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tavily search client. Used to ground content rewrites in real sources
 * so we don't fabricate stats/quotes.
 */
class Gleo_Tavily {
	const ENDPOINT = 'https://api.tavily.com/search';

	public static function search( $query, $args = [] ) {
		$key = Gleo_Env::tavily_key();
		if ( empty( $key ) ) {
			return new WP_Error( 'gleo_no_key', 'Tavily API key is not configured.' );
		}

		$body = [
			'api_key'           => $key,
			'query'             => $query,
			'search_depth'      => isset( $args['depth'] ) ? $args['depth'] : 'basic',
			'max_results'       => isset( $args['max_results'] ) ? (int) $args['max_results'] : 5,
			'include_answer'    => true,
			'include_raw_content' => false,
		];

		$response = wp_remote_post( self::ENDPOINT, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error'] ) ? $data['error'] : 'Tavily request failed.';
			return new WP_Error( 'gleo_tavily_http', $msg, [ 'status' => $code ] );
		}

		return $data;
	}
}
