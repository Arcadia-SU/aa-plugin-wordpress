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
        private $headers     = array();
        private $params      = array();
        private $json_params = array();

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

        public function set_json_params( $params ) {
            $this->json_params = $params;
        }

        public function get_json_params() {
            return $this->json_params;
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

// get_post_meta() stub.
if ( ! function_exists( 'get_post_meta' ) ) {
    global $_test_post_meta;
    if ( ! isset( $_test_post_meta ) ) {
        $_test_post_meta = array();
    }

    function get_post_meta( $post_id, $key = '', $single = false ) {
        global $_test_post_meta;
        if ( '' === $key ) {
            return isset( $_test_post_meta[ $post_id ] ) ? $_test_post_meta[ $post_id ] : array();
        }
        if ( ! isset( $_test_post_meta[ $post_id ][ $key ] ) ) {
            return $single ? '' : array();
        }
        return $single ? $_test_post_meta[ $post_id ][ $key ] : array( $_test_post_meta[ $post_id ][ $key ] );
    }
}

// get_permalink() stub.
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post_id = 0 ) {
        return 'http://localhost/?p=' . $post_id;
    }
}

// get_userdata() stub.
if ( ! function_exists( 'get_userdata' ) ) {
    global $_test_users;
    $_test_users = array();

    function get_userdata( $user_id ) {
        global $_test_users;
        return isset( $_test_users[ $user_id ] ) ? $_test_users[ $user_id ] : false;
    }
}

// wp_get_post_categories() stub.
if ( ! function_exists( 'wp_get_post_categories' ) ) {
    global $_test_post_categories;
    $_test_post_categories = array();

    function wp_get_post_categories( $post_id, $args = array() ) {
        global $_test_post_categories;
        return isset( $_test_post_categories[ $post_id ] ) ? $_test_post_categories[ $post_id ] : array();
    }
}

// wp_get_post_tags() stub.
if ( ! function_exists( 'wp_get_post_tags' ) ) {
    global $_test_post_tags;
    $_test_post_tags = array();

    function wp_get_post_tags( $post_id, $args = array() ) {
        global $_test_post_tags;
        return isset( $_test_post_tags[ $post_id ] ) ? $_test_post_tags[ $post_id ] : array();
    }
}

// get_post_thumbnail_id() stub.
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
    function get_post_thumbnail_id( $post_id ) {
        global $_test_post_meta;
        return isset( $_test_post_meta[ $post_id ]['_thumbnail_id'] ) ? $_test_post_meta[ $post_id ]['_thumbnail_id'] : 0;
    }
}

// wp_get_attachment_url() stub.
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $attachment_id ) {
        return 'http://localhost/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

// wp_strip_all_tags() stub.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return strip_tags( $text );
    }
}

// has_blocks() stub.
if ( ! function_exists( 'has_blocks' ) ) {
    function has_blocks( $content ) {
        return false !== strpos( $content, '<!-- wp:' );
    }
}

// taxonomy_exists() stub.
if ( ! function_exists( 'taxonomy_exists' ) ) {
    global $_test_taxonomies;
    $_test_taxonomies = array();

    function taxonomy_exists( $taxonomy ) {
        global $_test_taxonomies;
        return in_array( $taxonomy, $_test_taxonomies, true );
    }
}

// wp_set_object_terms() stub.
if ( ! function_exists( 'wp_set_object_terms' ) ) {
    global $_test_object_terms;
    $_test_object_terms = array();

    function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
        global $_test_object_terms;
        $_test_object_terms[ $object_id ][ $taxonomy ] = $terms;
        return array( 1 );
    }
}

// get_page_template_slug() stub.
if ( ! function_exists( 'get_page_template_slug' ) ) {
    function get_page_template_slug( $post_id ) {
        return '';
    }
}

// register_taxonomy() stub.
if ( ! function_exists( 'register_taxonomy' ) ) {
    function register_taxonomy( $taxonomy, $object_type, $args = array() ) {
        global $_test_taxonomies;
        $_test_taxonomies[] = $taxonomy;
    }
}

// wp_get_nav_menus() stub.
if ( ! function_exists( 'wp_get_nav_menus' ) ) {
    global $_test_nav_menus;
    $_test_nav_menus = array();

    function wp_get_nav_menus() {
        global $_test_nav_menus;
        return $_test_nav_menus;
    }
}

// wp_get_nav_menu_items() stub.
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
    global $_test_nav_menu_items;
    $_test_nav_menu_items = array();

    function wp_get_nav_menu_items( $menu, $args = array() ) {
        global $_test_nav_menu_items;
        $menu_id = is_object( $menu ) ? $menu->term_id : $menu;
        return isset( $_test_nav_menu_items[ $menu_id ] ) ? $_test_nav_menu_items[ $menu_id ] : array();
    }
}

// get_users() stub.
if ( ! function_exists( 'get_users' ) ) {
    global $_test_wp_users;
    $_test_wp_users = array();

    function get_users( $args = array() ) {
        global $_test_wp_users;
        return $_test_wp_users;
    }
}

// get_user_by() stub.
if ( ! function_exists( 'get_user_by' ) ) {
    global $_test_users_by;
    $_test_users_by = array();

    function get_user_by( $field, $value ) {
        global $_test_users_by;
        $key = $field . ':' . $value;
        return isset( $_test_users_by[ $key ] ) ? $_test_users_by[ $key ] : false;
    }
}

// count_user_posts() stub.
if ( ! function_exists( 'count_user_posts' ) ) {
    function count_user_posts( $user_id, $post_type = 'post', $public_only = false ) {
        return 0;
    }
}

// wp_delete_term() stub.
if ( ! function_exists( 'wp_delete_term' ) ) {
    function wp_delete_term( $term_id, $taxonomy ) {
        return true;
    }
}

// wp_update_term() stub.
if ( ! function_exists( 'wp_update_term' ) ) {
    function wp_update_term( $term_id, $taxonomy, $args = array() ) {
        return array( 'term_id' => $term_id );
    }
}

// get_term() stub.
if ( ! function_exists( 'get_term' ) ) {
    global $_test_terms;
    $_test_terms = array();

    function get_term( $term_id, $taxonomy = '' ) {
        global $_test_terms;
        $key = $taxonomy ? $taxonomy . ':' . $term_id : $term_id;
        if ( isset( $_test_terms[ $key ] ) ) {
            return $_test_terms[ $key ];
        }
        if ( isset( $_test_terms[ $term_id ] ) ) {
            return $_test_terms[ $term_id ];
        }
        return null;
    }
}

// wp_insert_term() stub.
if ( ! function_exists( 'wp_insert_term' ) ) {
    function wp_insert_term( $term, $taxonomy, $args = array() ) {
        return array( 'term_id' => 99 );
    }
}

// get_term_by() stub.
if ( ! function_exists( 'get_term_by' ) ) {
    function get_term_by( $field, $value, $taxonomy = '' ) {
        return false;
    }
}

// wp_delete_post() stub.
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $post_id, $force = false ) {
        return true;
    }
}

// wp_attachment_is_image() stub.
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
    function wp_attachment_is_image( $attachment_id ) {
        return true;
    }
}

// wp_get_attachment_metadata() stub.
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
    function wp_get_attachment_metadata( $attachment_id ) {
        return array( 'width' => 800, 'height' => 600 );
    }
}

// wp_update_attachment_metadata() stub.
if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
    function wp_update_attachment_metadata( $attachment_id, $data ) {
        return true;
    }
}

// delete_post_meta() stub.
if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
        global $_test_post_meta;
        unset( $_test_post_meta[ $post_id ][ $meta_key ] );
        return true;
    }
}

// get_terms() stub.
if ( ! function_exists( 'get_terms' ) ) {
    global $_test_get_terms_results;
    $_test_get_terms_results = array();

    function get_terms( $args = array() ) {
        global $_test_get_terms_results;
        $taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
        return isset( $_test_get_terms_results[ $taxonomy ] ) ? $_test_get_terms_results[ $taxonomy ] : array();
    }
}

// sanitize_title() stub.
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title ) {
        return strtolower( preg_replace( '/[^a-z0-9-]/', '-', strtolower( trim( $title ) ) ) );
    }
}

// wp_delete_attachment() stub.
if ( ! function_exists( 'wp_delete_attachment' ) ) {
    function wp_delete_attachment( $attachment_id, $force = false ) {
        return true;
    }
}

// wp_update_post() stub.
if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $post_data, $wp_error = false ) {
        $id = isset( $post_data['ID'] ) ? $post_data['ID'] : 0;
        return $id;
    }
}

// sanitize_mime_type() stub.
if ( ! function_exists( 'sanitize_mime_type' ) ) {
    function sanitize_mime_type( $mime_type ) {
        return preg_replace( '/[^a-zA-Z0-9\/\-\+\.]/', '', $mime_type );
    }
}

// esc_url_raw() stub.
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

// get_post_type_object() stub.
if ( ! function_exists( 'get_post_type_object' ) ) {
    global $_test_post_type_objects;
    $_test_post_type_objects = array(
        'post' => (object) array( 'name' => 'post', 'public' => true, 'hierarchical' => false ),
        'page' => (object) array( 'name' => 'page', 'public' => true, 'hierarchical' => true ),
    );

    function get_post_type_object( $post_type ) {
        global $_test_post_type_objects;
        return isset( $_test_post_type_objects[ $post_type ] ) ? $_test_post_type_objects[ $post_type ] : null;
    }
}

// wp_insert_post() stub.
if ( ! function_exists( 'wp_insert_post' ) ) {
    global $_test_next_post_id;
    $_test_next_post_id = 1000;

    function wp_insert_post( $post_data, $wp_error = false ) {
        global $_test_posts, $_test_next_post_id, $_test_post_meta;

        $id = $_test_next_post_id++;
        $_test_posts[ $id ] = (object) array(
            'ID'             => $id,
            'post_type'      => isset( $post_data['post_type'] ) ? $post_data['post_type'] : 'post',
            'post_title'     => isset( $post_data['post_title'] ) ? $post_data['post_title'] : '',
            'post_status'    => isset( $post_data['post_status'] ) ? $post_data['post_status'] : 'draft',
            'post_content'   => isset( $post_data['post_content'] ) ? $post_data['post_content'] : '',
            'post_excerpt'   => isset( $post_data['post_excerpt'] ) ? $post_data['post_excerpt'] : '',
            'post_date'      => date( 'Y-m-d H:i:s' ),
            'post_modified'  => date( 'Y-m-d H:i:s' ),
            'post_author'    => isset( $post_data['post_author'] ) ? $post_data['post_author'] : 1,
            'post_name'      => '',
            'post_mime_type' => '',
        );

        if ( ! isset( $_test_post_meta[ $id ] ) ) {
            $_test_post_meta[ $id ] = array();
        }

        return $id;
    }
}

// register_post_type() stub.
if ( ! function_exists( 'register_post_type' ) ) {
    function register_post_type( $post_type, $args = array() ) {
        return (object) array( 'name' => $post_type );
    }
}

// is_admin() stub.
if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return false;
    }
}

// wp_redirect() stub.
if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( $location, $status = 302 ) {
        return true;
    }
}

// wp_slash() stub.
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_slash', $value );
        }
        if ( is_string( $value ) ) {
            return addslashes( $value );
        }
        return $value;
    }
}

// wp_kses_post() stub.
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $data ) {
        return $data;
    }
}

// wp_set_current_user() stub.
if ( ! function_exists( 'wp_set_current_user' ) ) {
    global $_test_current_user_id;
    $_test_current_user_id = 0;

    function wp_set_current_user( $id ) {
        global $_test_current_user_id;
        $_test_current_user_id = $id;
    }
}

// get_current_user_id() stub.
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        global $_test_current_user_id;
        return isset( $_test_current_user_id ) ? $_test_current_user_id : 0;
    }
}

// wp_set_post_categories() stub.
if ( ! function_exists( 'wp_set_post_categories' ) ) {
    function wp_set_post_categories( $post_id, $categories, $append = false ) {
        return true;
    }
}

// wp_set_post_tags() stub.
if ( ! function_exists( 'wp_set_post_tags' ) ) {
    function wp_set_post_tags( $post_id, $tags, $append = false ) {
        return true;
    }
}

// add_query_arg() stub.
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) {
        $query = http_build_query( $args );
        $sep   = ( false === strpos( $url, '?' ) ) ? '?' : '&';
        return $url . $sep . $query;
    }
}

// setup_postdata() stub.
if ( ! function_exists( 'setup_postdata' ) ) {
    function setup_postdata( $post ) {
        return true;
    }
}

// get_single_template() stub.
if ( ! function_exists( 'get_single_template' ) ) {
    function get_single_template() {
        return '/tmp/single.php';
    }
}

// get_index_template() stub.
if ( ! function_exists( 'get_index_template' ) ) {
    function get_index_template() {
        return '/tmp/index.php';
    }
}

// download_url() stub.
if ( ! function_exists( 'download_url' ) ) {
    function download_url( $url, $timeout = 300 ) {
        return '/tmp/downloaded-image.jpg';
    }
}

// media_handle_sideload() stub.
if ( ! function_exists( 'media_handle_sideload' ) ) {
    global $_test_next_attachment_id;
    $_test_next_attachment_id = 5000;

    function media_handle_sideload( $file_array, $post_id = 0, $desc = '' ) {
        global $_test_next_attachment_id;
        return $_test_next_attachment_id++;
    }
}

// set_post_thumbnail() stub.
if ( ! function_exists( 'set_post_thumbnail' ) ) {
    function set_post_thumbnail( $post_id, $thumbnail_id ) {
        global $_test_post_meta;
        $_test_post_meta[ $post_id ]['_thumbnail_id'] = $thumbnail_id;
        return true;
    }
}

// wp_http_validate_url() stub.
if ( ! function_exists( 'wp_http_validate_url' ) ) {
    function wp_http_validate_url( $url ) {
        return $url;
    }
}

// wp_next_scheduled() stub.
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    global $_test_scheduled_events;
    $_test_scheduled_events = array();

    function wp_next_scheduled( $hook ) {
        global $_test_scheduled_events;
        return isset( $_test_scheduled_events[ $hook ] ) ? $_test_scheduled_events[ $hook ] : false;
    }
}

// wp_schedule_event() stub.
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook ) {
        global $_test_scheduled_events;
        $_test_scheduled_events[ $hook ] = $timestamp;
        return true;
    }
}

// wp_unschedule_event() stub.
if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( $timestamp, $hook ) {
        global $_test_scheduled_events;
        unset( $_test_scheduled_events[ $hook ] );
        return true;
    }
}

// hash_equals() is built into PHP >= 5.6.0 — guard just in case.
if ( ! function_exists( 'hash_equals' ) ) {
    function hash_equals( $known_string, $user_string ) {
        return $known_string === $user_string;
    }
}

// Load Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Load the classes we want to test (only those that don't have heavy WP dependencies).
// Note: We only load parse_markdown from Arcadia_Blocks since it's a static method.

// Load SEO meta class for testing.
require_once dirname( __DIR__, 2 ) . '/includes/class-seo-meta.php';
