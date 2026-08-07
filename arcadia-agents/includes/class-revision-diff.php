<?php
/**
 * Projects what a pending revision proposes, field by field.
 *
 * A pending revision stores its payload as JSON in _aa_revision_meta and writes
 * nothing to the live post until approval. That is the right design — but it
 * left the proposal unreadable: the reviewer saw a bare "PENDING" row, the
 * preview rendered an empty page, and the REST detail endpoint returned nothing
 * but metadata. Approving was a blind click.
 *
 * This class turns the stored payload into a before/after list. It is the single
 * builder behind all three surfaces (REST detail, classic-editor banner,
 * Gutenberg sidebar) — the same "one pipeline, several callers" shape that
 * Phase 42.1 imposed on the write path, for the same reason: a second copy is
 * free to drift, and a diff that disagrees with the writer is worse than none.
 *
 * HARD INVARIANT: building a diff has NO side effects. Every consumer is a GET
 * or a screen render. In particular it never calls process_acf_fields(), which
 * sideloads images — a read path that creates attachments would be a worse
 * defect than the one this class closes. Coercions are *named*, never applied
 * (see Arcadia_API::describe_field_transform()).
 *
 * @package ArcadiaAgents
 * @since   0.4.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Revision_Diff
 */
class Arcadia_Revision_Diff {

	/**
	 * Holder of the ACF schema and field-schema readers.
	 *
	 * Injected rather than reached for, so this class can be exercised against a
	 * lightweight trait composite instead of booting the whole API singleton.
	 *
	 * @var object|null
	 */
	private $field_context;

	/**
	 * Constructor.
	 *
	 * @param object|null $field_context Anything exposing build_acf_field_type_map()
	 *                                   and resolve_field_schema_mappings(). Defaults
	 *                                   to the API singleton.
	 */
	public function __construct( $field_context = null ) {
		$this->field_context = $field_context;
	}

	/**
	 * Resolve the field-schema/ACF reader.
	 *
	 * Null only on a half-loaded plugin: class-api.php is required in the same
	 * always-loaded block as this file. ACF-derived entries are dropped in that
	 * case rather than fatalling on an editor screen or a GET.
	 *
	 * @return object|null
	 */
	private function field_context() {
		if ( null === $this->field_context && class_exists( 'Arcadia_API' ) ) {
			$this->field_context = Arcadia_API::get_instance();
		}

		return $this->field_context;
	}

	/**
	 * Build the before/after projection for a revision.
	 *
	 * @param WP_Post $revision The aa_revision post.
	 * @return array{changes: array, content_changed: bool, skip_markdown: bool}
	 */
	public function build( $revision ) {
		$empty = array(
			'changes'         => array(),
			'content_changed' => false,
			'skip_markdown'   => false,
		);

		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return $empty;
		}

		$parent = get_post( (int) $revision->post_parent );
		if ( ! $parent ) {
			return $empty;
		}

		$payload = json_decode( (string) get_post_meta( $revision->ID, '_aa_revision_meta', true ), true );
		if ( ! is_array( $payload ) ) {
			// Corrupt or absent payload. approve_revision() raises this as a 500;
			// here we degrade to "nothing to show" rather than break the editor
			// screen the reviewer is standing on.
			return $empty;
		}

		$body          = isset( $payload['body'] ) && is_array( $payload['body'] ) ? $payload['body'] : array();
		$meta          = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
		$skip_markdown = ! empty( $payload['skip_markdown'] );

		$changes = array_merge(
			$this->post_changes( $parent, $body, $meta ),
			$this->seo_changes( $parent, $meta ),
			$this->taxonomy_changes( $parent, $meta ),
			$this->media_changes( $parent, $meta ),
			$this->acf_changes( $parent, $body, $skip_markdown ),
			$this->field_schema_changes( $parent, $body, $meta )
		);

		return array(
			'changes'         => $changes,
			// The revision CPT's post_content IS the proposed content, already
			// rendered to block markup at PUT time (trait-api-posts.php). Empty
			// means the PUT proposed no content change.
			'content_changed' => '' !== (string) $revision->post_content,
			'skip_markdown'   => $skip_markdown,
		);
	}

	/**
	 * Changes to columns of the post row itself.
	 *
	 * Deliberately absent: body.status. approve_revision() builds its $post_data
	 * without it (class-revisions.php), so a proposed status is never applied.
	 * Listing it as "proposed" would state something untrue — the gap is real and
	 * tracked separately, but this class must not paper over it.
	 *
	 * @param WP_Post $parent Live post.
	 * @param array   $body   Proposed body.
	 * @param array   $meta   Proposed meta.
	 * @return array
	 */
	private function post_changes( $parent, $body, $meta ) {
		$changes = array();

		if ( isset( $body['title'] ) ) {
			$changes[] = $this->entry( 'title', 'post', $parent->post_title, $body['title'] );
		}
		if ( isset( $body['excerpt'] ) ) {
			$changes[] = $this->entry( 'excerpt', 'post', $parent->post_excerpt, $body['excerpt'] );
		}
		if ( isset( $meta['slug'] ) ) {
			$changes[] = $this->entry( 'meta.slug', 'post', $parent->post_name, $meta['slug'] );
		}

		return $changes;
	}

	/**
	 * Changes to the search-snippet fields.
	 *
	 * Read through Arcadia_SEO_Meta so the "before" comes from whichever SEO
	 * plugin the site actually runs, not from a hardcoded Yoast meta key.
	 *
	 * @param WP_Post $parent Live post.
	 * @param array   $meta   Proposed meta.
	 * @return array
	 */
	private function seo_changes( $parent, $meta ) {
		if ( ! isset( $meta['title'] ) && ! isset( $meta['description'] ) ) {
			return array();
		}

		$seo     = Arcadia_SEO_Meta::get_seo_meta( $parent->ID );
		$changes = array();

		if ( isset( $meta['title'] ) ) {
			$changes[] = $this->entry( 'meta.title', 'seo', $seo['meta_title'] ?? '', $meta['title'] );
		}
		if ( isset( $meta['description'] ) ) {
			$changes[] = $this->entry( 'meta.description', 'seo', $seo['meta_description'] ?? '', $meta['description'] );
		}

		return $changes;
	}

	/**
	 * Category and tag changes, as term names on both sides.
	 *
	 * @param WP_Post $parent Live post.
	 * @param array   $meta   Proposed meta.
	 * @return array
	 */
	private function taxonomy_changes( $parent, $meta ) {
		$changes = array();

		if ( isset( $meta['categories'] ) ) {
			$current   = wp_get_post_categories( $parent->ID, array( 'fields' => 'names' ) );
			$changes[] = $this->entry(
				'meta.categories',
				'taxonomy',
				is_array( $current ) ? $current : array(),
				$meta['categories']
			);
		}
		if ( isset( $meta['tags'] ) ) {
			$current   = wp_get_post_tags( $parent->ID, array( 'fields' => 'names' ) );
			$changes[] = $this->entry(
				'meta.tags',
				'taxonomy',
				is_array( $current ) ? $current : array(),
				$meta['tags']
			);
		}

		return $changes;
	}

	/**
	 * Featured image changes, compared as URLs.
	 *
	 * @param WP_Post $parent Live post.
	 * @param array   $meta   Proposed meta.
	 * @return array
	 */
	private function media_changes( $parent, $meta ) {
		$changes = array();

		if ( isset( $meta['featured_image_url'] ) ) {
			$thumb_id  = get_post_thumbnail_id( $parent->ID );
			$current   = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
			$changes[] = $this->entry(
				'meta.featured_image_url',
				'media',
				$current ? $current : '',
				$meta['featured_image_url'],
				'sideload_image'
			);
		}
		if ( isset( $meta['featured_image_alt'] ) ) {
			$thumb_id  = get_post_thumbnail_id( $parent->ID );
			$current   = $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';
			$changes[] = $this->entry( 'meta.featured_image_alt', 'media', $current, $meta['featured_image_alt'] );
		}

		return $changes;
	}

	/**
	 * ACF field changes named explicitly in the payload.
	 *
	 * The proposed value is reported RAW, exactly as the agent sent it, plus the
	 * name of the coercion it will undergo. Showing a coerced value would mean
	 * running the coercion — which for an image means importing a media file.
	 * Naming beats doing on a read path.
	 *
	 * @param WP_Post $parent        Live post.
	 * @param array   $body          Proposed body.
	 * @param bool    $skip_markdown Round-trip flag stored with the revision.
	 * @return array
	 */
	private function acf_changes( $parent, $body, $skip_markdown ) {
		if ( empty( $body['acf_fields'] ) || ! is_array( $body['acf_fields'] ) ) {
			return array();
		}

		$api = $this->field_context();
		if ( ! $api ) {
			return array();
		}

		$type_map = $api->build_acf_field_type_map( $parent->post_type );
		$current  = $this->current_fields( $parent->ID );

		$changes = array();

		foreach ( $body['acf_fields'] as $field_name => $proposed ) {
			$field_type = $type_map[ $field_name ] ?? 'text';

			$changes[] = $this->entry(
				'acf_fields.' . $field_name,
				'acf',
				$current[ $field_name ] ?? null,
				$proposed,
				$api->describe_field_transform( $proposed, $field_type, $skip_markdown ),
				'payload'
			);
		}

		return $changes;
	}

	/**
	 * ACF fields the payload never names, but that approval will write anyway.
	 *
	 * Field-schema calibration derives values from excerpt / h1 / meta_title /
	 * meta_description / featured_image_url. Those writes are real and, until
	 * now, completely invisible to the person approving — the payload doesn't
	 * mention the field, so nothing on screen hinted the field would change.
	 *
	 * @param WP_Post $parent Live post.
	 * @param array   $body   Proposed body.
	 * @param array   $meta   Proposed meta.
	 * @return array
	 */
	private function field_schema_changes( $parent, $body, $meta ) {
		$api = $this->field_context();
		if ( ! $api ) {
			return array();
		}

		$resolved = $api->resolve_field_schema_mappings( $parent->post_type, $body, $meta );
		if ( empty( $resolved ) ) {
			return array();
		}

		$type_map = $api->build_acf_field_type_map( $parent->post_type );
		$current  = $this->current_fields( $parent->ID );

		$changes = array();

		foreach ( $resolved as $field_name => $entry ) {
			$field_type = $type_map[ $field_name ] ?? 'text';

			// Mirrors the one type-aware branch of apply_field_schema_mappings():
			// an image field fed a URL is sideloaded. Everything else is verbatim.
			$transform = null;
			if ( 'image' === $field_type && is_string( $entry['value'] ) && filter_var( $entry['value'], FILTER_VALIDATE_URL ) ) {
				$transform = 'sideload_image';
			}

			$changes[] = $this->entry(
				'acf_fields.' . $field_name,
				'acf',
				$current[ $field_name ] ?? null,
				$entry['value'],
				$transform,
				'field_schema',
				$entry['source']
			);
		}

		return $changes;
	}

	/**
	 * How many characters of a value the admin surfaces show before truncating.
	 */
	const DISPLAY_LIMIT = 300;

	/**
	 * Flatten changes into display-ready rows.
	 *
	 * Both admin surfaces render from this one list. The Gutenberg panel gets it
	 * through wp_localize_script and the classic banner renders it in PHP, so the
	 * two agree by construction rather than by two implementations happening to
	 * format things the same way — the parity is the point.
	 *
	 * @param array $changes The 'changes' list from build().
	 * @return array List of array{label, field, current, proposed, note}.
	 */
	public function to_display_rows( $changes ) {
		$rows = array();

		foreach ( $changes as $change ) {
			$rows[] = array(
				'label'    => $change['label'],
				'field'    => $change['field'],
				'current'  => self::stringify( $change['current'] ),
				'proposed' => self::stringify( $change['proposed'] ),
				'note'     => self::note_for( $change ),
			);
		}

		return $rows;
	}

	/**
	 * One-line caveat for a change, or '' when the value speaks for itself.
	 *
	 * @param array $change A change entry.
	 * @return string
	 */
	private static function note_for( $change ) {
		$notes = array();

		switch ( $change['transform'] ) {
			case 'markdown_to_html':
				$notes[] = __( 'Markdown will be converted to HTML on approval.', 'arcadia-agents' );
				break;
			case 'copy_rendered_content':
				$notes[] = __( 'This field will receive the proposed page content.', 'arcadia-agents' );
				break;
			case 'sideload_image':
				$notes[] = __( 'The image will be imported into the media library on approval.', 'arcadia-agents' );
				break;
		}

		if ( 'field_schema' === $change['origin'] ) {
			$notes[] = sprintf(
				/* translators: %s: mapping source name, e.g. meta_title */
				__( 'Calibrated field — the payload does not name it; the value is derived from %s.', 'arcadia-agents' ),
				(string) $change['source']
			);
		}

		return implode( ' ', $notes );
	}

	/**
	 * Render any payload value as a short readable string.
	 *
	 * @param mixed $value Value from either side of the comparison.
	 * @return string
	 */
	private static function stringify( $value ) {
		if ( null === $value ) {
			// Distinguishable from an empty string: one means "no value stored",
			// the other means "stored, and empty".
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $key => $item ) {
				$rendered = is_scalar( $item ) ? (string) $item : wp_json_encode( $item );
				$flat[]   = is_int( $key ) ? $rendered : $key . ': ' . $rendered;
			}
			$value = implode( ', ', $flat );
		}
		if ( ! is_scalar( $value ) ) {
			$value = (string) wp_json_encode( $value );
		}

		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );

		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $value, 0, self::DISPLAY_LIMIT, '…' );
		}

		return strlen( $value ) > self::DISPLAY_LIMIT
			? substr( $value, 0, self::DISPLAY_LIMIT - 1 ) . '…'
			: $value;
	}

	/**
	 * Current ACF values on the live post, as a plain array.
	 *
	 * @param int $post_id The parent post ID.
	 * @return array
	 */
	private function current_fields( $post_id ) {
		$current = function_exists( 'get_fields' ) ? get_fields( $post_id ) : array();

		return is_array( $current ) ? $current : array();
	}

	/**
	 * Shape one change entry.
	 *
	 * @param string      $field     Dotted payload path, e.g. 'acf_fields.chapo_2'.
	 * @param string      $kind      post | seo | taxonomy | media | acf.
	 * @param mixed       $current   Value on the live post today.
	 * @param mixed       $proposed  Value the revision carries, raw.
	 * @param string|null $transform Coercion that will be applied on approval, if any.
	 * @param string      $origin    payload (the agent named it) | field_schema (calibration).
	 * @param string|null $source    Mapping source, for field_schema entries only.
	 * @return array
	 */
	private function entry( $field, $kind, $current, $proposed, $transform = null, $origin = 'payload', $source = null ) {
		$parts = explode( '.', $field );

		return array(
			'field'     => $field,
			'label'     => end( $parts ),
			'kind'      => $kind,
			'current'   => $current,
			'proposed'  => $proposed,
			'transform' => $transform,
			'origin'    => $origin,
			'source'    => $source,
		);
	}
}
