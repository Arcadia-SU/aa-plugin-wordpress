<?php
/**
 * Admin list-screen UI: Arcadia badge (FS-1) and Arcadia view/filter (FS-2).
 *
 * Everything renders on the native edit.php screens for posts and pages:
 * - a post-state badge on contents CREATED by the agent (the arcadia_source
 *   term is set at creation only — agent edits of human content never badge);
 * - an "Arcadia (n)" link in the native views bar, combinable with the status
 *   links (two clicks = the review queue), hidden when the count is zero;
 * - the publishing-guard chip and a hidden input in the filter row.
 *
 * All hooks are gated hard: pre_get_posts fires for every query WordPress
 * runs (widgets included), so the tax_query is only ever added when
 * should_filter() proves this is the main edit.php list query.
 *
 * @package ArcadiaAgents
 * @since   0.6.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Admin_List_UI
 */
class Arcadia_Admin_List_UI {

	/**
	 * Public query parameter carrying the filter (edit.php?aa_source=arcadia).
	 */
	const QUERY_VAR = 'aa_source';

	/**
	 * The taxonomy term marking agent-created content. Hardcoded everywhere —
	 * user input never reaches a tax_query.
	 */
	const TERM = 'arcadia';

	/**
	 * The source-tracking taxonomy (registered in arcadia-agents.php).
	 */
	const TAXONOMY = 'arcadia_source';

	/**
	 * Post types whose list screens get the badge, view and filter.
	 *
	 * @var string[]
	 */
	private static $post_types = array( 'post', 'page' );

	/**
	 * Register hooks. Called from the plugin entry point (admin only).
	 */
	public static function init() {
		add_filter( 'display_post_states', array( __CLASS__, 'filter_post_states' ), 10, 2 );

		foreach ( self::$post_types as $post_type ) {
			add_filter(
				"views_edit-{$post_type}",
				static function ( $views ) use ( $post_type ) {
					return Arcadia_Admin_List_UI::filter_views( $views, $post_type );
				}
			);
		}

		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter_row' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_admin_query' ) );
	}

	/**
	 * FS-1 — "Arcadia" badge next to the title, for agent-created content.
	 *
	 * The display_post_states values may contain HTML; only our fixed classes
	 * and a translated literal go in — zero dynamic data.
	 *
	 * @param array<string, string> $post_states Post states for this row.
	 * @param object|null           $post        The post being rendered.
	 * @return array<string, string>
	 */
	public static function filter_post_states( $post_states, $post ) {
		if ( $post && has_term( self::TERM, self::TAXONOMY, $post ) ) {
			$post_states['arcadia'] = '<span class="aa-badge">' . esc_html__( 'Arcadia', 'arcadia-agents' ) . '</span>';
		}

		return $post_states;
	}

	/**
	 * FS-2 — add the "Arcadia (n)" link to the native views bar.
	 *
	 * When the filter is active, the status links are rewritten to keep it
	 * (two clicks = Arcadia → Drafts); "All" is never rewritten — it stays
	 * the exit door, per its core semantics. The view is hidden at zero,
	 * like core hides "Mine".
	 *
	 * @param array<string, string> $views     The views bar links.
	 * @param string                $post_type The list screen's post type.
	 * @return array<string, string>
	 */
	public static function filter_views( $views, $post_type ) {
		$active = self::is_filter_active();

		if ( $active ) {
			$views = self::add_query_arg_to_views( $views );
		}

		$count = Arcadia_Content_Counter::count( $post_type );

		if ( $count > 0 ) {
			$views = self::insert_after_all(
				$views,
				self::build_view_link( $post_type, $count, $active )
			);
		}

		return $views;
	}

	/**
	 * Build the "Arcadia (n)" view link.
	 *
	 * @param string $post_type Post type of the list screen.
	 * @param int    $count     Number of agent-created contents.
	 * @param bool   $current   Whether the filter is currently active.
	 * @return string HTML anchor.
	 */
	public static function build_view_link( $post_type, $count, $current ) {
		$url = add_query_arg(
			array(
				'post_type'     => $post_type,
				self::QUERY_VAR => self::TERM,
			),
			'edit.php'
		);

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Arcadia', 'arcadia-agents' ),
			number_format_i18n( $count )
		);
	}

	/**
	 * Rewrite the status links so they keep the active Arcadia filter.
	 *
	 * Security contract (review finding, blocking): the rewritten value goes
	 * through esc_url() without exception, and a link whose href does not
	 * match is left fully intact — never partially mutated. "All" and our own
	 * view are skipped.
	 *
	 * @param array<string, string> $views The views bar links.
	 * @return array<string, string>
	 */
	public static function add_query_arg_to_views( $views ) {
		foreach ( $views as $key => $view ) {
			if ( 'all' === $key || 'arcadia' === $key ) {
				continue;
			}

			$views[ $key ] = preg_replace_callback(
				'/href="([^"]*)"/',
				static function ( $matches ) {
					// Attribute values are entity-encoded (&#038;); decode
					// before appending, re-escape the whole URL after.
					$href = html_entity_decode( $matches[1], ENT_QUOTES );
					$href = add_query_arg( array( self::QUERY_VAR => self::TERM ), $href );
					return 'href="' . esc_url( $href ) . '"';
				},
				$view,
				1
			);
		}

		return $views;
	}

	/**
	 * Arcadia-filter persistence, on restrict_manage_posts.
	 *
	 * The hidden input keeps the Arcadia filter across search, date and
	 * category filters — the link rewrite alone only covers the views.
	 * (The guard chip used to render here too; removed in 0.6.2 — a
	 * set-once setting has no business as permanent list chrome. It still
	 * lives on the plugin dashboard and settings page.)
	 *
	 * @param string $post_type The list screen's post type.
	 * @param string $which     'top' or 'bottom' tablenav ('' before WP 4.4).
	 */
	public static function render_filter_row( $post_type, $which = 'top' ) {
		if ( ! in_array( $post_type, self::$post_types, true ) ) {
			return;
		}

		// Both tablenavs live in the same <form>: render once, at the top.
		if ( 'top' !== $which && '' !== $which ) {
			return;
		}

		if ( self::is_filter_active() ) {
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( self::QUERY_VAR ),
				esc_attr( self::TERM )
			);
		}
	}

	/**
	 * Constrain the main edit.php query to agent-created content when the
	 * filter is active.
	 *
	 * The gate is should_filter() — get_current_screen() does not exist yet
	 * at pre_get_posts, so the check runs on $pagenow. The tax_query term is
	 * hardcoded; the $_GET value only ever decides WHETHER to filter.
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public static function filter_admin_query( $query ) {
		global $pagenow;

		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, idempotent list filter (core pattern).
		$get = $_GET;

		if ( ! self::should_filter( is_admin(), isset( $pagenow ) ? $pagenow : '', $query->is_main_query(), $post_type, $get ) ) {
			return;
		}

		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		$tax_query[] = array(
			'taxonomy' => self::TAXONOMY,
			'field'    => 'slug',
			'terms'    => self::TERM,
		);

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Whether a given query is the one the Arcadia filter applies to.
	 *
	 * Pure — every input is a parameter, so the whole decision matrix is unit
	 * testable without get_current_screen().
	 *
	 * @param bool   $is_admin      is_admin().
	 * @param string $pagenow       Current admin page ($pagenow).
	 * @param bool   $is_main_query $query->is_main_query().
	 * @param string $post_type     The query's post type.
	 * @param array  $get           The request's $_GET.
	 * @return bool
	 */
	public static function should_filter( $is_admin, $pagenow, $is_main_query, $post_type, $get ) {
		if ( ! $is_admin || 'edit.php' !== $pagenow || ! $is_main_query ) {
			return false;
		}

		if ( ! in_array( $post_type, self::$post_types, true ) ) {
			return false;
		}

		if ( ! isset( $get[ self::QUERY_VAR ] ) || ! is_string( $get[ self::QUERY_VAR ] ) ) {
			return false;
		}

		return self::TERM === sanitize_key( wp_unslash( $get[ self::QUERY_VAR ] ) );
	}

	/**
	 * Whether the current request carries the (valid) Arcadia filter.
	 *
	 * @return bool
	 */
	public static function is_filter_active() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, idempotent list filter (core pattern).
		$get = $_GET;

		return isset( $get[ self::QUERY_VAR ] )
			&& is_string( $get[ self::QUERY_VAR ] )
			&& self::TERM === sanitize_key( wp_unslash( $get[ self::QUERY_VAR ] ) );
	}

	/**
	 * Insert the Arcadia view right after "All", preserving order.
	 *
	 * @param array<string, string> $views The views bar links.
	 * @param string                $html  The Arcadia view link.
	 * @return array<string, string>
	 */
	private static function insert_after_all( $views, $html ) {
		$out      = array();
		$inserted = false;

		foreach ( $views as $key => $view ) {
			$out[ $key ] = $view;
			if ( 'all' === $key ) {
				$out['arcadia'] = $html;
				$inserted       = true;
			}
		}

		if ( ! $inserted ) {
			$out['arcadia'] = $html;
		}

		return $out;
	}
}
