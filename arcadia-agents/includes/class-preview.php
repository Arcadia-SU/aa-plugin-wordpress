<?php
/**
 * Preview URL handler.
 *
 * Generates time-limited preview tokens for draft/private posts,
 * allowing the SEO agent to take screenshots without authentication.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Preview
 *
 * Manages preview tokens stored as post meta.
 */
class Arcadia_Preview {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Preview|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Preview
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Get an existing valid token or generate a new one.
	 *
	 * Reuses a valid (non-expired) token if one exists, avoiding
	 * unnecessary DB writes when listing multiple articles.
	 *
	 * @param int $post_id The post ID.
	 * @return string The token (existing or newly generated).
	 */
	public function get_or_create_token( $post_id ) {
		$stored_token = get_post_meta( $post_id, '_aa_preview_token', true );
		$expires      = (int) get_post_meta( $post_id, '_aa_preview_expires', true );

		if ( ! empty( $stored_token ) && ! empty( $expires ) && time() < $expires ) {
			return $stored_token;
		}

		return $this->generate_token( $post_id );
	}

	/**
	 * Generate a preview token for a post.
	 *
	 * Creates a random token, stores it in post meta with an expiry timestamp.
	 * If a valid token already exists, it is replaced.
	 *
	 * @param int $post_id The post ID.
	 * @return string The generated token.
	 */
	public function generate_token( $post_id ) {
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = time() + DAY_IN_SECONDS;

		update_post_meta( $post_id, '_aa_preview_token', $token );
		update_post_meta( $post_id, '_aa_preview_expires', $expires );

		return $token;
	}

	/**
	 * Validate a preview token for a post.
	 *
	 * Uses timing-safe comparison to prevent timing attacks.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $token   The token to validate.
	 * @return bool True if token is valid and not expired.
	 */
	public function validate_token( $post_id, $token ) {
		$stored_token = get_post_meta( $post_id, '_aa_preview_token', true );
		$expires      = (int) get_post_meta( $post_id, '_aa_preview_expires', true );

		if ( empty( $stored_token ) || empty( $expires ) ) {
			return false;
		}

		if ( time() > $expires ) {
			// Clean up expired token.
			delete_post_meta( $post_id, '_aa_preview_token' );
			delete_post_meta( $post_id, '_aa_preview_expires' );
			return false;
		}

		return hash_equals( $stored_token, $token );
	}

	/**
	 * Fix the main query for preview requests (pre_get_posts callback).
	 *
	 * Without this, `?p=ID` for a CPT draft resolves to 404 because
	 * WordPress doesn't know which post_type to query. This hook tells
	 * WP_Query to look for the correct type and to include non-published statuses.
	 *
	 * @param \WP_Query $query The main WP_Query instance.
	 */
	public function fix_query_for_preview( $query ) {
		// Only modify the main query.
		if ( ! $query->is_main_query() ) {
			return;
		}

		// Only act on preview requests.
		if ( empty( $_GET['aa_preview'] ) || empty( $_GET['p'] ) ) {
			return;
		}

		$post_id = (int) $_GET['p'];
		$token   = sanitize_text_field( $_GET['aa_preview'] );
		$post    = get_post( $post_id );

		if ( ! $post || ! $this->validate_token( $post_id, $token ) ) {
			return;
		}

		// For CPTs (not post/page), tell WP_Query which post type to look for.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$query->set( 'post_type', $post->post_type );
		}

		// Allow draft/pending/private/future posts to be found.
		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'private', 'future' ) );
	}

	/**
	 * Handle preview requests on template_redirect.
	 *
	 * Checks for `?aa_preview=TOKEN&p=ID` in the URL, validates the token,
	 * then takes full control of rendering: populates wp_query so that
	 * have_posts()/the_post() work inside the theme template, resolves
	 * the template via WordPress's hierarchy, includes it, and exits.
	 *
	 * We include the template ourselves (instead of returning and letting
	 * template-loader.php do it) because other template_redirect handlers
	 * (redirect_canonical, caching plugins, SEO plugins) can interfere
	 * with draft CPT rendering if we don't take control early.
	 *
	 * Debug mode: add `&aa_debug=1` to the preview URL to get a JSON
	 * diagnostic report instead of the rendered page. No additional gate
	 * beyond the preview token — the diagnostic contains no secrets.
	 */
	public function handle_preview() {
		if ( empty( $_GET['aa_preview'] ) || empty( $_GET['p'] ) ) {
			return;
		}

		$token   = sanitize_text_field( $_GET['aa_preview'] );
		$post_id = (int) $_GET['p'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( ! $this->validate_token( $post_id, $token ) ) {
			return;
		}

		// A revision renders in its parent's clothes, not its own (Phase 41.2).
		$context = $this->resolve_render_context( $post );

		// ...and in its parent's field values, not its own emptiness (Phase 43.3).
		// Must be installed before any template code runs.
		$this->install_field_overlay( $post, $context );

		// Set up rendering state (headers, post data, wp_query).
		$this->setup_preview_state( $post, $context );

		// Resolve template via WordPress hierarchy.
		$templates = $this->get_preview_template_hierarchy( $context );
		$template  = locate_template( $templates );

		if ( ! $template ) {
			$template = get_index_template();
		}

		// Debug mode: return JSON diagnostic instead of rendering.
		if ( $this->is_debug_request() ) {
			$this->send_debug_report( $post, $templates, $template, $context );
			// send_debug_report calls exit.
		}

		if ( $template ) {
			// Capture output to detect empty renders.
			ob_start();
			include $template;
			$output = ob_get_clean();

			if ( strlen( $output ) > 0 ) {
				echo $output;
			} else {
				// Template produced nothing — render minimal fallback
				// so the response is never Content-Length: 0.
				$this->render_fallback( $post );
			}
			exit;
		}

		// No template found at all — render fallback.
		$this->render_fallback( $post );
		exit;
	}

	/**
	 * Guard against re-entering the meta overlay filter.
	 *
	 * The filter needs to ask "does the revision itself carry this key?", which
	 * is another read of the very object it is filtering. Without this flag that
	 * question would call the filter again, forever.
	 *
	 * @var bool
	 */
	private $overlay_active = false;

	/**
	 * Make a revision preview read the parent's fields where it has none.
	 *
	 * A revision post carries only post_title and post_content; its proposal
	 * lives as JSON in _aa_revision_meta and is replayed onto the parent only at
	 * approval. But the preview loop yields the revision, so every get_field()
	 * in the theme resolved against a post with no fields and rendered nothing —
	 * a page that was 72KB live came back as 11KB with zero paragraphs. The
	 * preview was trying to render a delta as if it were a whole page.
	 *
	 * One rule, applied per meta key: the proposal wins when it has an opinion,
	 * otherwise the parent's stored value shows through.
	 *
	 * Read-only by construction. Nothing is written to the revision, so the
	 * overlay dies with the request. Writing the proposed fields onto the CPT at
	 * PUT time would have been the alternative — it would mean running coercions
	 * (importing images) during a write that is supposed to touch nothing, and
	 * then writing them a second time on approval.
	 *
	 * @param WP_Post     $revision      The revision being previewed.
	 * @param WP_Post     $parent        The post it proposes to modify.
	 * @param object|null $field_context Holder of the coercion pipeline. Defaults to the
	 *                                   API singleton; injectable so the overlay can be
	 *                                   tested without booting the whole API.
	 */
	public function install_field_overlay( $revision, $parent, $field_context = null ) {
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return;
		}
		if ( ! $parent || (int) $parent->ID === (int) $revision->ID ) {
			return;
		}

		$revision_id = (int) $revision->ID;
		$parent_id   = (int) $parent->ID;

		// Resolved before the filter is installed — this reads the revision's own
		// meta, which the filter would otherwise intercept.
		$proposed = $this->build_proposed_meta( $revision, $parent, $field_context );

		add_filter(
			'get_post_metadata',
			function ( $value, $object_id, $meta_key, $single ) use ( $revision_id, $parent_id, $proposed ) {
				unset( $single ); // Core takes [0] of whatever array we return.

				if ( $this->overlay_active ) {
					return $value;
				}

				$object_id = (int) $object_id;

				// A read aimed at the PARENT still belongs to this preview. The loop
				// yields the revision, but setup_preview_state() points
				// queried_object/queried_object_id at the parent — so a theme calling
				// get_field('x', get_queried_object_id()), or any of the many plugins
				// that read off the queried object, addressed the live post and got
				// live values. The page rendered full and well-formed with none of the
				// proposal in it: the most dangerous failure mode this feature has,
				// because nothing looks wrong. Only proposed keys are answered here —
				// the parent is the source of truth for everything else.
				if ( $object_id === $parent_id && $parent_id !== $revision_id ) {
					if ( '' !== $meta_key && array_key_exists( $meta_key, $proposed ) ) {
						return array( $proposed[ $meta_key ] );
					}
					return $value;
				}

				if ( $object_id !== $revision_id ) {
					return $value;
				}

				// Plugin bookkeeping must never inherit. A parent's preview token
				// resolving on the revision would be a security defect, not a
				// convenience; the same goes for the revision payload itself.
				if ( '' !== $meta_key && $this->is_internal_meta_key( $meta_key ) ) {
					return $value;
				}

				if ( '' === $meta_key ) {
					return $this->merged_meta( $revision_id, $parent_id, $proposed );
				}

				if ( array_key_exists( $meta_key, $proposed ) ) {
					return array( $proposed[ $meta_key ] );
				}

				// The revision may legitimately carry this key itself — let core serve it.
				if ( array() !== $this->own_meta( $revision_id, $meta_key ) ) {
					return $value;
				}

				// ACF stores every field as a pair: `name` and `_name` (the field key).
				// A generic key-by-key rule carries both automatically — which is
				// exactly why the rule is generic and not a list of field names.
				$inherited = $this->parent_meta( $parent_id, $meta_key );

				return empty( $inherited ) ? $value : $inherited;
			},
			10,
			4
		);
	}

	/**
	 * Read a key off the parent with the overlay stood down.
	 *
	 * The parent branch of the filter answers proposed keys, so an unguarded read
	 * here would bounce back through it. Nothing would break today — the caller
	 * already returned for proposed keys — but the re-entrancy is the kind that
	 * stops being harmless the moment either branch grows a case.
	 *
	 * @param int    $parent_id The parent post ID.
	 * @param string $meta_key  Key to read.
	 * @return array Raw values.
	 */
	private function parent_meta( $parent_id, $meta_key ) {
		$this->overlay_active = true;
		$inherited            = get_post_meta( $parent_id, $meta_key, false );
		$this->overlay_active = false;

		return is_array( $inherited ) ? $inherited : array();
	}

	/**
	 * Resolve the field values this revision proposes, without writing anything.
	 *
	 * @param WP_Post     $revision      The revision being previewed.
	 * @param WP_Post     $parent        The post it proposes to modify.
	 * @param object|null $field_context Holder of the coercion pipeline.
	 * @return array meta_key => coerced value.
	 */
	private function build_proposed_meta( $revision, $parent, $field_context = null ) {
		if ( null === $field_context ) {
			if ( ! class_exists( 'Arcadia_API' ) ) {
				return array();
			}
			$field_context = Arcadia_API::get_instance();
		}

		$payload = json_decode( (string) get_post_meta( $revision->ID, '_aa_revision_meta', true ), true );
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$body          = isset( $payload['body'] ) && is_array( $payload['body'] ) ? $payload['body'] : array();
		$meta          = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
		$skip_markdown = ! empty( $payload['skip_markdown'] );

		$api      = $field_context;
		$type_map = $api->build_acf_field_type_map( $parent->post_type );
		$proposed = array();

		// What a `wysiwyg: null` field copies. finalize_post() uses the revision's
		// rendered content, and falls back to the LIVE post's content when the
		// revision proposes none — so a PUT that only touches ACF fields keeps the
		// page body it already had. Reading only $revision->post_content here meant
		// copying '' into the field: the preview blanked a field that approval would
		// have preserved, and the reviewer rejected a correct revision on the
		// strength of it. Mirror the writer, including its fallback.
		$content_for_acf = (string) $revision->post_content;
		if ( '' === $content_for_acf ) {
			$content_for_acf = (string) $parent->post_content;
		}

		$explicit = isset( $body['acf_fields'] ) && is_array( $body['acf_fields'] ) ? $body['acf_fields'] : array();
		foreach ( $explicit as $field_name => $raw ) {
			$field_type = $type_map[ $field_name ] ?? 'text';
			$resolved   = $this->resolve_for_render( $api, $raw, $field_type, $content_for_acf, $skip_markdown );
			if ( null !== $resolved ) {
				$proposed[ $field_name ] = $resolved;
			}
		}

		// Calibrated fields the payload never names, but approval will write.
		foreach ( $api->resolve_field_schema_mappings( $parent->post_type, $body, $meta ) as $field_name => $entry ) {
			$field_type = $type_map[ $field_name ] ?? 'text';
			$resolved   = $this->resolve_for_render( $api, $entry['value'], $field_type, $content_for_acf, $skip_markdown );
			if ( null !== $resolved ) {
				$proposed[ $field_name ] = $resolved;
			}
		}

		return $proposed;
	}

	/**
	 * ACF field types the overlay cannot stand in for, and must not try to.
	 *
	 * ACF does not store these under their own name. A repeater is a row count in
	 * `field` plus one meta key per sub-field per row (`field_0_title`); groups,
	 * flexible content and clones decompose the same way. Handing the raw payload
	 * array back under the bare name made ACF read it as the row count — intval()
	 * of a non-empty array is 1 — and render exactly one row, filled from the
	 * PARENT's sub-field keys. The result matched neither the current page nor the
	 * proposal, which is worse than showing the current page unchanged: a reviewer
	 * can recognise "this hasn't updated", but not "this is a chimera".
	 *
	 * Decomposing the payload into row keys here would mean reimplementing ACF's
	 * storage layout on a read path, and getting it subtly wrong in a way that only
	 * shows up on the client's site. These fields fall back to the parent's live
	 * value and the before/after diff is what announces the change — the same
	 * documented limit as an image proposed by URL.
	 *
	 * @return string[]
	 */
	private static function structured_field_types() {
		return array( 'repeater', 'group', 'flexible_content', 'clone' );
	}

	/**
	 * Coerce one proposed value for display, or decline to.
	 *
	 * Delegates to the write path's own coercion so markdown renders as markup
	 * here exactly as it will once approved — duplicating those rules would
	 * rebuild the divergence Phase 42.1 closed.
	 *
	 * Declines (returns null) in two cases, both falling back to the parent's live
	 * value with the before/after diff left to announce the change:
	 *   - an image proposed as a URL — turning it into an attachment ID means
	 *     importing the file, and a page render may not create media;
	 *   - a structured field (repeater, group, flexible content, clone) — see
	 *     structured_field_types() for why standing in for one is worse than not.
	 *
	 * @param Arcadia_API $api           API instance holding the coercion pipeline.
	 * @param mixed       $raw           Proposed value as the agent sent it.
	 * @param string      $field_type    ACF field type.
	 * @param string      $post_content  Revision's rendered content (wysiwyg-null case).
	 * @param bool        $skip_markdown Round-trip flag stored with the revision.
	 * @return mixed|null Coerced value, or null to leave the field to the parent.
	 */
	private function resolve_for_render( $api, $raw, $field_type, $post_content, $skip_markdown ) {
		if ( in_array( $field_type, self::structured_field_types(), true ) ) {
			return null;
		}

		if ( 'sideload_image' === $api->describe_field_transform( $raw, $field_type, $skip_markdown ) ) {
			return null;
		}

		$coerced = $api->coerce_field_value( $raw, $field_type, $post_content, $skip_markdown, 0, false );

		return is_wp_error( $coerced ) ? null : $coerced;
	}

	/**
	 * Read a key straight off the revision, with the overlay stood down.
	 *
	 * @param int    $revision_id The revision post ID.
	 * @param string $meta_key    Key to read.
	 * @return array Raw values (empty when the revision has none).
	 */
	private function own_meta( $revision_id, $meta_key ) {
		$this->overlay_active = true;
		$own                  = get_post_meta( $revision_id, $meta_key, false );
		$this->overlay_active = false;

		return is_array( $own ) ? $own : array();
	}

	/**
	 * Full meta set for a bulk read ($meta_key === '').
	 *
	 * ACF primes its cache with one bulk read, so the overlay has to answer this
	 * shape too — otherwise the fallback is invisible on that path and half the
	 * fields render empty anyway. Returns core's raw shape: key => list of
	 * serialized values.
	 *
	 * @param int   $revision_id The revision post ID.
	 * @param int   $parent_id   The parent post ID.
	 * @param array $proposed    Resolved proposed values.
	 * @return array
	 */
	private function merged_meta( $revision_id, $parent_id, $proposed ) {
		$this->overlay_active = true;
		$own                  = get_post_meta( $revision_id );
		$inherited            = get_post_meta( $parent_id );
		$this->overlay_active = false;

		$own       = is_array( $own ) ? $own : array();
		$inherited = is_array( $inherited ) ? $inherited : array();

		foreach ( array_keys( $inherited ) as $key ) {
			if ( $this->is_internal_meta_key( $key ) ) {
				unset( $inherited[ $key ] );
			}
		}

		// Parent is the floor, the revision's own meta overrides it, the
		// proposal wins outright.
		$merged = array_merge( $inherited, $own );

		foreach ( $proposed as $key => $value ) {
			$merged[ $key ] = array( maybe_serialize( $value ) );
		}

		return $merged;
	}

	/**
	 * Keys that belong to the plugin's own bookkeeping and never inherit.
	 *
	 * @param string $meta_key Key to test.
	 * @return bool
	 */
	private function is_internal_meta_key( $meta_key ) {
		foreach ( array( '_aa_revision', '_aa_preview', '_edit_lock', '_edit_last' ) as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set up the global state for preview rendering.
	 *
	 * Separated from handle_preview() so unit tests can verify the state
	 * setup without triggering template inclusion and exit.
	 *
	 * @param \WP_Post      $post    The post object (modified in place: status → publish).
	 * @param \WP_Post|null $context Rendering context (the parent, for a revision).
	 *                               Defaults to $post.
	 */
	public function setup_preview_state( $post, $context = null ) {
		if ( null === $context ) {
			$context = $post;
		}

		// Override 404 status that WordPress may have set for draft CPTs.
		status_header( 200 );

		// Prevent caching of preview pages.
		nocache_headers();

		// Tell search engines not to index preview URLs.
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Force the post to appear published for rendering.
		$post->post_status = 'publish';

		// Set up global post data for theme template functions.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		// Fully populate wp_query so theme template loops work.
		// Without posts/post_count, have_posts() returns false and
		// the template renders an empty body (Content-Length: 0).
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$is_page = ( 'page' === $context->post_type );

			$GLOBALS['wp_query']->post          = $post;
			$GLOBALS['wp_query']->posts         = array( $post );
			$GLOBALS['wp_query']->post_count    = 1;
			$GLOBALS['wp_query']->found_posts   = 1;
			$GLOBALS['wp_query']->max_num_pages = 1;
			$GLOBALS['wp_query']->current_post  = -1;

			// The loop yields the revision (that's the content under review),
			// but the queried object is the context. body_class(), is_page()
			// and every theme conditional read the queried object, so this is
			// what makes the preview wear the parent's classes instead of
			// `single-aa_revision postid-<revision>` (Phase 41.2).
			$GLOBALS['wp_query']->queried_object    = $context;
			$GLOBALS['wp_query']->queried_object_id = $context->ID;

			$GLOBALS['wp_query']->is_page     = $is_page;
			$GLOBALS['wp_query']->is_single   = ! $is_page;
			$GLOBALS['wp_query']->is_singular = true;
			$GLOBALS['wp_query']->is_404      = false;
		}
	}

	/**
	 * Check if this is a debug request.
	 *
	 * Gated behind WP_DEBUG. The report lists theme template files and paths,
	 * which is filesystem reconnaissance we don't want exposed on a production
	 * site to anyone holding a (shareable) preview token. A capability gate is
	 * not usable here — preview access is proven by the URL token, not a WP
	 * login — so WP_DEBUG is the right switch: the tool stays available the
	 * moment a developer turns debugging on, and is inert otherwise.
	 *
	 * @return bool
	 */
	private function is_debug_request() {
		return ! empty( $_GET['aa_debug'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Strip the WordPress root from a path so the debug report never leaks the
	 * absolute server layout (e.g. /home/<client>/… or /var/www/html/…).
	 *
	 * @param string $value A path, or a message that may contain one.
	 * @return string The value with ABSPATH removed.
	 */
	private function strip_abspath( $value ) {
		if ( ! is_string( $value ) || ! defined( 'ABSPATH' ) ) {
			return $value;
		}
		return str_replace( ABSPATH, '', $value );
	}

	/**
	 * Send a JSON diagnostic report and exit.
	 *
	 * Captures what the template would render (via ob_start) to report
	 * the output size without actually sending it to the browser.
	 *
	 * @param \WP_Post      $post      The post object.
	 * @param array         $templates Template candidates that were tried.
	 * @param string        $template  Resolved template path (empty if none found).
	 * @param \WP_Post|null $context   Rendering context (the parent, for a revision).
	 */
	private function send_debug_report( $post, $templates, $template, $context = null ) {
		if ( null === $context ) {
			$context = $post;
		}

		// Try rendering the template to measure output.
		$output_length = 0;
		$output_sample = '';
		$render_error  = null;

		if ( $template ) {
			ob_start();
			try {
				include $template;
			} catch ( \Throwable $e ) {
				$render_error = $this->strip_abspath( $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
			$output        = ob_get_clean();
			$output_length = strlen( $output );
			$output_sample = substr( $output, 0, 500 );
		}

		// List template files that exist in the theme directory.
		$theme_dir   = get_stylesheet_directory();
		$theme_files = array();
		if ( is_dir( $theme_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $theme_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->getExtension() === 'php' ) {
					$relative = str_replace( $theme_dir . '/', '', $file->getPathname() );
					// Only list template-like files (single*, singular*, index*, page*).
					if ( preg_match( '/^(single|singular|index|page|archive|content|template)/i', $relative ) ) {
						$theme_files[] = $relative;
					}
				}
			}
			sort( $theme_files );
		}

		$report = array(
			'aa_preview_debug' => true,
			'post'             => array(
				'ID'           => $post->ID,
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'post_name'    => $post->post_name,
				'post_title'   => $post->post_title,
			),
			// What actually drove template resolution. Without this the fix of
			// Phase 41.2 is unverifiable on a client site: the report would
			// show the right candidates with no way to tell why.
			'render_context'   => array(
				'is_revision'    => 'aa_revision' === $post->post_type,
				'context_id'     => $context->ID,
				'context_type'   => $context->post_type,
				'context_name'   => $context->post_name,
				'parent_id'      => (int) $post->post_parent,
				'parent_missing' => 'aa_revision' === $post->post_type
					&& ! empty( $post->post_parent )
					&& $context->ID === $post->ID,
				'template_slug'  => get_page_template_slug( $context->ID ),
			),
			'theme'            => array(
				'stylesheet'       => get_stylesheet(),
				'template'         => get_template(),
				'stylesheet_dir'   => $this->strip_abspath( get_stylesheet_directory() ),
				'is_child_theme'   => get_stylesheet() !== get_template(),
			),
			'template_resolution' => array(
				'candidates'       => array_map( array( $this, 'strip_abspath' ), $templates ),
				'resolved'         => $template ? $this->strip_abspath( $template ) : null,
				'resolved_exists'  => $template ? file_exists( $template ) : false,
			),
			'theme_template_files' => $theme_files,
			'wp_query'           => array(
				'is_single'   => isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->is_single : null,
				'is_singular' => isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->is_singular : null,
				'is_404'      => isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->is_404 : null,
				'post_count'  => isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->post_count : null,
				'found_posts' => isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->found_posts : null,
			),
			'render'             => array(
				'output_length' => $output_length,
				'output_sample' => $output_sample,
				'render_error'  => $render_error,
			),
			'environment'        => array(
				'ob_level'     => ob_get_level(),
				'php_version'  => PHP_VERSION,
				'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			),
		);

		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Render a minimal fallback page when the theme template produces no output.
	 *
	 * Uses wp_head()/wp_footer() to load theme styles and scripts,
	 * and the_content() filter to render blocks/shortcodes properly.
	 *
	 * @param object $post The post object.
	 */
	private function render_fallback( $post ) {
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main>
	<article>
		<h1><?php echo esc_html( $post->post_title ); ?></h1>
		<div class="entry-content">
			<?php echo apply_filters( 'the_content', $post->post_content ); ?>
		</div>
	</article>
</main>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Resolve the post that provides the *rendering context* for a preview.
	 *
	 * For an ordinary post this is the post itself. For an `aa_revision` it is
	 * the parent it was taken from (Phase 41.2).
	 *
	 * A revision carries the proposed *content*, never the site's *presentation*:
	 * its own post_type is `aa_revision`, so deriving the template from it lands
	 * on `single-aa_revision*.php`, misses, and falls back to the generic
	 * template. Observed on iSelection preprod: body class
	 * `single-aa_revision postid-88553` where live renders
	 * `single-page-investir page-investir-template-default`. The client then
	 * approves a revision in a layout that is not the page's — HITL rendered
	 * blind.
	 *
	 * `post_parent` is set at insertion (class-revisions.php:122-133) and never
	 * mutated afterwards, so it is reliable. It is still guarded: nothing
	 * cascades revision deletion when the parent is deleted, so an orphan
	 * revision must degrade to its own context rather than fatal.
	 *
	 * @param \WP_Post $post The previewed post.
	 * @return \WP_Post The post whose type, slug and template drive rendering.
	 */
	private function resolve_render_context( $post ) {
		if ( 'aa_revision' !== $post->post_type || empty( $post->post_parent ) ) {
			return $post;
		}

		$parent = get_post( (int) $post->post_parent );

		return $parent ? $parent : $post;
	}

	/**
	 * Build the template hierarchy for a preview post.
	 *
	 * Constructs the hierarchy from the post object directly, avoiding
	 * get_queried_object() which may return null when WordPress is in 404 state.
	 *
	 * Mirrors the WordPress template hierarchy rather than approximating it:
	 * the editor-assigned page template wins (WP ≥ 4.7 allows one on any post
	 * type), then the `page-*` branch for `page` and the `single-*` branch for
	 * everything else. Both branches were missing before Phase 41.2 — a preview
	 * of a plain page fell through to `single.php`, which is not a template
	 * WordPress would ever pick for it.
	 *
	 * Pass the *render context* here, not the previewed post — see
	 * resolve_render_context().
	 *
	 * @param object $post The post object providing the rendering context.
	 * @return array Ordered list of template filenames to try.
	 */
	private function get_preview_template_hierarchy( $post ) {
		$templates = array();
		$type      = $post->post_type;

		$template_slug = get_page_template_slug( $post->ID );
		if ( is_string( $template_slug ) && '' !== $template_slug ) {
			$templates[] = $template_slug;
		}

		if ( 'page' === $type ) {
			if ( ! empty( $post->post_name ) ) {
				$templates[] = "page-{$post->post_name}.php";
			}
			$templates[] = "page-{$post->ID}.php";
			$templates[] = 'page.php';
		} else {
			if ( ! empty( $post->post_name ) ) {
				$templates[] = "single-{$type}-{$post->post_name}.php";
			}
			$templates[] = "single-{$type}.php";
			$templates[] = 'single.php';
		}

		$templates[] = 'singular.php';

		return $templates;
	}

	/**
	 * Clean up expired preview tokens.
	 *
	 * Queries for posts with expired `_aa_preview_expires` and removes
	 * both the token and expiry meta.
	 */
	public function cleanup_expired_tokens() {
		global $wpdb;

		$now = time();

		// Find all posts with expired preview tokens.
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_aa_preview_expires'
				 AND CAST(meta_value AS UNSIGNED) < %d",
				$now
			)
		);

		if ( ! empty( $expired ) ) {
			foreach ( $expired as $post_id ) {
				delete_post_meta( (int) $post_id, '_aa_preview_token' );
				delete_post_meta( (int) $post_id, '_aa_preview_expires' );
			}
		}
	}

	/**
	 * Schedule the daily cleanup cron event.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'arcadia_preview_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'arcadia_preview_cleanup' );
		}
	}

	/**
	 * Unschedule the cleanup cron event.
	 */
	public static function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( 'arcadia_preview_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'arcadia_preview_cleanup' );
		}
	}
}
