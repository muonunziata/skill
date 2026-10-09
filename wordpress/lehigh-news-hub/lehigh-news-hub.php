<?php
/**
 * Plugin Name:       Lehigh News Hub
 * Plugin URI:        https://github.com/muonunziata/skill
 * Description:       Editorial hub for AI news agents: review queue with audit scores, live view of what the Researcher, Writer and Auditor agents did, one-click publishing, and a fully configurable latest-news shortcode.
 * Version:           1.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lehigh News Hub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lehigh-news-hub
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LNH_VERSION', '1.4.1' );
define( 'LNH_FILE', __FILE__ );
define( 'LNH_DIR', plugin_dir_path( __FILE__ ) );
define( 'LNH_URL', plugin_dir_url( __FILE__ ) );

require_once LNH_DIR . 'includes/class-lnh-util.php';
require_once LNH_DIR . 'includes/class-lnh-settings.php';
require_once LNH_DIR . 'includes/class-lnh-meta.php';
require_once LNH_DIR . 'includes/class-lnh-pages.php';
require_once LNH_DIR . 'includes/class-lnh-connect.php';
require_once LNH_DIR . 'includes/class-lnh-runs.php';
require_once LNH_DIR . 'includes/class-lnh-control.php';
require_once LNH_DIR . 'includes/class-lnh-rest.php';
require_once LNH_DIR . 'includes/class-lnh-queue.php';
require_once LNH_DIR . 'includes/class-lnh-shortcode.php';
require_once LNH_DIR . 'includes/class-lnh-front.php';
require_once LNH_DIR . 'includes/class-lnh-admin.php';
require_once LNH_DIR . 'includes/class-lnh-plugin.php';

register_activation_hook( __FILE__, array( 'LNH_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LNH_Plugin', 'deactivate' ) );

LNH_Plugin::instance();
