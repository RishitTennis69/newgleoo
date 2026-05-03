<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the admin menu, enqueues the React app, injects bootstrap data + REST nonce,
 * and enqueues frontend CSS for injected content blocks.
 */
class Gleo_Admin {
	const SLUG = 'gleo';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'frontend_assets' ] );
	}

	public static function menu() {
		add_menu_page(
			'Gleo',
			'Gleo',
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-chart-line',
			58
		);
	}

	public static function render_page() {
		echo '<div class="wrap" id="gleo-root"><div class="gleo-loading">Loading Gleo…</div></div>';
	}

	public static function admin_assets( $hook ) {
		if ( strpos( $hook, self::SLUG ) === false ) {
			return;
		}

		$css_ver = file_exists( GLEO_PLUGIN_DIR . 'assets/admin.css' )
			? filemtime( GLEO_PLUGIN_DIR . 'assets/admin.css' )
			: GLEO_VERSION;

		$js_ver = file_exists( GLEO_PLUGIN_DIR . 'assets/admin.js' )
			? filemtime( GLEO_PLUGIN_DIR . 'assets/admin.js' )
			: GLEO_VERSION;

		wp_enqueue_style(
			'gleo-admin',
			GLEO_PLUGIN_URL . 'assets/admin.css',
			[],
			$css_ver
		);

		wp_enqueue_script(
			'gleo-admin',
			GLEO_PLUGIN_URL . 'assets/admin.js',
			[ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
			$js_ver,
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_localize_script( 'gleo-admin', 'GleoData', [
			'restUrl'  => esc_url_raw( rest_url( Gleo_Rest::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'siteName' => get_bloginfo( 'name' ),
			'siteUrl'  => home_url(),
			'autofixCap' => GLEO_AUTOFIX_CAP,
		] );
	}

	public static function frontend_assets() {
		wp_enqueue_style(
			'gleo-frontend',
			GLEO_PLUGIN_URL . 'assets/frontend.css',
			[],
			GLEO_VERSION
		);
	}
}
