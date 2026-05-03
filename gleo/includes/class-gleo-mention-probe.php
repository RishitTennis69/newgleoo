<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mention Probe — samples Gemini Flash for a list of user-defined queries
 * and counts brand mentions. Transparent methodology: stored alongside results.
 *
 * This is NOT real ChatGPT/Claude/etc. user traffic. It is a reproducible probe
 * against one model. Trends are meaningful; absolute % is a sample.
 */
class Gleo_Mention_Probe {
	const RESULTS_OPTION = 'gleo_probe_results';
	const HOOK = 'gleo_probe_run_hook';

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run_scheduled' ] );
	}

	public static function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['gleo_weekly'] ) ) {
			$schedules['gleo_weekly'] = [
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once a week (Gleo)', 'gleo' ),
			];
		}
		return $schedules;
	}

	public static function install() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'gleo_weekly', self::HOOK );
		}
		if ( ! get_option( self::RESULTS_OPTION ) ) {
			add_option( self::RESULTS_OPTION, [], '', false );
		}
	}

	public static function uninstall_cron() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	public static function run_scheduled() {
		$settings = get_option( 'gleo_settings', [] );
		if ( empty( $settings['probe_enabled'] ) ) { return; }
		self::run();
	}

	public static function run() {
		$settings = get_option( 'gleo_settings', [] );
		$queries  = isset( $settings['probe_queries'] ) ? array_filter( $settings['probe_queries'] ) : [];
		$brand    = isset( $settings['probe_brand'] ) ? trim( $settings['probe_brand'] ) : get_bloginfo( 'name' );
		$samples  = isset( $settings['probe_samples'] ) ? max( 1, min( 10, (int) $settings['probe_samples'] ) ) : 5;

		if ( empty( $queries ) || empty( $brand ) ) {
			return new WP_Error( 'gleo_probe_unconfigured', 'Probe queries or brand are not configured.' );
		}
		if ( empty( Gleo_Env::gemini_key() ) ) {
			return new WP_Error( 'gleo_no_key', 'Gemini API key required.' );
		}

		$run = [
			'time'      => current_time( 'mysql' ),
			'brand'     => $brand,
			'samples'   => $samples,
			'model'     => Gleo_Gemini::DEFAULT_MODEL,
			'queries'   => [],
		];

		foreach ( $queries as $query ) {
			$mentions = 0;
			$snippets = [];
			for ( $i = 0; $i < $samples; $i++ ) {
				$prompt = "Answer this question conversationally for a real user: \"$query\". Recommend specific businesses by name where relevant. Keep your answer under 200 words.";
				$response = Gleo_Gemini::generate( $prompt, [ 'temperature' => 0.7, 'max_tokens' => 400 ] );
				if ( is_wp_error( $response ) ) { continue; }
				if ( self::brand_mentioned( $response, $brand ) ) {
					$mentions++;
					$snippets[] = self::extract_mention_context( $response, $brand );
				}
			}
			$run['queries'][] = [
				'query'    => $query,
				'mentions' => $mentions,
				'samples'  => $samples,
				'rate'     => $samples > 0 ? round( ( $mentions / $samples ) * 100 ) : 0,
				'snippets' => array_slice( array_filter( $snippets ), 0, 3 ),
			];
		}

		$results = get_option( self::RESULTS_OPTION, [] );
		array_unshift( $results, $run );
		$results = array_slice( $results, 0, 52 ); // ~1 year of weekly runs
		update_option( self::RESULTS_OPTION, $results, false );

		return $run;
	}

	private static function brand_mentioned( $text, $brand ) {
		$brand = trim( $brand );
		if ( $brand === '' ) { return false; }
		// Case-insensitive whole-ish match — escape regex special chars in brand.
		$pattern = '/\b' . preg_quote( $brand, '/' ) . '\b/i';
		return (bool) preg_match( $pattern, $text );
	}

	private static function extract_mention_context( $text, $brand ) {
		$pos = stripos( $text, $brand );
		if ( $pos === false ) { return ''; }
		$start = max( 0, $pos - 80 );
		$end   = min( strlen( $text ), $pos + strlen( $brand ) + 80 );
		$snippet = trim( substr( $text, $start, $end - $start ) );
		return ( $start > 0 ? '…' : '' ) . $snippet . ( $end < strlen( $text ) ? '…' : '' );
	}

	public static function results() {
		return get_option( self::RESULTS_OPTION, [] );
	}

	// Filter is registered at module load (bottom of file) so the schedule is
	// available even during plugin activation, before plugins_loaded fires.
	public static function trend() {
		$results = self::results();
		$out = [];
		foreach ( array_reverse( $results ) as $run ) {
			$total = 0; $mentions = 0;
			foreach ( $run['queries'] as $q ) {
				$total    += $q['samples'];
				$mentions += $q['mentions'];
			}
			$out[] = [
				'date'     => substr( $run['time'], 0, 10 ),
				'rate'     => $total > 0 ? round( ( $mentions / $total ) * 100 ) : 0,
				'mentions' => $mentions,
				'total'    => $total,
			];
		}
		return $out;
	}
}

add_filter( 'cron_schedules', [ 'Gleo_Mention_Probe', 'add_weekly_schedule' ] );
