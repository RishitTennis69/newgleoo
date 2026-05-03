<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST API. All endpoints require manage_options. The React admin UI consumes these.
 */
class Gleo_Rest {
	const NS = 'gleo/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function permission() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		$ns = self::NS;
		$auth = [ __CLASS__, 'permission' ];

		register_rest_route( $ns, '/status', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'status' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/preview', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'preview' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/scan', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'scan' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/scan/latest', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'scan_latest' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/fix', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'fix' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/fix/all', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'fix_all' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/changelog', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'changelog' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/revert', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'revert' ],
			'permission_callback' => $auth,
			'args'     => [
				'change_id' => [ 'type' => 'string', 'required' => false ],
				'all'       => [ 'type' => 'boolean', 'required' => false ],
			],
		] );

		register_rest_route( $ns, '/crawlers', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'crawlers' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/probe', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'probe_get' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/probe/run', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'probe_run' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/settings', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'settings_get' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/settings', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'settings_set' ],
			'permission_callback' => $auth,
		] );
	}

	public static function status() {
		return [
			'version'        => GLEO_VERSION,
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url(),
			'has_gemini_key' => ! empty( Gleo_Env::gemini_key() ),
			'has_tavily_key' => ! empty( Gleo_Env::tavily_key() ),
			'autofix_cap'    => GLEO_AUTOFIX_CAP,
		];
	}

	public static function preview() {
		$front_id = (int) get_option( 'page_on_front' );
		$blog_id  = (int) get_option( 'page_for_posts' );
		$posts = Gleo_Scanner::get_priority_posts( 30 );
		$cap = GLEO_AUTOFIX_CAP;
		$out = [];
		$editable_count = 0;
		foreach ( $posts as $i => $p ) {
			$is_pb  = Gleo_Scanner::is_page_builder_content( $p );
			$will_edit = false;
			if ( ! $is_pb && $editable_count < $cap ) {
				$will_edit = true;
				$editable_count++;
			}
			$role = '';
			if ( $p->ID === $front_id ) { $role = 'homepage'; }
			elseif ( $p->ID === $blog_id ) { $role = 'blog'; }
			$out[] = [
				'post_id'    => $p->ID,
				'title'      => get_the_title( $p ) ?: '(untitled)',
				'permalink'  => get_permalink( $p ),
				'post_type'  => $p->post_type,
				'word_count' => str_word_count( wp_strip_all_tags( $p->post_content ) ),
				'is_page_builder' => $is_pb,
				'will_auto_edit'  => $will_edit,
				'role'       => $role,
			];
		}
		return [
			'autofix_cap'   => $cap,
			'pages'         => $out,
			'total_scanned' => count( $out ),
			'total_will_edit' => $editable_count,
		];
	}

	public static function scan( $req ) {
		$body = $req->get_json_params();
		$ids  = isset( $body['post_ids'] ) && is_array( $body['post_ids'] ) ? $body['post_ids'] : null;
		$report = Gleo_Scanner::scan( $ids );
		$score  = Gleo_Scorer::score( $report );
		$report['score'] = $score;
		update_option( 'gleo_last_scan', $report, false );
		return $report;
	}

	public static function scan_latest() {
		$report = get_option( 'gleo_last_scan' );
		if ( ! $report ) {
			return new WP_REST_Response( [ 'empty' => true ], 200 );
		}
		return $report;
	}

	public static function fix( $req ) {
		$body = $req->get_json_params();
		if ( empty( $body['issue'] ) ) {
			return new WP_Error( 'gleo_bad_request', 'issue payload required', [ 'status' => 400 ] );
		}
		$res = Gleo_Fixer::apply_one( $body['issue'] );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( [ 'error' => $res->get_error_message() ], 400 );
		}
		return [ 'change_id' => $res ];
	}

	public static function fix_all( $req ) {
		$body = $req->get_json_params();
		$ids  = isset( $body['post_ids'] ) && is_array( $body['post_ids'] ) ? array_map( 'intval', $body['post_ids'] ) : null;
		$report = $ids ? Gleo_Scanner::scan( $ids ) : ( get_option( 'gleo_last_scan' ) ?: Gleo_Scanner::scan() );
		$result = Gleo_Fixer::apply_all( $report );
		// Re-scan after fixes for fresh score (same scope).
		$rescan = Gleo_Scanner::scan( $ids );
		$rescan['score'] = Gleo_Scorer::score( $rescan );
		update_option( 'gleo_last_scan', $rescan, false );
		$result['rescan'] = $rescan;
		return $result;
	}

	public static function changelog() {
		return [ 'entries' => Gleo_Revisions::all() ];
	}

	public static function revert( $req ) {
		$body = $req->get_json_params();
		if ( ! empty( $body['all'] ) ) {
			return Gleo_Revisions::revert_all();
		}
		if ( empty( $body['change_id'] ) ) {
			return new WP_Error( 'gleo_bad_request', 'change_id required', [ 'status' => 400 ] );
		}
		$res = Gleo_Revisions::revert( $body['change_id'] );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( [ 'error' => $res->get_error_message() ], 400 );
		}
		return [ 'reverted' => true ];
	}

	public static function crawlers( $req ) {
		$days = isset( $req['days'] ) ? max( 1, min( 365, (int) $req['days'] ) ) : 30;
		return Gleo_Crawler_Tracker::summary( $days );
	}

	public static function probe_get() {
		return [
			'results' => Gleo_Mention_Probe::results(),
			'trend'   => Gleo_Mention_Probe::trend(),
		];
	}

	public static function probe_run() {
		$res = Gleo_Mention_Probe::run();
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( [ 'error' => $res->get_error_message() ], 400 );
		}
		return $res;
	}

	public static function settings_get() {
		$s = get_option( 'gleo_settings', [] );
		// Mask keys.
		$s['gemini_api_key_set'] = ! empty( Gleo_Env::gemini_key() );
		$s['tavily_api_key_set'] = ! empty( Gleo_Env::tavily_key() );
		unset( $s['gemini_api_key'], $s['tavily_api_key'] );
		return $s;
	}

	public static function settings_set( $req ) {
		$body = $req->get_json_params();
		$current = get_option( 'gleo_settings', [] );
		$allowed = [ 'gemini_api_key', 'tavily_api_key', 'autofix_cap', 'probe_enabled', 'probe_queries', 'probe_brand', 'probe_samples' ];
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $body ) ) {
				$v = $body[ $k ];
				if ( $k === 'probe_queries' ) {
					$v = array_values( array_filter( array_map( 'sanitize_text_field', (array) $v ) ) );
				} elseif ( $k === 'probe_enabled' ) {
					$v = (bool) $v;
				} elseif ( $k === 'autofix_cap' || $k === 'probe_samples' ) {
					$v = max( 1, (int) $v );
				} else {
					$v = sanitize_text_field( $v );
				}
				$current[ $k ] = $v;
			}
		}
		update_option( 'gleo_settings', $current, false );
		return self::settings_get();
	}
}
