<?php
/**
 * Package inspector tests.
 *
 * @package PushWP
 */

namespace PushWP\Tests;

use PushWP\PackageInspector;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase {

	/**
	 * Inspector under test.
	 *
	 * @var PackageInspector
	 */
	private $inspector;

	/**
	 * Temporary fixture root.
	 *
	 * @var string
	 */
	private $tmp;

	protected function setUp(): void {
		$this->inspector = new PackageInspector();
		$this->tmp       = sys_get_temp_dir() . '/pushwp_test_' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0755, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->tmp );
	}

	public function test_safe_entry_rejects_traversal() {
		$this->assertFalse( $this->inspector->is_safe_entry( '../evil.php' ) );
		$this->assertFalse( $this->inspector->is_safe_entry( 'a/../../evil.php' ) );
		$this->assertFalse( $this->inspector->is_safe_entry( '/absolute.php' ) );
		$this->assertFalse( $this->inspector->is_safe_entry( 'a\\b.php' ) );
		$this->assertFalse( $this->inspector->is_safe_entry( "a\0b.php" ) );
		$this->assertFalse( $this->inspector->is_safe_entry( 'C:/windows.php' ) );
		$this->assertFalse( $this->inspector->is_safe_entry( '' ) );
	}

	public function test_safe_entry_accepts_normal_paths() {
		$this->assertTrue( $this->inspector->is_safe_entry( 'plugin.php' ) );
		$this->assertTrue( $this->inspector->is_safe_entry( 'includes/class-foo.php' ) );
	}

	public function test_detects_plugin() {
		$root = $this->tmp . '/plugin';
		mkdir( $root, 0755, true );
		file_put_contents(
			$root . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: My Plugin\n * Version: 2.1.0\n * Description: A test.\n */\n"
		);

		$result = $this->inspector->detect( $root );

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'My Plugin', $result['name'] );
		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertSame( 'my-plugin.php', $result['main_file'] );
	}

	public function test_detects_theme() {
		$root = $this->tmp . '/theme';
		mkdir( $root, 0755, true );
		file_put_contents(
			$root . '/style.css',
			"/*\nTheme Name: My Theme\nVersion: 1.0.0\n*/\n"
		);

		$result = $this->inspector->detect( $root );

		$this->assertSame( 'theme', $result['type'] );
		$this->assertSame( 'My Theme', $result['name'] );
		$this->assertSame( '1.0.0', $result['version'] );
	}

	public function test_rejects_missing_package() {
		$root = $this->tmp . '/empty';
		mkdir( $root, 0755, true );

		$result = $this->inspector->detect( $root );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_ambiguous_multiple_plugins() {
		$root = $this->tmp . '/multi';
		mkdir( $root, 0755, true );
		file_put_contents( $root . '/one.php', "<?php\n/**\n * Plugin Name: One\n */\n" );
		file_put_contents( $root . '/two.php', "<?php\n/**\n * Plugin Name: Two\n */\n" );

		$result = $this->inspector->detect( $root );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ambiguous_plugin', $result->get_error_code() );
	}

	public function test_forced_type_rejects_wrong_package() {
		$root = $this->tmp . '/plugin';
		mkdir( $root, 0755, true );
		file_put_contents( $root . '/my-plugin.php', "<?php\n/**\n * Plugin Name: My Plugin\n */\n" );

		$result = $this->inspector->detect( $root, 'theme' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_theme', $result->get_error_code() );
	}

	public function test_extract_and_detect_from_zip() {
		$root = $this->tmp . '/src';
		mkdir( $root, 0755, true );
		file_put_contents( $root . '/plugin.php', "<?php\n/**\n * Plugin Name: Zipped Plugin\n * Version: 3.0.0\n */\n" );

		$zip_path = $this->tmp . '/package.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE );
		$zip->addFile( $root . '/plugin.php', 'owner-repo-abc123/plugin.php' );
		$zip->close();

		$dest = $this->tmp . '/extracted';
		$result = $this->inspector->extract_archive( $zip_path, $dest );

		$this->assertTrue( $result );

		$resolved = $this->inspector->resolve_root( $dest, '' );
		$detected = $this->inspector->detect( $resolved );

		$this->assertSame( 'plugin', $detected['type'] );
		$this->assertSame( 'Zipped Plugin', $detected['name'] );
	}

	public function test_extract_rejects_traversal_zip() {
		$zip_path = $this->tmp . '/evil.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE );
		$zip->addFromString( '../evil.php', '<?php ' );
		$zip->close();

		$result = $this->inspector->extract_archive( $zip_path, $this->tmp . '/out' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'archive_unsafe', $result->get_error_code() );
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			is_dir( $path ) ? $this->remove_dir( $path ) : @unlink( $path );
		}

		@rmdir( $dir );
	}
}
