<?php
/**
 * Mastodon API client.
 *
 * @package Share_On_Mastodon\Import_Mastodon_Comments
 */

namespace Share_On_Mastodon\Import_Mastodon_Comments;

/**
 * Mastodon API client.
 */
class Import_Handler {
	/**
	 * Array that holds Share on Mastodon's settings.
	 *
	 * @var array $options Holds Share on Mastodon's settings.
	 */
	private $options = array();

	/**
	 * Single instance of this class.
	 *
	 * @since 0.1.0
	 *
	 * @var Import_Handler $instance Single instance of this class.
	 */
	private static $instance;

	/**
	 * Returns the single instance of this class.
	 *
	 * @since 0.1.0
	 *
	 * @return Import_Mastodon_Comments Single class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * (Private) constructor.
	 */
	private function __construct() {
		// Fetch Share On Mastodon's settings from the database.
		$this->options = get_option( 'share_on_mastodon_settings', array() );
	}

	/**
	 * Registers the WP Cron callback.
	 *
	 * @return void
	 */
	public function register() {
		// Cron job callback.
		add_action( 'import_mastodon_comments', array( $this, 'run' ) );
	}

	/**
	 * Find statuses that were crossposted to Mastodon, and turn replies into
	 * WordPress comments.
	 *
	 * @since 0.1.0
	 */
	public function run() {
		if ( get_transient( 'import_mastodon_comments_lock' ) ) {
			// Prevent this hook fron being called multiple times in a row.
			error_log( 'This hook last ran less than 5 minutes ago. Quitting.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		} else {
			// Transient non-existent or expired.
			set_transient( 'import_mastodon_comments_lock', true, 300 );
		}

		if ( empty( $this->options['mastodon_host'] ) || ! wp_http_validate_url( $this->options['mastodon_host'] ) ) {
			// Invalid Mastodon instance.
			return;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			// Missing access token.
			return;
		}

		if ( empty( $this->options['post_types'] ) || ! is_array( $this->options['post_types'] ) ) {
			// Not enabled for any post type.
			return;
		}

		// Find statuses that live on Mastodon, too. Disregards statuses over
		// three weeks old (although this value is filterable).
		$args = array(
			'post_type'           => apply_filters( 'import_mastodon_comments_post_types', $this->options['post_types'] ),
			'orderby'             => 'ID',
			'order'               => 'DESC',
			'posts_per_page'      => -1,
			'ignore_sticky_posts' => '1',
			'fields'              => 'ids',
			'meta_key'            => '_share_on_mastodon_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relationship' => 'AND',
				array(
					'key'     => '_share_on_mastodon_url',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_share_on_mastodon_url',
					'value'   => '',
					'compare' => '!=',
				),
			),
			'date_query'          => array(
				array(
					'after' => apply_filters( 'import_mastodon_comments_max_age', '3 weeks ago' ),
				),
			),
		);

		$query = new \WP_Query( $args );

		// Loop over all crossposted posts.
		foreach ( $query->posts as $i => $post_id ) {
			// Search for and import interactions.
			$this->fetch_replies( $post_id );

			/*
			 * We could also schedule these, using `wp_schedule_single_event()`,
			 * like, a couple minutes apart into the near future. How many API
			 * calls get executed at once then depends on how frequently
			 * `WP_CRON` is run.
			 */

			// Ugly, but ... sleep for a quarter second after each API call.
			usleep( 250000 );

			$this->fetch_favorites( $post_id );

			usleep( 250000 );

			$this->fetch_boosts( $post_id );

			if ( $i < count( $query->posts ) - 1 ) {
				// No need to wait after the very last call.
				usleep( 250000 );
			}
		}
	}

	/**
	 * Fetches replies to a certain status.
	 *
	 * @param int $post_id Post ID.
	 */
	private function fetch_replies( $post_id ) {
		// Get (just) the Mastodon ID.
		$id = basename( untrailingslashit( get_post_meta( $post_id, '_share_on_mastodon_url', true ) ) );

		// Grab replies and such.
		$response = wp_remote_get( esc_url_raw( $this->get_host() . '/api/v1/statuses/' . $id . '/context' ) );

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// To do: log server error codes and such.

		$status = @json_decode( $response['body'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $status->descendants ) || ! is_array( $status->descendants ) ) {
			// Nothing to do.
			return;
		}

		global $wpdb;

		foreach ( $status->descendants as $reply ) {
			// See if maybe we haven't already imported it.
			$comment = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'import_mastodon_comments WHERE source = \'%1$s\' LIMIT 1', esc_url_raw( $reply->url ) )
			);

			if ( ! empty( $comment ) ) {
				error_log( 'Skipping ' . $reply->url . '. Comment has been processed before.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			// Add as comment.
			$this->add_comment( $post_id, $reply );
		}
	}

	/**
	 * Fetches a certain status's favorites.
	 *
	 * @param int $post_id Post ID.
	 */
	private function fetch_favorites( $post_id ) {
		$id = basename( untrailingslashit( get_post_meta( $post_id, '_share_on_mastodon_url', true ) ) );

		$response = wp_remote_get( esc_url_raw( $this->get_host() . '/api/v1/statuses/' . $id . '/favourited_by' ) );

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$accounts = @json_decode( $response['body'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $accounts ) || ! is_array( $accounts ) ) {
			return;
		}

		global $wpdb;

		foreach ( $accounts as $account ) {
			$comment = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'import_mastodon_comments WHERE source = \'%1$s\' AND post_id = \'%2$d\' LIMIT 1', esc_url_raw( $account->url ), $post_id )
			);

			if ( ! empty( $comment ) ) {
				error_log( 'Skipping ' . $account->url . '. Favorite has been processed before.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			$this->add_boost_or_favorite( $post_id, $account, 'favorite' );
		}
	}

	/**
	 * Fetches a certain status's boosts.
	 *
	 * @param int $post_id Post ID.
	 */
	private function fetch_boosts( $post_id ) {
		$id = basename( untrailingslashit( get_post_meta( $post_id, '_share_on_mastodon_url', true ) ) );

		$response = wp_remote_get( esc_url_raw( $this->get_host() . '/api/v1/statuses/' . $id . '/reblogged_by' ) );

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$accounts = @json_decode( $response['body'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $accounts ) || ! is_array( $accounts ) ) {
			return;
		}

		global $wpdb;

		foreach ( $accounts as $account ) {
			$comment = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'import_mastodon_comments WHERE source = \'%1$s\' AND post_id = \'%2$d\' LIMIT 1', esc_url_raw( $account->url ), $post_id )
			);

			if ( ! empty( $comment ) ) {
				error_log( 'Skipping ' . $account->url . '. Boost has been processed before.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			$this->add_boost_or_favorite( $post_id, $account, 'boost' );
		}
	}

	/**
	 * Import a reply as a new comment.
	 *
	 * @param int    $post_id Post ID.
	 * @param Object $reply   Mastodon status.
	 */
	private function add_comment( $post_id, $reply ) {
		if ( apply_filters( 'import_mastodon_comments_skip', false, $post_id, $reply ) ) {
			// Use this to skip replies that, e.g., were actually sent _from_
			// your blog.
			error_log( 'Skipping the status at ' . $reply->url . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$author_ip = ( ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$author_ip = apply_filters( 'import_mastodon_comments_author_ip', $author_ip );

		$commentdata = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $reply->account->display_name,
			'comment_author_email' => 'someone@example.com',
			'comment_author_url'   => esc_url_raw( $reply->account->url ),
			'comment_author_IP'    => $author_ip,
			'comment_content'      => $reply->content,
			'comment_parent'       => 0, // No comment threading, yet.
			'user_id'              => 0,
			'comment_type'         => '',
			'comment_date'         => get_date_from_gmt( $reply->created_at ),
			'comment_date_gmt'     => $reply->created_at,
		);

		// Disable comment flooding check.
		remove_action( 'check_comment_flood', 'check_comment_flood_db' );

		// Insert new comment.
		$comment_id = wp_new_comment( $commentdata, true );

		if ( is_wp_error( $comment_id ) ) {
			// Ignoring duplicate comment errors, which could be due to our
			// database table being out of sync.
			if ( ! in_array( 'comment_duplicate', $comment_id->get_error_codes(), true ) ) {
				// For troubleshooting.
				error_log( print_r( $comment_id->errors, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}

			error_log( print_r( $comment_id->errors, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		// Mark comment as processed.
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'import_mastodon_comments',
			array(
				'source'     => esc_url_raw( $reply->url ),
				'post_id'    => $post_id,
				'ip'         => $author_ip,
				'status'     => 'complete',
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Store original URL.
		update_comment_meta( $comment_id, '_mastodon_reply_url', esc_url_raw( $reply->url ) );

		// Attempt to store avatar.
		$avatar = Image_Handler::create_avatar( $reply->account );

		if ( false !== $avatar ) {
			update_comment_meta( $comment_id, '_mastodon_avatar', $avatar );
		}
	}

	/**
	 * Import a favorite or a boost as a new comment.
	 *
	 * @param int    $post_id Post ID.
	 * @param Object $account Mastodon account.
	 * @param string $type    Favorite or boost.
	 */
	private function add_boost_or_favorite( $post_id, $account, $type ) {
		if ( ! in_array( $type, array( 'boost', 'favorite' ), true ) ) {
			return;
		}

		$author_ip = ( ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$author_ip = apply_filters( 'import_mastodon_comments_author_ip', $author_ip );

		$commentdata = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $account->display_name,
			'comment_author_email' => 'someone@example.com',
			'comment_author_url'   => esc_url_raw( $account->url ),
			'comment_author_IP'    => $author_ip,
			'comment_parent'       => 0,
			'user_id'              => 0,
			'comment_type'         => '',
			'comment_date'         => get_the_date( 'Y-m-d H:i:s', $post_id ),
			'comment_date_gmt'     => get_gmt_from_date( get_the_date( 'Y-m-d H:i:s', $post_id ) ),
		);

		if ( 'boost' === $type ) {
			$commentdata['comment_content'] = __( '&hellip; reblogged this!', 'import-mastodon-comments' );
		}

		if ( 'favorite' === $type ) {
			$commentdata['comment_content'] = __( '&hellip; favorited this!', 'import-mastodon-comments' );
		}

		// Disable comment flooding check.
		remove_action( 'check_comment_flood', 'check_comment_flood_db' );

		// Insert new comment.
		$comment_id = wp_new_comment( $commentdata, true );

		if ( is_wp_error( $comment_id ) ) {
			// Ignoring duplicate comment errors, which could be due to our
			// database table being out of sync.
			if ( ! in_array( 'comment_duplicate', $comment_id->get_error_codes(), true ) ) {
				// For troubleshooting.
				error_log( print_r( $comment_id->errors, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}

			return;
		}

		// Mark comment as processed.
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'import_mastodon_comments',
			array(
				'source'     => esc_url_raw( $account->url ),
				'post_id'    => $post_id,
				'ip'         => $author_ip,
				'status'     => 'complete',
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Attempt to store avatar.
		$avatar = Image_Handler::create_avatar( $account );

		if ( false !== $avatar ) {
			update_comment_meta( $comment_id, '_mastodon_avatar', $avatar );
		}
	}

	/**
	 * Returns the Mastodon instance we're dealing with.
	 *
	 * @return string Mastodon instance.
	 */
	private function get_host() {
		return untrailingslashit( $this->options['mastodon_host'] );
	}
}
