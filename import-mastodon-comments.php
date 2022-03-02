<?php
/**
 * Plugin Name: Import Mastodon Comments
 * Description: Import Mastodon replies (and favorites, etc.) into WordPress, as comments.
 * Author:      Jan Boddez
 * Author URI:  https://janboddez.tech/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: import-mastodon-comments
 * Version:     0.2.0
 *
 * @package Share_On_Mastodon\Mastodon_Comments
 */

namespace Share_On_Mastodon\Import_Mastodon_Comments;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-image-handler.php';
require_once dirname( __FILE__ ) . '/includes/class-import-handler.php';
require_once dirname( __FILE__ ) . '/includes/class-import-mastodon-comments.php';

$import_mastodon_comments = Import_Mastodon_Comments::get_instance();
$import_mastodon_comments->register();
