<?php
/**
 * Plugin Name: Changelog Sync
 * Description: Receives signed changelog entries from GitHub Actions and lists them newest-first with the [changelog] shortcode.
 * Version:     1.0.0
 * Requires PHP: 7.4
 *
 * Secret: define( 'CHANGELOG_SYNC_SECRET', '...' ) in wp-config.php
 *         (or the `changelog_sync_secret` option). Endpoint is disabled while empty.
 * Endpoint: POST /wp-json/changelog-sync/v1/entry
 * Shortcode: [changelog product="notificationx" per_page="10"]  (product optional = all products)
 */

defined( 'ABSPATH' ) || exit;

final class Changelog_Sync {
	const CPT        = 'changelog_entry';
	const TAX        = 'changelog_product';
	const MAX_SKEW   = 300; // seconds
	const ITEM_TYPES = array( 'Added', 'Fixed', 'Improved', 'Updated', 'Changed', 'Removed', 'Deprecated', 'Security', 'Tweak', 'Revamped', 'Compatibility', 'Dev' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_shortcode( 'changelog', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'settings_menu' ) );
		add_action( 'admin_post_changelog_sync_secret', array( __CLASS__, 'save_secret' ) );
	}

	/* -------------------------------------------------------------- settings */

	public static function settings_menu() {
		add_submenu_page( 'edit.php?post_type=' . self::CPT, 'Changelog Sync', 'Settings', 'manage_options', 'changelog-sync', array( __CLASS__, 'settings_page' ) );
	}

	public static function settings_page() {
		$from_config = defined( 'CHANGELOG_SYNC_SECRET' );
		$secret      = self::secret();
		echo '<div class="wrap"><h1>Changelog Sync</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>Secret saved. Copy it into the GitHub secret <code>CHANGELOG_SECRET</code> now — it is not shown again in full.</p></div>';
		}
		echo '<table class="form-table"><tr><th>Endpoint (GitHub secret <code>CHANGELOG_ENDPOINT</code>)</th><td><code>' . esc_html( rest_url( 'changelog-sync/v1/entry' ) ) . '</code></td></tr>';
		echo '<tr><th>Secret status</th><td>' . ( strlen( $secret ) >= 32
			? 'Configured (' . ( $from_config ? 'wp-config.php' : 'database' ) . ', ends in <code>' . esc_html( substr( $secret, -4 ) ) . '</code>)'
			: '<strong>Not configured — endpoint disabled.</strong>' ) . '</td></tr></table>';
		if ( $from_config ) {
			echo '<p>The secret is defined in wp-config.php and cannot be changed here.</p></div>';
			return;
		}
		$fresh = get_transient( 'changelog_sync_show_secret_' . get_current_user_id() );
		if ( $fresh ) {
			delete_transient( 'changelog_sync_show_secret_' . get_current_user_id() );
			echo '<p>New secret (shown once):</p><p><input type="text" readonly class="large-text code" id="cl-new-secret" value="' . esc_attr( $fresh ) . '" onclick="this.select()"></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'changelog_sync_secret' );
		echo '<input type="hidden" name="action" value="changelog_sync_secret">';
		echo '<p><label>Paste a secret (min 32 chars), or leave blank to generate one:<br><input type="password" name="secret" class="large-text code" autocomplete="off"></label></p>';
		submit_button( $secret ? 'Replace secret' : 'Save secret' );
		echo '</form></div>';
	}

	public static function save_secret() {
		if ( ! current_user_can( 'manage_options' ) || defined( 'CHANGELOG_SYNC_SECRET' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'changelog_sync_secret' );
		$secret = trim( wp_unslash( $_POST['secret'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
		}
		if ( strlen( $secret ) < 32 || preg_match( '/\s/', $secret ) ) {
			wp_die( 'Secret must be at least 32 characters with no spaces.', '', array( 'back_link' => true ) );
		}
		update_option( 'changelog_sync_secret', $secret, false );
		set_transient( 'changelog_sync_show_secret_' . get_current_user_id(), $secret, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::CPT . '&page=changelog-sync&saved=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ data */

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'             => array(
					'name'          => 'Changelog',
					'singular_name' => 'Changelog entry',
					'add_new_item'  => 'Add changelog entry',
					'edit_item'     => 'Edit changelog entry',
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_rest'       => false,
				'menu_icon'          => 'dashicons-list-view',
				'supports'           => array( 'title', 'editor', 'revisions' ),
				'capability_type'    => 'post',
			)
		);
		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'label'             => 'Products',
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
			)
		);
	}

	/** "2026-09-14|00003.00003.00001.00000" — string DESC == newest date, then highest version. */
	public static function sort_key( $date, $version ) {
		preg_match_all( '/\d+/', (string) $version, $m );
		$parts = array_slice( array_pad( $m[0], 4, '0' ), 0, 4 );
		$parts = array_map(
			function ( $p ) {
				return str_pad( substr( $p, -5 ), 5, '0', STR_PAD_LEFT );
			},
			$parts
		);
		return $date . '|' . implode( '.', $parts );
	}

	private static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function find_entry( $key ) {
		$ids = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'any,trash',
				'meta_key'         => '_cl_key',
				'meta_value'       => $key,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function render_items( array $items ) {
		$html = "<ul class=\"cl-items\">\n";
		foreach ( $items as $item ) {
			$text = esc_html( $item['text'] );
			$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
			$type = $item['type'];
			$html .= $type
				? sprintf( '<li class="cl-item cl-type-%s"><span class="cl-tag">%s</span> %s</li>', esc_attr( strtolower( $type ) ), esc_html( $type ), $text )
				: sprintf( '<li class="cl-item">%s</li>', $text );
			$html .= "\n";
		}
		return $html . '</ul>';
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		register_rest_route(
			'changelog-sync/v1',
			'/entry',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => array( __CLASS__, 'verify_signature' ),
			)
		);
	}

	private static function secret() {
		$secret = defined( 'CHANGELOG_SYNC_SECRET' ) ? CHANGELOG_SYNC_SECRET : get_option( 'changelog_sync_secret', '' );
		return is_string( $secret ) ? $secret : '';
	}

	public static function verify_signature( WP_REST_Request $request ) {
		$secret = self::secret();
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error( 'changelog_disabled', 'Changelog sync is not configured.', array( 'status' => 503 ) );
		}
		$ts  = (string) $request->get_header( 'x_changelog_timestamp' );
		$sig = strtolower( (string) $request->get_header( 'x_changelog_signature' ) );
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return new WP_Error( 'changelog_stale', 'Missing or expired timestamp.', array( 'status' => 401 ) );
		}
		$expected = hash_hmac( 'sha256', $ts . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'changelog_bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function receive( WP_REST_Request $request ) {
		$p = json_decode( $request->get_body(), true );
		if ( ! is_array( $p ) ) {
			return new WP_Error( 'changelog_bad_json', 'Body must be a JSON object.', array( 'status' => 400 ) );
		}

		$product = sanitize_title( $p['product'] ?? '' );
		$name    = sanitize_text_field( $p['product_name'] ?? $product );
		$version = (string) ( $p['version'] ?? '' );
		$date    = $p['date'] ?? '';
		$status  = ( $p['status'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish';
		$status  = apply_filters( 'changelog_sync_status', $status, $p );

		if ( '' === $product || ! preg_match( '/^\d[\w.\-+]{0,30}$/', $version ) || ! self::valid_date( $date ) ) {
			return new WP_Error( 'changelog_invalid', 'product, version and date (YYYY-MM-DD) are required.', array( 'status' => 422 ) );
		}
		$items = array();
		foreach ( array_slice( (array) ( $p['items'] ?? array() ), 0, 200 ) as $item ) {
			// Keep literal text such as "<br>" (escaped at render); only drop control chars/bad UTF-8.
			$text = wp_check_invalid_utf8( mb_substr( (string) ( $item['text'] ?? '' ), 0, 2000 ) );
			$text = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
			$type = in_array( $item['type'] ?? '', self::ITEM_TYPES, true ) ? $item['type'] : '';
			if ( '' !== $text ) {
				$items[] = array( 'type' => $type, 'text' => $text );
			}
		}
		if ( ! $items ) {
			return new WP_Error( 'changelog_empty', 'Entry has no items.', array( 'status' => 422 ) );
		}

		$key  = $product . '|' . $version;
		$hash = md5( wp_json_encode( array( $name, $date, $items ) ) );
		$id   = self::find_entry( $key );

		if ( $id ) {
			$post = get_post( $id );
			if ( 'trash' === $post->post_status ) {
				return self::result( 'skipped_trashed', $post );
			}
			if ( get_post_meta( $id, '_cl_lock', true ) ) {
				return self::result( 'skipped_locked', $post );
			}
			// Never un-publish an entry someone already published.
			if ( 'publish' === $post->post_status ) {
				$status = 'publish';
			}
			if ( get_post_meta( $id, '_cl_hash', true ) === $hash && $post->post_status === $status ) {
				return self::result( 'unchanged', $post );
			}
		}

		// Past/today dates keep the release date; a future date would make WP schedule the post.
		$post_date = min( $date . ' 12:00:00', current_time( 'mysql' ) );
		$postarr   = array(
			'ID'            => $id,
			'post_type'     => self::CPT,
			'post_title'    => sprintf( '%s %s', $name, $version ),
			'post_content'  => self::render_items( $items ),
			'post_status'   => $status,
			'post_date'     => $post_date,
			'post_date_gmt' => get_gmt_from_date( $post_date ),
			'edit_date'     => true,
		);
		$new_id = $id ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		wp_set_object_terms( $new_id, $product, self::TAX );
		$term = get_term_by( 'slug', $product, self::TAX );
		if ( $term && $term->name !== $name ) {
			wp_update_term( $term->term_id, self::TAX, array( 'name' => $name ) );
		}
		update_post_meta( $new_id, '_cl_key', $key );
		update_post_meta( $new_id, '_cl_version', $version );
		update_post_meta( $new_id, '_cl_date', $date );
		update_post_meta( $new_id, '_cl_sort', self::sort_key( $date, $version ) );
		update_post_meta( $new_id, '_cl_hash', $hash );
		update_post_meta( $new_id, '_cl_source', esc_url_raw( $p['source_url'] ?? '' ) );

		self::purge_caches();
		do_action( 'changelog_sync_saved', $new_id, $p );

		return self::result( $id ? 'updated' : 'created', get_post( $new_id ) );
	}

	private static function result( $action, WP_Post $post ) {
		return new WP_REST_Response(
			array(
				'action' => $action,
				'id'     => $post->ID,
				'status' => $post->post_status,
				'link'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			),
			'created' === $action ? 201 : 200
		);
	}

	private static function purge_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
	}

	/* ------------------------------------------------------- manual editing */

	public static function meta_box() {
		add_meta_box( 'cl_meta', 'Release', array( __CLASS__, 'render_meta_box' ), self::CPT, 'side', 'high' );
	}

	public static function render_meta_box( WP_Post $post ) {
		wp_nonce_field( 'cl_meta', 'cl_meta_nonce' );
		$version = get_post_meta( $post->ID, '_cl_version', true );
		$date    = get_post_meta( $post->ID, '_cl_date', true );
		$lock    = get_post_meta( $post->ID, '_cl_lock', true );
		$source  = get_post_meta( $post->ID, '_cl_source', true );
		printf( '<p><label>Version<br><input type="text" name="cl_version" value="%s" class="widefat" required></label></p>', esc_attr( $version ) );
		printf( '<p><label>Release date<br><input type="date" name="cl_date" value="%s" class="widefat" required></label></p>', esc_attr( $date ) );
		printf( '<p><label><input type="checkbox" name="cl_lock" value="1" %s> Keep my edits (GitHub will not overwrite)</label></p>', checked( $lock, '1', false ) );
		if ( $source ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener">View release on GitHub</a></p>', esc_url( $source ) );
		}
		echo '<p class="description">Assign the product in the Products box.</p>';
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['cl_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cl_meta_nonce'] ), 'cl_meta' ) ) {
			return; // REST receiver and autosaves land here too.
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$version = sanitize_text_field( wp_unslash( $_POST['cl_version'] ?? '' ) );
		$date    = sanitize_text_field( wp_unslash( $_POST['cl_date'] ?? '' ) );
		if ( '' === $version || ! self::valid_date( $date ) ) {
			return;
		}
		$terms   = wp_get_object_terms( $post_id, self::TAX, array( 'fields' => 'slugs' ) );
		$product = ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : 'manual';
		update_post_meta( $post_id, '_cl_version', $version );
		update_post_meta( $post_id, '_cl_date', $date );
		update_post_meta( $post_id, '_cl_sort', self::sort_key( $date, $version ) );
		update_post_meta( $post_id, '_cl_key', $product . '|' . $version );
		update_post_meta( $post_id, '_cl_lock', empty( $_POST['cl_lock'] ) ? '' : '1' );
		self::purge_caches();
	}

	/* ------------------------------------------------------------ frontend */

	public static function shortcode( $atts ) {
		$atts  = shortcode_atts(
			array(
				'product'  => '',
				'per_page' => 10,
			),
			$atts,
			'changelog'
		);
		$paged = max( 1, absint( $_GET['cl-page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args  = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, absint( $atts['per_page'] ) ) ),
			'paged'          => $paged,
			'meta_key'       => '_cl_sort',
			'orderby'        => array(
				'meta_value' => 'DESC',
				'ID'         => 'DESC',
			),
		);
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', $atts['product'] ) ) );
		if ( $slugs ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => $slugs,
				),
			);
		}
		$q = new WP_Query( $args );
		if ( ! $q->have_posts() ) {
			return '<p class="cl-empty">No changelog entries yet.</p>';
		}
		$show_product = 1 !== count( $slugs );
		ob_start();
		echo '<div class="cl-list">';
		while ( $q->have_posts() ) {
			$q->the_post();
			$id      = get_the_ID();
			$date    = get_post_meta( $id, '_cl_date', true );
			$version = get_post_meta( $id, '_cl_version', true );
			$terms   = get_the_terms( $id, self::TAX );
			echo '<article class="cl-entry" id="' . esc_attr( 'v' . sanitize_title( $version ) ) . '"><header class="cl-head">';
			if ( $show_product && $terms && ! is_wp_error( $terms ) ) {
				echo '<span class="cl-product">' . esc_html( $terms[0]->name ) . '</span>';
			}
			echo '<h3 class="cl-version">' . esc_html( $version ) . '</h3>';
			if ( $date ) {
				echo '<time class="cl-date" datetime="' . esc_attr( $date ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ) ) . '</time>';
			}
			echo '</header><div class="cl-body">' . wp_kses_post( get_post_field( 'post_content', $id ) ) . '</div></article>';
		}
		echo '</div>';
		if ( $q->max_num_pages > 1 ) {
			echo '<nav class="cl-pages">';
			if ( $paged > 1 ) {
				echo '<a href="' . esc_url( add_query_arg( 'cl-page', $paged - 1 ) ) . '">&larr; Newer</a>';
			}
			if ( $paged < $q->max_num_pages ) {
				echo '<a href="' . esc_url( add_query_arg( 'cl-page', $paged + 1 ) ) . '">Older &rarr;</a>';
			}
			echo '</nav>';
		}
		wp_reset_postdata();
		self::styles();
		return ob_get_clean();
	}

	private static function styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo '<style>
.cl-list{display:flex;flex-direction:column;gap:1.5rem}
.cl-entry{border-left:3px solid #e2e4e7;padding:.25rem 0 .25rem 1rem}
.cl-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem .75rem;margin-bottom:.5rem}
.cl-version{margin:0;font-size:1.15rem}
.cl-date{color:#6b7280;font-size:.9rem}
.cl-product{font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#4b5563}
.cl-items{margin:0;padding-left:1.1rem}
.cl-item{margin:.3rem 0;line-height:1.5}
.cl-tag{display:inline-block;min-width:4.8rem;margin-right:.35rem;padding:0 .4rem;border-radius:3px;font-size:.72rem;font-weight:600;text-align:center;background:#eef0f3;color:#374151}
.cl-type-added .cl-tag{background:#dcfce7;color:#166534}
.cl-type-fixed .cl-tag{background:#fee2e2;color:#991b1b}
.cl-type-improved .cl-tag,.cl-type-updated .cl-tag,.cl-type-changed .cl-tag{background:#dbeafe;color:#1e40af}
.cl-type-security .cl-tag{background:#fef3c7;color:#92400e}
.cl-pages{display:flex;justify-content:space-between;margin-top:1.5rem}
</style>';
	}
}

Changelog_Sync::init();
