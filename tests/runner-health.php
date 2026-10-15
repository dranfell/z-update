<?php
// Run the real runner with controlled health/backup and an in-memory upgrader.
$root = sys_get_temp_dir() . '/wuo-runner-test-' . uniqid() . '/';
mkdir( $root . 'wp-admin/includes', 0700, true );
foreach ( array( 'class-wp-upgrader.php', 'update.php', 'plugin.php' ) as $file ) { file_put_contents( $root . 'wp-admin/includes/' . $file, '<?php' ); }
register_shutdown_function( static function () use ( $root ) { foreach ( glob( $root . 'wp-admin/includes/*' ) as $file ) { unlink( $file ); } rmdir( $root . 'wp-admin/includes' ); rmdir( $root . 'wp-admin' ); rmdir( $root ); } );
define( 'ABSPATH', $root );
define( 'WP_PLUGIN_DIR', $root );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
class WUO_Agent_Health {
 public static $samples = array();
 public static function collect( $update ) { return array_shift( self::$samples ); }
}
class WUO_Agent_Updraft {
 public static $backup = array( 'complete' => true, 'age_seconds' => 3600 );
 public static $running = false;
 public static function status() { return self::$backup; }
 public static function is_running() { return self::$running; }
}
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader { public function __construct( $skin ) {} public function upgrade( $slug ) { $GLOBALS['upgrades']++; return true; } }
function request_filesystem_credentials() {}
function wp_update_plugins() {}
function get_site_transient() { return (object) array( 'response' => array( 'demo/demo.php' => (object) array( 'new_version' => '2' ) ) ); }
function get_plugin_data() { return array( 'Version' => '2' ); }
function get_site_option() { return 0; }
function set_transient() {} function delete_transient() {}
function sanitize_key( $s ) { return $s; } function sanitize_text_field( $s ) { return $s; } function absint( $i ) { return abs( (int) $i ); }
function is_wp_error() { return false; }
require dirname( __DIR__ ) . '/includes/class-wuo-agent-runner.php';
$GLOBALS['upgrades'] = 0;
$slow = array( 'healthy' => true, 'degraded' => true, 'performance_degraded' => true );
$job = array( 'id' => 1, 'type' => 'plugin', 'slug' => 'demo/demo.php', 'target_version' => '2' );
WUO_Agent_Health::$samples = array( $slow, $slow );
$result = WUO_Agent_Runner::run( $job );
if ( ! $result['success'] || 'completed' !== $result['code'] || 1 !== $GLOBALS['upgrades'] ) { throw new Exception( 'Advisory latency must not fail preflight or postflight.' ); }
WUO_Agent_Health::$samples = array( array( 'healthy' => false ) );
$result = WUO_Agent_Runner::run( $job );
if ( ! $result['retryable'] || 'preflight_health_failed' !== $result['code'] ) { throw new Exception( 'Unavailable site must defer execution.' ); }
WUO_Agent_Updraft::$backup['age_seconds'] = 172800;
WUO_Agent_Health::$samples = array( $slow );
$result = WUO_Agent_Runner::run( $job );
if ( 'updraft_backup_ineligible' !== $result['code'] || ! $result['retryable'] ) { throw new Exception( 'Old backup must defer execution.' ); }
WUO_Agent_Updraft::$running = true;
WUO_Agent_Health::$samples = array( $slow );
$result = WUO_Agent_Runner::run( $job );
if ( 'updraft_backup_running' !== $result['code'] || 1 !== $GLOBALS['upgrades'] ) { throw new Exception( 'Backup running must defer execution without upgrading.' ); }
WUO_Agent_Updraft::$running = false;
WUO_Agent_Updraft::$backup['age_seconds'] = 3600;
WUO_Agent_Health::$samples = array( $slow, array( 'healthy' => false ) );
$result = WUO_Agent_Runner::run( $job );
if ( $result['success'] || 'postflight_health_failed' !== $result['code'] ) { throw new Exception( 'Actual unavailability after upgrade must still be reported.' ); }
echo "Exécution et contrôles : tests réussis.\n";
