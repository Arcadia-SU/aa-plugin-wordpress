<?php
/**
 * PHPStan bootstrap — declares the constants the plugin entry point defines
 * at runtime with dynamic values (plugin_dir_url/plugin_dir_path), which
 * static analysis cannot resolve on its own.
 *
 * Analysis-time only; never loaded by WordPress.
 *
 * @package ArcadiaAgents
 */

define( 'ARCADIA_AGENTS_VERSION', '0.0.0' );
define( 'ARCADIA_AGENTS_PLUGIN_DIR', __DIR__ . '/' );
define( 'ARCADIA_AGENTS_PLUGIN_URL', 'https://example.com/wp-content/plugins/arcadia-agents/' );
