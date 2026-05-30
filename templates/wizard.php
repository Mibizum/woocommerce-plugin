<?php
/**
 * First run wizard screen.
 *
 * Rendered by Admin\Wizard::render(). Expects $config_json (JSON string with
 * restBase, nonce, state, onboardingUrl, mibizumOrigin, settingsUrl, domain).
 *
 * The account / Widget Studio steps load the hosted onboarding iframe; the
 * indexing and widget steps are native and talk to the REST controller. If the
 * iframe is unreachable the merchant can configure manually. Never blocks.
 *
 * @package Mibizum\Search
 * @var string $config_json
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<div id="mbz-wiz" data-config="<?php echo esc_attr( $config_json ); ?>">
		<div class="mbz-card">
			<div class="mbz-head">
				<span class="mbz-brand">MIBIZUM</span>
				<ol class="mbz-steps">
					<li data-dot="intro"><?php esc_html_e( 'Start', 'mibizum-search' ); ?></li>
					<li data-dot="connect"><?php esc_html_e( 'Account', 'mibizum-search' ); ?></li>
					<li data-dot="indexing"><?php esc_html_e( 'Indexing', 'mibizum-search' ); ?></li>
					<li data-dot="widget"><?php esc_html_e( 'Widget', 'mibizum-search' ); ?></li>
				</ol>
			</div>
			<div class="mbz-body">
				<div id="mbz-msg" class="mbz-msg" style="display:none;"></div>

				<section class="mbz-step" data-step="intro">
					<h2><?php esc_html_e( 'Connect this store to Mibizum search', 'mibizum-search' ); ?></h2>
					<p><?php esc_html_e( 'We will connect your store to Mibizum: index your catalog, power the search box and add the instant search widget.', 'mibizum-search' ); ?></p>
					<p class="mbz-muted"><?php esc_html_e( 'Nothing changes in your store until the connection is complete. You can stop any time.', 'mibizum-search' ); ?></p>
					<div class="mbz-actions">
						<button type="button" class="button button-primary" id="mbz-go"><?php esc_html_e( 'Continue', 'mibizum-search' ); ?></button>
						<button type="button" class="button-link" id="mbz-later"><?php esc_html_e( 'Later', 'mibizum-search' ); ?></button>
					</div>
				</section>

				<section class="mbz-step" data-step="connect" style="display:none;">
					<h2><?php esc_html_e( 'Sign in or create your Mibizum account', 'mibizum-search' ); ?></h2>
					<div class="mbz-frame-wrap">
						<iframe id="mbz-frame" title="Mibizum onboarding" src="about:blank"></iframe>
						<div id="mbz-frame-fallback" class="mbz-fallback" style="display:none;">
							<p><?php esc_html_e( 'Could not load the Mibizum onboarding. You can configure the connection by hand instead.', 'mibizum-search' ); ?></p>
							<a class="button button-primary" id="mbz-manual" href="#"><?php esc_html_e( 'Configure manually', 'mibizum-search' ); ?></a>
						</div>
					</div>
				</section>

				<section class="mbz-step" data-step="indexing" style="display:none;">
					<h2><?php esc_html_e( 'Indexing your catalog', 'mibizum-search' ); ?></h2>
					<p class="mbz-muted"><?php esc_html_e( 'This runs in the background. You can keep working; you do not need to wait here.', 'mibizum-search' ); ?></p>
					<div class="mbz-progress"><div id="mbz-bar" class="mbz-bar"></div></div>
					<p class="mbz-count"><span id="mbz-pending">0</span> <?php esc_html_e( 'changes pending', 'mibizum-search' ); ?></p>
					<div class="mbz-actions">
						<button type="button" class="button button-primary" id="mbz-index-next"><?php esc_html_e( 'Continue', 'mibizum-search' ); ?></button>
					</div>
				</section>

				<section class="mbz-step" data-step="widget" style="display:none;">
					<h2><?php esc_html_e( 'Turn on the search widget', 'mibizum-search' ); ?></h2>
					<p class="mbz-muted"><?php esc_html_e( 'Paste the widget snippet from your Mibizum panel (Domains, JS code). If you connected through the wizard it may already be filled in.', 'mibizum-search' ); ?></p>
					<textarea id="mbz-snippet" rows="4" spellcheck="false" placeholder="&lt;script ...&gt;"></textarea>
					<div class="mbz-actions">
						<button type="button" class="button button-primary" id="mbz-widget-save"><?php esc_html_e( 'Enable widget', 'mibizum-search' ); ?></button>
						<button type="button" class="button-link" id="mbz-widget-skip"><?php esc_html_e( 'Skip for now', 'mibizum-search' ); ?></button>
					</div>
				</section>

				<section class="mbz-step mbz-done" data-step="done" style="display:none;">
					<h2><?php esc_html_e( 'All set', 'mibizum-search' ); ?></h2>
					<p><?php esc_html_e( 'Your store is connected to Mibizum. Your search box now uses the Mibizum engine.', 'mibizum-search' ); ?></p>
					<div class="mbz-actions">
						<a class="button button-primary" id="mbz-finish" href="#"><?php esc_html_e( 'Done', 'mibizum-search' ); ?></a>
					</div>
				</section>
			</div>
		</div>
	</div>
</div>

<style>
#mbz-wiz .mbz-card{max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-top:20px}
#mbz-wiz .mbz-head{display:flex;align-items:center;gap:16px;padding:14px 20px;border-bottom:1px solid #f0f0f1}
#mbz-wiz .mbz-brand{font-weight:800;letter-spacing:.22em;color:#2271b1;font-size:12px}
#mbz-wiz .mbz-steps{list-style:none;display:flex;gap:6px;margin:0;padding:0;flex:1;flex-wrap:wrap}
#mbz-wiz .mbz-steps li{font-size:11px;color:#787c82;padding:3px 9px;border-radius:11px;background:#f0f0f1}
#mbz-wiz .mbz-steps li.is-active{color:#fff;background:#2271b1;font-weight:600}
#mbz-wiz .mbz-steps li.is-done{color:#1d6b3a;background:#e6f4ec}
#mbz-wiz .mbz-body{padding:22px 24px 26px}
#mbz-wiz h2{margin:0 0 10px;font-size:19px}
#mbz-wiz .mbz-muted{color:#787c82}
#mbz-wiz .mbz-actions{margin-top:18px;display:flex;align-items:center;gap:14px}
#mbz-wiz .mbz-frame-wrap{position:relative;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;height:440px}
#mbz-wiz iframe{width:100%;height:100%;border:0;display:block}
#mbz-wiz .mbz-fallback{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:#fff;padding:24px;text-align:center}
#mbz-wiz .mbz-progress{height:10px;border-radius:6px;background:#f0f0f1;overflow:hidden;margin:6px 0 10px}
#mbz-wiz .mbz-bar{height:100%;width:0;background:#2271b1;transition:width .4s ease}
#mbz-wiz .mbz-bar.mbz-ind{width:40%;animation:mbz-ind 1.1s ease-in-out infinite}
@keyframes mbz-ind{0%{margin-left:-40%}100%{margin-left:100%}}
#mbz-wiz textarea{width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;padding:10px}
#mbz-wiz .mbz-msg{margin-bottom:14px;padding:9px 13px;border-radius:6px;font-weight:600}
#mbz-wiz .mbz-msg.ok{background:#e6f4ec;color:#1d6b3a}
#mbz-wiz .mbz-msg.err{background:#fcf0f1;color:#a4342b}
</style>

<script>
( function () {
	var root = document.getElementById( 'mbz-wiz' );
	if ( ! root ) { return; }
	var CFG = JSON.parse( root.getAttribute( 'data-config' ) );
	var ORDER = [ 'intro', 'connect', 'indexing', 'widget' ];
	var STEP_FOR_STATE = {
		pending: 'intro', intro: 'intro', account: 'connect', provisioning: 'connect',
		indexing: 'indexing', widget: 'widget', studio: 'widget', done: 'done'
	};

	function $( id ) { return document.getElementById( id ); }

	function api( path, method, body ) {
		return fetch( CFG.restBase + path, {
			method: method || 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().catch( function () { return {}; } ); } );
	}

	function msg( text, ok ) {
		var el = $( 'mbz-msg' );
		el.className = 'mbz-msg ' + ( ok ? 'ok' : 'err' );
		el.textContent = text;
		el.style.display = 'block';
	}

	function showStep( step ) {
		root.querySelectorAll( '.mbz-step' ).forEach( function ( s ) {
			s.style.display = ( s.getAttribute( 'data-step' ) === step ) ? 'block' : 'none';
		} );
		var idx = ORDER.indexOf( step );
		root.querySelectorAll( '.mbz-steps li' ).forEach( function ( li ) {
			var di = ORDER.indexOf( li.getAttribute( 'data-dot' ) );
			li.className = di < idx ? 'is-done' : ( di === idx ? 'is-active' : '' );
		} );
		if ( step === 'connect' ) { loadFrame(); }
		if ( step === 'indexing' ) { startIndexing(); }
	}

	function loadFrame() {
		var f = $( 'mbz-frame' );
		if ( ! CFG.mibizumOrigin || ! CFG.onboardingUrl ) { showFallback(); return; }
		f.src = CFG.onboardingUrl;
		f.onerror = showFallback;
	}
	function showFallback() { $( 'mbz-frame-fallback' ).style.display = 'flex'; }

	window.addEventListener( 'message', function ( ev ) {
		if ( ! CFG.mibizumOrigin || ev.origin !== CFG.mibizumOrigin ) { return; }
		var d = ev.data || {};
		if ( typeof d.type !== 'string' ) { return; }
		if ( d.type === 'mibizum:keys' ) {
			api( '/wizard/save-keys', 'POST', {
				indexer_key: d.indexerKey || '',
				search_key: d.searchKey || '',
				data_source_slug: d.dataSourceSlug || ''
			} ).then( function ( res ) {
				if ( res && res.success ) {
					if ( d.widgetSnippet ) { $( 'mbz-snippet' ).value = d.widgetSnippet; }
					showStep( 'indexing' );
				} else {
					msg( ( res && res.message ) || 'Could not save the connection.', false );
				}
			} );
		} else if ( d.type === 'mibizum:cancel' ) {
			later();
		} else if ( d.type === 'mibizum:studioDone' ) {
			goDone();
		}
	} );

	var poll = null;
	function startIndexing() {
		var bar = $( 'mbz-bar' );
		bar.className = 'mbz-bar mbz-ind';
		api( '/reindex/full', 'POST', {} );
		pollStats();
		poll = setInterval( pollStats, 2500 );
	}
	function pollStats() {
		api( '/reindex/stats', 'GET' ).then( function ( d ) {
			if ( ! d || ! d.queue ) { return; }
			$( 'mbz-pending' ).textContent = Number( d.queue.pending || 0 ).toLocaleString();
			if ( Number( d.queue.pending || 0 ) === 0 ) { finishIndexing(); }
		} );
	}
	function finishIndexing() {
		if ( poll ) { clearInterval( poll ); poll = null; }
		var bar = $( 'mbz-bar' );
		bar.className = 'mbz-bar';
		bar.style.width = '100%';
	}

	function setState( s ) { return api( '/wizard/state', 'POST', { state: s } ); }
	function later() { api( '/wizard/dismiss', 'POST', {} ).then( function () { window.location = CFG.settingsUrl; } ); }
	function goDone() { setState( 'done' ).then( function () { showStep( 'done' ); } ); }

	$( 'mbz-go' ).addEventListener( 'click', function () { setState( 'account' ); showStep( 'connect' ); } );
	$( 'mbz-later' ).addEventListener( 'click', later );
	$( 'mbz-manual' ).addEventListener( 'click', function ( e ) { e.preventDefault(); window.location = CFG.settingsUrl; } );
	$( 'mbz-index-next' ).addEventListener( 'click', function () { setState( 'widget' ); showStep( 'widget' ); } );
	$( 'mbz-widget-save' ).addEventListener( 'click', function () {
		api( '/wizard/widget', 'POST', { widget_snippet: $( 'mbz-snippet' ).value } ).then( function ( res ) {
			if ( res && res.success ) { goDone(); } else { msg( ( res && res.message ) || 'Could not enable the widget.', false ); }
		} );
	} );
	$( 'mbz-widget-skip' ).addEventListener( 'click', goDone );
	$( 'mbz-finish' ).addEventListener( 'click', function ( e ) { e.preventDefault(); window.location = CFG.settingsUrl; } );

	showStep( STEP_FOR_STATE[ CFG.state ] || 'intro' );
} )();
</script>
