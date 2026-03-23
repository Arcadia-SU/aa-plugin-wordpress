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
	 * Debug mode: add `&aa_debug=1` to the preview URL when WP_DEBUG is
	 * enabled to get a JSON diagnostic report instead of the rendered page.
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

		// Set up rendering state (headers, post data, wp_query).
		$this->setup_preview_state( $post );

		// Resolve template via WordPress hierarchy.
		$templates = $this->get_preview_template_hierarchy( $post );
		$template  = locate_template( $templates );

		if ( ! $template ) {
			$template = get_index_template();
		}

		// Debug mode: return JSON diagnostic instead of rendering.
		if ( $this->is_debug_request() ) {
			$this->send_debug_report( $post, $templates, $template );
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
	 * Set up the global state for preview rendering.
	 *
	 * Separated from handle_preview() so unit tests can verify the state
	 * setup without triggering template inclusion and exit.
	 *
	 * @param object $post The post object (modified in place: status → publish).
	 */
	public function setup_preview_state( $post ) {
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
			$GLOBALS['wp_query']->post              = $post;
			$GLOBALS['wp_query']->posts             = array( $post );
			$GLOBALS['wp_query']->post_count        = 1;
			$GLOBALS['wp_query']->found_posts       = 1;
			$GLOBALS['wp_query']->max_num_pages     = 1;
			$GLOBALS['wp_query']->current_post      = -1;
			$GLOBALS['wp_query']->queried_object    = $post;
			$GLOBALS['wp_query']->queried_object_id = $post->ID;
			$GLOBALS['wp_query']->is_single         = true;
			$GLOBALS['wp_query']->is_singular       = true;
			$GLOBALS['wp_query']->is_404            = false;
		}
	}

	/**
	 * Check if this is a debug request.
	 *
	 * Debug mode is only available when WP_DEBUG is enabled.
	 *
	 * @return bool
	 */
	private function is_debug_request() {
		return ! empty( $_GET['aa_debug'] )
			&& defined( 'WP_DEBUG' )
			&& WP_DEBUG;
	}

	/**
	 * Send a JSON diagnostic report and exit.
	 *
	 * Captures what the template would render (via ob_start) to report
	 * the output size without actually sending it to the browser.
	 *
	 * @param object $post      The post object.
	 * @param array  $templates Template candidates that were tried.
	 * @param string $template  Resolved template path (empty if none found).
	 */
	private function send_debug_report( $post, $templates, $template ) {
		// Try rendering the template to measure output.
		$output_length = 0;
		$output_sample = '';
		$render_error  = null;

		if ( $template ) {
			ob_start();
			try {
				include $template;
			} catch ( \Throwable $e ) {
				$render_error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
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
			'theme'            => array(
				'stylesheet'       => get_stylesheet(),
				'template'         => get_template(),
				'stylesheet_dir'   => get_stylesheet_directory(),
				'is_child_theme'   => get_stylesheet() !== get_template(),
			),
			'template_resolution' => array(
				'candidates'       => $templates,
				'resolved'         => $template ? $template : null,
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
	 * Build the template hierarchy for a preview post.
	 *
	 * Constructs the hierarchy from the post object directly, avoiding
	 * get_queried_object() which may return null when WordPress is in 404 state.
	 *
	 * @param object $post The post object.
	 * @return array Ordered list of template filenames to try.
	 */
	private function get_preview_template_hierarchy( $post ) {
		$templates = array();
		$type      = $post->post_type;

		if ( ! empty( $post->post_name ) ) {
			$templates[] = "single-{$type}-{$post->post_name}.php";
		}
		$templates[] = "single-{$type}.php";
		$templates[] = 'single.php';
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
