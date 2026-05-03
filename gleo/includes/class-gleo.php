<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Main coordinator. Boots subsystems, owns activation/deactivation.
 */
class Gleo {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Gleo_Crawler_Tracker::init();
		Gleo_LLMs_Txt::init();
		Gleo_Rest::init();
		Gleo_Admin::init();
		Gleo_Mention_Probe::init();
	}

	public static function activate() {
		Gleo_Crawler_Tracker::install();
		Gleo_Mention_Probe::install();

		if ( ! get_option( 'gleo_settings' ) ) {
			add_option( 'gleo_settings', [
				'gemini_api_key'  => '',
				'tavily_api_key'  => '',
				'autofix_cap'     => GLEO_AUTOFIX_CAP,
				'probe_enabled'   => false,
				'probe_queries'   => [],
				'probe_brand'     => get_bloginfo( 'name' ),
				'probe_samples'   => 5,
			] );
		}

		flush_rewrite_rules();
	}

	public static function deactivate() {
		Gleo_Mention_Probe::uninstall_cron();
		flush_rewrite_rules();
	}
}
