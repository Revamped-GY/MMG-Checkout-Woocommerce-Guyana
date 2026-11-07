<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

final class WP_Error {
	private $code;
	private $message;

	public function __construct( string $code, string $message ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

$remote_info = false;
$download_contents = '';
$download_count = 0;

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function get_site_transient( string $key ) { global $remote_info; return $remote_info; }
function plugin_basename( string $file ): string { global $basename_override; return $basename_override ?? basename( $file ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function apply_filters( string $hook, $value ) { return $value; }
function add_filter( ...$args ): void {}
function add_action( ...$args ): void {}
function home_url( string $path = '/' ): string { return 'https://store.example' . $path; }
function wp_safe_remote_get( string $url, array $args = array() ) { return new WP_Error( 'offline', 'Network unavailable in the unit test.' ); }
function download_url( string $url, int $timeout = 300 ) {
	global $download_contents, $download_count;
	$download_count++;
	$file = tempnam( sys_get_temp_dir(), 'mmgwc-updater-test-' );
	if ( ! is_string( $file ) ) {
		return new WP_Error( 'temp_failed', 'Could not create test file.' );
	}
	file_put_contents( $file, $download_contents );
	return $file;
}
function wp_delete_file( string $file ): void { if ( is_file( $file ) ) { unlink( $file ); } }

require dirname( __DIR__ ) . '/includes/class-mmgwc-updater.php';
MMGWC_Updater::init( dirname( __DIR__ ) . '/mmg-checkout-woocommerce.php' );

$tests = 0;
$failures = 0;

function updater_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$package = 'https://github.com/Revamped-GY/MMG-Checkout-Woocommerce-Guyana/releases/download/v2.16.0/mmg-checkout-woocommerce-v2.16.0.zip';
$download_contents = 'verified plugin package';
$remote_info = (object) array(
	'download_url' => $package,
	'package_sha256' => hash( 'sha256', $download_contents ),
	'source' => 'github',
);
$result = MMGWC_Updater::download_verified_package( false, $package, null, array() );
updater_check( is_string( $result ) && is_file( $result ), 'A matching package is returned as a verified local file before installation.' );
if ( is_string( $result ) && is_file( $result ) ) {
	unlink( $result );
}

$remote_info->package_sha256 = str_repeat( '0', 64 );
$result = MMGWC_Updater::download_verified_package( false, $package, null, array() );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_hash_mismatch', 'A mismatched package is rejected before installation.' );

$before = $download_count;
$remote_info->package_sha256 = '';
$result = MMGWC_Updater::download_verified_package( false, $package, null, array() );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_hash_missing', 'A GitHub release without a valid checksum fails closed.' );
updater_check( $download_count === $before, 'A checksum-free GitHub package is not downloaded.' );

$remote_info->source = 'manifest';
$result = MMGWC_Updater::download_verified_package( false, $package, null, array() );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_hash_missing', 'A fallback manifest without a valid checksum also fails closed.' );

$plugin_context = array( 'plugin' => 'mmg-checkout-woocommerce.php' );
$result = MMGWC_Updater::download_verified_package( false, 'https://example.com/changed.zip', null, $plugin_context );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_package_changed', 'A changed package URL for this plugin fails closed.' );

$saved_remote_info = $remote_info;
$remote_info = false;
$result = MMGWC_Updater::download_verified_package( false, $package, null, $plugin_context );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_metadata_missing', 'This plugin fails closed when update metadata is unavailable during installation.' );
$remote_info = $saved_remote_info;
$remote_info->package_sha256 = hash( 'sha256', $download_contents );

$predownloaded = tempnam( sys_get_temp_dir(), 'mmgwc-updater-filter-test-' );
file_put_contents( $predownloaded, 'altered pre-downloaded package' );
$result = MMGWC_Updater::download_verified_package( $predownloaded, $package, null, $plugin_context );
updater_check( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_update_hash_mismatch', 'An altered local file supplied by an earlier filter is rejected.' );
updater_check( ! is_file( $predownloaded ), 'A rejected pre-downloaded package is removed.' );

$remote_info = false;
$result = MMGWC_Updater::download_verified_package( false, 'https://example.com/unrelated.zip', null, array( 'plugin' => 'other/other.php' ) );
updater_check( $result === false, 'Packages for other plugins remain untouched.' );

// Folder handling during an update.
final class Test_Filesystem {
	public function exists( string $path ): bool { return file_exists( rtrim( $path, '/' ) ); }
	public function move( string $from, string $to ): bool { return rename( rtrim( $from, '/' ), rtrim( $to, '/' ) ); }
}
$wp_filesystem = new Test_Filesystem();
$work = sys_get_temp_dir() . '/mmgwc-folder-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $work . '/mmg-checkout-woocommerce', 0777, true );
file_put_contents( $work . '/mmg-checkout-woocommerce/mmg-checkout-woocommerce.php', '<?php' );

$result = MMGWC_Updater::keep_installed_folder( $work . '/mmg-checkout-woocommerce/', $work, null, array( 'plugin' => 'mmg-checkout-woocommerce.php' ) );
updater_check( $result === $work . '/mmg-checkout-woocommerce/', 'A plugin installed without a folder keeps the package as unpacked.' );

$basename_override = 'MMG-Checkout-Woocommerce-Guyana-main/mmg-checkout-woocommerce.php';
MMGWC_Updater::init( dirname( __DIR__ ) . '/mmg-checkout-woocommerce.php' );
$result = MMGWC_Updater::keep_installed_folder( $work . '/mmg-checkout-woocommerce/', $work, null, array( 'plugin' => $basename_override ) );
updater_check( $result === $work . '/MMG-Checkout-Woocommerce-Guyana-main/', 'The package is moved into the folder the plugin is installed in.' );
updater_check( is_file( $work . '/MMG-Checkout-Woocommerce-Guyana-main/mmg-checkout-woocommerce.php' ), 'The moved package still holds the main plugin file.' );

$result = MMGWC_Updater::keep_installed_folder( $work . '/other/', $work, null, array( 'plugin' => 'other/other.php' ) );
updater_check( $result === $work . '/other/', 'Other plugin updates are left alone.' );

$basename_override = 'mmg-checkout-woocommerce/mmg-checkout-woocommerce.php';
MMGWC_Updater::init( dirname( __DIR__ ) . '/mmg-checkout-woocommerce.php' );
$result = MMGWC_Updater::keep_installed_folder( $work . '/MMG-Checkout-Woocommerce-Guyana-main/', $work, null, array( 'plugin' => $basename_override ) );
updater_check( $result === $work . '/mmg-checkout-woocommerce/', 'The standard folder name is restored when that is the installed folder.' );

array_map( 'unlink', glob( $work . '/*/*' ) ?: array() );
array_map( 'rmdir', glob( $work . '/*' ) ?: array() );
rmdir( $work );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} updater integrity tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} updater integrity tests passed.\n" );
