<?php
/**
 * PHPUnit bootstrap file for Arcadia Agents tests.
 *
 * This bootstrap sets up minimal WordPress stubs for unit testing
 * without requiring a full WordPress installation.
 *
 * @package ArcadiaAgents\Tests
 */

// Define constants that WordPress would normally define.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'ARCADIA_AGENTS_VERSION' ) ) {
    define( 'ARCADIA_AGENTS_VERSION', '0.1.0' );
}

// Mock WordPress functions that are used by the classes we're testing.
// These are minimal stubs - they don't need to be fully functional.

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'http://localhost' . $path;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    // Simple in-memory option storage for tests.
    global $_test_options;
    $_test_options = array();

    function get_option( $option, $default = false ) {
        global $_test_options;
        return isset( $_test_options[ $option ] ) ? $_test_options[ $option ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value ) {
        global $_test_options;
        $_test_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        global $_test_options;
        unset( $_test_options[ $option ] );
        return true;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return strip_tags( trim( $str ) );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type = 'mysql' ) {
        return date( 'Y-m-d H:i:s' );
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Minimal WP_REST_Request stub for unit testing.
     */
    class WP_REST_Request {
        private $headers = array();
        private $params  = array();

        public function set_header( $key, $value ) {
            $this->headers[ strtolower( $key ) ] = $value;
        }

        public function get_header( $key ) {
            $key = strtolower( $key );
            return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
        }

        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        public function get_param( $key ) {
            return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
        }
    }
}

// ACF stubs for testing.
if ( ! function_exists( 'update_field' ) ) {
    global $_test_acf_update_field_calls;
    $_test_acf_update_field_calls = array();

    function update_field( $field_name, $value, $post_id = 0 ) {
        global $_test_acf_update_field_calls;
        $_test_acf_update_field_calls[] = array(
            'field_name' => $field_name,
            'value'      => $value,
            'post_id'    => $post_id,
        );
        return true;
    }
}

if ( ! function_exists( 'acf_get_field_groups' ) ) {
    global $_test_acf_field_groups;
    $_test_acf_field_groups = array();

    function acf_get_field_groups( $args = array() ) {
        global $_test_acf_field_groups;
        return $_test_acf_field_groups;
    }
}

if ( ! function_exists( 'acf_get_fields' ) ) {
    global $_test_acf_fields_by_group;
    $_test_acf_fields_by_group = array();

    function acf_get_fields( $group_key ) {
        global $_test_acf_fields_by_group;
        return isset( $_test_acf_fields_by_group[ $group_key ] )
            ? $_test_acf_fields_by_group[ $group_key ]
            : array();
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return strip_tags( trim( $str ) );
    }
}

// WP_REST_Response stub.
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        public $headers = array();

        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data    = $data;
            $this->status  = $status;
            $this->headers = $headers;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }
    }
}

// WP_Query stub with configurable results.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        /**
         * Queried posts. Set via static set_next_result().
         */
        public $posts = array();

        /**
         * Total found posts.
         */
        public $found_posts = 0;

        /**
         * Max pages.
         */
        public $max_num_pages = 1;

        /**
         * Next result to return (set by tests).
         */
        private static $next_result = null;

        /**
         * Set the result the next WP_Query instance should return.
         *
         * @param array $posts Array of post objects.
         */
        public static function set_next_result( $posts ) {
            self::$next_result = $posts;
        }

        /**
         * Reset the next result.
         */
        public static function reset() {
            self::$next_result = null;
        }

        public function __construct( $args = array() ) {
            if ( null !== self::$next_result ) {
                $this->posts       = self::$next_result;
                $this->found_posts = count( self::$next_result );
                self::$next_result = null;
            }
        }
    }
}

// parse_blocks() stub.
if ( ! function_exists( 'parse_blocks' ) ) {
    global $_test_parse_blocks_results;
    $_test_parse_blocks_results = array();

    function parse_blocks( $content ) {
        global $_test_parse_blocks_results;
        if ( isset( $_test_parse_blocks_results[ $content ] ) ) {
            return $_test_parse_blocks_results[ $content ];
        }
        return array();
    }
}

// get_post() stub.
if ( ! function_exists( 'get_post' ) ) {
    global $_test_posts;
    $_test_posts = array();

    function get_post( $post_id = null ) {
        global $_test_posts;
        if ( isset( $_test_posts[ $post_id ] ) ) {
            return $_test_posts[ $post_id ];
        }
        return null;
    }
}

// get_post_types() stub.
if ( ! function_exists( 'get_post_types' ) ) {
    function get_post_types( $args = array(), $output = 'names' ) {
        // Return a minimal set of public post types.
        if ( ! empty( $args['public'] ) ) {
            return array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' );
        }
        return array( 'post' => 'post', 'page' => 'page' );
    }
}

// Transient stubs.
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $_test_options;
        $key = '_transient_' . $transient;
        return isset( $_test_options[ $key ] ) ? $_test_options[ $key ] : false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $_test_options;
        $_test_options[ '_transient_' . $transient ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        global $_test_options;
        unset( $_test_options[ '_transient_' . $transient ] );
        return true;
    }
}

// DAY_IN_SECONDS constant.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

// do_action() stub (no-op).
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        // No-op for unit tests.
    }
}

// update_post_meta() stub.
if ( ! function_exists( 'update_post_meta' ) ) {
    global $_test_post_meta;
    $_test_post_meta = array();

    function update_post_meta( $post_id, $meta_key, $meta_value ) {
        global $_test_post_meta;
        $_test_post_meta[ $post_id ][ $meta_key ] = $meta_value;
        return true;
    }
}

// Load Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Load the classes we want to test (only those that don't have heavy WP dependencies).
// Note: We only load parse_markdown from Arcadia_Blocks since it's a static method.
