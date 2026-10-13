<?php
/**
 * Klasa systemu plikow "chroot" dla lab-chroot-fs.php (tylko laboratorium).
 * Kompozycja: wewnetrzny WP_Filesystem_Direct dostaje sciezki lokalne, na zewnatrz obowiazuja sciezki zdalne.
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

class WP_Filesystem_Chroot extends WP_Filesystem_Base {

	const ROOT = '/var/www/html';

	private $inner;

	public function __construct( $arg ) {
		unset( $arg );
		$this->method = 'chroot';
		$this->errors = new WP_Error();
		$this->inner  = new WP_Filesystem_Direct( null );
	}

	public function connect() {
		return true;
	}

	private function l( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return self::ROOT . '/' . ltrim( $path, '/' );
	}

	public function find_folder( $folder ) {
		$folder = str_replace( '\\', '/', (string) $folder );

		return 0 === strpos( $folder, self::ROOT ) ? trailingslashit( substr( $folder, strlen( self::ROOT ) ) ) : false;
	}

	public function get_contents( $file ) {
		return $this->inner->get_contents( $this->l( $file ) );
	}

	public function get_contents_array( $file ) {
		return $this->inner->get_contents_array( $this->l( $file ) );
	}

	public function put_contents( $file, $contents, $mode = false ) {
		return $this->inner->put_contents( $this->l( $file ), $contents, $mode );
	}

	public function cwd() {
		return '/';
	}

	public function chdir( $dir ) {
		return true;
	}

	public function chgrp( $file, $group, $recursive = false ) {
		return true;
	}

	public function chmod( $file, $mode = false, $recursive = false ) {
		return $this->inner->chmod( $this->l( $file ), $mode, $recursive );
	}

	public function chown( $file, $owner, $recursive = false ) {
		return true;
	}

	public function owner( $file ) {
		return $this->inner->owner( $this->l( $file ) );
	}

	public function getchmod( $file ) {
		return $this->inner->getchmod( $this->l( $file ) );
	}

	public function group( $file ) {
		return $this->inner->group( $this->l( $file ) );
	}

	public function copy( $source, $destination, $overwrite = false, $mode = false ) {
		return $this->inner->copy( $this->l( $source ), $this->l( $destination ), $overwrite, $mode );
	}

	public function move( $source, $destination, $overwrite = false ) {
		return $this->inner->move( $this->l( $source ), $this->l( $destination ), $overwrite );
	}

	public function delete( $file, $recursive = false, $type = false ) {
		return $this->inner->delete( $this->l( $file ), $recursive, $type );
	}

	public function exists( $path ) {
		return $this->inner->exists( $this->l( $path ) );
	}

	public function is_file( $file ) {
		return $this->inner->is_file( $this->l( $file ) );
	}

	public function is_dir( $path ) {
		return $this->inner->is_dir( $this->l( $path ) );
	}

	public function is_readable( $file ) {
		return $this->inner->is_readable( $this->l( $file ) );
	}

	public function is_writable( $path ) {
		return $this->inner->is_writable( $this->l( $path ) );
	}

	public function atime( $file ) {
		return $this->inner->atime( $this->l( $file ) );
	}

	public function mtime( $file ) {
		return $this->inner->mtime( $this->l( $file ) );
	}

	public function size( $file ) {
		return $this->inner->size( $this->l( $file ) );
	}

	public function touch( $file, $time = 0, $atime = 0 ) {
		return $this->inner->touch( $this->l( $file ), $time, $atime );
	}

	public function mkdir( $path, $chmod = false, $chown = false, $chgrp = false ) {
		return $this->inner->mkdir( $this->l( $path ), $chmod, $chown, $chgrp );
	}

	public function rmdir( $path, $recursive = false ) {
		return $this->inner->rmdir( $this->l( $path ), $recursive );
	}

	public function dirlist( $path, $include_hidden = true, $recursive = false ) {
		return $this->inner->dirlist( $this->l( $path ), $include_hidden, $recursive );
	}
}
