<?php
/**
 * Imagick wrapper for creating thumbnails.
 *
 * @package Share_On_Mastodon\Import_Mastodon_Comments
 */

namespace Share_On_Mastodon\Import_Mastodon_Comments;

/**
 * Imagick wrapper for creating thumbnails.
 */
class Image_Handler {
	/**
	 * Locally stores an account's avatar.
	 *
	 * @param Object $account Mastodon account.
	 *
	 * @return string|null (Local) avatar URL, or null on failure.
	 */
	public static function create_avatar( $account ) {
		if ( empty( $account->avatar ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$avatar_dir = trailingslashit( $upload_dir['basedir'] ) . 'avatars';

		if ( ! is_dir( $avatar_dir ) ) {
			// This'll create, e.g., `wp-content/uploads/avatars/`.
			mkdir( $avatar_dir, 0755 );
		}

		// Remove any query string, then grab the file extension.
		$ext = preg_replace( '/\?.*/', '', pathinfo( $account->avatar, PATHINFO_EXTENSION ) );

		// Generate a somewhat nice, but not necessarily unique (!) filename.
		$filename = str_replace( array( 'http://', 'https://' ), '', $account->url );
		$filename = sanitize_title( $filename );
		$filename = trim( $filename, '-' ) . ( ! empty( $ext ) ? '.' . $ext : '' );

		$image_path = trailingslashit( $avatar_dir ) . $filename;

		// Make it filterable.
		$image_path = apply_filters( 'import_mastodon_comments_avatar', $image_path, $account->url );

		if ( is_file( $image_path ) && ( time() - filectime( $image_path ) ) < MONTH_IN_SECONDS ) {
			// Image exists and is under a month old.
			error_log( 'Image already exists. Images are cached for a month.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Return image URL.
			return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $image_path );
		}

		// Fetch remote image.
		$response = wp_remote_get(
			esc_url_raw( $account->avatar ),
			array(
				'timeout'    => 20,
				'user-agent' => apply_filters( 'import_mastodon_comments', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36 Edg/81.0.416.53' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		if ( empty( $response['body'] ) ) {
			error_log( 'Image could not be not be retrieved.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		// Resize and store avatar.
		$thumbnail = self::create_thumbnail( $response['body'] );

		if ( empty( $thumbnail ) ) {
			error_log( 'Image could not be not be created.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Finally, store our freshly created thumbnail.
		$wp_filesystem->put_contents( $image_path, $thumbnail, 0644 );

		if ( false === $size || ! is_file( $image_path ) ) {
			error_log( 'Image could not be not saved.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		if ( ! in_array( mime_content_type( $image_path ), array( 'image/gif', 'image/jpg', 'image/jpeg', 'image/png' ), true ) ) {
			error_log( 'Invalid file format. Deleting previously saved image.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Remove the previously saved image.
			unlink( $image_path );
			return false;
		}

		return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $image_path );
	}

	/**
	 * Turns an image buffer into a thumbnail of 150 x 150 pixels.
	 *
	 * @param string $blob Image blob.
	 *
	 * @return string|null Resized image blob, or nothing on failure.
	 */
	public static function create_thumbnail( $blob ) {
		if ( ! class_exists( '\Imagick' ) ) {
			// Imagick not supported. Bail.
			return null;
		}

		$im = new \Imagick();
		$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );

		try {
			$im->readImageBlob( $blob );
			$im->cropThumbnailImage( 150, 150 );

			return $im->getImageBlob();
		} catch ( \Exception $e ) {
			error_log( $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return null;
	}
}
