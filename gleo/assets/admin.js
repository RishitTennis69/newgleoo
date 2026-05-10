/**
 * Gleo admin app. Pure React via wp.element — zero build step.
 *
 * Tabs:
 *  - Overview: GEO score, category bars, recent activity
 *  - Issues: list of fixable issues, one-click fix
 *  - Crawlers: AI crawler hits dashboard
 *  - Probe: Mention Probe results + trend
 *  - Changelog: every Gleo edit + revert
 *  - Settings: API keys, probe config
 */
( function( wp ) {
	const { createElement: h, useState, useEffect, useCallback, useRef, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;

	apiFetch.use( apiFetch.createNonceMiddleware( window.GleoData.nonce ) );

	const cls = ( ...parts ) => parts.filter( Boolean ).join( ' ' );

	// -------- API helpers --------
	const NS = '/gleo/v1';
	const api = {
		status:    ()       => apiFetch( { path: NS + '/status' } ),
		preview:   ()       => apiFetch( { path: NS + '/preview' } ),
		scan:      ( ids )  => apiFetch( { path: NS + '/scan', method: 'POST', data: ids ? { post_ids: ids } : {} } ),
		scanLatest:()       => apiFetch( { path: NS + '/scan/latest' } ),
		fix:       ( issue )=> apiFetch( { path: NS + '/fix', method: 'POST', data: { issue } } ),
		fixAll:    ( ids )  => apiFetch( { path: NS + '/fix/all', method: 'POST', data: ids ? { post_ids: ids } : {} } ),
		changelog: ()       => apiFetch( { path: NS + '/changelog' } ),
		revert:    ( id )   => apiFetch( { path: NS + '/revert', method: 'POST', data: { change_id: id } } ),
		revertAll: ()       => apiFetch( { path: NS + '/revert', method: 'POST', data: { all: true } } ),
		crawlers:  ( days ) => apiFetch( { path: NS + '/crawlers' + ( days ? '?days=' + days : '' ) } ),
		probe:     ()       => apiFetch( { path: NS + '/probe' } ),
		probeRun:  ()       => apiFetch( { path: NS + '/probe/run', method: 'POST', data: {} } ),
		settingsGet: ()     => apiFetch( { path: NS + '/settings' } ),
		settingsSet: ( s )  => apiFetch( { path: NS + '/settings', method: 'POST', data: s } ),
	};

	// -------- Reusable bits --------
	function Button( { variant, onClick, disabled, children, small } ) {
		return h( 'button', {
			className: cls( 'gleo-btn', variant && 'gleo-btn--' + variant, small && 'gleo-btn--sm' ),
			onClick, disabled,
		}, children );
	}

	function Tag( { tone, children } ) {
		return h( 'span', { className: cls( 'gleo-tag', tone && 'gleo-tag--' + tone ) }, children );
	}

	function HalfGauge( { score, band } ) {
		const pct = Math.max( 0, Math.min( 100, score || 0 ) );
		// Arc from (20,130) to (240,130) via top — length ~ π * 110 ≈ 345.6
		const arcLen = Math.PI * 110;
		const filled = ( pct / 100 ) * arcLen;
		const colors = {
			excellent: '#2D7A3E', good: '#2D7A3E',
			fair: '#B45309',
			poor: '#E11D48', critical: '#E11D48',
		};
		const color = colors[ band ] || '#5B5BD6';
		const arcD = 'M 20 130 A 110 110 0 0 1 240 130';
		return h( 'div', { className: 'gleo-gauge gleo-gauge--' + band },
			h( 'svg', { viewBox: '0 0 260 150' },
				h( 'path', { d: arcD, fill: 'none', stroke: 'rgba(0,0,0,0.06)', strokeWidth: 14, strokeLinecap: 'round' } ),
				h( 'path', {
					d: arcD, fill: 'none', stroke: color, strokeWidth: 14, strokeLinecap: 'round',
					strokeDasharray: filled.toFixed( 1 ) + ' ' + arcLen.toFixed( 1 ),
					style: { transition: 'stroke-dasharray 600ms ease' },
				} ),
			),
			h( 'div', { className: 'gleo-gauge__num' }, pct ),
			h( 'div', { className: 'gleo-gauge__band' },
				'● ', ( band || 'good' ).toUpperCase(),
			),
		);
	}

	function ScoreRing( { score, band } ) {
		const r = 56;
		const c = 2 * Math.PI * r;
		const offset = c - ( score / 100 ) * c;
		return h( 'div', { className: 'gleo-ring gleo-ring--' + band },
			h( 'svg', { width: 140, height: 140, viewBox: '0 0 140 140' },
				h( 'circle', { cx: 70, cy: 70, r, fill: 'none', stroke: 'var(--gleo-ring-bg)', strokeWidth: 12 } ),
				h( 'circle', {
					cx: 70, cy: 70, r, fill: 'none',
					stroke: 'currentColor', strokeWidth: 12, strokeLinecap: 'round',
					strokeDasharray: c, strokeDashoffset: offset,
					transform: 'rotate(-90 70 70)',
					style: { transition: 'stroke-dashoffset 600ms ease' },
				} ),
			),
			h( 'div', { className: 'gleo-ring__center' },
				h( 'div', { className: 'gleo-ring__num' }, score ),
				h( 'div', { className: 'gleo-ring__band' }, band ),
			),
		);
	}

	const CATEGORIES = {
		content:   { label: 'Content',    icon: '📝', color: '#ed7a3a', blurb: 'How readable and answer-shaped your copy is for chatbots.' },
		substance: { label: 'Substance',  icon: '🧪', color: '#5b9bd5', blurb: 'Whether your pages contain concrete, citable facts.' },
		structure: { label: 'Structure',  icon: '🏗',  color: '#b09cd8', blurb: 'Heading hierarchy, schema markup, and content blocks chatbots can parse.' },
		technical: { label: 'Technical',  icon: '⚙️', color: '#2c7a7b', blurb: 'robots.txt, llms.txt, and crawl access for AI bots.' },
		trust:     { label: 'Trust',      icon: '🛡',  color: '#f1b27c', blurb: 'Authorship, citations, and signals that build model confidence.' },
	};

	// Every check Gleo runs per category — used to show green ticks when nothing is wrong
	const CATEGORY_CHECKLIST = {
		content:   [
			'Conversational, readable tone (short sentences, plain language)',
			'Content is long enough to be citable (300+ words)',
		],
		substance: [
			'Concrete numbers, stats, or dates present',
			'Specific, verifiable claims (not vague)',
		],
		structure: [
			'H1 heading on every page',
			'Logical heading hierarchy (H1 → H2 → H3, no skipped levels)',
			'TL;DR or key takeaways section at the top',
			'FAQ section with FAQPage schema markup',
		],
		technical: [
			'AI crawlers allowed in robots.txt (GPTBot, ClaudeBot, PerplexityBot, Google-Extended)',
			'/llms.txt file exists and up to date',
			'Organization or LocalBusiness schema on homepage',
			'All images have descriptive alt text',
			'Article schema on blog posts',
		],
		trust: [
			'Site tagline / description set',
			'Author bylines on posts',
		],
	};

	function bubbleStyle( color ) {
		// Soft tinted background, full-color icon
		return { background: color + '26', color };
	}

	function CategoryCardGrid( { byCategory, issues, openKey, setOpenKey } ) {
		const ordered = Object.keys( CATEGORIES ).slice().sort( ( a, b ) => {
			const pa = ( byCategory[ a ] || { percent: 100 } ).percent;
			const pb = ( byCategory[ b ] || { percent: 100 } ).percent;
			return pa - pb;
		} );
		const groups = {};
		Object.keys( CATEGORIES ).forEach( k => { groups[ k ] = 0; } );
		( issues || [] ).forEach( iss => { if ( groups[ iss.category ] !== undefined ) { groups[ iss.category ]++; } } );

		return h( 'div', { className: 'gleo-cgrid' },
			ordered.map( key => {
				const meta = CATEGORIES[ key ];
				const c = byCategory[ key ] || { percent: 0, weight: 0 };
				const tone = c.percent >= 80 ? 'good' : c.percent >= 50 ? 'warn' : 'bad';
				const status = tone === 'good' ? { label: 'good' } : tone === 'warn' ? { label: 'needs work' } : { label: 'action needed' };
				const isOpen = openKey === key;
				return h( 'button', {
					key,
					className: cls( 'gleo-ccard', isOpen && 'gleo-ccard--active' ),
					onClick: () => setOpenKey( isOpen ? null : key ),
				},
					h( 'div', { className: 'gleo-ccard__head' },
						h( 'div', { className: 'gleo-ccard__left' },
							h( 'div', { className: 'gleo-ccard__bubble', style: bubbleStyle( meta.color ) }, meta.icon ),
							h( 'div', null,
								h( 'div', { className: 'gleo-ccard__name' }, meta.label ),
								h( 'div', { className: 'gleo-ccard__sub' }, ( groups[ key ] || 0 ) + ' issue' + ( groups[ key ] === 1 ? '' : 's' ) ),
							),
						),
						h( 'div', { className: 'gleo-ccard__pct' }, c.percent + '%' ),
					),
					h( 'div', { className: 'gleo-ccard__track' },
						h( 'div', { className: 'gleo-ccard__fill gleo-ccard__fill--' + tone, style: { width: c.percent + '%' } } ),
					),
					h( 'div', { className: 'gleo-ccard__foot' },
						h( 'span', null, 'weight ', c.weight, '%' ),
						h( 'span', { className: 'gleo-pill gleo-pill--' + tone }, status.label ),
					),
				);
			} ),
		);
	}

	function CategoryDetail( { catKey, issues, pages, onClose, onFix, fixingId } ) {
		const meta = CATEGORIES[ catKey ];
		if ( ! meta ) { return null; }

		const catIssues = ( issues || [] ).filter( i => i.category === catKey );

		// Build page title lookup
		const pageMap = {};
		( pages || [] ).forEach( p => { pageMap[ p.post_id ] = p; } );

		// Strip ": Page Title" suffix the scanner appends so we can show it separately
		function parseIssue( iss ) {
			const page = iss.post_id ? pageMap[ iss.post_id ] : null;
			let desc = iss.title || '';
			if ( page && desc.endsWith( ': ' + page.title ) ) {
				desc = desc.slice( 0, desc.length - page.title.length - 2 );
			}
			return { desc, page };
		}

		// Group: site-wide first, then per-page
		const siteWide = catIssues.filter( i => ! i.post_id );
		const perPage  = catIssues.filter( i => !! i.post_id );

		// Unique pages that have issues in this category, preserving report order
		const pageIds = [];
		perPage.forEach( i => { if ( ! pageIds.includes( i.post_id ) ) { pageIds.push( i.post_id ); } } );

		const checklist = CATEGORY_CHECKLIST[ catKey ] || [];

		function IssueRow( { iss } ) {
			const { desc, page } = parseIssue( iss );
			return h( 'div', { className: cls( 'gleo-check', 'gleo-check--' + iss.severity ) },
				h( 'div', { className: 'gleo-check__dot' } ),
				h( 'div', { className: 'gleo-check__body' },
					h( 'div', { className: 'gleo-check__title' }, desc ),
					iss.detail && h( 'div', { className: 'gleo-check__detail' }, iss.detail ),
					iss.auto_fixable && onFix && h( 'div', { className: 'gleo-check__fix-hint' },
						h( 'button', {
							className: 'gleo-check__fix-btn',
							disabled: fixingId === iss.id,
							onClick: e => { e.stopPropagation(); onFix( iss ); },
						}, fixingId === iss.id ? 'Fixing…' : '⚡ Fix this' ),
					),
				),
				h( 'div', { className: 'gleo-check__tags' },
					h( Tag, { tone: iss.severity }, iss.severity ),
					iss.auto_fixable && h( Tag, { tone: 'good' }, 'auto-fix' ),
				),
			);
		}

		return h( 'div', { className: 'gleo-cdetail' },
			h( 'div', { className: 'gleo-cdetail__head' },
				h( 'div', null,
					h( 'div', { className: 'gleo-cdetail__title' }, meta.icon, ' ', meta.label ),
					h( 'div', { className: 'gleo-cdetail__blurb' }, meta.blurb ),
				),
				h( 'button', { className: 'gleo-cdetail__close', onClick: onClose }, '×' ),
			),

			// Site-wide issues (no specific page)
			siteWide.length > 0 && h( 'div', { className: 'gleo-cb__checks' },
				h( 'div', { className: 'gleo-cdetail__group-label' }, 'Site-wide' ),
				siteWide.map( iss => h( IssueRow, { key: iss.id, iss } ) ),
			),

			// Per-page issues grouped by page
			pageIds.map( pid => {
				const page = pageMap[ pid ];
				const pageIssues = perPage.filter( i => i.post_id === pid );
				return h( 'div', { key: pid, className: 'gleo-cb__checks gleo-cdetail__page-group' },
					h( 'div', { className: 'gleo-cdetail__group-label' },
						page
							? h( 'a', { href: page.permalink, target: '_blank', rel: 'noopener', className: 'gleo-cdetail__page-link' }, page.title )
							: 'Post #' + pid,
						h( 'span', { className: 'gleo-cdetail__issue-count' }, pageIssues.length + ' issue' + ( pageIssues.length === 1 ? '' : 's' ) ),
					),
					pageIssues.map( iss => h( IssueRow, { key: iss.id, iss } ) ),
				);
			} ),

			catIssues.length === 0 && h( 'div', { className: 'gleo-cdetail__empty' }, '✓ No issues found in this category.' ),
		);
	}

	function OverviewRail( { issues, onFixOne, fixingId, onOpenTour } ) {
		const [ changeCount, setChangeCount ] = useState( null );
		useEffect( () => {
			api.changelog().then( r => {
				const live = ( r.entries || [] ).filter( e => ! e.reverted );
				setChangeCount( live.length );
			} ).catch( () => {} );
		}, [] );

		const sevRank = { high: 0, medium: 1, low: 2 };
		const top = ( issues || [] ).slice().sort( ( a, b ) => {
			const af = a.auto_fixable ? 0 : 1;
			const bf = b.auto_fixable ? 0 : 1;
			if ( af !== bf ) { return af - bf; }
			return ( sevRank[ a.severity ] ?? 9 ) - ( sevRank[ b.severity ] ?? 9 );
		} )[ 0 ];

		return h( Fragment, null,
			top && h( 'div', { className: 'gleo-rail-card gleo-rail-card--cta' },
				h( 'div', { className: 'gleo-rail-card__title' }, 'Next best action' ),
				h( 'div', { className: 'gleo-rail-cta__head' }, top.title || 'Run one-click fix' ),
				h( 'div', { className: 'gleo-rail-cta__sub' }, top.detail || '' ),
				top.auto_fixable && onFixOne && h( Button, {
					variant: 'primary', small: true,
					disabled: fixingId === top.id,
					onClick: () => onFixOne( top ),
				}, fixingId === top.id ? 'Fixing…' : '⚡ Fix now' ),
			),
			! top && h( 'div', { className: 'gleo-rail-card' },
				h( 'div', { className: 'gleo-rail-card__title' }, 'Status' ),
				h( 'div', { style: { fontSize: 13, color: 'var(--mint-dark)', fontWeight: 600 } }, '✓ All auto-fixable issues resolved' ),
			),
			changeCount > 0 && onOpenTour && h( 'div', { className: 'gleo-rail-card' },
				h( 'div', { className: 'gleo-rail-card__title' }, 'Changes applied' ),
				h( 'div', { style: { fontSize: 13, marginBottom: 10 } },
					h( 'strong', null, changeCount ),
					' edit' + ( changeCount === 1 ? '' : 's' ) + ' live on your site',
				),
				h( Button, { variant: 'ghost', small: true, onClick: onOpenTour }, 'See before & after →' ),
			),
		);
	}

	function ProgressBar( { value, label } ) {
		return h( 'div', { className: 'gleo-progress' },
			h( 'div', { className: 'gleo-progress__label' }, label ),
			h( 'div', { className: 'gleo-progress__track' },
				h( 'div', { className: 'gleo-progress__fill', style: { width: Math.min( 100, value ) + '%' } } ),
			),
		);
	}

	function EmptyState( { title, body, action } ) {
		return h( 'div', { className: 'gleo-empty' },
			h( 'div', { className: 'gleo-empty__title' }, title ),
			body && h( 'div', { className: 'gleo-empty__body' }, body ),
			action,
		);
	}

	function FixAllModal( { modal, setModal, onConfirm } ) {
		const [ sel, setSel ] = useState( modal.selected || [] );
		const editable = ( modal.pages || [] ).filter( p => ! p.is_page_builder );
		const skipped  = ( modal.pages || [] ).filter( p => p.is_page_builder );
		const allEditableIds = editable.map( p => p.post_id );
		const allSelected = sel.length === allEditableIds.length && allEditableIds.every( id => sel.includes( id ) );
		const cap = modal.cap || 10;
		const overCap = sel.length > cap;
		const toggle = ( id ) => setSel( s => s.includes( id ) ? s.filter( x => x !== id ) : [ ...s, id ] );

		return h( 'div', { className: 'gleo-modal-overlay', onClick: () => setModal( null ) },
			h( 'div', { className: 'gleo-modal', onClick: e => e.stopPropagation() },
				h( 'div', { className: 'gleo-modal__head' },
					h( 'div', null,
						h( 'div', { className: 'gleo-modal__title' }, 'One-click optimize' ),
						h( 'div', { className: 'gleo-modal__sub' }, 'Pick the pages Gleo will edit. Every change is reversible from the Changelog tab.' ),
					),
					h( 'button', { className: 'gleo-modal__close', onClick: () => setModal( null ) }, '×' ),
				),
				h( 'div', { className: 'gleo-modal__body' },
					h( 'div', { className: 'gleo-modal__row' },
						h( 'div', { className: 'gleo-muted' },
							h( 'strong', null, sel.length ), ' of ', editable.length, ' editable pages selected · cap ', cap, ' per run',
						),
						h( 'div', { style: { display: 'flex', gap: 8 } },
							h( Button, { variant: 'ghost', small: true, onClick: () => setSel( allEditableIds ) }, 'Select all' ),
							h( Button, { variant: 'ghost', small: true, onClick: () => setSel( [] ) }, 'Clear' ),
						),
					),
					overCap && h( 'div', { className: 'gleo-modal__warn' },
						'You picked ', sel.length, ' pages but the cap is ', cap, '. Only the first ', cap, ' will be processed (raise the cap in Settings).',
					),
					h( 'table', { className: 'gleo-table' },
						h( 'thead', null, h( 'tr', null,
							h( 'th', { style: { width: 32 } },
								h( 'input', { type: 'checkbox', checked: allSelected, onChange: e => setSel( e.target.checked ? allEditableIds : [] ) } ),
							),
							h( 'th', null, 'Title' ),
							h( 'th', null, 'Type' ),
							h( 'th', null, 'Words' ),
						) ),
						h( 'tbody', null,
							editable.map( p => h( 'tr', { key: p.post_id, className: ! sel.includes( p.post_id ) ? 'gleo-row--muted' : '' },
								h( 'td', null, h( 'input', { type: 'checkbox', checked: sel.includes( p.post_id ), onChange: () => toggle( p.post_id ) } ) ),
								h( 'td', null, p.title || '(untitled)', p.role && h( Tag, { tone: 'good' }, p.role ) ),
								h( 'td', null, p.post_type ),
								h( 'td', null, p.word_count ),
							) ),
						),
					),
					skipped.length > 0 && h( 'div', { className: 'gleo-modal__skipped' },
						h( 'div', { className: 'gleo-card__label' }, 'Page-builder pages — scan only' ),
						h( 'div', { className: 'gleo-muted', style: { fontSize: 12 } },
							'Gleo will never touch the layout of: ', skipped.map( p => p.title ).join( ', ' ),
						),
					),
				),
				h( 'div', { className: 'gleo-modal__foot' },
					h( Button, { variant: 'ghost', onClick: () => setModal( null ) }, 'Cancel' ),
					h( Button, {
						variant: 'primary',
						disabled: sel.length === 0,
						onClick: () => onConfirm( sel ),
					}, sel.length === 0 ? 'Pick at least one page' : 'Apply fixes to ' + Math.min( sel.length, cap ) + ' page' + ( Math.min( sel.length, cap ) === 1 ? '' : 's' ) ),
				),
			),
		);
	}

	function fixTypeLabel( fixType ) {
		switch ( fixType ) {
			case 'tldr_injection':        return 'Added a TL;DR summary';
			case 'faq_injection':         return 'Added an FAQ section';
			case 'stat_injection':        return 'Injected verifiable statistics';
			case 'heading_normalization': return 'Fixed heading hierarchy';
			case 'rewrite_tone':          return 'Rewrote for conversational tone';
			case 'robots_txt':            return 'Opened robots.txt to AI crawlers';
			case 'llms_txt':              return 'Created /llms.txt';
			case 'organization_schema':   return 'Added Organization schema';
			case 'article_schema':        return 'Added Article schema';
			default:                      return 'Updated content';
		}
	}

	function whyCopy( fixType ) {
		switch ( fixType ) {
			case 'tldr_injection':        return 'Chatbots cite pages that answer the question fast. A TL;DR up top gives them a clean, quotable answer.';
			case 'faq_injection':         return 'FAQ schema gets surfaced in AI answers and rich results. Each Q&A is one more chance to be cited.';
			case 'stat_injection':        return 'Concrete numbers with sources are far more citable than vague claims. Models prefer specific facts.';
			case 'heading_normalization': return 'Clean H1→H2→H3 hierarchy lets crawlers parse your content. Skipped levels confuse them.';
			case 'rewrite_tone':          return 'AI favours direct, readable prose. Shorter sentences and plain language get quoted more often.';
			case 'robots_txt':            return 'If AI bots can\'t crawl your pages, you can\'t be cited. This opens the door.';
			case 'llms_txt':              return 'llms.txt is the AI-era robots.txt. It tells models which pages best represent your site.';
			case 'organization_schema':   return 'Organization schema tells models who you are. Missing it makes attribution unreliable.';
			case 'article_schema':        return 'Article schema gives LLMs the author, date, and topic of your post — making it far more citable.';
			default:                      return 'This change makes your content easier for AI models to read, parse, and cite.';
		}
	}

	// Selector hints by fix_type — used to scroll+highlight the changed element in the live iframe
	const FIX_SELECTOR = {
		tldr_injection:        '.gleo-tldr, [class*="tldr"], [id*="tldr"]',
		faq_injection:         '.gleo-faq, [class*="faq"], [id*="faq"]',
		stat_injection:        'p',
		heading_normalization: 'h1, h2',
		rewrite_tone:          'p',
		robots_txt:            null,
		llms_txt:              null,
		organization_schema:   null,
		article_schema:        null,
	};

	// Tour — live site iframe + annotation panel
	function Tour( { entries, pages, onClose } ) {
		if ( ! entries || entries.length === 0 ) { return null; }

		const pageMap = {};
		( pages || [] ).forEach( p => { pageMap[ p.post_id ] = p; } );

		// Group entries by page so the iframe only reloads when moving to a new page
		const groups = [];
		const seen = new Set();
		entries.forEach( e => {
			const key = e.post_id || '__site__';
			if ( ! seen.has( key ) ) {
				seen.add( key );
				groups.push( {
					page: e.post_id ? pageMap[ e.post_id ] : null,
					steps: entries.filter( x => ( x.post_id || '__site__' ) === key ),
				} );
			}
		} );

		const [ gIdx, setGIdx ] = useState( 0 );
		const [ sIdx, setSIdx ] = useState( 0 );
		const [ showBefore, setShowBefore ] = useState( false );
		const [ iframeReady, setIframeReady ] = useState( false );
		const iframeRef = useRef( null );

		const group = groups[ gIdx ] || groups[ 0 ];
		const step  = group.steps[ sIdx ];
		const isLastGroup = gIdx >= groups.length - 1;
		const isLastStep  = sIdx >= group.steps.length - 1;

		const goNext = () => {
			setShowBefore( false );
			if ( ! isLastStep ) {
				setSIdx( s => s + 1 );
			} else if ( ! isLastGroup ) {
				setGIdx( g => g + 1 );
				setSIdx( 0 );
				setIframeReady( false );
			} else {
				onClose();
			}
		};
		const goPrev = () => {
			setShowBefore( false );
			if ( sIdx > 0 ) {
				setSIdx( s => s - 1 );
			} else if ( gIdx > 0 ) {
				setGIdx( g => g - 1 );
				setSIdx( groups[ gIdx - 1 ].steps.length - 1 );
			}
		};
		const isFirst = gIdx === 0 && sIdx === 0;

		// Total step count across all groups
		const totalSteps = groups.reduce( ( acc, g ) => acc + g.steps.length, 0 );
		const currentStep = groups.slice( 0, gIdx ).reduce( ( acc, g ) => acc + g.steps.length, 0 ) + sIdx + 1;

		// When step changes, highlight the element in the iframe
		useEffect( () => {
			if ( ! iframeReady || ! iframeRef.current || ! group.page ) { return; }
			const sel = FIX_SELECTOR[ step.fix_type ];
			if ( ! sel ) { return; }
			try {
				const doc = iframeRef.current.contentDocument || iframeRef.current.contentWindow.document;
				if ( ! doc || ! doc.body ) { return; }
				const el = doc.querySelector( sel );
				if ( ! el ) { return; }
				el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				const orig = el.getAttribute( 'style' ) || '';
				el.setAttribute( 'style', orig + '; outline: 3px solid #2D7A3E !important; outline-offset: 6px; border-radius: 4px;' );
				setTimeout( () => { el.setAttribute( 'style', orig ); }, 2400 );
			} catch ( _ ) { /* cross-origin or not ready */ }
		}, [ sIdx, iframeReady ] );

		const before = ( step && step.before ) || '';
		const why    = whyCopy( step.fix_type );
		const label  = fixTypeLabel( step.fix_type );

		return h( 'div', { className: 'gleo-tour-overlay' },
			h( 'div', { className: 'gleo-live-tour' },
				// ── Left: live iframe or site-change placeholder ──
				h( 'div', { className: 'gleo-live-tour__frame' },
					group.page
						? h( 'iframe', {
							ref: iframeRef,
							src: group.page.permalink,
							className: 'gleo-live-tour__iframe',
							onLoad: () => setIframeReady( true ),
						} )
						: h( 'div', { className: 'gleo-live-tour__site-bg' },
							h( 'div', { className: 'gleo-live-tour__site-icon' }, '🌐' ),
							h( 'div', { className: 'gleo-live-tour__site-label' }, 'Site-wide change' ),
							h( 'div', { className: 'gleo-live-tour__site-sub' }, 'This fix affects your whole site, not one specific page.' ),
						),
					// Pulse indicator while iframe loads
					group.page && ! iframeReady && h( 'div', { className: 'gleo-live-tour__loading' },
						h( 'div', { className: 'gleo-fix-overlay__spinner' } ),
						h( 'div', { style: { fontSize: 13, color: '#6b7280', marginTop: 12 } }, 'Loading your site…' ),
					),
				),

				// ── Right: annotation panel ──
				h( 'div', { className: 'gleo-live-tour__panel' },
					// Header
					h( 'div', { className: 'gleo-live-tour__panel-head' },
						h( 'div', null,
							h( 'div', { className: 'gleo-live-tour__step-count' }, currentStep + ' / ' + totalSteps ),
							h( 'div', { className: 'gleo-tour__dots' },
								Array.from( { length: totalSteps }, ( _, i ) => h( 'div', {
									key: i,
									className: cls( 'gleo-tour__dot', i < currentStep - 1 && 'gleo-tour__dot--done', i === currentStep - 1 && 'gleo-tour__dot--now' ),
								} ) ),
							),
						),
						h( 'button', { className: 'gleo-modal__close', onClick: onClose }, '×' ),
					),

					// Step content
					h( 'div', { className: 'gleo-live-tour__panel-body' },
						h( 'div', { className: 'gleo-live-tour__label' }, label ),
						group.page && h( 'div', { className: 'gleo-live-tour__page-name' },
							h( 'a', { href: group.page.permalink, target: '_blank', rel: 'noopener' }, group.page.title + ' ↗' ),
						),
						why && h( 'div', { className: 'gleo-live-tour__why' }, why ),

						// Before compare toggle
						before && h( 'div', { className: 'gleo-live-tour__compare' },
							h( 'button', {
								className: cls( 'gleo-live-tour__compare-btn', showBefore && 'gleo-live-tour__compare-btn--active' ),
								onClick: () => setShowBefore( b => ! b ),
							}, showBefore ? 'Hide original' : 'Compare with before' ),
							showBefore && h( 'div', { className: 'gleo-live-tour__before-text' }, before.slice( 0, 800 ) ),
						),
					),

					// Footer nav
					h( 'div', { className: 'gleo-live-tour__panel-foot' },
						h( Button, { variant: 'ghost', small: true, onClick: goPrev, disabled: isFirst }, '← Back' ),
						h( Button, { variant: 'primary', small: true, onClick: goNext },
							isLastGroup && isLastStep ? 'Done ✓' : isLastStep ? 'Next page →' : 'Next →',
						),
					),
				),
			),
		);
	}

	// Full-screen overlay shown during and immediately after one-click fix
	function FixingOverlay( { phase, onTour, onDismiss } ) {
		return h( 'div', { className: 'gleo-fix-overlay' },
			h( 'div', { className: 'gleo-fix-overlay__card' },
				phase.running
					? h( Fragment, null,
						h( 'div', { className: 'gleo-fix-overlay__spinner' } ),
						h( 'div', { className: 'gleo-fix-overlay__stage' }, phase.stage ),
						h( 'div', { className: 'gleo-fix-overlay__bar' },
							h( 'div', { className: 'gleo-fix-overlay__fill', style: { width: phase.pct + '%' } } ),
						),
						h( 'div', { className: 'gleo-fix-overlay__hint' }, 'AI is optimizing your content — don\'t close this tab' ),
					)
					: h( Fragment, null,
						h( 'div', { className: 'gleo-fix-overlay__check' }, '✓' ),
						h( 'div', { className: 'gleo-fix-overlay__done-title' },
							phase.applied > 0
								? phase.applied + ' fix' + ( phase.applied === 1 ? '' : 'es' ) + ' applied to your site'
								: 'Done',
						),
						h( 'div', { className: 'gleo-fix-overlay__done-sub' }, 'Every change is live — and fully reversible from the Changelog tab.' ),
						h( 'div', { className: 'gleo-fix-overlay__done-actions' },
							phase.tourEntries && phase.tourEntries.length > 0
								? h( Button, { variant: 'primary', onClick: onTour }, 'Walk me through what changed →' )
								: null,
							h( Button, { variant: 'ghost', onClick: onDismiss }, 'Back to dashboard' ),
						),
					),
			),
		);
	}

	// -------- App --------
	function App() {
		const [ tab, setTab ] = useState( 'overview' );
		const [ status, setStatus ] = useState( null );
		const [ report, setReport ] = useState( null );
		const [ scanning, setScanning ] = useState( false );
		const [ scanProgress, setScanProgress ] = useState( null );
		const [ fixPhase, setFixPhase ] = useState( null ); // null | { running, stage, pct, applied?, tourEntries? }
		const [ toast, setToast ] = useState( null );
		const [ selectedIds, setSelectedIds ] = useState( null );
		const [ fixModal, setFixModal ] = useState( null );
		const [ tour, setTour ] = useState( null ); // null | { entries, pages }

		const showToast = useCallback( ( msg, tone ) => {
			setToast( { msg, tone: tone || 'info' } );
			setTimeout( () => setToast( null ), 4000 );
		}, [] );

		const openTourFromChangelog = useCallback( async () => {
			try {
				const cl = await api.changelog();
				const live = ( cl.entries || [] ).filter( e => ! e.reverted && ( e.before || e.after ) );
				if ( live.length > 0 ) {
					setTour( { entries: live, pages: report ? ( report.pages || [] ) : [] } );
				} else { showToast( 'No changes recorded yet.', 'info' ); }
			} catch ( e ) { showToast( 'Could not load changes.', 'error' ); }
		}, [ showToast, report ] );

		useEffect( () => {
			api.status().then( setStatus ).catch( () => {} );
			api.scanLatest().then( r => { if ( r && ! r.empty ) { setReport( r ); } } ).catch( () => {} );
		}, [] );

		const runScan = async () => {
			setScanning( true );
			setScanProgress( { stage: 'Scanning your site…', pct: 25 } );
			try {
				const r = await api.scan( selectedIds && selectedIds.length ? selectedIds : null );
				setReport( r );
				setScanProgress( { stage: 'Done.', pct: 100 } );
				setTimeout( () => setScanProgress( null ), 600 );
				showToast( 'Scan complete', 'success' );
			} catch ( e ) {
				showToast( 'Scan failed: ' + ( e.message || 'unknown' ), 'error' );
				setScanProgress( null );
			} finally {
				setScanning( false );
			}
		};

		const openFixModal = async () => {
			let preview;
			try { preview = await api.preview(); } catch ( e ) { preview = null; }
			if ( ! preview || ! preview.pages || preview.pages.length === 0 ) {
				showToast( 'No pages found to fix.', 'error' );
				return;
			}
			// Default selection: pages flagged will_auto_edit (or selectedIds if user already chose).
			const defaults = ( selectedIds && selectedIds.length )
				? preview.pages.filter( p => selectedIds.includes( p.post_id ) ).map( p => p.post_id )
				: preview.pages.filter( p => p.will_auto_edit ).map( p => p.post_id );
			setFixModal( { pages: preview.pages, cap: preview.autofix_cap, selected: defaults } );
		};

		const runFixAll = async ( ids ) => {
			setFixModal( null );
			setFixPhase( { running: true, stage: 'Reading your pages…', pct: 15 } );
			const t1 = setTimeout( () => setFixPhase( { running: true, stage: 'Generating with AI…', pct: 42 } ), 900 );
			const t2 = setTimeout( () => setFixPhase( { running: true, stage: 'Writing to your pages…', pct: 68 } ), 2600 );
			const t3 = setTimeout( () => setFixPhase( { running: true, stage: 'Re-scanning your site…', pct: 88 } ), 4200 );
			try {
				const r = await api.fixAll( ids && ids.length ? ids : null );
				clearTimeout( t1 ); clearTimeout( t2 ); clearTimeout( t3 );
				setReport( r.rescan );
				const applied = ( r.applied ? r.applied.length : 0 );
				// Load all changelog entries so the tour is consistent with the rail button
				let tourEntries = [];
				try {
					const fresh = await api.changelog();
					tourEntries = ( fresh.entries || [] ).filter( e => ! e.reverted );
				} catch ( _ ) {}
				setFixPhase( { running: false, stage: '', pct: 100, applied, tourEntries } );
			} catch ( e ) {
				clearTimeout( t1 ); clearTimeout( t2 ); clearTimeout( t3 );
				showToast( 'Fix-all failed: ' + ( e.message || 'unknown' ), 'error' );
				setFixPhase( null );
			}
		};

		const tabs = [
			[ 'overview', 'Overview' ],
			[ 'monitoring', 'AI Monitoring' ],
			[ 'changelog', 'Changelog' ],
			[ 'settings', 'Settings' ],
		];

		const openTour = () => {
			const entries = fixPhase && fixPhase.tourEntries;
			setFixPhase( null );
			if ( entries && entries.length > 0 ) {
				setTour( { entries, pages: report ? ( report.pages || [] ) : [] } );
			}
		};

		return h( 'div', { className: 'gleo-app' },
			h( Header, { status, runScan, scanning, runFixAll: openFixModal, fixingAll: !! fixPhase, hasIssues: report && report.issues && report.issues.length > 0 } ),
			fixModal && h( FixAllModal, {
				modal: fixModal,
				setModal: setFixModal,
				onConfirm: runFixAll,
			} ),
			fixPhase && h( FixingOverlay, {
				phase: fixPhase,
				onTour: openTour,
				onDismiss: () => setFixPhase( null ),
			} ),
			tour && h( Tour, {
				entries: tour.entries,
				pages: tour.pages || [],
				onClose: () => setTour( null ),
			} ),
			scanProgress && h( ProgressBanner, scanProgress ),
			h( 'nav', { className: 'gleo-tabs' },
				tabs.map( ( [ id, label ] ) => h( 'button', {
					key: id,
					className: cls( 'gleo-tab', tab === id && 'gleo-tab--active' ),
					onClick: () => setTab( id ),
				}, label ) ),
			),
			h( 'div', { className: 'gleo-body' },
				tab === 'overview' && h( OverviewTab, { report, status, runScan, scanning, selectedIds, setSelectedIds, setReport, showToast, onOpenTour: openTourFromChangelog } ),
				tab === 'monitoring' && h( AIMonitoringTab, { showToast, status } ),
				tab === 'changelog' && h( ChangelogTab, { showToast } ),
				tab === 'settings' && h( SettingsTab, { showToast, status, setStatus } ),
			),
			toast && h( 'div', { className: 'gleo-toast gleo-toast--' + toast.tone }, toast.msg ),
		);
	}

	function Header( { status, runScan, scanning, runFixAll, fixingAll, hasIssues } ) {
		return h( 'header', { className: 'gleo-header' },
			h( 'div', { className: 'gleo-header__brand' },
				h( 'div', { className: 'gleo-header__title' }, 'Gleo' ),
				h( 'div', { className: 'gleo-header__sub' }, ( window.GleoData.siteName || '' ) ),
			),
			h( 'div', { className: 'gleo-header__actions' },
				h( Button, { variant: 'ghost', onClick: runScan, disabled: scanning }, scanning ? 'Scanning…' : 'Re-scan' ),
				h( Button, { variant: 'primary', onClick: runFixAll, disabled: fixingAll || ! hasIssues }, fixingAll ? 'Fixing…' : 'One-click fix' ),
			),
		);
	}

	function ProgressBanner( { stage, pct } ) {
		return h( 'div', { className: 'gleo-progress-banner' },
			h( 'div', { className: 'gleo-progress-banner__label' }, stage ),
			h( 'div', { className: 'gleo-progress-banner__track' },
				h( 'div', { className: 'gleo-progress-banner__fill', style: { width: pct + '%' } } ),
			),
		);
	}

	// -------- Tabs --------
	function PreviewPanel( { selectedIds, setSelectedIds } ) {
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( null );
		useEffect( () => {
			api.preview().then( d => {
				setData( d );
				if ( selectedIds === null ) {
					// Default: select all editable + scannable pages
					setSelectedIds( ( d.pages || [] ).map( p => p.post_id ) );
				}
			} ).catch( e => setError( e.message || 'Failed to load page list' ) );
		}, [] );

		const allIds = data ? ( data.pages || [] ).map( p => p.post_id ) : [];
		const allSelected = data && selectedIds && selectedIds.length === allIds.length;
		const noneSelected = selectedIds && selectedIds.length === 0;
		const toggle = ( id ) => {
			const sel = selectedIds || [];
			if ( sel.includes( id ) ) {
				setSelectedIds( sel.filter( x => x !== id ) );
			} else {
				setSelectedIds( [ ...sel, id ] );
			}
		};
		const selectAll = () => setSelectedIds( allIds );
		const selectNone = () => setSelectedIds( [] );
		if ( error ) {
			return h( 'div', { className: 'gleo-card' },
				h( 'div', { className: 'gleo-card__label' }, 'What Gleo will look at' ),
				h( 'div', { className: 'gleo-muted' }, 'Could not load page list: ' + error ),
			);
		}
		if ( ! data ) { return h( 'div', { className: 'gleo-card' }, h( 'div', { className: 'gleo-muted' }, 'Loading page list…' ) ); }
		if ( ! data.pages || data.pages.length === 0 ) {
			return h( 'div', { className: 'gleo-card' },
				h( 'div', { className: 'gleo-card__label' }, 'What Gleo will look at' ),
				h( 'div', { className: 'gleo-muted' }, 'No published posts or pages found on this site yet. Publish at least one before scanning.' ),
			);
		}
		const sel = selectedIds || [];
		return h( 'div', { className: 'gleo-card' },
			h( 'div', { className: 'gleo-card__label' }, 'Pick pages to analyze' ),
			h( 'p', { className: 'gleo-muted', style: { marginTop: 0 } },
				'Choose which pages Gleo will scan and (when you click One-click fix) auto-edit. ',
				h( 'strong', null, sel.length ),
				' of ', allIds.length, ' selected. ',
				'Page-builder pages stay scan-only — Gleo never touches their layout.'
			),
			h( 'div', { className: 'gleo-row', style: { marginBottom: 12 } },
				h( 'div', { style: { display: 'flex', gap: 8 } },
					h( Button, { variant: 'ghost', small: true, onClick: selectAll, disabled: allSelected }, 'Select all' ),
					h( Button, { variant: 'ghost', small: true, onClick: selectNone, disabled: noneSelected }, 'Clear' ),
				),
			),
			h( 'table', { className: 'gleo-table' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { style: { width: 32 } },
						h( 'input', { type: 'checkbox', checked: !! allSelected, onChange: e => e.target.checked ? selectAll() : selectNone() } ),
					),
					h( 'th', null, 'Title' ),
					h( 'th', null, 'Type' ),
					h( 'th', null, 'Words' ),
					h( 'th', null, 'Notes' ),
				) ),
				h( 'tbody', null,
					data.pages.map( ( p ) => {
						const checked = sel.includes( p.post_id );
						return h( 'tr', { key: p.post_id, className: ! checked ? 'gleo-row--muted' : '' },
							h( 'td', null,
								h( 'input', { type: 'checkbox', checked, onChange: () => toggle( p.post_id ) } ),
							),
							h( 'td', null,
								h( 'a', { href: p.permalink, target: '_blank', rel: 'noopener' }, p.title ),
								p.role && h( Tag, { tone: 'good' }, p.role ),
							),
							h( 'td', null, p.post_type ),
							h( 'td', null, p.word_count ),
							h( 'td', null,
								p.is_page_builder ? h( Tag, { tone: 'warn' }, 'scan only — page builder' )
								: h( Tag, { tone: 'muted' }, 'editable' )
							),
						);
					} )
				)
			)
		);
	}

	function OverviewTab( { report, status, runScan, scanning, selectedIds, setSelectedIds, setReport, showToast, onOpenTour } ) {
		const [ fixingId, setFixingId ] = useState( null );
		const [ openKey, setOpenKey ] = useState( null );

		if ( ! report ) {
			return h( Fragment, null,
				h( EmptyState, {
					title: h( Fragment, null, 'Get cited by ', h( 'em', null, 'AI.' ), ' Not buried by it.' ),
					body: 'Gleo will analyze your site against six categories of Generative Engine Optimization best practices. Pick the pages to analyze below, then start the scan.',
					action: h( Button, { variant: 'primary', onClick: runScan, disabled: scanning }, scanning ? 'Scanning…' : 'Start scan' ),
				} ),
				h( PreviewPanel, { selectedIds, setSelectedIds } ),
			);
		}

		const score = report.score || { score: 0, band: 'critical', by_category: {}, issue_counts: {} };
		const counts = score.issue_counts || {};

		const fixOne = async ( issue ) => {
			setFixingId( issue.id );
			try {
				await api.fix( issue );
				const fresh = await api.scan( selectedIds && selectedIds.length ? selectedIds : null );
				setReport( fresh );
				showToast && showToast( 'Fixed: ' + ( issue.title || '' ).split( ':' )[0], 'success' );
			} catch ( e ) {
				showToast && showToast( e.message || 'Fix failed', 'error' );
			} finally {
				setFixingId( null );
			}
		};

		return h( Fragment, null,
			h( 'div', { className: 'gleo-card gleo-hero' },
				h( HalfGauge, { score: score.score, band: score.band } ),
				h( 'div', null,
					h( 'div', { className: 'gleo-card__label' }, 'GEO Score' ),
					h( 'div', { className: 'gleo-hero__copy' }, scoreCopy( score.band ) ),
					h( 'div', { className: 'gleo-kpi-grid' },
						h( 'div', { className: 'gleo-kpi' },
							h( 'div', { className: 'gleo-kpi__num' }, counts.total || 0 ),
							h( 'div', { className: 'gleo-kpi__lbl' }, 'Open issues' ),
						),
						h( 'div', { className: 'gleo-kpi' },
							h( 'div', { className: 'gleo-kpi__num gleo-kpi__num--bad' }, counts.high || 0 ),
							h( 'div', { className: 'gleo-kpi__lbl' }, 'High severity' ),
						),
						h( 'div', { className: 'gleo-kpi' },
							h( 'div', { className: 'gleo-kpi__num gleo-kpi__num--good' }, counts.auto_fixable || 0 ),
							h( 'div', { className: 'gleo-kpi__lbl' }, 'Auto-fixable' ),
						),
					),
				),
			),
			h( 'div', { className: 'gleo-overview' },
				h( 'div', { className: 'gleo-overview__main' },
					h( 'div', { className: 'gleo-section-head' },
						h( 'div', { className: 'gleo-section-title' }, 'Categories' ),
						h( 'div', { className: 'gleo-muted', style: { fontSize: 12 } }, 'Sorted worst first' ),
					),
					h( CategoryCardGrid, {
						byCategory: score.by_category || {},
						issues: report.issues || [],
						openKey,
						setOpenKey,
					} ),
					openKey && h( CategoryDetail, {
						catKey: openKey,
						issues: report.issues || [],
						pages: report.pages || [],
						onClose: () => setOpenKey( null ),
						onFix: fixOne,
						fixingId,
					} ),
					h( PreviewPanel, { selectedIds, setSelectedIds } ),
					( report.pages || [] ).length > 0 && h( 'div', { className: 'gleo-pages-preview' },
						h( 'div', { className: 'gleo-section-head' },
							h( 'div', { className: 'gleo-section-title' }, 'Your pages' ),
						),
						h( 'div', { className: 'gleo-pagelist' },
							( report.pages || [] ).slice( 0, 10 ).map( p => {
								const issueCount = ( p.issues && p.issues.length ) || 0;
								const tone = issueCount === 0 ? 'good' : issueCount <= 2 ? 'warn' : 'bad';
								return h( 'div', { key: p.post_id, className: 'gleo-pagelist__row' },
									h( 'div', { className: 'gleo-pagelist__info' },
										h( 'a', { href: p.permalink, target: '_blank', rel: 'noopener', className: 'gleo-pagelist__title' },
											p.title || '(untitled)',
										),
										h( 'div', { className: 'gleo-pagelist__meta' },
											p.post_type,
											' · ', p.word_count, ' words',
											p.is_page_builder && ' · scan only',
										),
									),
									h( 'div', { className: 'gleo-pagelist__right' },
										h( 'span', { className: 'gleo-pill gleo-pill--' + tone },
											issueCount === 0 ? '✓ clean' : issueCount + ' issue' + ( issueCount === 1 ? '' : 's' ),
										),
										h( 'a', { href: p.permalink, target: '_blank', rel: 'noopener', className: 'gleo-pagelist__preview' }, 'Preview site →' ),
									),
								);
							} ),
						),
					),
				),
				h( 'div', { className: 'gleo-overview__rail' },
					h( OverviewRail, {
						issues: report.issues || [],
						onFixOne: fixOne,
						fixingId,
						onOpenTour,
					} ),
				),
			),
		);
	}

	function scoreCopy( band ) {
		switch ( band ) {
			case 'excellent': return 'Strong GEO foundation. Keep monitoring.';
			case 'good':      return 'Solid. A few targeted fixes will push you higher.';
			case 'fair':      return 'Real wins available. Run one-click fix.';
			case 'poor':      return 'Significant gaps. Auto-fixes will move the needle.';
			default:          return 'Critical gaps. Start with one-click fix.';
		}
	}

	function IssuesTab( { report, showToast, setReport } ) {
		const [ fixingId, setFixingId ] = useState( null );
		if ( ! report ) {
			return h( EmptyState, { title: 'No scan yet', body: 'Run a scan from the Overview tab.' } );
		}
		const issues = report.issues || [];
		if ( issues.length === 0 ) {
			return h( EmptyState, { title: 'No issues found', body: 'Your site looks great. Re-scan periodically to catch regressions.' } );
		}

		const sevRank = { high: 0, medium: 1, low: 2 };
		const sorted = issues.slice().sort( ( a, b ) => ( sevRank[ a.severity ] || 9 ) - ( sevRank[ b.severity ] || 9 ) );

		const fixOne = async ( issue ) => {
			setFixingId( issue.id );
			try {
				await api.fix( issue );
				showToast( 'Fixed: ' + issue.title.split( ':' )[0], 'success' );
				const fresh = await api.scan();
				setReport( fresh );
			} catch ( e ) {
				showToast( e.message || 'Fix failed', 'error' );
			} finally {
				setFixingId( null );
			}
		};

		return h( 'div', { className: 'gleo-issues' },
			sorted.map( issue => h( 'div', { key: issue.id, className: 'gleo-issue gleo-issue--' + issue.severity },
				h( 'div', { className: 'gleo-issue__head' },
					h( Tag, { tone: issue.severity }, issue.severity ),
					h( Tag, null, issue.category ),
					issue.auto_fixable && h( Tag, { tone: 'good' }, 'auto-fixable' ),
				),
				h( 'div', { className: 'gleo-issue__title' }, issue.title ),
				h( 'div', { className: 'gleo-issue__detail' }, issue.detail ),
				h( 'div', { className: 'gleo-issue__actions' },
					issue.auto_fixable
						? h( Button, { variant: 'primary', small: true, disabled: fixingId === issue.id, onClick: () => fixOne( issue ) },
								fixingId === issue.id ? 'Fixing…' : 'Fix this' )
						: h( 'span', { className: 'gleo-muted' }, 'Manual: see recommendation' ),
				),
			) ),
		);
	}

	function AIMonitoringTab( { showToast, status } ) {
		// ── Crawlers section ──
		const [ crawlData, setCrawlData ] = useState( null );
		const [ days, setDays ] = useState( 30 );
		useEffect( () => { api.crawlers( days ).then( setCrawlData ).catch( () => {} ); }, [ days ] );

		// ── Probe section ──
		const [ probeData, setProbeData ] = useState( null );
		const [ running, setRunning ] = useState( false );
		const loadProbe = () => api.probe().then( setProbeData ).catch( () => {} );
		useEffect( loadProbe, [] );

		const runProbe = async () => {
			if ( ! status || ! status.has_gemini_key ) {
				showToast( 'Add a Gemini API key in Settings first.', 'error' );
				return;
			}
			setRunning( true );
			try { await api.probeRun(); await loadProbe(); showToast( 'Probe run complete', 'success' ); }
			catch ( e ) { showToast( e.message || 'Probe failed', 'error' ); }
			finally { setRunning( false ); }
		};

		const probeResults = ( probeData && probeData.results ) || [];
		const latest = probeResults[ 0 ];

		return h( Fragment, null,

			// ── Share of Voice ──
			h( 'div', { className: 'gleo-monitor-section' },
				h( 'div', { className: 'gleo-monitor-section__head' },
					h( 'div', null,
						h( 'div', { className: 'gleo-monitor-section__title' }, 'Share of Voice' ),
						h( 'div', { className: 'gleo-muted' }, 'How often your brand appears when Gemini answers your target queries.' ),
					),
					h( Button, { variant: 'primary', small: true, onClick: runProbe, disabled: running }, running ? 'Running…' : 'Run probe now' ),
				),
				latest
					? h( 'div', { className: 'gleo-card', style: { marginTop: 12 } },
						h( 'div', { className: 'gleo-card__label' }, 'Latest run · ' + latest.time ),
						h( 'div', { className: 'gleo-muted', style: { marginBottom: 12 } }, 'Brand: ' + latest.brand + ' · ' + latest.samples + ' samples per query' ),
						( latest.queries || [] ).map( ( q, i ) =>
							h( 'div', { key: i, className: 'gleo-probe-row' },
								h( 'div', { className: 'gleo-probe-row__q' }, q.query ),
								h( ProgressBar, { value: q.rate, label: q.rate + '% · ' + q.mentions + '/' + q.samples } ),
								q.snippets && q.snippets.length > 0 && h( 'details', { className: 'gleo-probe-row__snips' },
									h( 'summary', null, 'Sample snippets' ),
									q.snippets.map( ( s, j ) => h( 'blockquote', { key: j }, s ) ),
								),
							),
						),
					)
					: h( 'div', { className: 'gleo-muted', style: { marginTop: 12 } }, 'No probe runs yet. Configure your brand and queries in Settings, then click "Run probe now".' ),
			),

			// ── AI Bots ──
			h( 'div', { className: 'gleo-monitor-section' },
				h( 'div', { className: 'gleo-monitor-section__head' },
					h( 'div', { className: 'gleo-monitor-section__title' }, 'AI Bots' ),
					h( 'div', { className: 'gleo-row__select' },
						[ 7, 30, 90 ].map( d => h( 'button', {
							key: d,
							className: cls( 'gleo-chip', days === d && 'gleo-chip--active' ),
							onClick: () => setDays( d ),
						}, d + 'd' ) ),
					),
				),
				! crawlData
					? h( 'div', { className: 'gleo-muted', style: { marginTop: 12 } }, 'Loading…' )
					: h( Fragment, null,
						crawlData.total === 0
							? h( 'div', { className: 'gleo-muted', style: { marginTop: 12 } }, 'No AI crawler hits logged yet. After installing Gleo, hits start appearing as bots discover your site.' )
							: h( 'div', { className: 'gleo-bignum', style: { margin: '12px 0 8px' } }, crawlData.total, h( 'span', { style: { fontSize: 16, fontWeight: 400, color: 'var(--ink-dim)', marginLeft: 8 } }, 'hits' ) ),
						h( 'div', { className: 'gleo-grid gleo-grid--two', style: { marginTop: 12 } },
							h( 'div', { className: 'gleo-card' },
								h( 'div', { className: 'gleo-card__label' }, 'By bot' ),
								crawlData.by_bot.length === 0
									? h( 'div', { className: 'gleo-muted' }, 'No data yet.' )
									: h( 'table', { className: 'gleo-table' },
										h( 'thead', null, h( 'tr', null, h( 'th', null, 'Bot' ), h( 'th', null, 'Owner' ), h( 'th', null, 'Hits' ) ) ),
										h( 'tbody', null, crawlData.by_bot.map( ( b, i ) =>
											h( 'tr', { key: i }, h( 'td', null, b.bot_name ), h( 'td', null, b.bot_owner ), h( 'td', null, b.hits ) ),
										) ),
									),
							),
							h( 'div', { className: 'gleo-card' },
								h( 'div', { className: 'gleo-card__label' }, 'Most-crawled paths' ),
								crawlData.top_paths.length === 0
									? h( 'div', { className: 'gleo-muted' }, 'No data yet.' )
									: h( 'table', { className: 'gleo-table' },
										h( 'thead', null, h( 'tr', null, h( 'th', null, 'Path' ), h( 'th', null, 'Hits' ) ) ),
										h( 'tbody', null, crawlData.top_paths.map( ( p, i ) =>
											h( 'tr', { key: i }, h( 'td', null, h( 'code', null, p.path ) ), h( 'td', null, p.hits ) ),
										) ),
									),
							),
						),
						crawlData.daily && crawlData.daily.length > 0 && h( 'div', { className: 'gleo-card', style: { marginTop: 12 } },
							h( 'div', { className: 'gleo-card__label' }, 'Daily hits' ),
							h( Sparkline, { points: crawlData.daily.map( d => d.hits ), labels: crawlData.daily.map( d => d.day ) } ),
						),
					),
			),
		);
	}

	function Sparkline( { points, labels } ) {
		if ( ! points || points.length === 0 ) { return null; }
		const w = 600, hgt = 100, pad = 8;
		const max = Math.max( 1, ...points );
		const step = ( w - pad * 2 ) / Math.max( 1, points.length - 1 );
		const path = points.map( ( v, i ) => {
			const x = pad + i * step;
			const y = hgt - pad - ( v / max ) * ( hgt - pad * 2 );
			return ( i === 0 ? 'M' : 'L' ) + x.toFixed( 1 ) + ' ' + y.toFixed( 1 );
		} ).join( ' ' );
		return h( 'svg', { className: 'gleo-spark', viewBox: '0 0 ' + w + ' ' + hgt, preserveAspectRatio: 'none' },
			h( 'path', { d: path, fill: 'none', stroke: 'currentColor', strokeWidth: 2 } ),
		);
	}


	function ChangelogTab( { showToast } ) {
		const [ entries, setEntries ] = useState( [] );
		const load = () => api.changelog().then( r => setEntries( r.entries || [] ) ).catch( () => {} );
		useEffect( load, [] );

		const revertOne = async ( id ) => {
			if ( ! window.confirm( 'Revert this change?' ) ) { return; }
			try { await api.revert( id ); await load(); showToast( 'Reverted', 'success' ); }
			catch ( e ) { showToast( e.message || 'Revert failed', 'error' ); }
		};

		const revertAll = async () => {
			if ( ! window.confirm( 'Revert ALL Gleo changes? This cannot be undone.' ) ) { return; }
			try { await api.revertAll(); await load(); showToast( 'All changes reverted', 'success' ); }
			catch ( e ) { showToast( e.message || 'Revert-all failed', 'error' ); }
		};

		if ( entries.length === 0 ) {
			return h( EmptyState, { title: 'No changes yet', body: 'Once Gleo applies fixes, every edit shows up here with a one-click revert.' } );
		}

		return h( Fragment, null,
			h( 'div', { className: 'gleo-row' },
				h( 'div', { className: 'gleo-card__label' }, entries.length + ' change' + ( entries.length === 1 ? '' : 's' ) ),
				h( Button, { variant: 'ghost', small: true, onClick: revertAll }, 'Revert all' ),
			),
			h( 'div', { className: 'gleo-changelog' },
				entries.map( e => h( 'div', { key: e.id, className: cls( 'gleo-change', e.reverted && 'gleo-change--reverted' ) },
					h( 'div', { className: 'gleo-change__head' },
						h( Tag, { tone: e.reverted ? 'muted' : 'good' }, e.reverted ? 'reverted' : 'live' ),
						h( Tag, null, e.fix_type ),
						h( 'span', { className: 'gleo-change__time' }, e.time ),
					),
					h( 'div', { className: 'gleo-change__summary' }, e.summary ),
					e.post_id ? h( 'div', { className: 'gleo-muted' }, 'Post #' + e.post_id ) : null,
					! e.reverted && h( Button, { small: true, variant: 'ghost', onClick: () => revertOne( e.id ) }, 'Revert' ),
				) ),
			),
		);
	}

	function SettingsTab( { showToast, status, setStatus } ) {
		const [ s, setS ] = useState( null );
		const [ saving, setSaving ] = useState( false );
		const [ form, setForm ] = useState( {} );

		useEffect( () => {
			api.settingsGet().then( res => {
				setS( res );
				setForm( {
					gemini_api_key: '',
					tavily_api_key: '',
					autofix_cap: res.autofix_cap || 10,
					probe_enabled: !! res.probe_enabled,
					probe_queries: ( res.probe_queries || [] ).join( '\n' ),
					probe_brand: res.probe_brand || window.GleoData.siteName,
					probe_samples: res.probe_samples || 5,
				} );
			} ).catch( () => {} );
		}, [] );

		if ( ! s ) { return h( 'div', null, 'Loading…' ); }

		const upd = ( k, v ) => setForm( prev => ( { ...prev, [ k ]: v } ) );

		const save = async () => {
			setSaving( true );
			const payload = {
				autofix_cap: parseInt( form.autofix_cap, 10 ) || 10,
				probe_enabled: !! form.probe_enabled,
				probe_queries: ( form.probe_queries || '' ).split( '\n' ).map( x => x.trim() ).filter( Boolean ),
				probe_brand: form.probe_brand,
				probe_samples: parseInt( form.probe_samples, 10 ) || 5,
			};
			if ( form.gemini_api_key ) { payload.gemini_api_key = form.gemini_api_key; }
			if ( form.tavily_api_key ) { payload.tavily_api_key = form.tavily_api_key; }
			try {
				const fresh = await api.settingsSet( payload );
				setS( fresh );
				const ns = await api.status();
				setStatus( ns );
				upd( 'gemini_api_key', '' ); upd( 'tavily_api_key', '' );
				showToast( 'Settings saved', 'success' );
			} catch ( e ) {
				showToast( e.message || 'Save failed', 'error' );
			} finally {
				setSaving( false );
			}
		};

		const Field = ( label, child, hint ) => h( 'div', { className: 'gleo-field' },
			h( 'label', { className: 'gleo-field__label' }, label ),
			child,
			hint && h( 'div', { className: 'gleo-field__hint' }, hint ),
		);

		return h( 'div', { className: 'gleo-settings' },
			h( 'div', { className: 'gleo-card' },
				h( 'div', { className: 'gleo-card__label' }, 'API Keys' ),
				Field(
					'Gemini API key' + ( s.gemini_api_key_set ? ' (set ✓)' : '' ),
					h( 'input', { type: 'password', value: form.gemini_api_key || '', placeholder: s.gemini_api_key_set ? '••••••••' : 'AIza…', onChange: e => upd( 'gemini_api_key', e.target.value ) } ),
					'Get a key at aistudio.google.com. Required for content rewrites and Mention Probe.'
				),
				Field(
					'Tavily API key' + ( s.tavily_api_key_set ? ' (set ✓)' : '' ),
					h( 'input', { type: 'password', value: form.tavily_api_key || '', placeholder: s.tavily_api_key_set ? '••••••••' : 'tvly-…', onChange: e => upd( 'tavily_api_key', e.target.value ) } ),
					'Get a key at tavily.com. Used to ground stat injection in real sources.'
				),
				h( 'div', { className: 'gleo-field__hint' }, 'Tip: for production you can also define GLEO_GEMINI_API_KEY / GLEO_TAVILY_API_KEY in wp-config.php, or in a .env file in the plugin directory.' ),
			),
			h( 'div', { className: 'gleo-card' },
				h( 'div', { className: 'gleo-card__label' }, 'Auto-fix' ),
				Field(
					'Max pages to auto-edit per run',
					h( 'input', { type: 'number', min: 1, max: 50, value: form.autofix_cap, onChange: e => upd( 'autofix_cap', e.target.value ) } ),
					'Limits cost and review burden. Default: 10.'
				),
			),
			h( 'div', { className: 'gleo-card' },
				h( 'div', { className: 'gleo-card__label' }, 'Mention Probe' ),
				Field(
					'Brand to look for',
					h( 'input', { type: 'text', value: form.probe_brand, onChange: e => upd( 'probe_brand', e.target.value ) } ),
					'Exact name to match in AI responses.'
				),
				Field(
					'Probe queries (one per line)',
					h( 'textarea', { rows: 5, value: form.probe_queries, onChange: e => upd( 'probe_queries', e.target.value ), placeholder: 'best dentist in San Francisco\nfamily dentist near Mission District\ndentist that takes Cigna SF' } ),
					'Real questions a customer would ask ChatGPT or Gemini.'
				),
				Field(
					'Samples per query',
					h( 'input', { type: 'number', min: 1, max: 10, value: form.probe_samples, onChange: e => upd( 'probe_samples', e.target.value ) } ),
					'1–10. More samples = more reliable rate, more cost.'
				),
				Field(
					'',
					h( 'label', { className: 'gleo-checkbox' },
						h( 'input', { type: 'checkbox', checked: !! form.probe_enabled, onChange: e => upd( 'probe_enabled', e.target.checked ) } ),
						' Run probe automatically (weekly)',
					),
				),
			),
			h( 'div', { className: 'gleo-row' },
				h( Button, { variant: 'primary', onClick: save, disabled: saving }, saving ? 'Saving…' : 'Save settings' ),
			),
		);
	}

	// -------- Mount --------
	wp.element.render(
		h( App, null ),
		document.getElementById( 'gleo-root' )
	);
} )( window.wp );
