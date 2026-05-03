<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site scanner. Walks pages/posts and the site root, runs GEO checks,
 * returns a structured report. Read-only — never modifies content.
 *
 * Issue shape:
 *   [
 *     'id'        => stable string,
 *     'category'  => content|substance|structure|technical|trust,
 *     'severity'  => low|medium|high,
 *     'title'     => human-readable,
 *     'detail'    => longer explanation,
 *     'post_id'   => int|null (null = site-wide),
 *     'auto_fixable' => bool,
 *     'fix_type'  => string,
 *   ]
 */
class Gleo_Scanner {

	public static function scan( $post_ids = null ) {
		$report = [
			'generated_at' => current_time( 'mysql' ),
			'site_url'     => home_url(),
			'issues'       => [],
			'pages'        => [],
			'site'         => [],
		];

		$report['site']   = self::scan_site_wide();
		$report['issues'] = array_merge( $report['issues'], self::issues_from_site( $report['site'] ) );

		if ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
			$posts = array_filter( array_map( 'get_post', array_map( 'intval', $post_ids ) ) );
		} else {
			$posts = self::get_priority_posts();
		}
		foreach ( $posts as $post ) {
			$page_report = self::scan_post( $post );
			$report['pages'][] = $page_report;
			$report['issues']  = array_merge( $report['issues'], $page_report['issues'] );
		}

		return $report;
	}

	public static function get_priority_posts( $limit = 30 ) {
		global $wpdb;

		$front_id = (int) get_option( 'page_on_front' );
		$blog_id  = (int) get_option( 'page_for_posts' );

		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );

		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$all = array_merge( $pages, $posts );

		usort( $all, function( $a, $b ) use ( $front_id, $blog_id ) {
			$score_a = self::priority_score( $a, $front_id, $blog_id );
			$score_b = self::priority_score( $b, $front_id, $blog_id );
			return $score_b - $score_a;
		} );

		return array_slice( $all, 0, $limit );
	}

	private static function priority_score( $post, $front_id, $blog_id ) {
		$score = 0;
		if ( $post->ID === $front_id ) { $score += 1000; }
		if ( $post->ID === $blog_id )  { $score += 500; }
		$slug = strtolower( $post->post_name );
		$title = strtolower( $post->post_title );
		$markers = [ 'about' => 200, 'services' => 180, 'service' => 170, 'pricing' => 160, 'contact' => 140, 'faq' => 130, 'product' => 120 ];
		foreach ( $markers as $needle => $bonus ) {
			if ( strpos( $slug, $needle ) !== false || strpos( $title, $needle ) !== false ) {
				$score += $bonus;
			}
		}
		if ( $post->post_type === 'page' ) { $score += 50; }
		$score += min( 100, (int) ( strlen( $post->post_content ) / 100 ) );
		return $score;
	}

	private static function scan_site_wide() {
		$out = [
			'robots_txt'        => self::check_robots_txt(),
			'llms_txt'          => self::check_llms_txt(),
			'sitemap'           => self::check_sitemap(),
			'organization_schema' => self::check_organization_schema(),
			'site_title'        => get_bloginfo( 'name' ),
			'site_description'  => get_bloginfo( 'description' ),
		];
		return $out;
	}

	private static function issues_from_site( $site ) {
		$issues = [];

		if ( ! $site['robots_txt']['allows_ai_bots'] ) {
			$issues[] = [
				'id'        => 'robots_blocks_ai',
				'category'  => 'technical',
				'severity'  => 'high',
				'title'     => 'AI crawlers are blocked or not explicitly allowed in robots.txt',
				'detail'    => 'GPTBot, ClaudeBot, PerplexityBot and Google-Extended need crawl access for your site to be cited by AI assistants. Gleo can rewrite robots.txt to allow them.',
				'post_id'   => null,
				'auto_fixable' => true,
				'fix_type'  => 'robots_txt',
			];
		}

		if ( ! $site['llms_txt']['exists'] ) {
			$issues[] = [
				'id'        => 'missing_llms_txt',
				'category'  => 'technical',
				'severity'  => 'medium',
				'title'     => 'No /llms.txt file',
				'detail'    => 'llms.txt is an emerging standard that lets you tell LLMs which pages best represent your site. Gleo can generate one from your sitemap.',
				'post_id'   => null,
				'auto_fixable' => true,
				'fix_type'  => 'llms_txt',
			];
		}

		if ( ! $site['organization_schema']['exists'] ) {
			$issues[] = [
				'id'        => 'missing_org_schema',
				'category'  => 'technical',
				'severity'  => 'high',
				'title'     => 'No Organization schema markup',
				'detail'    => 'Organization schema tells search engines and LLMs the official details of your business (name, URL, logo, social profiles).',
				'post_id'   => null,
				'auto_fixable' => true,
				'fix_type'  => 'organization_schema',
			];
		}

		if ( empty( $site['site_description'] ) ) {
			$issues[] = [
				'id'        => 'missing_tagline',
				'category'  => 'trust',
				'severity'  => 'low',
				'title'     => 'Your site has no tagline / description',
				'detail'    => 'Set a tagline in Settings → General. LLMs use it to summarize your business.',
				'post_id'   => null,
				'auto_fixable' => false,
				'fix_type'  => '',
			];
		}

		return $issues;
	}

	private static function check_robots_txt() {
		$url = home_url( '/robots.txt' );
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			return [ 'exists' => false, 'allows_ai_bots' => false, 'body' => '' ];
		}
		$body = wp_remote_retrieve_body( $response );
		$bots = [ 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended' ];
		$mentioned = 0;
		foreach ( $bots as $bot ) {
			if ( stripos( $body, $bot ) !== false ) {
				$mentioned++;
			}
		}
		return [
			'exists'          => wp_remote_retrieve_response_code( $response ) === 200,
			'allows_ai_bots'  => $mentioned >= 2,
			'body'            => $body,
		];
	}

	private static function check_llms_txt() {
		$url = home_url( '/llms.txt' );
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			return [ 'exists' => false ];
		}
		return [ 'exists' => wp_remote_retrieve_response_code( $response ) === 200 ];
	}

	private static function check_sitemap() {
		$urls = [ home_url( '/sitemap.xml' ), home_url( '/wp-sitemap.xml' ) ];
		foreach ( $urls as $url ) {
			$r = wp_remote_get( $url, [ 'timeout' => 10 ] );
			if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
				return [ 'exists' => true, 'url' => $url ];
			}
		}
		return [ 'exists' => false, 'url' => '' ];
	}

	private static function check_organization_schema() {
		$response = wp_remote_get( home_url( '/' ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			return [ 'exists' => false ];
		}
		$body = wp_remote_retrieve_body( $response );
		$has_org = preg_match( '/"@type"\s*:\s*"(Organization|LocalBusiness)"/i', $body );
		return [ 'exists' => (bool) $has_org ];
	}

	public static function scan_post( $post ) {
		$content = $post->post_content;
		$plain   = wp_strip_all_tags( $content );
		$word_count = str_word_count( $plain );

		$result = [
			'post_id'    => $post->ID,
			'title'      => get_the_title( $post ),
			'permalink'  => get_permalink( $post ),
			'post_type'  => $post->post_type,
			'word_count' => $word_count,
			'is_page_builder' => self::is_page_builder_content( $post ),
			'checks'     => [],
			'issues'     => [],
		];

		$result['checks']['has_h1'] = self::has_h1( $content );
		$result['checks']['heading_hierarchy'] = self::heading_hierarchy_ok( $content );
		$result['checks']['has_tldr'] = self::has_marker( $content, [ 'tldr', 'tl;dr', 'key takeaway', 'gleo-tldr' ] );
		$result['checks']['has_faq']  = self::has_marker( $content, [ 'gleo-faq', 'frequently asked', 'faq' ] ) || self::has_faq_schema( $content );
		$result['checks']['has_lists'] = self::has_lists( $content );
		$result['checks']['has_alt_text'] = self::all_images_have_alt( $content );
		$result['checks']['conversational'] = self::looks_conversational( $plain );
		$result['checks']['fact_density'] = self::fact_density( $plain );
		$result['checks']['has_article_schema'] = self::has_article_schema( $post );

		if ( $result['is_page_builder'] ) {
			$result['issues'][] = [
				'id'        => 'page_builder_' . $post->ID,
				'category'  => 'technical',
				'severity'  => 'low',
				'title'     => sprintf( 'Built with a page builder: %s', get_the_title( $post ) ),
				'detail'    => 'Gleo will not auto-edit page-builder content (Elementor, Divi, etc.) to avoid breaking your layout. You can still apply schema and metadata fixes.',
				'post_id'   => $post->ID,
				'auto_fixable' => false,
				'fix_type'  => '',
			];
		}

		if ( ! $result['checks']['has_h1'] ) {
			$result['issues'][] = self::issue( 'missing_h1', 'structure', 'medium', $post, 'No H1 heading found in content', 'LLMs use H1 to identify the main topic of a page.', ! $result['is_page_builder'], 'heading_hierarchy' );
		}
		if ( ! $result['checks']['heading_hierarchy'] ) {
			$result['issues'][] = self::issue( 'bad_heading_hierarchy', 'structure', 'low', $post, 'Heading levels skip (e.g. H1 → H3)', 'Logical H1 → H2 → H3 hierarchy helps LLMs parse your content.', ! $result['is_page_builder'], 'heading_hierarchy' );
		}
		if ( ! $result['checks']['has_tldr'] && $word_count > 300 ) {
			$result['issues'][] = self::issue( 'missing_tldr', 'structure', 'high', $post, 'No TL;DR / key takeaways section', 'A conclusion-first summary at the top is one of the highest-impact GEO tactics. Gleo can generate one from your existing content.', ! $result['is_page_builder'], 'tldr' );
		}
		if ( ! $result['checks']['has_faq'] && $word_count > 400 ) {
			$result['issues'][] = self::issue( 'missing_faq', 'structure', 'medium', $post, 'No FAQ section', 'FAQ sections (with FAQPage schema) are heavily cited by AI assistants.', ! $result['is_page_builder'], 'faq' );
		}
		if ( ! $result['checks']['has_alt_text'] ) {
			$result['issues'][] = self::issue( 'missing_alt_text', 'technical', 'medium', $post, 'One or more images are missing alt text', 'Alt text helps LLMs and search engines understand your images.', ! $result['is_page_builder'], 'alt_text' );
		}
		if ( ! $result['checks']['has_article_schema'] && $post->post_type === 'post' ) {
			$result['issues'][] = self::issue( 'missing_article_schema', 'technical', 'medium', $post, 'No Article schema', 'Article schema gives LLMs the author, publish date, and topic of your post.', true, 'article_schema' );
		}
		if ( ! $result['checks']['conversational'] && $word_count > 200 ) {
			$result['issues'][] = self::issue( 'not_conversational', 'content', 'medium', $post, 'Tone may be too formal / not conversational', 'Aim for 7th-grade reading level and a direct, confident voice. Gleo can rewrite for tone.', ! $result['is_page_builder'], 'rewrite_tone' );
		}
		if ( $result['checks']['fact_density'] < 0.5 && $word_count > 300 ) {
			$result['issues'][] = self::issue( 'low_fact_density', 'substance', 'medium', $post, 'Low fact density (few stats, dates, or specifics)', 'Pages with concrete numbers and citations get cited more by LLMs. Gleo can pull verifiable stats from the web with Tavily.', ! $result['is_page_builder'], 'enrich_facts' );
		}

		return $result;
	}

	private static function issue( $id, $category, $severity, $post, $title, $detail, $auto, $fix_type ) {
		return [
			'id'        => $id . '_' . $post->ID,
			'category'  => $category,
			'severity'  => $severity,
			'title'     => $title . ': ' . get_the_title( $post ),
			'detail'    => $detail,
			'post_id'   => $post->ID,
			'auto_fixable' => $auto,
			'fix_type'  => $fix_type,
		];
	}

	public static function is_page_builder_content( $post ) {
		$markers = [
			'<!-- wp:', // Gutenberg is fine, but check below
			'data-elementor',
			'[et_pb_',
			'<!-- Beaver Builder',
			'wpb-content-wrapper',
			'data-vc-',
		];
		$content = $post->post_content;
		if ( strpos( $content, 'data-elementor' ) !== false ) { return true; }
		if ( strpos( $content, '[et_pb_' ) !== false ) { return true; }
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) { return true; }
		if ( get_post_meta( $post->ID, '_et_pb_use_builder', true ) === 'on' ) { return true; }
		if ( get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) { return true; }
		return false;
	}

	private static function has_h1( $html ) {
		return (bool) preg_match( '/<h1\b/i', $html );
	}

	private static function heading_hierarchy_ok( $html ) {
		preg_match_all( '/<h([1-6])\b/i', $html, $m );
		if ( empty( $m[1] ) ) { return true; }
		$levels = array_map( 'intval', $m[1] );
		$prev = 0;
		foreach ( $levels as $lvl ) {
			if ( $prev > 0 && $lvl > $prev + 1 ) {
				return false;
			}
			$prev = $lvl;
		}
		return true;
	}

	private static function has_marker( $html, $needles ) {
		$lower = strtolower( $html );
		foreach ( $needles as $n ) {
			if ( strpos( $lower, strtolower( $n ) ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function has_lists( $html ) {
		return (bool) preg_match( '/<(ul|ol)\b/i', $html );
	}

	private static function all_images_have_alt( $html ) {
		preg_match_all( '/<img\b[^>]*>/i', $html, $imgs );
		if ( empty( $imgs[0] ) ) { return true; }
		foreach ( $imgs[0] as $img ) {
			if ( ! preg_match( '/\balt\s*=\s*"[^"]+"/i', $img ) && ! preg_match( "/\balt\s*=\s*'[^']+'/i", $img ) ) {
				return false;
			}
		}
		return true;
	}

	private static function looks_conversational( $plain ) {
		if ( strlen( $plain ) < 100 ) { return true; }
		$sentences = preg_split( '/[.!?]+/', $plain );
		$sentences = array_filter( array_map( 'trim', $sentences ) );
		if ( empty( $sentences ) ) { return true; }
		$total_words = 0;
		foreach ( $sentences as $s ) {
			$total_words += str_word_count( $s );
		}
		$avg = $total_words / max( 1, count( $sentences ) );
		return $avg < 22;
	}

	private static function fact_density( $plain ) {
		$words = max( 1, str_word_count( $plain ) );
		preg_match_all( '/\d+(?:\.\d+)?%?|\b(?:19|20)\d{2}\b|\$\d+/', $plain, $m );
		$facts = isset( $m[0] ) ? count( $m[0] ) : 0;
		return ( $facts / $words ) * 100;
	}

	private static function has_faq_schema( $html ) {
		return (bool) preg_match( '/"@type"\s*:\s*"FAQPage"/i', $html );
	}

	public static function has_article_schema( $post ) {
		$content = $post->post_content;
		if ( preg_match( '/"@type"\s*:\s*"(Article|BlogPosting|NewsArticle)"/i', $content ) ) {
			return true;
		}
		return get_post_meta( $post->ID, '_gleo_article_schema', true ) ? true : false;
	}
}
