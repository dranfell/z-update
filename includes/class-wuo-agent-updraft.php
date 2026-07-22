<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Updraft {
	const SNAPSHOT_OPTION = 'wuo_agent_updraft_snapshot';

	public static function init() {
		add_filter( 'updraft_plus_save_last_backup', array( __CLASS__, 'capture_last_backup' ), 99 );
		add_action( 'updraft_backup', array( __CLASS__, 'mark_running' ), 1 );
		add_action( 'updraft_backup_database', array( __CLASS__, 'mark_running' ), 1 );
	}

	public static function mark_running() {
		set_transient( 'wuo_updraft_running', time(), 6 * HOUR_IN_SECONDS );
	}

	public static function capture_last_backup( $last_backup ) {
		update_option( self::SNAPSHOT_OPTION, self::normalise_last_backup( $last_backup ), false );
		delete_transient( 'wuo_updraft_running' );
		return $last_backup;
	}

	public static function is_running() {
		return (bool) get_transient( 'wuo_updraft_running' );
	}

	public static function status() {
		$status = array(
			'installed'          => defined( 'UPDRAFTPLUS_DIR' ) || class_exists( 'UpdraftPlus' ),
			'latest_timestamp'   => 0,
			'age_seconds'        => null,
			'destination'        => '',
			'remote_confirmed'   => false,
			'has_database'       => false,
			'has_files'          => false,
			'complete'           => false,
			'running'            => self::is_running(),
			'next_file_backup'   => self::next_scheduled( 'updraft_backup' ),
			'next_database_backup'=> self::next_scheduled( 'updraft_backup_database' ),
			'source'             => 'none',
		);

		if ( ! $status['installed'] ) {
			return $status;
		}

		$history = self::history();
		if ( ! empty( $history ) ) {
			$timestamp = max( array_map( 'intval', array_keys( $history ) ) );
			$entry     = isset( $history[ $timestamp ] ) && is_array( $history[ $timestamp ] ) ? $history[ $timestamp ] : array();
			$status    = array_merge( $status, self::normalise_history_entry( $timestamp, $entry ) );
			$status['source'] = 'history';
		} else {
			$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
			if ( is_array( $snapshot ) && ! empty( $snapshot['latest_timestamp'] ) ) {
				$status = array_merge( $status, $snapshot );
				$status['source'] = 'hook';
			}
		}

		if ( $status['latest_timestamp'] ) {
			$status['age_seconds'] = max( 0, time() - (int) $status['latest_timestamp'] );
		}
		$status['complete'] = (bool) ( $status['remote_confirmed'] && $status['has_database'] && $status['has_files'] );

		return $status;
	}

	private static function next_scheduled( $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		return $timestamp ? (int) $timestamp : 0;
	}

	private static function history() {
		global $updraftplus;
		if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'get_backup_history' ) ) {
			$history = $updraftplus->get_backup_history();
			return is_array( $history ) ? $history : array();
		}
		if ( class_exists( 'UpdraftPlus_Backup_History' ) && is_callable( array( 'UpdraftPlus_Backup_History', 'get_history' ) ) ) {
			$history = UpdraftPlus_Backup_History::get_history();
			return is_array( $history ) ? $history : array();
		}
		return array();
	}

	private static function normalise_history_entry( $timestamp, array $entry ) {
		$service = '';
		foreach ( array( 'service', 'services', 'remote_service', 'backup_destination' ) as $key ) {
			if ( ! empty( $entry[ $key ] ) ) {
				$service = is_array( $entry[ $key ] ) ? implode( ',', $entry[ $key ] ) : (string) $entry[ $key ];
				break;
			}
		}
		$service = strtolower( $service );
		$has_db  = ! empty( $entry['db'] ) || ! empty( $entry['database'] );
		$has_files = false;
		foreach ( array( 'plugins', 'themes', 'uploads', 'others' ) as $entity ) {
			$has_files = $has_files || ! empty( $entry[ $entity ] );
		}

		return array(
			'latest_timestamp' => (int) $timestamp,
			'destination'      => false !== strpos( $service, 'googledrive' ) ? 'Google Drive' : sanitize_text_field( $service ),
			'remote_confirmed' => false !== strpos( $service, 'googledrive' ),
			'has_database'     => (bool) $has_db,
			'has_files'        => (bool) $has_files,
		);
	}

	private static function normalise_last_backup( $last_backup ) {
		$data = is_array( $last_backup ) ? $last_backup : array();
		$timestamp = 0;
		foreach ( array( 'backup_time', 'backup_timestamp', 'timestamp', 'time_started' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$timestamp = (int) $data[ $key ];
				break;
			}
		}
		$service = isset( $data['backup_destination'] ) ? strtolower( (string) $data['backup_destination'] ) : '';
		return array(
			'latest_timestamp' => $timestamp ?: time(),
			'destination'      => false !== strpos( $service, 'googledrive' ) ? 'Google Drive' : sanitize_text_field( $service ),
			'remote_confirmed' => false !== strpos( $service, 'googledrive' ),
			'has_database'     => ! empty( $data['backup_database'] ),
			'has_files'        => ! empty( $data['backup_files'] ),
		);
	}
}
