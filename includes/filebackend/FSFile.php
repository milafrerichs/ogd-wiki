<?php
/**
 * Non-directory file on the file system.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup FileBackend
 */

/**
 * Class representing a non-directory file on the file system
 *
 * @ingroup FileBackend
 */
class FSFile {
	/** @var string Path to file */
	protected $path;

	/** @var string File SHA-1 in base 36 */
	protected $sha1Base36;

	/**
	 * Sets up the file object
	 *
	 * @param string $path Path to temporary file on local disk
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Returns the file system path
	 *
	 * @return string
	 */
	public function getPath() {
		return $this->path;
	}

	/**
	 * Checks if the file exists
	 *
	 * @return bool
	 */
	public function exists() {
		return is_file( $this->path );
	}

	/**
	 * Get the file size in bytes
	 *
	 * @return int|bool
	 */
	public function getSize() {
		return filesize( $this->path );
	}

	/**
	 * Get the file's last-modified timestamp
	 *
	 * @return string|bool TS_MW timestamp or false on failure
	 */
	public function getTimestamp() {
		MediaWiki\suppressWarnings();
		$timestamp = filemtime( $this->path );
		MediaWiki\restoreWarnings();
		if ( $timestamp !== false ) {
			$timestamp = wfTimestamp( TS_MW, $timestamp );
		}

		return $timestamp;
	}

	/**
	 * Get an associative array containing information about
	 * a file with the given storage path.
	 *
	 * Resulting array fields include:
	 *   - fileExists
	 *   - size (filesize in bytes)
	 *   - mime (as major/minor)
	 *   - media_type (value to be used with the MEDIATYPE_xxx constants)
	 *   - metadata (handler specific)
	 *   - sha1 (in base 36)
	 *   - width
	 *   - height
	 *   - bits (bitrate)
	 *   - file-mime
	 *   - major_mime
	 *   - minor_mime
	 *
	 * @param string|bool $ext The file extension, or true to extract it from the filename.
	 *             Set it to false to ignore the extension.
	 * @return array
	 */
	public function getProps( $ext = true ) {
		$info = self::placeholderProps();
		$info['fileExists'] = $this->exists();

		if ( $info['fileExists'] ) {
			$info['size'] = $this->getSize(); // bytes
			$info['sha1'] = $this->getSha1Base36();
			// @TODO: replace the code below with bare FileInfo use so this can go in /libs
			$magic = MimeMagic::singleton();

			# MIME type according to file contents
			$info['file-mime'] = $magic->guessMimeType( $this->path, false );
			# Logical MIME type
			$ext = ( $ext === true ) ? FileBackend::extensionFromPath( $this->path ) : $ext;
			$info['mime'] = $magic->improveTypeFromExtension( $info['file-mime'], $ext );

			list( $info['major_mime'], $info['minor_mime'] ) = File::splitMime( $info['mime'] );
			$info['media_type'] = $magic->getMediaType( $this->path, $info['mime'] );

			# Height, width and metadata
			$handler = MediaHandler::getHandler( $info['mime'] );
			if ( $handler ) {
				$info['metadata'] = $handler->getMetadata( $this, $this->path );
				/** @noinspection PhpMethodParametersCountMismatchInspection */
				$gis = $handler->getImageSize( $this, $this->path, $info['metadata'] );
				if ( is_array( $gis ) ) {
					$info = $this->extractImageSizeInfo( $gis ) + $info;
				}
			}
		}

		return $info;
	}

	/**
	 * Placeholder file properties to use for files that don't exist
	 *
	 * Resulting array fields include:
	 *   - fileExists
	 *   - size
	 *   - file-mime (as major/minor)
	 *   - mime (as major/minor)
	 *   - major_mime
	 *   - minor_mime
	 *   - media_type (value to be used with the MEDIATYPE_xxx constants)
	 *   - metadata (handler specific)
	 *   - sha1 (in base 36)
	 *   - width
	 *   - height
	 *   - bits (bitrate)
	 *
	 * @return array
	 */
	public static function placeholderProps() {
		$info = [];
		$info['fileExists'] = false;
		$info['size'] = 0;
		$info['file-mime'] = null;
		$info['major_mime'] = null;
		$info['minor_mime'] = null;
		$info['mime'] = null;
		$info['media_type'] = MEDIATYPE_UNKNOWN;
		$info['metadata'] = '';
		$info['sha1'] = '';
		$info['width'] = 0;
		$info['height'] = 0;
		$info['bits'] = 0;

		return $info;
	}

	/**
	 * Exract image size information
	 *
	 * @param array $gis
	 * @return array
	 */
	protected function extractImageSizeInfo( array $gis ) {
		$info = [];
		# NOTE: $gis[2] contains a code for the image type. This is no longer used.
		$info['width'] = $gis[0];
		$info['height'] = $gis[1];
		if ( isset( $gis['bits'] ) ) {
			$info['bits'] = $gis['bits'];
		} else {
			$info['bits'] = 0;
		}

		return $info;
	}

	/**
	 * Get a SHA-1 hash of a file in the local filesystem, in base-36 lower case
	 * encoding, zero padded to 31 digits.
	 *
	 * 160 log 2 / log 36 = 30.95, so the 160-bit hash fills 31 digits in base 36
	 * fairly neatly.
	 *
	 * @param bool $recache
	 * @return bool|string False on failure
	 */
	public function getSha1Base36( $recache = false ) {
		if ( $this->sha1Base36 !== null && !$recache ) {
			return $this->sha1Base36;
		}

		MediaWiki\suppressWarnings();
		$this->sha1Base36 = sha1_file( $this->path );
		MediaWiki\restoreWarnings();

		if ( $this->sha1Base36 !== false ) {
			$this->sha1Base36 = Wikimedia\base_convert( $this->sha1Base36, 16, 36, 31 );
		}

		return $this->sha1Base36;
	}

	/**
	 * Get the final file extension from a file system path
	 *
	 * @param string $path
	 * @return string
	 */
	public static function extensionFromPath( $path ) {
		$i = strrpos( $path, '.' );

		return strtolower( $i ? substr( $path, $i + 1 ) : '' );
	}

	/**
	 * Get an associative array containing information about a file in the local filesystem.
	 *
	 * @param string $path Absolute local filesystem path
	 * @param string|bool $ext The file extension, or true to extract it from the filename.
	 *   Set it to false to ignore the extension.
	 * @return array
	 */
	public static function getPropsFromPath( $path, $ext = true ) {
		$fsFile = new self( $path );

		return $fsFile->getProps( $ext );
	}

	/**
	 * Get a SHA-1 hash of a file in the local filesystem, in base-36 lower case
	 * encoding, zero padded to 31 digits.
	 *
	 * 160 log 2 / log 36 = 30.95, so the 160-bit hash fills 31 digits in base 36
	 * fairly neatly.
	 *
	 * @param string $path
	 * @return bool|string False on failure
	 */
	public static function getSha1Base36FromPath( $path ) {
		$fsFile = new self( $path );

		return $fsFile->getSha1Base36();
	}
}
