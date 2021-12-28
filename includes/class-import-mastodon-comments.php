<?php
/**
 * The actual plugin class.
 *
 * @package Share_On_Mastodon\Import_Mastodon_Comments
 */

namespace Share_On_Mastodon\Import_Mastodon_Comments;

/**
 * Main plugin class.
 */
class Import_Mastodon_Comments {
	/**
	 * This plugin's single instance.
	 *
	 * @since 0.1.0
	 *
	 * @var Import_Mastodon_Comments $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Import_Handler` instance.
	 *
	 * @since 0.1.0
	 *
	 * @var Options_Handler $import_handler `Import_Handler` instance.
	 */
	private $import_handler;

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
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->import_handler = Import_Handler::get_instance();
		$this->import_handler->register();
	}

	/**
	 * Registers hook callbacks and such.
	 */
	public function register() {
		register_activation_hook( dirname( dirname( __FILE__ ) ) . '/import-mastodon-comments.php', array( $this, 'activate' ) );
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/import-mastodon-comments.php', array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Ensures the importer keeps running.
	 *
	 * @since 0.1.0
	 */
	public function init() {
		if ( false === wp_next_scheduled( 'import_mastodon_comments' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'import_mastodon_comments' );
		}
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.1.0
	 */
	public function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'import_mastodon_comments';
		$charset_collate = $wpdb->get_charset_collate();

		// We create our own database table to keep track of imported
		// interactions and prevent duplicates.
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) UNSIGNED NOT NULL AUTO_INCREMENT,
			source varchar(191) DEFAULT '' NOT NULL,
			post_id bigint(20) UNSIGNED DEFAULT 0 NOT NULL,
			ip varchar(100) DEFAULT '' NOT NULL,
			status varchar(20) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );

		// Store current database version.
		add_option( 'import_mastodon_comments_db_version', $this->db_version );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @since 0.1.0
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'import_mastodon_comments' );
	}
}
