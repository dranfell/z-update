<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Health {
	const BASELINE_OPTION = 'wuo_agent_health_baseline';

	public static function collect( $update_baseline = true ) {
		global $wpdb;

		$db_start = microtime( true );
		$db_ok    = '1' === (string) $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db_ms    = round( ( microtime( true ) - $db_start ) * 1000, 1 );

		$http_start = microtime( true );
		$response   = wp_safe_remote_get( home_url( '/' ), array(
			'timeout'     => 10,
			'redirection' => 2,
			'headers'     => array( 'X-WUO-Probe' => '1' ),
		) );
		$http_ms = round( ( microtime( true ) - $http_start ) * 1000, 1 );
		$code    = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		$probe_file = trailingslashit( WP_CONTENT_DIR ) . '.wuo-write-probe-' . wp_generate_password( 8, false );
		$write_ok   = false !== @file_put_contents( $probe_file, 'ok', LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $write_ok ) {
			@unlink( $probe_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$baseline = get_option( self::BASELINE_OPTION, array( 'http_ms' => 0, 'db_ms' => 0 ) );
		$healthy  = $db_ok && $write_ok && $code >= 200 && $code < 400;
		if ( $healthy && $update_baseline ) {
			$baseline['http_ms'] = self::ema( $baseline['http_ms'], $http_ms );
			$baseline['db_ms']   = self::ema( $baseline['db_ms'], $db_ms );
			update_option( self::BASELINE_OPTION, $baseline, false );
		}

		$degraded = ! $healthy;
		if ( ! $degraded && ! empty( $baseline['http_ms'] ) ) {
			$degraded = $http_ms > ( (float) $baseline['http_ms'] * 2 );
		}
		if ( ! $degraded && ! empty( $baseline['db_ms'] ) ) {
			$degraded = $db_ms > ( (float) $baseline['db_ms'] * 2 );
		}

		return array(
			'healthy'           => $healthy && ! $degraded,
			'degraded'          => $degraded,
			'http_code'         => $code,
			'http_ms'           => $http_ms,
			'db_ms'             => $db_ms,
			'write_ok'          => $write_ok,
			'php_memory_used'   => memory_get_usage( true ),
			'php_memory_limit'  => ini_get( 'memory_limit' ),
			'baseline_http_ms'  => isset( $baseline['http_ms'] ) ? round( $baseline['http_ms'], 1 ) : 0,
			'baseline_db_ms'    => isset( $baseline['db_ms'] ) ? round( $baseline['db_ms'], 1 ) : 0,
			'checked_at'        => time(),
		);
	}

	private static function ema( $current, $sample ) {
		return $current > 0 ? ( (float) $current * 0.8 ) + ( (float) $sample * 0.2 ) : (float) $sample;
	}
}
