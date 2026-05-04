<?php
/**
 * Post builder — assembles wp_insert_post / wp_update_post payloads.
 *
 * Extracted from trait-api-posts.php (Phase C). Holds the parts of
 * create_post() and update_post() that diverged less than 10%, so the
 * trait can shrink to orchestration + mode-specific concerns
 * (auth, post lookup, pending-revision short-circuit, response shape).
 *
 * Three public seams:
 *   - build_post_data()  — title/slug/excerpt/status + content rendering
 *   - write_post()       — wp_slash + wp_insert_post|wp_update_post + user swap
 *   - finalize_post()    — sideload re-attach, taxonomies, featured image,
 *                          SEO meta, ACF fields, schema mappings, render test
 *
 * No internal singleton: state-free. The caller injects an Arcadia_Blocks
 * instance because the builder needs json_to_blocks() for structured
 * content, but otherwise the builder holds no mutable state.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Post_Builder
 *
 * Stateless helper. Instantiate per request; do not reuse across requests
 * because some downstream hooks (ACF save, render test) mutate global state
 * that should be scoped to the current call site.
 */
final class Arcadia_Post_Builder {

	/**
	 * Block generator used to render structured content (h1/sections/children)
	 * into Gutenberg block markup before inserting/updating a post.
	 *
	 * @var Arcadia_Blocks
	 */
	private $blocks;

	/**
	 * Constructor.
	 *
	 * @param Arcadia_Blocks $blocks Block generator dependency.
	 */
	public function __construct( $blocks ) {
		$this->blocks = $blocks;
	}

	/**
	 * Assemble the post_data array consumed by wp_insert_post / wp_update_post.
	 *
	 * Behavior matrix:
	 *   - $existing === null  → create mode. post_data carries post_type +
	 *     post_status (validated, defaults 'draft', honors aa_force_draft).
	 *     Caller adds post_author after this returns.
	 *   - $existing !== null  → update mode. post_data carries ID; post_status
	 *     only set if body.status present (and aa_force_draft is honored).
	 *
	 * Side effect: $meta is mutated in create mode when body.content is an
	 * array and meta is empty — nested-content shape promotes nested.meta
	 * (title/slug/description/post_type) to the top-level meta so the caller
	 * can pass it into finalize_post() unchanged.
	 *
	 * @param array        $body      Decoded request JSON.
	 * @param array        $meta      Top-level meta (mutated by reference).
	 * @param string       $post_type Resolved post type (already validated).
	 * @param WP_Post|null $existing  Existing post for update mode, or null for create.
	 * @return array{post_data:array, rendered_content:string, force_draft_applied:bool}|WP_Error
	 */
	public function build_post_data( array $body, array &$meta, $post_type, $existing = null ) {
		$post_data           = array();
		$force_draft_applied = false;
		$is_create           = ( null === $existing );

		// Status + post_type vs ID.
		if ( $is_create ) {
			$status_or_error = $this->resolve_status( $body, 'draft' );
			if ( is_wp_error( $status_or_error ) ) {
				return $status_or_error;
			}
			$status = $status_or_error;

			if ( get_option( 'aa_force_draft', false ) ) {
				$status              = 'draft';
				$force_draft_applied = true;
			}

			$post_data['post_type']   = $post_type;
			$post_data['post_status'] = $status;
		} else {
			$post_data['ID'] = (int) $existing->ID;

			if ( ! empty( $body['status'] ) ) {
				$status_or_error = $this->resolve_status( $body, null );
				if ( is_wp_error( $status_or_error ) ) {
					return $status_or_error;
				}
				$post_data['post_status'] = $status_or_error;
			}

			if ( get_option( 'aa_force_draft', false ) ) {
				$post_data['post_status'] = 'draft';
				$force_draft_applied      = true;
			}
		}

		// Title — body.title (H1) wins; meta.title is fallback.
		if ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		} elseif ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
		}

		// Slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Excerpt: meta.description, then top-level excerpt overrides
		// (so an empty body.excerpt clears the field).
		if ( ! empty( $meta['description'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
		}
		if ( isset( $body['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $body['excerpt'] );
		}

		// Content. Create mode also supports legacy nested body.content shape.
		$content_data = $body;
		if ( $is_create && isset( $body['content'] ) && is_array( $body['content'] ) ) {
			$content_data = $body['content'];

			if ( empty( $meta ) && ! empty( $content_data['meta'] ) ) {
				$meta = $content_data['meta'];

				if ( empty( $post_data['post_title'] ) && ! empty( $meta['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $meta['title'] );
				}
				if ( empty( $post_data['post_name'] ) && ! empty( $meta['slug'] ) ) {
					$post_data['post_name'] = sanitize_title( $meta['slug'] );
				}
				if ( empty( $post_data['post_excerpt'] ) && ! empty( $meta['description'] ) ) {
					$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
				}
			}
		}

		$rendered_content = '';
		if ( ! empty( $content_data['h1'] ) || ! empty( $content_data['sections'] ) || ! empty( $content_data['children'] ) ) {
			$content = $this->blocks->json_to_blocks( $content_data, $post_type );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$post_data['post_content'] = $content;
			$rendered_content          = $content;
		} elseif ( ! empty( $body['content'] ) && is_string( $body['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $body['content'] );
			$rendered_content          = $post_data['post_content'];
		}

		return array(
			'post_data'           => $post_data,
			'rendered_content'    => $rendered_content,
			'force_draft_applied' => $force_draft_applied,
		);
	}

	/**
	 * Persist post_data via wp_insert_post or wp_update_post under the
	 * supplied author identity (so wp_filter_post_kses doesn't strip block
	 * comments containing HTML).
	 *
	 * Always wraps post_data in wp_slash() because both insert/update
	 * call wp_unslash() internally.
	 *
	 * @param array $post_data   Result of build_post_data()['post_data'].
	 * @param int   $author_uid  User ID under which to run the write.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function write_post( array $post_data, $author_uid ) {
		$original_user_id = get_current_user_id();
		wp_set_current_user( (int) $author_uid );

		$slashed = wp_slash( $post_data );

		if ( isset( $slashed['ID'] ) ) {
			$result = wp_update_post( $slashed, true );
		} else {
			$result = wp_insert_post( $slashed, true );
		}

		wp_set_current_user( $original_user_id );

		return $result;
	}

	/**
	 * Apply post-write side effects: sideload re-attach, taxonomies,
	 * featured image, SEO meta, ACF fields, schema mappings, render test.
	 *
	 * Fatal errors (process_acf_fields, render_test) are returned as
	 * WP_Error and stop the request. Non-fatal errors (featured image
	 * sideload, term creation) are returned in 'warnings' for the caller
	 * to surface in the response payload.
	 *
	 * @param int                  $post_id            ID returned by write_post().
	 * @param array                $body               Original request body.
	 * @param array                $meta               Possibly mutated meta from build_post_data().
	 * @param string               $post_type          Post type slug.
	 * @param string               $rendered_content   Content from build_post_data() (empty if none).
	 * @param object               $finalizer_context  Object exposing the trait helpers needed
	 *                                                 (get_or_create_terms, sideload_and_set_featured_image,
	 *                                                  process_acf_fields, auto_populate_acf_fields,
	 *                                                  apply_field_schema_mappings).
	 * @param array{is_create:bool} $options           Mode-specific switches.
	 * @return array{warnings:array<int,string>}|WP_Error
	 */
	public function finalize_post( $post_id, array $body, array $meta, $post_type, $rendered_content, $finalizer_context, array $options ) {
		$is_create = ! empty( $options['is_create'] );
		$warnings  = array();

		// Re-attach sideloaded images from H1.2 validation (created with post_parent=0).
		if ( Arcadia_Blocks::is_acf_available() ) {
			$acf_validator  = Arcadia_ACF_Validator::get_instance();
			$sideloaded_ids = $acf_validator->get_and_clear_sideloaded_ids();
			foreach ( $sideloaded_ids as $att_id ) {
				wp_update_post( array( 'ID' => $att_id, 'post_parent' => $post_id ) );
			}
		}

		// Taxonomies. UPDATE supports body.append_taxonomies for additive semantics.
		$append = ! empty( $body['append_taxonomies'] );

		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$cat_result = $finalizer_context->get_or_create_terms( $meta['categories'], 'category' );
			wp_set_post_categories( $post_id, $cat_result['ids'], $append );
			$warnings = array_merge( $warnings, $cat_result['errors'] );
		}

		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$tag_result = $finalizer_context->get_or_create_terms( $meta['tags'], 'post_tag' );
			wp_set_post_tags( $post_id, $tag_result['ids'], $append );
			$warnings = array_merge( $warnings, $tag_result['errors'] );
		}

		// Featured image (non-fatal failure → warning).
		if ( ! empty( $meta['featured_image_url'] ) ) {
			$fi_result = $finalizer_context->sideload_and_set_featured_image(
				$post_id,
				$meta['featured_image_url'],
				$meta['featured_image_alt'] ?? ''
			);
			if ( is_wp_error( $fi_result ) ) {
				$warnings[] = sprintf( 'Featured image sideload failed: %s', $fi_result->get_error_message() );
			}
		}

		// SEO meta — meta.title is the SEO meta-title, distinct from post_title (H1).
		if ( ! empty( $meta['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $meta['title'] ) );
		}
		if ( ! empty( $meta['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $meta['description'] ) );
		}

		// ACF fields. UPDATE falls back to existing post_content if rendered is empty
		// so wysiwyg ACF fields can re-derive a value when the request didn't send content.
		$content_for_acf = $rendered_content;
		if ( '' === $content_for_acf && ! $is_create ) {
			$existing        = get_post( $post_id );
			$content_for_acf = $existing ? $existing->post_content : '';
		}

		if ( ! empty( $body['acf_fields'] ) && is_array( $body['acf_fields'] ) ) {
			$acf_result = $finalizer_context->process_acf_fields( $post_id, $body['acf_fields'], $post_type, $content_for_acf );
			if ( is_wp_error( $acf_result ) ) {
				return $acf_result;
			}
		} else {
			// No explicit acf_fields — create safe references so get_fields() returns
			// an array (not false), preventing fatal errors in themes that don't guard.
			$finalizer_context->auto_populate_acf_fields( $post_id, $post_type );
		}

		// FS-4: Auto-apply field schema mappings.
		$finalizer_context->apply_field_schema_mappings( $post_id, $post_type, $body, $meta );

		// Mark ACF as changed and trigger save hook so field references are written.
		if ( function_exists( 'update_field' ) ) {
			update_post_meta( $post_id, '_acf_changed', 1 );
			do_action( 'acf/save_post', $post_id );
		}

		// Source tracking (CREATE only — leave existing source taxonomy on update).
		if ( $is_create && taxonomy_exists( 'arcadia_source' ) ) {
			wp_set_object_terms( $post_id, 'arcadia', 'arcadia_source' );
		}

		// Render test — catches template-level errors after full setup.
		if ( Arcadia_Blocks::is_acf_available() && function_exists( 'render_block' ) ) {
			$acf_validator = Arcadia_ACF_Validator::get_instance();
			$render_result = $acf_validator->render_test( $post_id );
			if ( is_wp_error( $render_result ) ) {
				return $render_result;
			}
		}

		return array( 'warnings' => $warnings );
	}

	/**
	 * Validate body.status against the allowed list.
	 *
	 * @param array       $body    Request body.
	 * @param string|null $default Default when body.status is absent.
	 * @return string|WP_Error Validated status, or WP_Error on invalid input.
	 */
	private function resolve_status( array $body, $default ) {
		$allowed = array( 'publish', 'draft', 'pending', 'private' );
		$status  = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : $default;

		if ( null === $status ) {
			return ''; // No status to set.
		}

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: 1: received status, 2: allowed statuses */
					__( "Invalid post status '%1\$s'. Allowed: %2\$s.", 'arcadia-agents' ),
					$status,
					implode( ', ', $allowed )
				),
				array( 'status' => 400 )
			);
		}

		return $status;
	}
}
