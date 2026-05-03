<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generates JSON-LD schema markup. Two paths:
 *  - Organization schema: stored in option, injected into <head> on every page.
 *  - Article / FAQ schema: stored as post meta, injected per-post.
 */
class Gleo_Schema {
	const ORG_OPTION = 'gleo_organization_schema';

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'render' ], 5 );
	}

	public static function render() {
		$blocks = [];

		$org = get_option( self::ORG_OPTION );
		if ( ! empty( $org ) && is_array( $org ) ) {
			$blocks[] = $org;
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$article = get_post_meta( $post_id, '_gleo_article_schema', true );
			if ( ! empty( $article ) && is_array( $article ) ) {
				$blocks[] = $article;
			}
			$faq = get_post_meta( $post_id, '_gleo_faq_schema', true );
			if ( ! empty( $faq ) && is_array( $faq ) ) {
				$blocks[] = $faq;
			}
		}

		foreach ( $blocks as $block ) {
			echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
		}
	}

	public static function build_organization() {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );
		$desc      = get_bloginfo( 'description' );

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $site_name,
			'url'      => $site_url,
		];
		if ( $desc ) {
			$schema['description'] = $desc;
		}

		$logo = self::site_logo_url();
		if ( $logo ) {
			$schema['logo'] = $logo;
		}

		return $schema;
	}

	public static function build_article( $post ) {
		$author_id = $post->post_author;
		$author_name = get_the_author_meta( 'display_name', $author_id );
		$schema = [
			'@context'      => 'https://schema.org',
			'@type'         => $post->post_type === 'post' ? 'BlogPosting' : 'Article',
			'headline'      => get_the_title( $post ),
			'datePublished' => mysql2date( 'c', $post->post_date_gmt ),
			'dateModified'  => mysql2date( 'c', $post->post_modified_gmt ),
			'url'           => get_permalink( $post ),
			'author'        => [
				'@type' => 'Person',
				'name'  => $author_name,
			],
			'publisher'     => [
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			],
		];

		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$schema['description'] = wp_strip_all_tags( $excerpt );
		}

		$thumb = get_the_post_thumbnail_url( $post, 'large' );
		if ( $thumb ) {
			$schema['image'] = $thumb;
		}

		return $schema;
	}

	public static function build_faq( $faqs ) {
		$entities = [];
		foreach ( $faqs as $faq ) {
			if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) { continue; }
			$entities[] = [
				'@type'          => 'Question',
				'name'           => $faq['question'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $faq['answer'],
				],
			];
		}
		if ( empty( $entities ) ) { return null; }
		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		];
	}

	private static function site_logo_url() {
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $src ) { return $src[0]; }
		}
		$icon_id = get_option( 'site_icon' );
		if ( $icon_id ) {
			$src = wp_get_attachment_image_src( $icon_id, 'full' );
			if ( $src ) { return $src[0]; }
		}
		return '';
	}
}

Gleo_Schema::init();
