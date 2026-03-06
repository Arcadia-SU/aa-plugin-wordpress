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
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}

		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'attachment_read_failed',
				__( 'Failed to read attachment after upload.', 'arcadia-agents' ),
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
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
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

		$result = set_post_thumbnail( $post_id, $attachment_id );

		if ( ! $result ) {
			return new WP_Error(
				'set_thumbnail_failed',
				__( 'Failed to set featured image.', 'arcadia-agents' ),
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
		// Validate URL scheme — only HTTP(S) allowed (finding #13).
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'invalid_url_scheme',
				__( 'Only HTTP and HTTPS URLs are allowed.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Block private/reserved IPs — SSRF protection (finding #13).
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'URL is not allowed (private/reserved IP).', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
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
	private function sideload_and_set_featured_image( $post_id, $url, $alt = '' ) {
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
				__( 'Media not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$body      = $request->get_json_params();
		$post_data = array( 'ID' => $attachment_id );
		$updated   = false;

		if ( isset( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
			$updated = true;
		}

		if ( isset( $body['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $body['caption'] );
			$updated = true;
		}

		if ( $updated ) {
			wp_update_post( $post_data );
		}

		if ( isset( $body['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $body['alt_text'] ) );
			$updated = true;
		}

		if ( ! $updated ) {
			return new WP_Error(
				'nothing_to_update',
				__( 'No fields to update.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$attachment = get_post( $attachment_id );

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
				__( 'Media not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$body  = $request->get_json_params();
		$force = ! empty( $body['force'] );

		$result = wp_delete_attachment( $attachment_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete media.', 'arcadia-agents' ),
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
