<?php
defined( 'ABSPATH' ) || exit;

final class Z_Update_Agent_Updater {
	public static function init() {
		$settings   = get_option( WUO_Agent_Client::OPTION, array() );
		$policy     = isset( $settings['policy'] ) && is_array( $settings['policy'] ) ? $settings['policy'] : array();
		$repository = Z_Update_Package_Verifier::valid_repository( $policy['update_repository'] ?? '' );
		if ( ! $repository || ! Z_Update_Package_Verifier::register( WUO_AGENT_FILE, $repository, 'z-update-agent' ) ) {
			return;
		}
		self::load_library();
		$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$repository,
			WUO_AGENT_FILE,
			'wp-update-orchestrator-agent',
			24
		);
		if ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) {
			$checker->getVcsApi()->enableReleaseAssets( '/z-update-agent-[0-9][0-9A-Za-z._-]*\\.zip($|[?&#])/i' );
		}
		$generation = absint( $policy['update_check_generation'] ?? 0 );
		$last       = absint( get_option( 'z_update_agent_check_generation', 0 ) );
		if ( $generation > $last && is_object( $checker ) && method_exists( $checker, 'checkForUpdates' ) ) {
			$checker->checkForUpdates();
			update_option( 'z_update_agent_check_generation', $generation, false );
		}
	}

	private static function load_library() {
		if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory', false ) ) {
			require WUO_AGENT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		}
	}
}
