<?php
/**
 * Media API handlers.
 *
 * Handles media upload and featured image operations via REST API.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-pending-actions.php';

// Shared SSRF guard (single source of truth for remote-URL validation).
require_once __DIR__ . '/../class-url-guard.php';

/**
 * Trait Arcadia_API_Media_Handler
 *
 * Provides methods for handling media endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Media_Handler {

	/**
	 * Upload media from URL (sideload).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['url'] ) ) {
			return new WP_Error(
				'missing_url',
				__( 'Image URL is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$url     = esc_url_raw( $body['url'] );
		$alt     = isset( $body['alt'] ) ? sanitize_text_field( $body['alt'] ) : '';
		$title   = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
		$caption = isset( $body['caption'] ) ? sanitize_textarea_field( $body['caption'] ) : '';

		// Sideload the image.
		$attachment_id = $this->sideload_image( $url, $title );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Update attachment meta.
		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( $caption ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $attachment_id,
						'post_excerpt' => $caption,
					)
				)
			);
		}

		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'attachment_read_failed',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Failed to read attachment %d after upload.', 'arcadia-agents' ),
					$attachment_id
				),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'title'         => $attachment->post_title,
				'alt'           => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			),
			201
		);
	}

	/**
	 * Set featured image for a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_featured_image( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Post with ID %d not found.', 'arcadia-agents' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		// A deletion of this article waits for approval: no other write
		// goes through until it is decided (write table §2.1).
		$blocked = Arcadia_Pending_Actions::get_instance()->pending_delete_refusal( $post_id );
		if ( $blocked ) {
			return $blocked;
		}

		$body = $request->get_json_params();

		// Accept either attachment_id or url.
		if ( ! empty( $body['attachment_id'] ) ) {
			$attachment_id = (int) $body['attachment_id'];

			// Verify attachment exists.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Invalid attachment ID or not an image.', 'arcadia-agents' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( ! empty( $body['url'] ) ) {
			$attachment_id = $this->sideload_image( esc_url_raw( $body['url'] ) );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		} else {
			return new WP_Error(
				'missing_image',
				__( 'Either attachment_id or url is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Write table §2.1: on a live article under Force Draft the change waits
		// for approval. A URL was already sideloaded above — an unused file in
		// the library is not visible, so the upload itself is not held.
		if ( Arcadia_Write_Table::HOLD === Arcadia_Write_Table::featured_image( $post->post_status, Arcadia_Write_Table::force_draft_on() ) ) {
			return Arcadia_Pending_Actions::get_instance()->hold(
				'content.featured_image',
				array(
					'kind' => 'content',
					'type' => $post->post_type,
					'id'   => $post_id,
				),
				array( 'attachment_id' => (int) $attachment_id )
			);
		}

		return $this->apply_featured_image( $post_id, (int) $attachment_id );
	}

	/**
	 * Set the featured image — the direct write, also run by an approval.
	 *
	 * @param int $post_id       Article ID.
	 * @param int $attachment_id Attachment ID.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_featured_image( $post_id, $attachment_id ) {
		$result = set_post_thumbnail( $post_id, $attachment_id );

		if ( ! $result ) {
			return new WP_Error(
				'set_thumbnail_failed',
				sprintf(
					/* translators: 1: post ID, 2: attachment ID */
					__( 'Failed to set featured image on post %1$d (attachment %2$d).', 'arcadia-agents' ),
					$post_id,
					$attachment_id
				),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
			),
			200
		);
	}

	/**
	 * Sideload an image from URL.
	 *
	 * @param string $url   The image URL.
	 * @param string $title Optional title for the attachment.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_image( $url, $title = '' ) {
		// Validate scheme + block private/reserved hosts — SSRF protection.
		$valid = Arcadia_Url_Guard::validate_remote_url( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Require media functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Download the file with 30s timeout (finding #13).
		$tmp = download_url( $url, 30 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download image: %s', 'arcadia-agents' ),
					$tmp->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		// Get filename from URL.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $url_path );

		// Prepare file array.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Sideload the file.
		$attachment_id = media_handle_sideload( $file_array, 0, $title );

		// Clean up temp file if sideload failed.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new WP_Error(
				'sideload_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to import image: %s', 'arcadia-agents' ),
					$attachment_id->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		return $attachment_id;
	}

	/**
	 * Sideload image and set as featured image.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $url     The image URL.
	 * @param string $alt     Optional alt text for the image.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function sideload_and_set_featured_image( $post_id, $url, $alt = '' ) {
		$attachment_id = $this->sideload_image( $url );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return $attachment_id;
	}

	/**
	 * Get media library items.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_media( $request ) {
		$per_page  = (int) $request->get_param( 'per_page' ) ?: 20;
		$page      = (int) $request->get_param( 'page' ) ?: 1;
		$search    = $request->get_param( 'search' );
		$mime_type = $request->get_param( 'mime_type' );
		$type      = $request->get_param( 'type' );

		// Validate per_page bounds.
		$per_page = max( 1, min( 100, $per_page ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Search in title and alt text.
		if ( $search ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Filter by MIME type (exact).
		if ( $mime_type ) {
			$args['post_mime_type'] = sanitize_mime_type( $mime_type );
		}

		// Filter by high-level type (image → image/*, video → video/*).
		if ( $type && ! $mime_type ) {
			$type_whitelist = array( 'image', 'video', 'audio', 'application' );
			$type           = sanitize_text_field( $type );
			if ( in_array( $type, $type_whitelist, true ) ) {
				$args['post_mime_type'] = $type;
			}
		}

		// Date range filters.
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		if ( $date_from || $date_to ) {
			$date_query = array();
			if ( $date_from ) {
				$date_query['after'] = sanitize_text_field( $date_from );
			}
			if ( $date_to ) {
				$date_query['before'] = sanitize_text_field( $date_to );
			}
			$date_query['inclusive'] = true;
			$args['date_query']     = array( $date_query );
		}

		$query = new WP_Query( $args );

		$media = array();
		foreach ( $query->posts as $attachment ) {
			$media[] = $this->format_media( $attachment );
		}

		return new WP_REST_Response(
			array(
				'media'       => $media,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Update media metadata (alt text, title, caption).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_media( $request ) {
		$attachment_id = (int) $request->get_param( 'id' );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'media_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Media with ID %d not found.', 'arcadia-agents' ),
					$attachment_id
				),
				array( 'status' => 404 )
			);
		}

		$body   = $request->get_json_params();
		$fields = array();

		if ( isset( $body['title'] ) ) {
			$fields['title'] = sanitize_text_field( $body['title'] );
		}
		if ( isset( $body['caption'] ) ) {
			$fields['caption'] = sanitize_textarea_field( $body['caption'] );
		}
		if ( isset( $body['alt_text'] ) ) {
			$fields['alt_text'] = sanitize_text_field( $body['alt_text'] );
		}

		if ( empty( $fields ) ) {
			return new WP_Error(
				'nothing_to_update',
				__( 'No updateable fields provided. Allowed: title, caption, alt_text.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		if ( Arcadia_Write_Table::HOLD === Arcadia_Write_Table::simple( 'media.update', Arcadia_Write_Table::force_draft_on() ) ) {
			return Arcadia_Pending_Actions::get_instance()->hold(
				'media.update',
				array(
					'kind' => 'media',
					'type' => 'attachment',
					'id'   => $attachment_id,
				),
				array( 'fields' => $fields )
			);
		}

		return $this->apply_media_update( $attachment_id, $fields );
	}

	/**
	 * Edit media fields — the direct write, also run by an approval.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $fields        title, caption, alt_text (already sanitized).
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_media_update( $attachment_id, array $fields ) {
		$post_data = array( 'ID' => $attachment_id );
		if ( isset( $fields['title'] ) ) {
			$post_data['post_title'] = $fields['title'];
		}
		if ( isset( $fields['caption'] ) ) {
			$post_data['post_excerpt'] = $fields['caption'];
		}
		if ( count( $post_data ) > 1 ) {
			wp_update_post( wp_slash( $post_data ) );
		}

		if ( isset( $fields['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $fields['alt_text'] );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return new WP_Error( 'media_not_found', __( 'Media not found.', 'arcadia-agents' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'media'   => $this->format_media( $attachment ),
			),
			200
		);
	}

	/**
	 * Delete a media attachment.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_media( $request ) {
		$attachment_id = (int) $request->get_param( 'id' );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'media_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Media with ID %d not found.', 'arcadia-agents' ),
					$attachment_id
				),
				array( 'status' => 404 )
			);
		}

		$body  = $request->get_json_params();
		$force = ! empty( $body['force'] );

		if ( Arcadia_Write_Table::HOLD === Arcadia_Write_Table::simple( 'media.delete', Arcadia_Write_Table::force_draft_on() ) ) {
			return Arcadia_Pending_Actions::get_instance()->hold(
				'media.delete',
				array(
					'kind' => 'media',
					'type' => 'attachment',
					'id'   => $attachment_id,
				),
				array( 'force' => $force )
			);
		}

		return $this->apply_media_delete( $attachment_id, $force );
	}

	/**
	 * Delete media — the direct write, also run by an approval.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Skip the trash.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_media_delete( $attachment_id, $force ) {
		$result = wp_delete_attachment( $attachment_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Failed to delete media %d.', 'arcadia-agents' ),
					$attachment_id
				),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'deleted' => $attachment_id,
				'force'   => $force,
			),
			200
		);
	}

	/**
	 * Format a media attachment for API response.
	 *
	 * @param WP_Post $attachment The attachment post object.
	 * @return array Formatted media data.
	 */
	private function format_media( $attachment ) {
		$metadata = wp_get_attachment_metadata( $attachment->ID );

		return array(
			'id'        => $attachment->ID,
			'title'     => $attachment->post_title,
			'url'       => wp_get_attachment_url( $attachment->ID ),
			'alt'       => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'   => $attachment->post_excerpt,
			'mime_type' => $attachment->post_mime_type,
			'width'     => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'    => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'date'      => $attachment->post_date,
		);
	}
}
