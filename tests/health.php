<?php
// Exercise the real collector with controlled HTTP/DB outcomes; no WordPress needed.
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
$GLOBALS['baseline'] = array( 'http_ms' => 1, 'db_ms' => 0.01 );
$GLOBALS['http_code'] = 200;
$GLOBALS['db_result'] = '1';
$GLOBALS['http_delay'] = 0;
$GLOBALS['wpdb'] = new class { public function get_var( $sql ) { return $GLOBALS['db_result']; } };
function wp_safe_remote_get() { usleep( $GLOBALS['http_delay'] ); return array(); }
function home_url() { return 'https://example.test/'; }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code() { return $GLOBALS['http_code']; }
function trailingslashit( $path ) { return $path . '/'; }
function wp_generate_password() { return uniqid( 'health-test-' ); }
function get_option( $name, $default ) { return $GLOBALS['baseline']; }
function update_option() { throw new Exception( 'Preflight must not update the baseline.' ); }
require dirname( __DIR__ ) . '/includes/class-wuo-agent-health.php';
function check_health( $ok, $message ) { if ( ! $ok ) { throw new Exception( $message ); } }
check_health( ! WUO_Agent_Health::performance_degraded( 100, 0.9, array( 'http_ms' => 10, 'db_ms' => 0.4 ) ), 'Small absolute latencies must not warn.' );
check_health( WUO_Agent_Health::performance_degraded( 2200, 1, array( 'http_ms' => 800, 'db_ms' => 1 ) ), 'Significant HTTP regression must warn.' );
check_health( WUO_Agent_Health::performance_degraded( 100, 60, array( 'http_ms' => 100, 'db_ms' => 10 ) ), 'Significant DB regression must warn.' );
check_health( ! WUO_Agent_Health::performance_degraded( 3000, 60, array( 'http_ms' => 1500, 'db_ms' => 30 ) ), 'Exactly twice baseline must not warn.' );
$GLOBALS['http_delay'] = 2050000;
$health = WUO_Agent_Health::collect( false );
check_health( $health['healthy'] && $health['degraded'] && $health['performance_degraded'] && $health['db_ok'], 'A slow but functional site must remain safe for preflight and postflight.' );
$GLOBALS['http_delay'] = 0;
$GLOBALS['http_code'] = 503;
check_health( ! WUO_Agent_Health::collect( false )['healthy'], 'HTTP failure must still prevent execution.' );
$GLOBALS['http_code'] = 200;
$GLOBALS['db_result'] = null;
$health = WUO_Agent_Health::collect( false );
check_health( ! $health['healthy'] && ! $health['db_ok'], 'DB failure must still prevent execution.' );
echo "Santé agent : tests réussis.\n";
