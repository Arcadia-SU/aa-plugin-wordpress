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
		// Require media functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Download the file.
		$tmp = download_url( $url );

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
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_and_set_featured_image( $post_id, $url ) {
		$attachment_id = $this->sideload_image( $url );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}
}
