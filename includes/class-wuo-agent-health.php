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
			'db_diagnostics'    => self::database_diagnostics(),
			'checked_at'        => time(),
		);
	}

	/** Collecte d’indicateurs d’entretien sans modifier la base. */
	private static function database_diagnostics() {
		global $wpdb;
		$empty = array( 'database_size_bytes' => 0, 'autoload_bytes' => 0, 'autoload_count' => 0, 'expired_transients' => 0, 'revisions_count' => 0, 'largest_table' => '', 'largest_table_bytes' => 0 );
		if ( ! is_object( $wpdb ) || empty( $wpdb->dbname ) ) {
			return $empty;
		}
		$options = $wpdb->options;
		$posts   = $wpdb->posts;
		$size = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(data_length + index_length),0) AS bytes FROM information_schema.tables WHERE table_schema=%s', $wpdb->dbname ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$autoload = $wpdb->get_row( "SELECT COALESCE(SUM(LENGTH(option_value)),0) AS bytes,COUNT(*) AS items FROM {$options} WHERE autoload='yes'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$expired = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$revisions = $wpdb->get_var( "SELECT COUNT(*) FROM {$posts} WHERE post_type='revision'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$largest = $wpdb->get_row( $wpdb->prepare( 'SELECT table_name,COALESCE(data_length + index_length,0) AS bytes FROM information_schema.tables WHERE table_schema=%s ORDER BY bytes DESC LIMIT 1', $wpdb->dbname ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'database_size_bytes' => absint( $size->bytes ?? 0 ),
			'autoload_bytes'      => absint( $autoload->bytes ?? 0 ),
			'autoload_count'      => absint( $autoload->items ?? 0 ),
			'expired_transients'  => absint( $expired ),
			'revisions_count'     => absint( $revisions ),
			'largest_table'       => sanitize_text_field( $largest->table_name ?? '' ),
			'largest_table_bytes' => absint( $largest->bytes ?? 0 ),
		);
	}

	private static function ema( $current, $sample ) {
		return $current > 0 ? ( (float) $current * 0.8 ) + ( (float) $sample * 0.2 ) : (float) $sample;
	}
}
