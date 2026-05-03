<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Logs hits from known AI crawlers (and AI-search user agents) to a custom table.
 * Hooked very early so we capture even uncached requests.
 *
 * Real data — no estimation. We only log requests we can directly observe.
 */
class Gleo_Crawler_Tracker {
	const TABLE = 'gleo_crawler_hits';

	private static $bot_signatures = [
		'GPTBot'           => 'OpenAI',
		'ChatGPT-User'     => 'OpenAI',
		'OAI-SearchBot'    => 'OpenAI',
		'ClaudeBot'        => 'Anthropic',
		'Claude-Web'       => 'Anthropic',
		'anthropic-ai'     => 'Anthropic',
		'PerplexityBot'    => 'Perplexity',
		'Perplexity-User'  => 'Perplexity',
		'Google-Extended'  => 'Google',
		'Googlebot'        => 'Google',
		'Bingbot'          => 'Microsoft',
		'CCBot'            => 'CommonCrawl',
		'cohere-ai'        => 'Cohere',
		'Bytespider'       => 'ByteDance',
		'Applebot'         => 'Apple',
		'Meta-ExternalAgent'=> 'Meta',
		'FacebookBot'      => 'Meta',
		'DuckAssistBot'    => 'DuckDuckGo',
		'YouBot'           => 'You.com',
		'mistralai'        => 'Mistral',
	];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_log' ], 1 );
	}

	public static function install() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hit_time DATETIME NOT NULL,
			bot_name VARCHAR(64) NOT NULL,
			bot_owner VARCHAR(64) NOT NULL,
			user_agent VARCHAR(255) NOT NULL,
			path VARCHAR(255) NOT NULL,
			ip_hash CHAR(40) NOT NULL,
			PRIMARY KEY (id),
			KEY bot_time (bot_name, hit_time),
			KEY hit_time (hit_time)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function maybe_log() {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}
		$ua = $_SERVER['HTTP_USER_AGENT'];
		$matched = self::match_bot( $ua );
		if ( ! $matched ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$wpdb->insert( $table, [
			'hit_time'   => current_time( 'mysql' ),
			'bot_name'   => $matched['name'],
			'bot_owner'  => $matched['owner'],
			'user_agent' => mb_substr( $ua, 0, 255 ),
			'path'       => mb_substr( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', 0, 255 ),
			'ip_hash'    => sha1( $ip . wp_salt() ),
		] );
	}

	private static function match_bot( $ua ) {
		foreach ( self::$bot_signatures as $signature => $owner ) {
			if ( stripos( $ua, $signature ) !== false ) {
				return [ 'name' => $signature, 'owner' => $owner ];
			}
		}
		return null;
	}

	public static function summary( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hit_time >= %s", $since ) );
		$by_bot = $wpdb->get_results( $wpdb->prepare(
			"SELECT bot_name, bot_owner, COUNT(*) AS hits FROM $table WHERE hit_time >= %s GROUP BY bot_name, bot_owner ORDER BY hits DESC",
			$since
		), ARRAY_A );
		$top_paths = $wpdb->get_results( $wpdb->prepare(
			"SELECT path, COUNT(*) AS hits FROM $table WHERE hit_time >= %s GROUP BY path ORDER BY hits DESC LIMIT 15",
			$since
		), ARRAY_A );
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(hit_time) AS day, COUNT(*) AS hits FROM $table WHERE hit_time >= %s GROUP BY DATE(hit_time) ORDER BY day ASC",
			$since
		), ARRAY_A );

		return [
			'days'      => $days,
			'total'     => $total,
			'by_bot'    => $by_bot ?: [],
			'top_paths' => $top_paths ?: [],
			'daily'     => $daily ?: [],
		];
	}
}
