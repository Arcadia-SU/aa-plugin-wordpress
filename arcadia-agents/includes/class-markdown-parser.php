<?php
/**
 * Markdown parser (inline + block).
 *
 * Pure-function utility extracted from Arcadia_Blocks (Phase D), extended with a
 * hand-rolled block-level parser in Phase 36.
 *
 * @package ArcadiaAgents
 * @since   0.1.24
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Markdown_Parser
 *
 * Stateless converter from markdown to HTML. All public methods are static.
 */
final class Arcadia_Markdown_Parser {

	/**
	 * Max blockquote/list nesting depth before content is emitted flat.
	 *
	 * Backstop against pathological deeply-nested input causing deep recursion.
	 * Well-formed agent output never approaches this.
	 */
	const MAX_BLOCK_DEPTH = 12;

	/**
	 * Parse inline markdown and convert to HTML (inline-only output).
	 *
	 * Supports: **bold**, *italic*, `code`, [link](url). The output is sanitised
	 * to a small inline allowlist ({strong, em, code, a}) — structural tags are
	 * stripped. Use this for short, pre-encapsulated text (headings, paragraphs,
	 * list items). For full wysiwyg fields that legitimately carry structure
	 * (<h2>, <ul>, <table>…), use parse_rich() instead.
	 *
	 * @param string $text Text containing markdown.
	 * @return string Text with HTML formatting.
	 */
	public static function parse_markdown( $text ) {
		// Final safety net: the inline rules interpolate raw agent text, so strip
		// anything outside the small inline allowlist. Neutralises stored XSS
		// (e.g. `**<img src=x onerror=alert(1)>**`) while keeping the formatting.
		return wp_kses(
			self::convert_inline( $text ),
			array(
				'strong' => array(),
				'em'     => array(),
				'code'   => array(),
				'a'      => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
	}

	/**
	 * Parse block + inline markdown and convert to sanitised rich HTML.
	 *
	 * This is the wysiwyg write path. The agent stores STRUCTURAL MARKDOWN in ACF
	 * wysiwyg fields — `##`–`######` headings, `-`/`1.` lists, `| |` GFM tables,
	 * `>` blockquotes — plus the inline tokens (**bold**, *italic*, `code`,
	 * [link](url)). It never stores HTML (AA produces content, the plugin produces
	 * HTML — ADR-013/ADR-022). parse_block_markdown() turns that markdown into
	 * block+inline HTML; wp_kses_post() is the single security boundary that strips
	 * script/iframe tags, on* handlers and javascript: URLs regardless of how the
	 * HTML was produced.
	 *
	 * Already-HTML content (read back from the site and pushed verbatim on a
	 * round-trip) sets `$skip_markdown = true` so parsing is bypassed and only the
	 * sanitiser runs — this avoids re-parsing final HTML, where stray `*` pairs or
	 * `[..](..)` in legacy markup would be mangled (round-trip exception, ADR-013
	 * amendment 2026-06-27, backend item aa-u6nl). The caller derives the flag from
	 * the request — see Arcadia_Api::is_skip_markdown(). As a second line of
	 * defence even when the flag is absent, a line that begins with a structural
	 * HTML tag is passed through verbatim by the block parser (no inline re-parse).
	 *
	 * @param string $text          Text containing block + inline markdown, or
	 *                              already-rendered HTML when $skip_markdown is true.
	 * @param bool   $skip_markdown When true, skip markdown parsing — sanitise only.
	 * @return string Sanitised rich HTML.
	 */
	public static function parse_rich( $text, $skip_markdown = false ) {
		if ( $skip_markdown ) {
			return wp_kses_post( is_string( $text ) ? $text : '' );
		}
		return wp_kses_post( self::parse_block_markdown( $text ) );
	}

	/**
	 * Convert block + inline markdown to HTML, WITHOUT final sanitisation.
	 *
	 * Callers MUST sanitise the result (wp_kses_post) — this interpolates raw input.
	 * Public so the sanitisation boundary stays the caller's explicit choice
	 * (parse_rich applies wp_kses_post; round-trip callers may pre-sanitise once).
	 *
	 * @param string $text Block + inline markdown.
	 * @return string Block + inline HTML (unsanitised).
	 */
	public static function parse_block_markdown( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// PCRE safety preprocessing (traps confirmed by edge-case research):
		// 1. Guarantee valid UTF-8 — otherwise /u classes break on FR accents and
		//    preg_* returns null silently (= content vanishes). mbstring is only
		//    WP-"recommended" (WordPress core polyfills mb_substr/mb_strlen but NOT
		//    mb_check_encoding/mb_convert_encoding), so guard it: on a host without
		//    ext-mbstring we skip the normalisation rather than fatal the request.
		if ( function_exists( 'mb_check_encoding' ) && function_exists( 'mb_convert_encoding' )
			&& ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		// 2. Normalise line endings so the line scanner sees only "\n".
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$lines = explode( "\n", $text );

		return self::parse_blocks( $lines, 0 );
	}

	/**
	 * Block-level scanner: dispatch each line to its construct, in precedence order.
	 *
	 * Recursive for blockquote content (depth-guarded by MAX_BLOCK_DEPTH).
	 *
	 * @param array $lines Lines (already CRLF-normalised).
	 * @param int   $depth Current nesting depth.
	 * @return string Block HTML.
	 */
	private static function parse_blocks( array $lines, $depth ) {
		$out = array();
		$n   = count( $lines );
		$i   = 0;

		while ( $i < $n ) {
			$line = $lines[ $i ];

			// Blank line — block separator, skip.
			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			// 1. Raw HTML passthrough (defensive net for incidental whole-HTML).
			if ( self::is_html_block_start( $line ) ) {
				$buf = array();
				while ( $i < $n && '' !== trim( $lines[ $i ] ) ) {
					$buf[] = $lines[ $i ];
					++$i;
				}
				$out[] = implode( "\n", $buf );
				continue;
			}

			// 2. Fenced code block — no internal parsing (a `#` inside a sample
			//    must not become a heading).
			if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/u', $line, $fm ) ) {
				$fence_char = $fm[1][0];
				$fence_len  = strlen( $fm[1] );
				++$i;
				$code = array();
				while ( $i < $n ) {
					if ( preg_match( '/^ {0,3}(`{3,}|~{3,})[ \t]*$/u', $lines[ $i ], $cm )
						&& $cm[1][0] === $fence_char
						&& strlen( $cm[1] ) >= $fence_len ) {
						++$i; // Consume the closing fence.
						break;
					}
					$code[] = $lines[ $i ];
					++$i;
				}
				$out[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
				continue;
			}

			// 3. ATX heading — space after the #s required; 7+ #s is not a heading.
			if ( preg_match( '/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/u', $line, $hm ) ) {
				$level   = strlen( $hm[1] );
				$content = isset( $hm[2] ) ? self::convert_inline( trim( $hm[2] ) ) : '';
				$out[]   = "<h{$level}>{$content}</h{$level}>";
				++$i;
				continue;
			}

			// 4. Blockquote — consecutive `>` lines, marker stripped, re-parsed.
			if ( preg_match( '/^ {0,3}>/u', $line ) ) {
				$buf = array();
				while ( $i < $n && preg_match( '/^ {0,3}>/u', $lines[ $i ] ) ) {
					$buf[] = preg_replace( '/^ {0,3}> ?/u', '', $lines[ $i ] );
					++$i;
				}
				if ( $depth < self::MAX_BLOCK_DEPTH ) {
					$inner = self::parse_blocks( $buf, $depth + 1 );
				} else {
					$inner = '<p>' . self::convert_inline( implode( ' ', array_map( 'trim', $buf ) ) ) . '</p>';
				}
				$out[] = "<blockquote>{$inner}</blockquote>";
				continue;
			}

			// 5. GFM table — header row immediately followed by a matching delimiter.
			if ( self::looks_like_table_header( $lines, $i ) ) {
				$out[] = self::parse_table( $lines, $i, $n );
				continue; // parse_table advances $i by reference.
			}

			// 6. Thematic break — ≥3 of -, *, or _ (pipes would make it a table, #5).
			if ( preg_match( '/^ {0,3}([-*_])([ \t]*\1){2,}[ \t]*$/u', $line ) ) {
				$out[] = '<hr>';
				++$i;
				continue;
			}

			// 7. List (UL/OL) — tight, one nesting level by indentation.
			if ( preg_match( '/^ {0,3}(?:[-+*]|\d{1,9}[.)])[ \t]+/u', $line ) ) {
				$end   = $i;
				$out[] = self::parse_list( $lines, $i, $n, $end, $depth );
				$i     = $end;
				continue;
			}

			// 8. Paragraph (default) — consecutive non-blank lines joined by a space.
			$buf = array();
			while ( $i < $n ) {
				$l = $lines[ $i ];
				if ( '' === trim( $l ) ) {
					break;
				}
				if ( self::is_paragraph_interrupt( $l ) ) {
					break;
				}
				if ( ! empty( $buf ) && self::looks_like_table_header( $lines, $i ) ) {
					break;
				}
				$buf[] = trim( $l );
				++$i;
			}
			if ( ! empty( $buf ) ) {
				$out[] = '<p>' . self::convert_inline( implode( ' ', $buf ) ) . '</p>';
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * True when the line begins with a raw HTML tag (verbatim passthrough trigger).
	 *
	 * Covers block-level tags AND the inline tags (a/span/strong/em/…) that a
	 * round-tripped, already-rendered HTML field can lead a line with. The agent
	 * sends structural MARKDOWN (never raw HTML), so a line starting with a real
	 * tag is round-trip HTML to reproduce verbatim — not markdown to inline-parse.
	 * Without the inline tags, a `skip_markdown`-less round-trip whose line starts
	 * with `<a …>` would have its stray `*pair*` / `[x](y)` mangled by the inline
	 * pass (review #8). Mirrors a widened CommonMark HTML block type 6/7.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function is_html_block_start( $line ) {
		return (bool) preg_match(
			'#^ {0,3}</?(?:h[1-6]|p|ul|ol|li|table|thead|tbody|tfoot|tr|th|td|blockquote|div|figure|figcaption|pre|hr|br|img|a|span|strong|em|b|i|code|small|sub|sup|mark|u|abbr|s|del|ins|q|cite)(?:[ \t/>]|$)#iu',
			$line
		);
	}

	/**
	 * True when the line would interrupt an open paragraph (start a new block).
	 *
	 * Stricter than a generic block-start test: an unordered/ordered list only
	 * interrupts a paragraph when it has content after the marker, and an ordered
	 * list only when it starts at 1 (so "Texte\n2. x" stays one paragraph).
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function is_paragraph_interrupt( $line ) {
		if ( self::is_html_block_start( $line ) ) {
			return true;
		}
		if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/u', $line ) ) {
			return true; // Fenced code.
		}
		if ( preg_match( '/^ {0,3}#{1,6}(?:[ \t]|$)/u', $line ) ) {
			return true; // ATX heading.
		}
		if ( preg_match( '/^ {0,3}>/u', $line ) ) {
			return true; // Blockquote.
		}
		if ( preg_match( '/^ {0,3}([-*_])([ \t]*\1){2,}[ \t]*$/u', $line ) ) {
			return true; // Thematic break.
		}
		if ( preg_match( '/^ {0,3}[-+*][ \t]+\S/u', $line ) ) {
			return true; // UL with content.
		}
		if ( preg_match( '/^ {0,3}1[.)][ \t]+\S/u', $line ) ) {
			return true; // OL starting at 1 with content.
		}
		return false;
	}

	/**
	 * True when line $i is a GFM table header (line $i+1 is a matching delimiter).
	 *
	 * @param array $lines Lines.
	 * @param int   $i     Index of the candidate header line.
	 * @return bool
	 */
	private static function looks_like_table_header( array $lines, $i ) {
		if ( ! isset( $lines[ $i + 1 ] ) ) {
			return false;
		}
		if ( false === strpos( $lines[ $i ], '|' ) ) {
			return false;
		}
		$header = self::split_table_row( $lines[ $i ] );
		$delim  = self::split_table_row( $lines[ $i + 1 ] );
		if ( count( $header ) < 1 || count( $header ) !== count( $delim ) ) {
			return false; // Cell-count mismatch ⇒ not a table.
		}
		foreach ( $delim as $cell ) {
			if ( false === self::parse_delimiter_cell( $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a GFM table from the header line at $i. Advances $i past the table.
	 *
	 * @param array $lines Lines.
	 * @param int   $i     Header line index (by reference; advanced).
	 * @param int   $n     Total line count.
	 * @return string Table HTML.
	 */
	private static function parse_table( array $lines, &$i, $n ) {
		$headers = self::split_table_row( $lines[ $i ] );
		$delim   = self::split_table_row( $lines[ $i + 1 ] );
		$count   = count( $headers );

		$aligns = array();
		foreach ( $delim as $cell ) {
			$aligns[] = self::parse_delimiter_cell( $cell );
		}

		$html = '<table><thead><tr>';
		foreach ( $headers as $idx => $cell ) {
			$html .= '<th' . self::align_attr( $aligns[ $idx ] ) . '>' . self::convert_inline( $cell ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		$i += 2; // Past header + delimiter.
		$body_rows = 0;
		while ( $i < $n && '' !== trim( $lines[ $i ] ) && false !== strpos( $lines[ $i ], '|' ) ) {
			$cells = self::split_table_row( $lines[ $i ] );
			// Pad / truncate to the header column count.
			$cells = array_slice( array_pad( $cells, $count, '' ), 0, $count );
			$html .= '<tr>';
			foreach ( $cells as $idx => $cell ) {
				$html .= '<td' . self::align_attr( $aligns[ $idx ] ) . '>' . self::convert_inline( $cell ) . '</td>';
			}
			$html .= '</tr>';
			++$body_rows;
			++$i;
		}

		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * Split a table row into trimmed cells on unescaped pipes.
	 *
	 * Strips a single leading/trailing pipe, splits on `|` not preceded by `\`,
	 * then unescapes `\|` → `|`.
	 *
	 * @param string $row Raw table row.
	 * @return array Cells.
	 */
	private static function split_table_row( $row ) {
		$row = trim( $row );
		$row = preg_replace( '/^\|/', '', $row );
		$row = preg_replace( '/\|[ \t]*$/', '', $row );
		$cells = preg_split( '/(?<!\\\\)\|/', $row );
		if ( false === $cells ) {
			return array( $row );
		}
		return array_map(
			static function ( $cell ) {
				return str_replace( '\\|', '|', trim( $cell ) );
			},
			$cells
		);
	}

	/**
	 * Classify a delimiter cell. Returns alignment or false if not a delimiter.
	 *
	 * @param string $cell Delimiter cell (e.g. `:--`, `--:`, `:-:`, `---`).
	 * @return string|false 'left'|'right'|'center'|'none' or false.
	 */
	private static function parse_delimiter_cell( $cell ) {
		$cell = trim( $cell );
		if ( ! preg_match( '/^(:?)-+(:?)$/', $cell, $m ) ) {
			return false;
		}
		$left  = ':' === $m[1];
		$right = ':' === $m[2];
		if ( $left && $right ) {
			return 'center';
		}
		if ( $right ) {
			return 'right';
		}
		if ( $left ) {
			return 'left';
		}
		return 'none';
	}

	/**
	 * Inline style attribute for a table alignment.
	 *
	 * @param string|false $align Alignment from parse_delimiter_cell().
	 * @return string Empty string or ` style="text-align:…"`.
	 */
	private static function align_attr( $align ) {
		if ( 'left' === $align || 'right' === $align || 'center' === $align ) {
			return ' style="text-align:' . $align . '"';
		}
		return '';
	}

	/**
	 * Parse a tight list (UL or OL) starting at $start. Advances $end past it.
	 *
	 * One nesting level is supported by indentation: lines indented deeper than the
	 * list's base indent become a nested sublist on the current item. A blank line,
	 * a dedent, or a non-item line ends the list (tight semantics — no inner <p>).
	 *
	 * @param array $lines Lines.
	 * @param int   $start First item index.
	 * @param int   $n     Total line count.
	 * @param int   $end   Index past the list (by reference).
	 * @param int   $depth Current nesting depth.
	 * @return string List HTML.
	 */
	private static function parse_list( array $lines, $start, $n, &$end, $depth ) {
		$base    = self::indent_of( $lines[ $start ] );
		$ordered = self::is_ordered_marker( $lines[ $start ] );
		$tag     = $ordered ? 'ol' : 'ul';
		$items   = array();
		$i       = $start;

		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				break; // Tight list: a blank line ends it.
			}
			$indent = self::indent_of( $line );
			if ( $indent < $base || $indent > $base ) {
				break; // Dedent, or stray deeper line not consumed as nested below.
			}
			$marker = self::match_marker( $line, $ordered );
			if ( false === $marker ) {
				break; // Marker type changed or no marker — end this list.
			}
			$content = $marker['content'];
			++$i;

			// Gather deeper-indented item lines as this <li>'s nested sublist.
			$nested = array();
			while ( $i < $n
				&& '' !== trim( $lines[ $i ] )
				&& self::indent_of( $lines[ $i ] ) > $base
				&& false !== self::match_marker( $lines[ $i ], null ) ) {
				$nested[] = $lines[ $i ];
				++$i;
			}

			$li = self::convert_inline( trim( $content ) );
			if ( ! empty( $nested ) && $depth < self::MAX_BLOCK_DEPTH ) {
				$sub_end = 0;
				$li     .= self::parse_list( $nested, 0, count( $nested ), $sub_end, $depth + 1 );
			}
			$items[] = "<li>{$li}</li>";
		}

		$end = $i;
		return "<{$tag}>" . implode( '', $items ) . "</{$tag}>";
	}

	/**
	 * Count leading spaces of a line (its indentation).
	 *
	 * @param string $line Line.
	 * @return int Leading-space count.
	 */
	private static function indent_of( $line ) {
		if ( preg_match( '/^( *)/', $line, $m ) ) {
			return strlen( $m[1] );
		}
		return 0;
	}

	/**
	 * True when the line starts an ordered-list marker (digits + . or )).
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function is_ordered_marker( $line ) {
		return (bool) preg_match( '/^ *\d{1,9}[.)][ \t]+/u', $line );
	}

	/**
	 * Match a list marker and capture the item content.
	 *
	 * @param string    $line    Line.
	 * @param bool|null $ordered true = ordered only, false = unordered only,
	 *                           null = either (used for nested detection).
	 * @return array|false ['content' => string] or false.
	 */
	private static function match_marker( $line, $ordered = null ) {
		if ( true === $ordered ) {
			if ( preg_match( '/^ *\d{1,9}[.)][ \t]+(.*)$/u', $line, $m ) ) {
				return array( 'content' => $m[1] );
			}
			return false;
		}
		if ( false === $ordered ) {
			if ( preg_match( '/^ *[-+*][ \t]+(.*)$/u', $line, $m ) ) {
				return array( 'content' => $m[1] );
			}
			return false;
		}
		if ( preg_match( '/^ *(?:[-+*]|\d{1,9}[.)])[ \t]+(.*)$/u', $line, $m ) ) {
			return array( 'content' => $m[1] );
		}
		return false;
	}

	/**
	 * Convert inline markdown tokens to HTML, without final sanitisation.
	 *
	 * Shared core of parse_markdown(), parse_rich() (via parse_block_markdown) and
	 * the block parser. Callers MUST sanitise the result — this interpolates raw
	 * input.
	 *
	 * Order matters:
	 * 1. Code spans are extracted to placeholders FIRST, so their contents are
	 *    immune to emphasis and link parsing (`` `*x*` `` stays literal, not
	 *    <em>; `` `[a](b)` `` stays literal, not a link). They are restored last.
	 * 2. Links are extracted to placeholders NEXT, BEFORE the emphasis passes, so a
	 *    URL containing single or double asterisks (e.g. an `…/path/v1/file` whose
	 *    segment is wrapped in asterisks) is never seen by the emphasis pass — which
	 *    would rewrite it to an em element and then esc_url() would
	 *    mangle the href (review #7). Emphasis inside the link TEXT is applied in
	 *    the callback so `[**bold**](url)` still renders bold.
	 * 3. Bold then italic on the remaining (non-link, non-code) text.
	 * 4. Restore links, then code spans.
	 *
	 * @param string $text Text containing markdown.
	 * @return string Text with inline HTML (unsanitised).
	 */
	private static function convert_inline( $text ) {
		// 1. Code inline: `code` -> placeholder (restored at the end).
		// Extracting to an opaque token protects the span's content from the
		// emphasis/link passes below — the asterisks/brackets inside a code span
		// must remain literal text, not be re-interpreted as markdown.
		$code_spans = array();
		$text       = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $matches ) use ( &$code_spans ) {
				$token                = "\x00c" . count( $code_spans ) . "\x00";
				$code_spans[ $token ] = '<code>' . esc_html( $matches[1] ) . '</code>';
				return $token;
			},
			$text
		);

		// 2. Links: [text](url) -> placeholder holding the built <a>. Extracted
		// before emphasis so the URL never reaches the * passes. The URL pattern
		// allows ONE level of balanced parens so Wikipedia-style targets such as
		// `…/PHP_(programming_language)` are not truncated at the first `)`
		// (review #6). The alternation branches are first-char-disjoint
		// ([^()] vs `(`) so there is no catastrophic backtracking.
		$links     = array();
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$text      = preg_replace_callback(
			'/\[([^\]]+)\]\(((?:[^()]|\([^()]*\))*)\)/',
			function ( $matches ) use ( &$links, $site_host ) {
				$link_text = self::apply_emphasis( $matches[1] ); // Emphasis inside link text.
				$url       = esc_url( $matches[2] );

				// Mark external links so they open safely in a new tab.
				$link_host = wp_parse_url( $url, PHP_URL_HOST );

				$target = '';
				$rel    = '';

				if ( $link_host && $link_host !== $site_host ) {
					$target = ' target="_blank"';
					$rel    = ' rel="noopener noreferrer"';
				}

				$token           = "\x00l" . count( $links ) . "\x00";
				$links[ $token ] = sprintf( '<a href="%s"%s%s>%s</a>', $url, $target, $rel, $link_text );
				return $token;
			},
			$text
		);

		// 3. Emphasis (bold then italic) on the remaining text.
		$text = self::apply_emphasis( $text );

		// 4. Restore links, then code spans (after all other inline passes).
		if ( ! empty( $links ) ) {
			$text = strtr( $text, $links );
		}
		if ( ! empty( $code_spans ) ) {
			$text = strtr( $text, $code_spans );
		}

		return $text;
	}

	/**
	 * Apply bold then italic emphasis to a fragment (no sanitisation).
	 *
	 * Bold runs before italic so `**x**` is not mis-split into two `*`. Used both
	 * on the outer text and, inside the link callback, on link text — so the
	 * emphasis rule lives in one place (the URL itself is never passed here).
	 *
	 * @param string $text Fragment.
	 * @return string Fragment with <strong>/<em> applied.
	 */
	private static function apply_emphasis( $text ) {
		// Bold: **text** -> <strong>text</strong> (before italic).
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		// Italic: *text* -> <em>text</em> (single * not flanked by another *).
		$text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
		return $text;
	}
}
