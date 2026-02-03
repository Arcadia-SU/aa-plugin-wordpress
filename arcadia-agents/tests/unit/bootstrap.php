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

// Load Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Load the classes we want to test (only those that don't have heavy WP dependencies).
// Note: We only load parse_markdown from Arcadia_Blocks since it's a static method.
