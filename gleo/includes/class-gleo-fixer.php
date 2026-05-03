<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Applies fixes. Every fix path:
 *   1. captures "before" state
 *   2. computes "after"
 *   3. writes via WP API (which creates a revision for posts)
 *   4. records the change in Gleo_Revisions
 *
 * Per-post auto-fix is capped at GLEO_AUTOFIX_CAP pages per run.
 *
 * IMPORTANT: We never auto-edit page-builder content. Page builders store data
 * in post meta or custom shortcodes; rewriting post_content would corrupt it.
 */
class Gleo_Fixer {

	public static function apply_all( $report = null, $options = [] ) {
		if ( $report === null ) {
			$report = Gleo_Scanner::scan();
		}
		$applied = [];
		$skipped = [];
		$errors  = [];

		// Site-wide fixes first.
		foreach ( $report['issues'] as $issue ) {
			if ( ! empty( $issue['post_id'] ) || empty( $issue['auto_fixable'] ) ) { continue; }
			$res = self::apply_one( $issue, $report );
			if ( is_wp_error( $res ) ) {
				$errors[] = [ 'issue' => $issue['id'], 'error' => $res->get_error_message() ];
			} else {
				$applied[] = [ 'issue' => $issue['id'], 'change_id' => $res ];
			}
		}

		// Group post-scoped issues by post and only touch top N posts.
		$by_post = [];
		foreach ( $report['issues'] as $issue ) {
			if ( empty( $issue['post_id'] ) || empty( $issue['auto_fixable'] ) ) { continue; }
			$by_post[ $issue['post_id'] ][] = $issue;
		}

		$cap = isset( $options['cap'] ) ? (int) $options['cap'] : GLEO_AUTOFIX_CAP;
		$post_ids = array_slice( array_keys( $by_post ), 0, $cap );

		foreach ( $by_post as $pid => $issues ) {
			if ( ! in_array( $pid, $post_ids, true ) ) {
				$skipped[] = [ 'post_id' => $pid, 'reason' => 'cap_reached' ];
				continue;
			}
			$post = get_post( $pid );
			if ( ! $post ) { continue; }
			if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
				$skipped[] = [ 'post_id' => $pid, 'reason' => 'page_builder' ];
				continue;
			}
			foreach ( $issues as $issue ) {
				$res = self::apply_one( $issue, $report );
				if ( is_wp_error( $res ) ) {
					$errors[] = [ 'issue' => $issue['id'], 'error' => $res->get_error_message() ];
				} else {
					$applied[] = [ 'issue' => $issue['id'], 'change_id' => $res ];
				}
			}
		}

		return [
			'applied' => $applied,
			'skipped' => $skipped,
			'errors'  => $errors,
		];
	}

	public static function apply_one( $issue, $report = null ) {
		switch ( $issue['fix_type'] ) {
			case 'robots_txt':
				return self::fix_robots_txt();
			case 'llms_txt':
				return self::fix_llms_txt();
			case 'organization_schema':
				return self::fix_organization_schema();
			case 'article_schema':
				return self::fix_article_schema( (int) $issue['post_id'] );
			case 'tldr':
				return self::fix_tldr( (int) $issue['post_id'] );
			case 'faq':
				return self::fix_faq( (int) $issue['post_id'] );
			case 'heading_hierarchy':
				return self::fix_heading_hierarchy( (int) $issue['post_id'] );
			case 'alt_text':
				return self::fix_alt_text( (int) $issue['post_id'] );
			case 'rewrite_tone':
				return self::fix_tone( (int) $issue['post_id'] );
			case 'enrich_facts':
				return self::fix_enrich_facts( (int) $issue['post_id'] );
			default:
				return new WP_Error( 'gleo_unknown_fix', 'Unknown fix type: ' . $issue['fix_type'] );
		}
	}

	// ---------- Site-wide fixes ----------

	private static function fix_robots_txt() {
		$current = get_option( 'gleo_robots_addendum', '' );
		$addendum = self::ai_robots_block();
		update_option( 'gleo_robots_addendum', $addendum, false );
		return Gleo_Revisions::record( [
			'type'     => 'option',
			'fix_type' => 'robots_txt',
			'summary'  => 'Allowed AI crawlers in robots.txt',
			'before'   => $current,
			'after'    => [ 'key' => 'gleo_robots_addendum', 'value' => $addendum ],
		] );
	}

	public static function ai_robots_block() {
		$bots = [ 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'CCBot', 'cohere-ai', 'Bytespider', 'Applebot-Extended' ];
		$out = "\n# Gleo: explicit allowlist for AI crawlers\n";
		foreach ( $bots as $bot ) {
			$out .= "User-agent: $bot\nAllow: /\n\n";
		}
		return $out;
	}

	private static function fix_llms_txt() {
		$current = get_option( 'gleo_llms_txt', '' );
		$content = Gleo_LLMs_Txt::generate();
		update_option( 'gleo_llms_txt', $content, false );
		return Gleo_Revisions::record( [
			'type'     => 'option',
			'fix_type' => 'llms_txt',
			'summary'  => 'Generated /llms.txt',
			'before'   => $current,
			'after'    => [ 'key' => 'gleo_llms_txt', 'value' => $content ],
		] );
	}

	private static function fix_organization_schema() {
		$current = get_option( Gleo_Schema::ORG_OPTION );
		$schema  = Gleo_Schema::build_organization();
		update_option( Gleo_Schema::ORG_OPTION, $schema, false );
		return Gleo_Revisions::record( [
			'type'     => 'option',
			'fix_type' => 'organization_schema',
			'summary'  => 'Added Organization schema to homepage <head>',
			'before'   => $current ?: null,
			'after'    => [ 'key' => Gleo_Schema::ORG_OPTION, 'value' => $schema ],
		] );
	}

	// ---------- Per-post fixes ----------

	private static function fix_article_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		$current = get_post_meta( $post_id, '_gleo_article_schema', true );
		$schema  = Gleo_Schema::build_article( $post );
		update_post_meta( $post_id, '_gleo_article_schema', $schema );
		return Gleo_Revisions::record( [
			'type'     => 'post_meta',
			'post_id'  => $post_id,
			'fix_type' => 'article_schema',
			'summary'  => 'Added Article schema',
			'before'   => $current ?: null,
			'after'    => [ 'key' => '_gleo_article_schema', 'value' => $schema ],
		] );
	}

	private static function fix_tldr( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}
		if ( strpos( $post->post_content, 'gleo-tldr' ) !== false ) {
			return new WP_Error( 'gleo_already_done', 'TL;DR already present.' );
		}

		$plain = wp_strip_all_tags( $post->post_content );
		$plain = mb_substr( $plain, 0, 6000 );

		$prompt = "You generate concise TL;DRs for blog posts and pages. Rules:\n"
			. "- Output JSON: {\"tldr\": \"one-sentence summary\", \"takeaways\": [\"...\", \"...\", \"...\"]}\n"
			. "- 3 to 5 takeaway bullets, each <= 18 words\n"
			. "- Use only facts from the source. Do NOT invent statistics, dates, or claims.\n"
			. "- Conversational, 7th-grade reading level, confident voice.\n\n"
			. "TITLE: " . $post->post_title . "\n\nCONTENT:\n" . $plain;

		$result = Gleo_Gemini::generate_json( $prompt, [ 'temperature' => 0.3, 'max_tokens' => 700 ] );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['tldr'] ) || empty( $result['takeaways'] ) ) {
			return new WP_Error( 'gleo_bad_response', 'Gemini did not return a usable TL;DR.' );
		}

		$html = self::render_tldr_block( $result['tldr'], $result['takeaways'] );
		$before = $post->post_content;
		$after  = $html . "\n\n" . $before;

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $after ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'tldr',
			'summary'  => 'Added TL;DR / Key Takeaways block',
			'before'   => $before,
			'after'    => $after,
		] );
	}

	public static function render_tldr_block( $tldr, $takeaways ) {
		$items = '';
		foreach ( $takeaways as $t ) {
			$items .= '<li>' . esc_html( $t ) . '</li>';
		}
		return '<aside class="gleo-tldr" aria-label="Key takeaways">'
			. '<h3 class="gleo-tldr__title">Key Takeaways</h3>'
			. '<p class="gleo-tldr__summary">' . esc_html( $tldr ) . '</p>'
			. '<ul class="gleo-tldr__list">' . $items . '</ul>'
			. '</aside>';
	}

	private static function fix_faq( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}
		if ( strpos( $post->post_content, 'gleo-faq' ) !== false ) {
			return new WP_Error( 'gleo_already_done', 'FAQ already present.' );
		}

		$plain = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 6000 );

		$prompt = "Generate an FAQ section based ONLY on the source content. Rules:\n"
			. "- Output JSON: {\"faqs\": [{\"question\":\"...\",\"answer\":\"...\"}, ...]}\n"
			. "- 4 to 6 FAQs.\n"
			. "- Questions are real questions a customer would type into ChatGPT or Google.\n"
			. "- Answers are 40-90 words, direct, conversational, and answer the question in the FIRST sentence.\n"
			. "- Use only facts from the source. Do not invent statistics, prices, or guarantees.\n\n"
			. "TITLE: " . $post->post_title . "\n\nCONTENT:\n" . $plain;

		$result = Gleo_Gemini::generate_json( $prompt, [ 'temperature' => 0.4, 'max_tokens' => 1500 ] );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['faqs'] ) || ! is_array( $result['faqs'] ) ) {
			return new WP_Error( 'gleo_bad_response', 'Gemini did not return FAQs.' );
		}

		$html = self::render_faq_block( $result['faqs'] );
		$before = $post->post_content;
		$after  = $before . "\n\n" . $html;

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $after ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		$schema = Gleo_Schema::build_faq( $result['faqs'] );
		if ( $schema ) {
			update_post_meta( $post_id, '_gleo_faq_schema', $schema );
		}

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'faq',
			'summary'  => sprintf( 'Added FAQ block with %d questions', count( $result['faqs'] ) ),
			'before'   => $before,
			'after'    => $after,
		] );
	}

	public static function render_faq_block( $faqs ) {
		$items = '';
		foreach ( $faqs as $faq ) {
			if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) { continue; }
			$items .= '<details class="gleo-faq__item"><summary class="gleo-faq__q">' . esc_html( $faq['question'] ) . '</summary>'
				. '<div class="gleo-faq__a"><p>' . esc_html( $faq['answer'] ) . '</p></div></details>';
		}
		return '<section class="gleo-faq" aria-label="Frequently Asked Questions">'
			. '<h2 class="gleo-faq__title">Frequently Asked Questions</h2>'
			. $items
			. '</section>';
	}

	private static function fix_heading_hierarchy( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}

		$before  = $post->post_content;
		$content = $before;

		// If no H1, promote the first H2 to H1.
		if ( ! preg_match( '/<h1\b/i', $content ) && preg_match( '/<h2\b/i', $content ) ) {
			$content = preg_replace( '/<h2(\b[^>]*)>(.*?)<\/h2>/i', '<h1$1>$2</h1>', $content, 1 );
		}

		// Normalize skips: collapse any heading deeper than parent+1 to parent+1.
		$content = self::normalize_headings( $content );

		if ( $content === $before ) {
			return new WP_Error( 'gleo_no_change', 'Heading hierarchy already valid.' );
		}

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'heading_hierarchy',
			'summary'  => 'Normalized heading hierarchy',
			'before'   => $before,
			'after'    => $content,
		] );
	}

	private static function normalize_headings( $html ) {
		$prev_level = 0;
		return preg_replace_callback(
			'/<h([1-6])(\b[^>]*)>(.*?)<\/h\1>/is',
			function( $m ) use ( &$prev_level ) {
				$lvl = (int) $m[1];
				if ( $prev_level > 0 && $lvl > $prev_level + 1 ) {
					$lvl = $prev_level + 1;
				}
				$prev_level = $lvl;
				return '<h' . $lvl . $m[2] . '>' . $m[3] . '</h' . $lvl . '>';
			},
			$html
		);
	}

	private static function fix_alt_text( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}

		$before  = $post->post_content;
		$context_title = $post->post_title;
		$content = preg_replace_callback( '/<img\b([^>]*)>/i', function( $m ) use ( $context_title ) {
			$attrs = $m[1];
			if ( preg_match( '/\balt\s*=\s*("[^"]+"|\'[^\']+\')/i', $attrs ) ) {
				return $m[0];
			}
			$alt = '';
			if ( preg_match( '/title\s*=\s*"([^"]+)"/i', $attrs, $tm ) ) {
				$alt = $tm[1];
			} elseif ( preg_match( '/src\s*=\s*"([^"]+)"/i', $attrs, $sm ) ) {
				$file = basename( parse_url( $sm[1], PHP_URL_PATH ) );
				$file = preg_replace( '/\.[a-z0-9]+$/i', '', $file );
				$file = preg_replace( '/[-_]+/', ' ', $file );
				$alt  = trim( $file );
				if ( strlen( $alt ) < 4 ) {
					$alt = $context_title;
				}
			} else {
				$alt = $context_title;
			}
			$attrs = preg_replace( '/\balt\s*=\s*("|\')(\1)/i', '', $attrs );
			return '<img' . $attrs . ' alt="' . esc_attr( $alt ) . '">';
		}, $before );

		if ( $content === $before ) {
			return new WP_Error( 'gleo_no_change', 'No images needed alt text.' );
		}

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'alt_text',
			'summary'  => 'Filled missing image alt text',
			'before'   => $before,
			'after'    => $content,
		] );
	}

	private static function fix_tone( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}

		$before = $post->post_content;
		// Operate on the plain prose only — preserve existing tags via paragraph-by-paragraph rewrite.
		$paragraphs = self::extract_rewritable_paragraphs( $before );
		if ( empty( $paragraphs ) ) {
			return new WP_Error( 'gleo_no_change', 'No rewritable paragraphs found.' );
		}

		$prompt = "Rewrite each paragraph for a 7th-grade reading level, conversational and confident. Rules:\n"
			. "- Keep meaning identical. Do not invent facts, statistics, or claims.\n"
			. "- Lead with the conclusion when possible. Short sentences.\n"
			. "- Output JSON: {\"rewrites\": [\"...\", \"...\"]} with the SAME number of items, in the SAME order.\n\n"
			. "PARAGRAPHS:\n" . wp_json_encode( array_values( $paragraphs ) );

		$result = Gleo_Gemini::generate_json( $prompt, [ 'temperature' => 0.5, 'max_tokens' => 4096 ] );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['rewrites'] ) || count( $result['rewrites'] ) !== count( $paragraphs ) ) {
			return new WP_Error( 'gleo_bad_response', 'Rewrite count mismatch.' );
		}

		$after = $before;
		$i = 0;
		foreach ( $paragraphs as $original => $_ ) {
			$replacement = $result['rewrites'][ $i ];
			$after = self::replace_first( $after, $original, $replacement );
			$i++;
		}

		if ( $after === $before ) {
			return new WP_Error( 'gleo_no_change', 'Rewrites produced no diff.' );
		}

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $after ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'rewrite_tone',
			'summary'  => sprintf( 'Rewrote %d paragraphs for tone', count( $paragraphs ) ),
			'before'   => $before,
			'after'    => $after,
		] );
	}

	private static function extract_rewritable_paragraphs( $html ) {
		preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $m );
		$out = [];
		if ( empty( $m[0] ) ) { return $out; }
		foreach ( $m[1] as $i => $inner ) {
			$plain = trim( wp_strip_all_tags( $inner ) );
			if ( strlen( $plain ) < 60 ) { continue; }
			if ( preg_match( '/<(img|a|iframe|video|script|button|form)/i', $inner ) ) { continue; }
			$out[ $plain ] = true;
			if ( count( $out ) >= 8 ) { break; }
		}
		return $out;
	}

	private static function replace_first( $haystack, $needle, $replacement ) {
		$pos = strpos( $haystack, $needle );
		if ( $pos === false ) { return $haystack; }
		return substr( $haystack, 0, $pos ) . $replacement . substr( $haystack, $pos + strlen( $needle ) );
	}

	private static function fix_enrich_facts( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'gleo_no_post', 'Post not found.' ); }
		if ( Gleo_Scanner::is_page_builder_content( $post ) ) {
			return new WP_Error( 'gleo_page_builder', 'Skipped page-builder content.' );
		}

		$query = $post->post_title . ' statistics 2025';
		$search = Gleo_Tavily::search( $query, [ 'max_results' => 5, 'depth' => 'basic' ] );
		if ( is_wp_error( $search ) ) { return $search; }

		$sources = [];
		if ( ! empty( $search['results'] ) ) {
			foreach ( $search['results'] as $r ) {
				$sources[] = [
					'title' => $r['title'] ?? '',
					'url'   => $r['url'] ?? '',
					'snippet' => $r['content'] ?? '',
				];
			}
		}
		if ( empty( $sources ) ) {
			return new WP_Error( 'gleo_no_sources', 'No sources found to enrich content.' );
		}

		$plain  = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 4000 );
		$prompt = "You add a 'By the numbers' callout to a page using ONLY facts from the provided sources. Rules:\n"
			. "- Output JSON: {\"facts\": [{\"text\":\"<short factual sentence>\",\"source_url\":\"<exact url from sources>\"}, ...]}\n"
			. "- 3 to 5 facts. Each must include a real number/percentage/date.\n"
			. "- Use only facts that appear in the source snippets. If a fact isn't in the sources, do not include it.\n"
			. "- Cite the exact source URL it came from.\n\n"
			. "PAGE TITLE: " . $post->post_title . "\n\nPAGE CONTENT:\n" . $plain
			. "\n\nSOURCES:\n" . wp_json_encode( $sources );

		$result = Gleo_Gemini::generate_json( $prompt, [ 'temperature' => 0.2, 'max_tokens' => 1200 ] );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['facts'] ) ) {
			return new WP_Error( 'gleo_bad_response', 'No facts returned.' );
		}

		$valid_urls = array_filter( array_column( $sources, 'url' ) );
		$facts = array_filter( $result['facts'], function( $f ) use ( $valid_urls ) {
			return ! empty( $f['text'] ) && ! empty( $f['source_url'] ) && in_array( $f['source_url'], $valid_urls, true );
		} );
		if ( empty( $facts ) ) {
			return new WP_Error( 'gleo_no_valid_facts', 'No facts cited valid sources.' );
		}

		$html = self::render_facts_block( array_values( $facts ) );
		$before = $post->post_content;
		// Inject after the first H2 if present, else at the end.
		if ( preg_match( '/<\/h2>/i', $before, $hm, PREG_OFFSET_CAPTURE ) ) {
			$pos = $hm[0][1] + strlen( $hm[0][0] );
			$after = substr( $before, 0, $pos ) . "\n\n" . $html . "\n\n" . substr( $before, $pos );
		} else {
			$after = $before . "\n\n" . $html;
		}

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $after ], true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		return Gleo_Revisions::record( [
			'type'     => 'post_content',
			'post_id'  => $post_id,
			'fix_type' => 'enrich_facts',
			'summary'  => sprintf( 'Added %d cited facts', count( $facts ) ),
			'before'   => $before,
			'after'    => $after,
		] );
	}

	public static function render_facts_block( $facts ) {
		$items = '';
		foreach ( $facts as $f ) {
			$items .= '<li class="gleo-facts__item">'
				. esc_html( $f['text'] )
				. ' <a class="gleo-facts__cite" href="' . esc_url( $f['source_url'] ) . '" rel="noopener nofollow" target="_blank">[source]</a>'
				. '</li>';
		}
		return '<aside class="gleo-facts" aria-label="By the numbers">'
			. '<h3 class="gleo-facts__title">By the numbers</h3>'
			. '<ul class="gleo-facts__list">' . $items . '</ul>'
			. '</aside>';
	}
}
