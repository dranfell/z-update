<?php
/**
 * Plugin Name: Z-Update — Agent
 * Description: Agent léger pour l’orchestration sécurisée des mises à jour WordPress sur hébergement mutualisé.
 * Version: 0.4.20
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Zimages
 * License: GPL-2.0-or-later
 * Text Domain: wuo-agent
 */

defined( 'ABSPATH' ) || exit;

define( 'WUO_AGENT_VERSION', '0.4.20' );
define( 'WUO_AGENT_FILE', __FILE__ );
define( 'WUO_AGENT_DIR', plugin_dir_path( __FILE__ ) );

require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-signature.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-crypto.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-updraft.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-health.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-runner.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-client.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-control.php';
require_once WUO_AGENT_DIR . 'includes/class-wuo-agent-admin.php';
require_once WUO_AGENT_DIR . 'includes/class-z-update-package-verifier.php';
require_once WUO_AGENT_DIR . 'includes/class-z-update-agent-updater.php';

register_activation_hook( __FILE__, array( 'WUO_Agent_Client', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WUO_Agent_Client', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	WUO_Agent_Updraft::init();
	WUO_Agent_Client::init();
	WUO_Agent_Control::init();
	WUO_Agent_Admin::init();
	Z_Update_Agent_Updater::init();
} );
