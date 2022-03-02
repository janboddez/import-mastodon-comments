# Import Mastodon Comments
Import Mastodon interactions (replies, favorites, etc.) into WordPress, as comments.

Requires [Share on Mastodon](https://wordpress.org/plugins/share-on-mastodon/).

Works by looping over posts created by Share on Mastodon in the last 3 weeks (this duration is filterable, however) and fetching replies, favorites, and reblogs from your Mastodon instance's API. All of these are then added as "regular" WordPress comments.

Comments that are subsequently deleted will not be re-imported.

The plugin'll also attempt to download and store user avatars. Avatars are normally stored in `wp-content/uploads/avatars/`. Avatar URLs can be retrieved using the `_mastodon_avatar` comment meta field.

Use the `import_mastodon_comments_skip` filter to exclude certain _replies_ from being imported. (Like if you've set up Share on Mastodon to crosspost "threaded replies" and don't want to import replies that originated _from your site_.)

Example:
```
add_filter( 'import_mastodon_comments_skip', function( $skip, $post_id, $reply ) {
  // We could check `$reply` for a backlink to our blog, for instance.
  if ( false !== strpos( wp_strip_all_tags( $reply->content ), '(' . home_url() ) ) {
    $skip = true;
  }

  return $skip;
} );
```
