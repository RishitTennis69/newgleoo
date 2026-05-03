<?php
/**
 * Plugin Name: Gleo
 * Plugin URI: https://gleo.app
 * Description: Get your business mentioned by AI chatbots. Scans your site for Generative Engine Optimization (GEO) issues, applies one-click fixes, and tracks AI crawler traffic.
 * Version: 0.1.0
 * Author: Gleo
 * License: GPL-2.0-or-later
 * Text Domain: gleo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GLEO_VERSION', '0.1.0' );
define( 'GLEO_PLUGIN_FILE', __FILE__ );
define( 'GLEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GLEO_AUTOFIX_CAP', 10 );

require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-env.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-logger.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-gemini.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-tavily.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-scanner.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-scorer.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-revisions.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-schema.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-fixer.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-llms-txt.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-crawler-tracker.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-mention-probe.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-rest.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-admin.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo.php';

register_activation_hook( __FILE__, [ 'Gleo', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Gleo', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'Gleo', 'instance' ] );
