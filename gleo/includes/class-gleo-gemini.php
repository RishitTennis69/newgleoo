<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin client for Google Gemini.
 * We use Flash for cost: rewrites, FAQ generation, mention-probe sampling.
 */
class Gleo_Gemini {
	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
	const DEFAULT_MODEL = 'gemini-2.0-flash';

	public static function generate( $prompt, $args = [] ) {
		$key = Gleo_Env::gemini_key();
		if ( empty( $key ) ) {
			return new WP_Error( 'gleo_no_key', 'Gemini API key is not configured.' );
		}

		$model = isset( $args['model'] ) ? $args['model'] : self::DEFAULT_MODEL;
		$url   = sprintf( self::ENDPOINT, rawurlencode( $model ) ) . '?key=' . rawurlencode( $key );

		$body = [
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [ [ 'text' => $prompt ] ],
				],
			],
			'generationConfig' => [
				'temperature'     => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.4,
				'maxOutputTokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 2048,
				'responseMimeType'=> isset( $args['json'] ) && $args['json'] ? 'application/json' : 'text/plain',
			],
		];

		if ( isset( $args['system'] ) ) {
			$body['systemInstruction'] = [
				'parts' => [ [ 'text' => $args['system'] ] ],
			];
		}

		$response = wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini request failed.';
			return new WP_Error( 'gleo_gemini_http', $msg, [ 'status' => $code, 'body' => $raw ] );
		}

		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		if ( $text === '' ) {
			return new WP_Error( 'gleo_gemini_empty', 'Gemini returned no text.', [ 'body' => $data ] );
		}

		return $text;
	}

	/**
	 * Helper for prompts that should return strict JSON.
	 */
	public static function generate_json( $prompt, $args = [] ) {
		$args['json'] = true;
		$text = self::generate( $prompt, $args );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$decoded = json_decode( $text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$cleaned = preg_replace( '/^```(?:json)?|```$/m', '', trim( $text ) );
			$decoded = json_decode( $cleaned, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'gleo_gemini_json', 'Gemini returned invalid JSON.', [ 'raw' => $text ] );
			}
		}
		return $decoded;
	}
}
