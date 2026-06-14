#!/usr/bin/env php
<?php
/**
 * Mechanical guard against the wp_slash data-corruption bug class.
 *
 * WordPress write APIs (wp_insert_post / wp_update_post / update_post_meta / ...)
 * run wp_unslash() internally. Content built with wp_json_encode() — block markup,
 * JSON meta — is full of backslash escapes; if it isn't wp_slash()'d first, those
 * escapes are stripped at storage time and the data is silently corrupted. This
 * exact bug reached production twice.
 *
 * This checker fails the build if a gated write is missing wp_slash(), making the
 * whole class mechanically impossible to reintroduce — the one guard that covers
 * EVERY write site, not just the ones a test happens to exercise.
 *
 * Rules (high-signal, chosen to catch both real incidents with near-zero noise):
 *   - wp_insert_post(), wp_update_post(): the first argument must be slashed —
 *     either wp_slash(...) appears in the call, or the first arg is a variable
 *     assigned from wp_slash() earlier in the file.
 *   - update_post_meta(), add_post_meta(): slashing is required ONLY when the
 *     value is built with wp_json_encode() (the JSON-in-meta signature). Scalar
 *     meta writes are intentionally left alone so the gate stays meaningful.
 *
 * Escape hatch (poka-yoke): a write that is provably safe — e.g. only a post ID
 * and status, no rich content — may carry a `// arcadia:slash-safe — <reason>`
 * comment on the call line or the line above. The proven-safe case becomes
 * explicit and grep-able instead of implicit.
 *
 * Usage: php bin/check-wp-slash.php [root_dir]
 * Exit:  0 = clean, 1 = violations found.
 *
 * @package ArcadiaAgents
 */

const GATED_POST  = array( 'wp_insert_post', 'wp_update_post' );
const GATED_META  = array( 'update_post_meta', 'add_post_meta' );
const ANNOTATION  = 'arcadia:slash-safe';

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

$violations = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	$path = $file->getPathname();
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	// Skip dependencies, test code, and this checker (its strings are not calls).
	if ( preg_match( '#/(vendor|tests|test|bin)/#', $path ) ) {
		continue;
	}
	check_file( $path, $root, $violations );
}

if ( empty( $violations ) ) {
	fwrite( STDOUT, "wp_slash gate: OK — no unslashed gated writes found.\n" );
	exit( 0 );
}

fwrite( STDERR, "wp_slash gate: FAILED — " . count( $violations ) . " unslashed write(s):\n" );
foreach ( $violations as $v ) {
	fwrite( STDERR, "  $v\n" );
}
fwrite( STDERR, "\nFix: wrap the write argument in wp_slash(), or — if the value carries no\n" );
fwrite( STDERR, "slashable content — annotate the line with `// " . ANNOTATION . " — <reason>`.\n" );
exit( 1 );

/**
 * Scan one file for unslashed gated writes.
 *
 * @param string $path        Absolute file path.
 * @param string $root        Scan root (for relative reporting).
 * @param array  $violations  Accumulator (by reference).
 * @return void
 */
function check_file( $path, $root, array &$violations ) {
	$code   = file_get_contents( $path );
	$tokens = token_get_all( $code );
	$lines  = explode( "\n", $code );
	$rel    = ltrim( str_replace( $root, '', $path ), '/' );

	$slashed_vars = collect_slashed_vars( $tokens );

	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] ) {
			continue;
		}
		$name      = $tok[1];
		$call_line = $tok[2];
		$is_post   = in_array( $name, GATED_POST, true );
		$is_meta   = in_array( $name, GATED_META, true );
		if ( ! $is_post && ! $is_meta ) {
			continue;
		}

		// Must be a direct function call: next significant token is '('.
		$open = next_significant( $tokens, $i + 1 );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			continue;
		}
		// Skip method calls ($obj->wp_update_post), static calls, and definitions.
		$before = prev_significant( $tokens, $i - 1 );
		if ( null !== $before ) {
			$bt = $tokens[ $before ];
			if ( is_array( $bt ) && in_array( $bt[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}
		}

		list( $arg_tokens, $end_index ) = extract_args( $tokens, $open );

		$has_slash  = span_contains( $arg_tokens, 'wp_slash' );
		$has_json   = span_contains( $arg_tokens, 'wp_json_encode' );
		$annotated  = line_has_annotation( $lines, $call_line );

		if ( $is_post ) {
			$first_var = first_arg_variable( $arg_tokens );
			$ok        = $has_slash
				|| ( null !== $first_var && isset( $slashed_vars[ $first_var ] ) )
				|| $annotated;
			if ( ! $ok ) {
				$violations[] = sprintf( '%s:%d  %s() — first argument is not wp_slash()ed', $rel, $call_line, $name );
			}
		} elseif ( $has_json && ! $has_slash && ! $annotated ) {
			$violations[] = sprintf( '%s:%d  %s() — wp_json_encode() value stored without wp_slash()', $rel, $call_line, $name );
		}

		$i = $end_index;
	}
}

/**
 * Collect names of variables assigned from a wp_slash() expression.
 *
 * Matches `$name = ... wp_slash( ... ) ... ;` (one level, file-wide). Lets the
 * common `$slashed = wp_slash($data); wp_update_post($slashed, ...)` idiom pass
 * without an annotation.
 *
 * @param array $tokens Token stream.
 * @return array<string,true> Set of slashed variable names (without the $).
 */
function collect_slashed_vars( array $tokens ) {
	$slashed = array();
	$count   = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_VARIABLE !== $tokens[ $i ][0] ) {
			continue;
		}
		$var = ltrim( $tokens[ $i ][1], '$' );
		$eq  = next_significant( $tokens, $i + 1 );
		if ( null === $eq || '=' !== $tokens[ $eq ] ) {
			continue;
		}
		// Scan the right-hand side until the statement ends.
		for ( $j = $eq + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( ';' === $t ) {
				break;
			}
			if ( is_array( $t ) && T_STRING === $t[0] && 'wp_slash' === $t[1] ) {
				$slashed[ $var ] = true;
				break;
			}
		}
	}
	return $slashed;
}

/**
 * Extract the argument tokens of a call, given the index of its '(' token.
 *
 * @param array $tokens Token stream.
 * @param int   $open   Index of the opening paren.
 * @return array{0:array,1:int} [arg tokens, index of closing paren].
 */
function extract_args( array $tokens, $open ) {
	$depth = 0;
	$args  = array();
	$count = count( $tokens );
	for ( $i = $open; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( '(' === $t ) {
			$depth++;
			if ( 1 === $depth ) {
				continue; // Don't include the outer '('.
			}
		} elseif ( ')' === $t ) {
			$depth--;
			if ( 0 === $depth ) {
				return array( $args, $i );
			}
		}
		$args[] = $t;
	}
	return array( $args, $count - 1 );
}

/**
 * Whether a token span contains a call to the given function name.
 *
 * @param array  $span Token slice.
 * @param string $fn   Function name.
 * @return bool
 */
function span_contains( array $span, $fn ) {
	foreach ( $span as $t ) {
		if ( is_array( $t ) && T_STRING === $t[0] && $fn === $t[1] ) {
			return true;
		}
	}
	return false;
}

/**
 * If the first argument is a single bare variable, return its name (no $).
 *
 * @param array $span Argument token slice.
 * @return string|null
 */
function first_arg_variable( array $span ) {
	$depth     = 0;
	$collected = array();
	foreach ( $span as $t ) {
		if ( '(' === $t || '[' === $t ) {
			$depth++;
		} elseif ( ')' === $t || ']' === $t ) {
			$depth--;
		} elseif ( ',' === $t && 0 === $depth ) {
			break; // End of first argument.
		}
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$collected[] = $t;
	}
	if ( 1 === count( $collected ) && is_array( $collected[0] ) && T_VARIABLE === $collected[0][0] ) {
		return ltrim( $collected[0][1], '$' );
	}
	return null;
}

/**
 * Whether the call line or the line above carries the slash-safe annotation.
 *
 * @param array $lines 0-indexed source lines.
 * @param int   $line  1-indexed call line.
 * @return bool
 */
function line_has_annotation( array $lines, $line ) {
	$idx = $line - 1;
	foreach ( array( $idx, $idx - 1 ) as $probe ) {
		if ( isset( $lines[ $probe ] ) && false !== strpos( $lines[ $probe ], ANNOTATION ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Index of the next non-whitespace, non-comment token at or after $from.
 *
 * @param array $tokens Token stream.
 * @param int   $from   Start index.
 * @return int|null
 */
function next_significant( array $tokens, $from ) {
	$count = count( $tokens );
	for ( $i = $from; $i < $count; $i++ ) {
		if ( is_skippable( $tokens[ $i ] ) ) {
			continue;
		}
		return $i;
	}
	return null;
}

/**
 * Index of the previous non-whitespace, non-comment token at or before $from.
 *
 * @param array $tokens Token stream.
 * @param int   $from   Start index.
 * @return int|null
 */
function prev_significant( array $tokens, $from ) {
	for ( $i = $from; $i >= 0; $i-- ) {
		if ( is_skippable( $tokens[ $i ] ) ) {
			continue;
		}
		return $i;
	}
	return null;
}

/**
 * Whether a token is whitespace or a comment (ignored when finding neighbours).
 *
 * @param mixed $token A token.
 * @return bool
 */
function is_skippable( $token ) {
	return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
}
