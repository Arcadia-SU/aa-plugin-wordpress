<?php
/**
 * Deprecation signalling for superseded REST paths.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_API_Deprecations
 *
 * Stamps `Deprecation`, `Sunset` and `Link; rel="successor-version"` on
 * responses served from a superseded path.
 *
 * Hooked on `rest_post_dispatch` rather than wrapping the route callbacks,
 * for three reasons:
 *
 *   1. A wrapper would break parity. The whole point of build_endpoints() is
 *      that `/contents/x` and `/articles/x` hold the *same* Closure instance;
 *      wrapping one of them destroys the identity the parity test asserts.
 *   2. A wrapper never runs on 401/403 — `permission_callback` short-circuits
 *      first — and a client rejected for a missing scope is precisely the one
 *      that most needs to hear the path is going away.
 *   3. `rest_post_dispatch` also covers 404s, fires once per HTTP request, and
 *      spares the handler from ever knowing which path reached it.
 *
 * Nothing is added to the response body. Byte-for-byte identical payloads
 * between twins are what makes the parity assertion worth anything.
 */
final class Arcadia_API_Deprecations {

	/**
	 * Canonical replacement prefix for the content surface.
	 */
	const CONTENT_SUCCESSOR = '/contents';

	/**
	 * Date after which the deprecated paths may be removed.
	 *
	 * Six months — at least one full release cycle, so no client is forced to
	 * upgrade on our schedule. Removal touches four places, all of them in the
	 * files this deprecation was introduced with.
	 */
	const SUNSET = '2027-02-01 00:00:00 GMT';

	/**
	 * Deprecated path rules, keyed by path prefix.
	 *
	 * Keyed by *prefix*, not by an enumerated list of paths: a tenth
	 * `/contents` route then cannot be added without its `/articles` twin
	 * being deprecated too, because the rule already covers it.
	 *
	 * `methods` empty means every method. `/pages` is listed for PUT only —
	 * `GET /pages` is fully supported and feeds AA's internal-linking map.
	 *
	 * @return array<int, array{prefix:string, methods:string[], successor:string}>
	 */
	private static function rules() {
		return array(
			array(
				'prefix'    => '/articles',
				'methods'   => array(),
				'successor' => self::CONTENT_SUCCESSOR,
			),
			array(
				'prefix'    => '/pages',
				'methods'   => array( 'PUT' ),
				'successor' => self::CONTENT_SUCCESSOR,
			),
		);
	}

	/**
	 * Register the filter.
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_headers' ), 10, 3 );
	}

	/**
	 * Stamp deprecation headers on a response served from a superseded path.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response
	 */
	public static function add_headers( $response, $server, $request ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		$route = self::route_of( $request );
		if ( '' === $route ) {
			return $response;
		}

		$rule = self::matching_rule( $route, self::method_of( $request ) );
		if ( null === $rule ) {
			return $response;
		}

		// RFC 9745: the value is the deprecation date as an IMF-fixdate. The
		// bare `true` of the earlier draft was dropped from the final RFC.
		$response->header( 'Deprecation', self::imf_fixdate( self::SUNSET ) );

		// RFC 8594.
		$response->header( 'Sunset', self::imf_fixdate( self::SUNSET ) );

		// RFC 5829. Appended, not replaced — a route may already carry a Link.
		$response->header(
			'Link',
			sprintf( '<%s>; rel="successor-version"', self::successor_url( $route, $rule ) ),
			false
		);

		// No Warning: 299 — obsoleted by RFC 9111.

		return $response;
	}

	/**
	 * Find the rule covering a route, if any.
	 *
	 * @param string $route  Namespaced route, e.g. `/arcadia/v1/articles/12`.
	 * @param string $method HTTP method.
	 * @return array|null
	 */
	private static function matching_rule( $route, $method ) {
		$path = self::strip_namespace( $route );

		if ( null === $path ) {
			return null;
		}

		foreach ( self::rules() as $rule ) {
			if ( ! self::path_has_prefix( $path, $rule['prefix'] ) ) {
				continue;
			}

			if ( ! empty( $rule['methods'] ) && ! in_array( $method, $rule['methods'], true ) ) {
				continue;
			}

			return $rule;
		}

		return null;
	}

	/**
	 * Whether a path sits under a prefix, on a segment boundary.
	 *
	 * The boundary is the whole point: a bare strpos() would deprecate
	 * `/articles-archive`, which is a different resource.
	 *
	 * @param string $path   Path without namespace, e.g. `/articles/12`.
	 * @param string $prefix Prefix, e.g. `/articles`.
	 * @return bool
	 */
	private static function path_has_prefix( $path, $prefix ) {
		if ( $path === $prefix ) {
			return true;
		}

		return 0 === strpos( $path, $prefix . '/' );
	}

	/**
	 * Strip the plugin namespace from a route.
	 *
	 * Returns null for a route in someone else's namespace — we never stamp
	 * headers on `/wp/v2/*` or another plugin's surface.
	 *
	 * @param string $route Namespaced route.
	 * @return string|null Path without namespace, or null if foreign.
	 */
	private static function strip_namespace( $route ) {
		$prefix = '/arcadia/v1';

		if ( $route === $prefix ) {
			return '';
		}

		if ( 0 !== strpos( $route, $prefix . '/' ) ) {
			return null;
		}

		return substr( $route, strlen( $prefix ) );
	}

	/**
	 * Build the successor URL for a deprecated route.
	 *
	 * @param string $route Namespaced route.
	 * @param array  $rule  The matching rule.
	 * @return string
	 */
	private static function successor_url( $route, $rule ) {
		$path = self::strip_namespace( $route );

		// `/pages/{id}` has no positional twin under `/contents`; the id moves
		// across unchanged, so swapping the prefix is the right mapping for
		// both rules.
		$successor = $rule['successor'] . substr( $path, strlen( $rule['prefix'] ) );

		return rest_url( 'arcadia/v1' . $successor );
	}

	/**
	 * Read the route from a request, tolerating a request object without one.
	 *
	 * @param mixed $request Request object.
	 * @return string
	 */
	private static function route_of( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return '';
		}

		$route = $request->get_route();

		return is_string( $route ) ? $route : '';
	}

	/**
	 * Read the method from a request.
	 *
	 * @param mixed $request Request object.
	 * @return string
	 */
	private static function method_of( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
			return '';
		}

		return strtoupper( (string) $request->get_method() );
	}

	/**
	 * Format a date as an IMF-fixdate in GMT, as both RFCs require.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private static function imf_fixdate( $date ) {
		return gmdate( 'D, d M Y H:i:s \G\M\T', strtotime( $date ) );
	}
}
