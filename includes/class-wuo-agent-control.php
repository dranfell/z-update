<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Control {
	public static function init() {
		if ( ! self::is_paired() ) {
			return;
		}
		add_filter( 'auto_update_plugin', '__return_false', PHP_INT_MAX );
		add_filter( 'auto_update_theme', '__return_false', PHP_INT_MAX );
		add_filter( 'allow_dev_auto_core_updates', '__return_false', PHP_INT_MAX );
		add_filter( 'allow_major_auto_core_updates', '__return_false', PHP_INT_MAX );
		add_filter( 'allow_minor_auto_core_updates', array( __CLASS__, 'allow_minor_core' ), PHP_INT_MAX );
		add_filter( 'auto_update_core', array( __CLASS__, 'allow_core_update' ), PHP_INT_MAX, 2 );
	}

	public static function effective_mode() {
		$settings = get_option( WUO_Agent_Client::OPTION, array() );
		$policy   = isset( $settings['policy'] ) && is_array( $settings['policy'] ) ? $settings['policy'] : array();
		$mode     = isset( $policy['control_mode'] ) ? sanitize_key( $policy['control_mode'] ) : 'total';
		$hours    = isset( $policy['failover_hours'] ) ? max( 1, absint( $policy['failover_hours'] ) ) : 48;
		$contact  = isset( $settings['last_master_contact'] ) ? absint( $settings['last_master_contact'] ) : 0;
		if ( 'total' === $mode && ! empty( $policy['failover_security'] ) && $contact && $contact < time() - $hours * HOUR_IN_SECONDS ) {
			return 'security';
		}
		return in_array( $mode, array( 'total', 'security' ), true ) ? $mode : 'total';
	}

	public static function allow_minor_core( $allowed ) {
		// Les correctifs de sécurité WordPress restent natifs et ne passent plus
		// par la file Z-Update, y compris lorsque le maître est joignable.
		return $allowed;
	}

	public static function allow_core_update( $allowed, $item ) {
		if ( 'total' === self::effective_mode() ) {
			return self::is_security_core_item( $item ) ? $allowed : false;
		}
		return $allowed;
	}

	private static function is_security_core_item( $item ) {
		$response = is_object( $item ) ? ( $item->response ?? '' ) : ( is_array( $item ) ? ( $item['response'] ?? '' ) : '' );
		$response = strtolower( sanitize_key( $response ) );
		return in_array( $response, array( 'minor', 'security', 'security_update', 'autoupdate' ), true ) || false !== strpos( $response, 'security' );
	}

	private static function is_paired() {
		$settings = get_option( WUO_Agent_Client::OPTION, array() );
		return ! empty( $settings['site_id'] ) && ! empty( $settings['secret_enc'] );
	}
}
