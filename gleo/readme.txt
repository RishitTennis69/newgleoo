=== Gleo ===
Contributors: gleo
Tags: seo, geo, ai, llm, optimization
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Get your business mentioned by AI chatbots. Scan your site for Generative Engine Optimization (GEO) issues, apply one-click fixes, and track AI crawler traffic.

== Description ==

Gleo helps small businesses get cited by AI assistants (ChatGPT, Claude, Gemini, Perplexity) by applying GEO best practices to their WordPress site.

= Features =

* GEO Scan & Score across six categories: content, substance, structure, technical, trust, formatting
* One-click fixes for the highest-leverage issues:
  * Schema markup injection (Organization, Article, FAQ)
  * TL;DR / Key Takeaways blocks (generated from your existing content)
  * FAQ sections with FAQPage schema
  * Heading hierarchy normalization
  * Image alt text
  * Tone rewrites for conversational, 7th-grade reading level
  * Fact enrichment grounded in real web sources via Tavily
  * robots.txt allowlist for AI crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.)
  * Auto-generated /llms.txt
* AI Crawler Tracking — real hit logs from GPTBot, ClaudeBot, PerplexityBot, Google-Extended, and more
* Mention Probe — sample Gemini Flash with your queries to estimate brand mention rate over time
* Full changelog with one-click revert per change or a "revert all" nuclear button
* Edits are scoped to standard post_content; page-builder content (Elementor, Divi) is detected and skipped to avoid breaking layouts

= How it stays safe =

* Every Gleo edit creates a WP revision plus a Gleo changelog entry
* The auto-fix run is capped (default 10 pages) to limit cost and review burden
* Page-builder content is detected and skipped
* Content rewrites preserve theme styling — Gleo only writes semantic HTML inside `.gleo-*` wrappers that inherit your theme's colors, fonts, and spacing

= Required keys =

* Google Gemini API key (https://aistudio.google.com/) — for content generation and Mention Probe
* Tavily API key (https://tavily.com/) — for grounded fact enrichment

Both can be configured via the Settings tab, a .env file in the plugin directory, or constants in wp-config.php.

== Installation ==

1. Upload the `gleo` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Gleo in the admin sidebar
4. Add your Gemini and Tavily API keys in Settings
5. Click "Start scan", then "One-click fix"

== Changelog ==

= 0.1.0 =
* Initial MVP: scanner, scorer, one-click fixer, schema injection, llms.txt, robots.txt, crawler tracking, mention probe, changelog/revert.
