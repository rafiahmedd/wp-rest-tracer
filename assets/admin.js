/**
 * REST API Tracer — admin assets.
 *
 * Modules:
 *  1. API helper (wp.apiFetch with a plain-fetch fallback)
 *  2. Live trace list (Traces tab): polls the plugin REST API every few
 *     seconds, so captured requests stream into the table without a reload
 *  3. Capture how-to: curl sample + copy button (Traces tab)
 *  4. Endpoint explorer (Endpoints tab)
 *  5. Demo trace runner (Traces tab)
 *  6. Trace viewer: flame graph + waterfall + tooltip + zoom + search (View page)
 */
(function () {
	'use strict';

	var ADMIN = window.REST_TRACER_ADMIN || {};

	var COLORS = {
		request: '#2f6fb3',
		handler: '#00a32a',
		permission: '#0d9488',
		hook: '#3858e9',
		query: '#dba617',
		http: '#8e44ad',
		other: '#787c82'
	};
	var TYPE_LABELS = {
		request: 'REST request',
		handler: 'REST handler',
		permission: 'Permission check',
		hook: 'Hook callback',
		query: 'SQL query',
		http: 'HTTP request',
		other: 'Other'
	};
	var META_LABELS = {
		hook: 'Hook',
		sql: 'SQL',
		caller: 'Caller',
		url: 'URL',
		method: 'Method',
		status: 'Status',
		error: 'Error',
		detail: 'Detail',
		exception: 'Exception',
		transport: 'Transport',
		permission: 'Permission',
		namespace: 'Namespace',
		short_circuited: 'Short-circuited'
	};
	var ROW = 20; // px per flame depth level (kept for reference; viewer uses 28).
	// eslint-disable-line no-unused-vars

	function fmtMs(v) {
		if (v < 0.1) { return Math.round(v * 1000) + ' µs'; }
		if (v < 1000) { return v.toFixed(v < 10 ? 2 : 1) + ' ms'; }
		return (v / 1000).toFixed(3) + ' s';
	}
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function colorOf(t) { return COLORS[t] || COLORS.other; }
	function labelOf(t) { return TYPE_LABELS[t] || TYPE_LABELS.other; }

	/* ===================================================================
	 * 1. API helper — wp.apiFetch when present, plain fetch otherwise.
	 * =================================================================== */
	function api(path, method, data) {
		if (window.wp && window.wp.apiFetch) {
			return window.wp.apiFetch({ path: path, method: method || 'GET', data: data || undefined });
		}
		var root = ADMIN.root || '/wp-json/';
		var url = root.replace(/\/?$/, '/') + path.replace(/^\//, '');
		var opts = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: ADMIN.nonce ? { 'X-WP-Nonce': ADMIN.nonce } : {}
		};
		if (data) {
			opts.body = JSON.stringify(data);
			opts.headers['Content-Type'] = 'application/json';
		}
		return fetch(url, opts).then(function (r) {
			if (!r.ok) { throw new Error('Request failed (' + r.status + ')'); }
			return r.json();
		});
	}

	/* ===================================================================
	 * 2. Live trace list (Traces tab)
	 * =================================================================== */
	function initTraceList() {
		var body = document.getElementById('rt-traces-body');
		if (!body) { return; }

		var pill = document.getElementById('rt-live-pill');
		var pillLabel = document.getElementById('rt-live-label');
		var countEl = document.getElementById('rt-live-count');
		var toggleBtn = document.getElementById('rt-live-toggle');
		var clearBtn = document.getElementById('rt-clear-all');
		var filterEl = document.getElementById('rt-filter');
		var moreBtn = document.getElementById('rt-load-more');

		var state = {
			rows: [],       // newest → oldest
			newest: 0,      // highest id seen (poll cursor)
			live: true,
			page: 1,
			perPage: ADMIN.perPage || 25,
			hasMore: false,
			filter: '',
			err: false
		};
		var freshIds = {}; // ids that just arrived → highlight once, then forget
		var pollTimer = null;
		var backoff = 2000;

		/* ---------- formatting ---------- */
		function parseCreated(s) {
			var str = String(s || '');
			if (str.indexOf('T') === -1) { str = str.replace(' ', 'T') + 'Z'; }
			return new Date(str);
		}
		function fmtAbs(s) {
			var d = parseCreated(s);
			return isNaN(d.getTime()) ? String(s || '') : d.toLocaleString();
		}
		function fmtAgo(s) {
			var t = parseCreated(s).getTime();
			if (isNaN(t)) { return String(s || ''); }
			var diff = (Date.now() - t) / 1000;
			if (diff < 5) { return 'just now'; }
			if (diff < 60) { return Math.max(1, Math.floor(diff)) + 's ago'; }
			if (diff < 3600) { return Math.floor(diff / 60) + 'm ago'; }
			if (diff < 86400) { return Math.floor(diff / 3600) + 'h ago'; }
			return fmtAbs(s);
		}
		function fmtBytes(v) {
			v = parseInt(v, 10) || 0;
			if (v < 1024) { return v + ' B'; }
			var units = ['KB', 'MB', 'GB'];
			var i = -1;
			do { v = v / 1024; i++; } while (v >= 1024 && i < units.length - 1);
			return v.toFixed(v >= 10 ? 0 : 1) + ' ' + units[i];
		}
		function statusClass(s) {
			return (s >= 200 && s < 300) ? 'ok' : (s >= 400 ? 'bad' : 'warn');
		}

		/* ---------- rendering ---------- */
		function rowHtml(r) {
			var s = parseInt(r.status, 10) || 0;
			var isNew = freshIds[r.id] ? ' rt-row-new' : '';
			var view = 'tools.php?page=rest-tracer&view=' + encodeURIComponent(r.id);
			var peak = fmtBytes(r.peak_memory);
			return '<tr class="rt-row' + isNew + '" data-id="' + esc(r.id) + '">' +
				'<td class="rt-cell-time" title="' + esc(fmtAbs(r.created)) + '">' + esc(fmtAgo(r.created)) + '</td>' +
				'<td class="rt-cell-req"><a class="rt-req" href="' + view + '" title="Peak memory: ' + esc(peak) + '">' +
				'<span class="rt-method rt-method-' + esc(String(r.method || 'GET').toLowerCase()) + '">' + esc(r.method || 'GET') + '</span>' +
				'<code>' + esc(r.route || '') + '</code></a></td>' +
				'<td><span class="rt-badge rt-badge-' + statusClass(s) + '">' + (s ? esc(s) : '—') + '</span></td>' +
				'<td class="rt-num">' + esc(fmtMs(parseFloat(r.duration_ms) || 0)) + '</td>' +
				'<td class="rt-num">' + esc(parseInt(r.nodes, 10) || 0) +
				(parseInt(r.truncated, 10) ? ' <span class="rt-truncated" title="Trace hit the operation cap">truncated</span>' : '') + '</td>' +
				'<td class="rt-cell-actions"><a href="' + view + '">View</a>' +
				' <span class="rt-muted">·</span> <a href="#" class="rt-row-delete" data-id="' + esc(r.id) + '">Delete</a></td>' +
				'</tr>';
		}

		function visibleRows() {
			if (!state.filter) { return state.rows; }
			return state.rows.filter(function (r) {
				var hay = (r.route + ' ' + r.method + ' ' + r.namespace + ' ' + r.status + ' #' + r.id).toLowerCase();
				return hay.indexOf(state.filter) !== -1;
			});
		}

		function emptyHtml() {
			if (state.filter) {
				return 'No traces match “' + esc(state.filter) + '”.';
			}
			var watched = (ADMIN.watched || []).join(', ');
			if (!watched) {
				return 'No traces yet. <a href="tools.php?page=rest-tracer&amp;tab=settings">Add a namespace to watch</a>, then make a request — it will show up here instantly.';
			}
			return 'No traces yet. Run the demo trace above, or send a request to <code>' + esc(watched) + '</code>' +
				(ADMIN.mode === 'header' ? ' with the <code>X-REST-Tracer</code> header' : '') +
				'. Captured requests appear here instantly.';
		}

		function renderAll() {
			var rows = visibleRows();
			if (!rows.length) {
				body.innerHTML = '<tr><td colspan="6" class="rt-empty">' + emptyHtml() + '</td></tr>';
			} else {
				var html = '';
				rows.forEach(function (r) { html += rowHtml(r); });
				body.innerHTML = html;
			}
			if (countEl) {
				countEl.textContent = state.rows.length
					? state.rows.length + (state.rows.length === 1 ? ' trace' : ' traces')
					: '';
			}
			if (moreBtn) { moreBtn.hidden = !state.hasMore; }
		}

		/* ---------- live pill ---------- */
		function setErr(on) {
			state.err = on;
			if (!pill) { return; }
			pill.classList.toggle('rt-pill-err', !!on);
			if (pillLabel) { pillLabel.textContent = on ? 'Reconnecting…' : (state.live ? 'Live' : 'Paused'); }
			if (!on) { backoff = 2000; }
		}
		function setLive(on) {
			state.live = on;
			if (toggleBtn) { toggleBtn.textContent = on ? 'Pause' : 'Resume'; }
			if (pill) {
				pill.classList.toggle('rt-pill-live', on);
				pill.classList.toggle('rt-pill-paused', !on);
			}
			if (pillLabel && !state.err) { pillLabel.textContent = on ? 'Live' : 'Paused'; }
		}

		/* ---------- loading + polling ---------- */
		function loadInitial() {
			api('/rest-tracer/v1/traces?per_page=' + state.perPage).then(function (rows) {
				rows = rows || [];
				state.rows = rows;
				state.page = 1;
				state.newest = rows.length ? parseInt(rows[0].id, 10) : 0;
				state.hasMore = rows.length >= state.perPage;
				setErr(false);
				renderAll();
				schedulePoll();
			}).catch(function () {
				setErr(true);
				body.innerHTML = '<tr><td colspan="6" class="rt-error">Could not load traces — retrying…</td></tr>';
				pollTimer = setTimeout(loadInitial, backoff);
				backoff = Math.min(backoff * 2, 15000);
			});
		}

		function poll() {
			if (!state.live || document.hidden) {
				schedulePoll();
				return;
			}
			api('/rest-tracer/v1/traces?since_id=' + state.newest + '&per_page=100').then(function (rows) {
				rows = rows || [];
				if (rows.length) {
					rows.forEach(function (r) { freshIds[r.id] = true; });
					state.rows = rows.slice().reverse().concat(state.rows);
					state.newest = parseInt(rows[rows.length - 1].id, 10);
					renderAll();
					// Forget "new" markers so later re-renders don't re-animate them.
					setTimeout(function () {
						var had = false;
						Object.keys(freshIds).forEach(function (k) { had = true; delete freshIds[k]; });
						if (had) { renderAll(); }
					}, 3000);
				}
				setErr(false);
				schedulePoll();
			}).catch(function () {
				setErr(true);
				schedulePoll();
			});
		}

		function schedulePoll() {
			if (pollTimer) { clearTimeout(pollTimer); }
			pollTimer = setTimeout(poll, backoff);
		}

		/* ---------- controls ---------- */
		if (toggleBtn) {
			toggleBtn.addEventListener('click', function () { setLive(!state.live); });
		}

		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				if (!window.confirm('Delete all recorded traces?')) { return; }
				clearBtn.disabled = true;
				api('/rest-tracer/v1/traces/all', 'DELETE').then(function () {
					clearBtn.disabled = false;
					state.rows = [];
					state.newest = 0;
					state.page = 1;
					state.hasMore = false;
					renderAll();
				}).catch(function (e) {
					clearBtn.disabled = false;
					window.alert('Clear failed: ' + ((e && e.message) || 'unknown error'));
				});
			});
		}

		if (filterEl) {
			filterEl.addEventListener('input', function () {
				state.filter = filterEl.value.trim().toLowerCase();
				renderAll();
			});
		}

		if (moreBtn) {
			moreBtn.addEventListener('click', function () {
				moreBtn.disabled = true;
				api('/rest-tracer/v1/traces?per_page=' + state.perPage + '&page=' + (state.page + 1)).then(function (rows) {
					rows = rows || [];
					moreBtn.disabled = false;
					state.page += 1;
					// Live prepends shift server-side pages — skip rows already shown.
					var have = {};
					state.rows.forEach(function (r) { have[r.id] = true; });
					state.rows = state.rows.concat(rows.filter(function (r) { return !have[r.id]; }));
					state.hasMore = rows.length >= state.perPage;
					renderAll();
				}).catch(function () {
					moreBtn.disabled = false;
				});
			});
		}

		// Delete links (event delegation — rows are re-rendered constantly).
		body.addEventListener('click', function (e) {
			var del = e.target.closest ? e.target.closest('.rt-row-delete') : null;
			if (!del) { return; }
			e.preventDefault();
			var id = parseInt(del.getAttribute('data-id'), 10);
			if (!id || !window.confirm('Delete trace #' + id + '?')) { return; }
			api('/rest-tracer/v1/traces/' + id, 'DELETE').then(function () {
				state.rows = state.rows.filter(function (r) { return parseInt(r.id, 10) !== id; });
				renderAll();
			}).catch(function (err2) {
				window.alert('Delete failed: ' + ((err2 && err2.message) || 'unknown error'));
			});
		});

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && state.live) {
				// Immediate catch-up when the tab becomes visible again.
				poll();
				renderAll();
			}
		});

		// Keep relative times ("5m ago") fresh.
		setInterval(function () {
			if (!document.hidden && body.querySelectorAll('.rt-cell-time').length) { renderAll(); }
		}, 10000);

		setLive(true);
		loadInitial();
	}

	/* ===================================================================
	 * 3. Capture how-to: curl sample + copy button (Traces tab)
	 * =================================================================== */
	function initCurl() {
		var pre = document.getElementById('rt-curl-sample');
		if (!pre) { return; }

		var cmd = "curl -X GET '" + (ADMIN.sampleUrl || '') + "' -H 'X-REST-Tracer: " + (ADMIN.token || '') + "'";
		pre.textContent = cmd;

		var btn = document.getElementById('rt-curl-copy');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var done = function () {
				btn.textContent = 'Copied ✓';
				setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(cmd).then(done, function () { fallbackCopy(cmd); done(); });
			} else {
				fallbackCopy(cmd);
				done();
			}
		});
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* noop */ }
		ta.remove();
	}

	/* ===================================================================
	 * 4. Endpoint explorer (Endpoints tab)
	 * =================================================================== */
	function initExplorer() {
		var btn = document.getElementById('rt-ns-load');
		var input = document.getElementById('rt-ns-input');
		var out = document.getElementById('rt-ns-results');
		if (!btn || !input || !out) { return; }

		function load() {
			var ns = normalizeNs(input.value);
			if (!ns) {
				out.innerHTML = '<p class="rt-error">Enter a namespace, e.g. <code>myshop/v1</code>.</p>';
				return;
			}
			out.innerHTML = '<p class="rt-muted">Loading…</p>';

			api('/rest-tracer/v1/routes?namespace=' + encodeURIComponent(ns)).then(function (data) {
				var endpoints = (data && data.endpoints) || [];
				if (!endpoints.length) {
					out.innerHTML = '<p class="rt-error">No endpoints found under <code>' + esc(ns) + '</code>. ' +
						'Check the namespace spelling (the route prefix in <code>/wp-json/&lt;namespace&gt;/…</code>).</p>';
					return;
				}
				var html = '<table class="wp-list-table widefat striped rt-endpoints"><thead><tr>' +
					'<th>Route</th><th>Methods</th><th>Callback</th><th>Permission</th></tr></thead><tbody>';
				endpoints.forEach(function (ep) {
					html += '<tr><td><code>' + esc(ep.route) + '</code></td>' +
						'<td>' + esc((ep.methods || []).join(' ')) + '</td>' +
						'<td>' + esc(ep.callback) + '</td>' +
						'<td>' + esc(ep.permission) + '</td></tr>';
				});
				html += '</tbody></table><p class="rt-muted">' + endpoints.length + ' endpoint(s) under <code>' + esc(ns) + '</code></p>';
				out.innerHTML = html;
			}).catch(function (e) {
				out.innerHTML = '<p class="rt-error">' + esc((e && e.message) || 'Request failed') + '</p>';
			});
		}

		btn.addEventListener('click', load);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
		});
	}

	function normalizeNs(s) {
		return String(s || '').trim().replace(/^\/+|\/+$/g, '').replace(/\s+/g, '');
	}

	/* ===================================================================
	 * 5. Demo trace runner (Traces tab)
	 * =================================================================== */
	function initDemo() {
		var btn = document.getElementById('rt-demo-run');
		if (!btn) { return; }
		var http = document.getElementById('rt-demo-http');

		btn.addEventListener('click', function () {
			btn.disabled = true;
			var original = btn.textContent;
			btn.textContent = 'Running demo…';

			var headers = { 'Content-Type': 'application/json' };
			if (ADMIN.token) { headers['X-REST-Tracer'] = ADMIN.token; }

			fetch(ADMIN.demoUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify({ with_http: !!(http && http.checked) })
			}).then(function (r) {
				if (!r.ok) { throw new Error('Demo endpoint returned ' + r.status); }
				return r.json();
			}).then(function () {
				// Pick up the newest trace and jump straight to its diagram.
				return api('/rest-tracer/v1/traces?per_page=1');
			}).then(function (list) {
				if (list && list[0] && list[0].id) {
					window.location.href = 'tools.php?page=rest-tracer&view=' + list[0].id;
					return;
				}
				btn.disabled = false;
				btn.textContent = original;
			}).catch(function (e) {
				window.alert('Demo failed: ' + e.message);
				btn.disabled = false;
				btn.textContent = original;
			});
		});
	}

	/* ===================================================================
	 * 6. Trace viewer — stat strip, 2 graph styles (flame / flow),
	 *    waterfall, aggregated call tree, per-user
	 *    preferences persisted via the plugin's REST API.
	 * =================================================================== */
	function initViewer() {
		var flameEl = document.getElementById('rtv-flame');
		var viewerEl = document.getElementById('rt-viewer');
		var data = window.REST_TRACER_TRACE;
		if (!flameEl || !viewerEl || !data || !data.nodes || !data.nodes.length) { return; }

		// Tolerate stale cached JS paired with newer markup (or vice versa):
		// bail out cleanly instead of crashing on missing elements.
		var needed = ['rtv-flow', 'rtv-gmodes', 'rtv-wf', 'rtv-tree', 'rtv-stats', 'rtv-chips', 'rtv-crumbs', 'rtv-tip', 'rtv-q'];
		for (var ni = 0; ni < needed.length; ni++) {
			if (!document.getElementById(needed[ni])) {
				if (window.console) { window.console.warn('REST Tracer: viewer markup out of sync with admin.js — clear cached assets.'); }
				return;
			}
		}

		var META = data.meta || {};
		var PREFS = ADMIN.prefs || {};
		var NS = 'http://www.w3.org/2000/svg';

		/* ---------- parse + build tree ---------- */
		var nodes = data.nodes.map(function (n, i) {
			return {
				id: i,
				parent: (n[0] === null || n[0] === undefined) ? null : n[0],
				label: String(n[1] === undefined ? '(unnamed)' : n[1]),
				type: n[2] || 'other',
				start: n[3] || 0,
				dur: n[4] || 0,
				meta: n[5] || null,
				children: [],
				depth: 0,
				self: 0
			};
		});

		var root = nodes[0];
		nodes.forEach(function (n) {
			if (n !== root && n.parent !== null && nodes[n.parent]) {
				nodes[n.parent].children.push(n);
			}
		});
		(function walk(n, d) {
			n.depth = d;
			var s = 0;
			n.children.forEach(function (c) { walk(c, d + 1); s += c.dur; });
			n.self = Math.max(0, n.dur - s);
		})(root, 0);

		var maxDepth = 0;
		nodes.forEach(function (n) { if (n.depth > maxDepth) { maxDepth = n.depth; } });
		var total = root.dur || 1;

		var $ = function (id) { return document.getElementById(id); };
		var flameHost = flameEl;
		var crumbs = $('rtv-crumbs');
		var currentRoot = root;

		function fmtBytes(v) {
			v = parseInt(v, 10) || 0;
			if (v < 1024) { return v + ' B'; }
			var u = ['KB', 'MB', 'GB'], i = -1;
			do { v /= 1024; i++; } while (v >= 1024 && i < u.length - 1);
			return v.toFixed(v >= 10 ? 0 : 1) + ' ' + u[i];
		}

		// Fixed-point percentage for style injection — immune to float noise
		// (scientific notation like 3.5e-15) and NaN/Infinity.
		function pct(v) {
			v = +v;
			if (!isFinite(v) || v < 0) { v = 0; }
			return v.toFixed(4);
		}

		/* ---------- preferences (per user, DB-backed) ---------- */
		var prefTimer = null;
		var prefPending = {};
		function savePrefs(patch) {
			Object.keys(patch).forEach(function (k) { prefPending[k] = patch[k]; });
			if (prefTimer) { clearTimeout(prefTimer); }
			prefTimer = setTimeout(function () {
				var body = prefPending;
				prefPending = {};
				api('/rest-tracer/v1/preferences', 'PUT', body).catch(function () { /* offline: keep UI state anyway */ });
			}, 250);
		}

		/* ---------- stat strip ---------- */
		var byType = {};
		nodes.forEach(function (n) {
			if (!byType[n.type]) { byType[n.type] = { count: 0, time: 0 }; }
			byType[n.type].count++;
			byType[n.type].time += n.dur;
		});
		(function stats() {
			function card(k, v, sub, color, share) {
				var bar = color ? '<span class="rtv-bar" style="width:' + pct(Math.min(100, share)) + '%;background:' + color + '"></span>' : '';
				return '<div class="rtv-stat"><div class="rtv-k">' + k + '</div><div class="rtv-v">' + v + '</div><div class="rtv-sub">' + sub + '</div>' + bar + '</div>';
			}
			var slowQ = nodes.filter(function (n) { return n.type === 'query'; }).map(function (n) { return n.dur; });
			var html = card('Total duration', fmtMs(META.duration_ms || total), META.started ? String(META.started).replace(' UTC', '') : '');
			html += card('Operations', nodes.length, (maxDepth + 1) + ' levels deep');
			html += card('SQL queries', (byType.query ? byType.query.count : 0) + ' · ' + fmtMs(byType.query ? byType.query.time : 0), 'slowest ' + fmtMs(slowQ.length ? Math.max.apply(null, slowQ) : 0), colorOf('query'), (byType.query ? byType.query.time : 0) / total * 100);
			html += card('Outbound HTTP', (byType.http ? byType.http.count : 0) + ' · ' + fmtMs(byType.http ? byType.http.time : 0), 'external calls made by this request', colorOf('http'), (byType.http ? byType.http.time : 0) / total * 100);
			html += card('Hook callbacks', (byType.hook ? byType.hook.count : 0) + ' · ' + fmtMs(byType.hook ? byType.hook.time : 0), 'actions + filters', colorOf('hook'), (byType.hook ? byType.hook.time : 0) / total * 100);
			html += card('Peak memory', fmtBytes(META.peak_memory), 'PHP process, whole request');
			$('rtv-stats').innerHTML = html;
		})();

		/* ---------- details ---------- */
		(function details() {
			var d = [];
			var r = META.response || {};
			function kv(k, v) { return '<div class="rtv-kv"><div class="rtv-kv-k">' + k + '</div><div class="rtv-kv-v">' + esc(v) + '</div></div>'; }
			if (r.status) { d.push(kv('Response', r.status + (r.body_bytes ? ' · ' + fmtBytes(r.body_bytes) + ' body' : ''))); }
			if (META.namespace) { d.push(kv('Namespace', META.namespace)); }
			if (root.meta && root.meta.user !== undefined) { d.push(kv('User', root.meta.user ? 'user #' + root.meta.user : 'not authenticated')); }
			if (root.meta && root.meta.params) { d.push(kv('Params', JSON.stringify(root.meta.params))); }
			if (META.php) { d.push(kv('Environment', 'WP ' + META.wp + ' · PHP ' + META.php)); }
			if (META.truncated) { d.push(kv('Truncated', 'yes — raise "Max operations per trace" in Settings')); }
			$('rtv-details').innerHTML = d.join('');
		})();

		/* ---------- type chips (legend + filter) ---------- */
		var activeTypes = {};
		Object.keys(byType).forEach(function (t) { activeTypes[t] = true; });
		var chipsEl = $('rtv-chips');
		function renderChips() {
			chipsEl.innerHTML = Object.keys(byType).map(function (t) {
				var v = byType[t];
				return '<span class="rtv-chip ' + (activeTypes[t] ? 'on' : 'off') + '" data-type="' + t + '">' +
					'<span class="rtv-sw" style="background:' + colorOf(t) + '"></span>' +
					'<b>' + esc(labelOf(t)) + '</b><span class="rtv-t">' + v.count + ' · ' + fmtMs(v.time) + '</span></span>';
			}).join('');
		}
		chipsEl.addEventListener('click', function (e) {
			var chip = e.target.closest('.rtv-chip');
			if (!chip) { return; }
			activeTypes[chip.getAttribute('data-type')] = !activeTypes[chip.getAttribute('data-type')];
			renderChips();
			applyFilters();
		});
		renderChips();

		/* ---------- search ---------- */
		var q = '';
		var qEl = $('rtv-q');
		function matches(n) {
			if (!q) { return true; }
			if (n.label.toLowerCase().indexOf(q) !== -1) { return true; }
			if (n.meta) {
				if (n.meta.sql && String(n.meta.sql).toLowerCase().indexOf(q) !== -1) { return true; }
				if (n.meta.url && String(n.meta.url).toLowerCase().indexOf(q) !== -1) { return true; }
			}
			return false;
		}
		qEl.addEventListener('input', function () {
			q = qEl.value.trim().toLowerCase();
			applyFilters();
		});
		document.addEventListener('keydown', function (e) {
			var tag = (document.activeElement && document.activeElement.tagName) || '';
			if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
				e.preventDefault();
				qEl.focus();
			} else if (e.key === 'Escape') {
				if (document.activeElement === qEl) { qEl.blur(); } else { zoomTo(root); }
			}
		});

		function applyFilters() {
			var hits = 0;
			function one(el) {
				var n = nodes[+el.getAttribute('data-id')];
				var typeOff = !activeTypes[n.type];
				var hit = !typeOff && (!q || matches(n));
				el.classList.toggle('dim', typeOff);
				el.classList.toggle('hit', !!q && hit);
				if (q && hit) { hits++; }
			}
			document.querySelectorAll('#rtv-flame .rtv-frame').forEach(one);
			document.querySelectorAll('#rtv-flow .gnode').forEach(one);
			document.querySelectorAll('#rtv-flow .gedge').forEach(function (el) {
				var n = nodes[+el.getAttribute('data-to')];
				el.classList.toggle('dim', !activeTypes[n.type]);
			});
			$('rtv-match').textContent = q ? hits + ' match' + (hits === 1 ? '' : 'es') : '';
		}

		/* ---------- tooltip ---------- */
		var tip = $('rtv-tip');
		function showTip(n, ev) {
			var p = (n.parent !== null && nodes[n.parent]) ? nodes[n.parent] : null;
			var rows =
				'<div class="rtv-kv"><span>Duration</span><b>' + fmtMs(n.dur) + '</b></div>' +
				'<div class="rtv-kv"><span>Self time</span><b>' + fmtMs(n.self) + '</b></div>' +
				'<div class="rtv-kv"><span>Of request</span><b>' + (n.dur / total * 100).toFixed(1) + '%</b></div>' +
				'<div class="rtv-kv"><span>Of parent</span><b>' + (p && p.dur ? (n.dur / p.dur * 100).toFixed(1) : '100') + '%</b></div>';
			var extra = '';
			if (n.meta) {
				Object.keys(n.meta).forEach(function (k) {
					var v = n.meta[k];
					if (v === null || v === undefined || v === '' || k === 'qidx') { return; }
					if (k === 'sql' || k === 'params') {
						extra += '<pre>' + esc(String(typeof v === 'string' ? v : JSON.stringify(v)).substring(0, 400)) + '</pre>';
					} else {
						var lbl = META_LABELS[k] || (k.charAt(0).toUpperCase() + k.slice(1));
						rows += '<div class="rtv-kv"><span>' + esc(lbl) + '</span><b>' + esc(String(v).substring(0, 120)) + '</b></div>';
					}
				});
			}
			tip.innerHTML = '<div class="rtv-tip-name">' + esc(n.label) + '</div>' +
				'<div class="rtv-tip-type"><i style="background:' + colorOf(n.type) + '"></i>' + esc(labelOf(n.type)) + '</div>' +
				'<div class="rtv-tip-grid">' + rows + '</div>' + extra;
			tip.hidden = false;
			var x = ev.clientX + 16, y = ev.clientY + 16, r = tip.getBoundingClientRect();
			if (x + r.width > window.innerWidth - 10) { x = ev.clientX - r.width - 16; }
			if (y + r.height > window.innerHeight - 10) { y = Math.max(10, ev.clientY - r.height - 16); }
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
		}
		function hideTip() { tip.hidden = true; }

		/* ---------- graph mode state ---------- */
		var GMODES = ['flame', 'flow'];
		var gmode = (GMODES.indexOf(PREFS.graph) !== -1) ? PREFS.graph : 'flame';
		var gmHint = $('rtv-gm-hint');

		/* ---------- flame graph (root at the bottom, stacks upward) ---------- */
		function renderRuler() {
			var el = $('rtv-ruler');
			var span = currentRoot.dur || 1;
			var raw = span / 5, mag = Math.pow(10, Math.floor(Math.log10(raw))), norm = raw / mag;
			var step = (norm < 1.5 ? 1 : norm < 3.5 ? 2 : norm < 7.5 ? 5 : 10) * mag;
			var out = '', t = 0;
			while (t <= span) {
				out += '<span class="rtv-tick' + (t === 0 ? ' rtv-tick-first' : '') + '" style="left:' + pct(t / span * 100) + '%">' + (step < 1 ? t.toFixed(1) : Math.round(t)) + ' ms</span>';
				t += step;
			}
			el.innerHTML = out;
		}

		function renderCrumbs() {
			var path = [], n = currentRoot;
			while (n) { path.unshift(n); n = (n.parent !== null && nodes[n.parent]) ? nodes[n.parent] : null; }
			crumbs.innerHTML = path.map(function (p, i) {
				return (i ? '<span class="rtv-crumb-sep">›</span>' : '') +
					'<span class="rtv-crumb' + (p === currentRoot ? ' rtv-crumb-cur' : '') + '" data-id="' + p.id + '">' +
					esc(p.label.length > 64 ? p.label.slice(0, 64) + '…' : p.label) + '</span>';
			}).join('');
		}

		function renderTimeGraph() {
			var host = flameHost;
			host.innerHTML = '';
			var rows = maxDepth - currentRoot.depth + 1;
			host.style.height = (rows * 28 + 4) + 'px';
			var base = currentRoot.start, span = currentRoot.dur || 1;
			var made = [];
			function frameFor(n, depth, left, width, label, zoomNode) {
				var el = document.createElement('div');
				el.className = 'rtv-frame';
				el.setAttribute('data-id', n.id);
				el.style.left = 'calc(' + pct(left) + '% + 2px)';
				el.style.width = width < 0.2 ? '1px' : 'calc(' + pct(width) + '% - 4px)';
				el.style.bottom = (((rows - 1 - depth) * 28) + 2) + 'px';
				el.style.background = 'linear-gradient(180deg, ' + colorOf(n.type) + ', ' + shade(colorOf(n.type), -18) + ')';
				el.innerHTML = '<span class="rtv-frame-lb">' + esc(label) + '</span>';
				el.addEventListener('click', function (e) { e.stopPropagation(); zoomTo(zoomNode || n); });
				el.addEventListener('mousemove', function (e) { showTip(n, e); });
				el.addEventListener('mouseleave', hideTip);
				host.appendChild(el);
				made.push(el);
			}

			(function add(n, depth) {
				// The node's own frame (the zoom root spans the full canvas).
				var left, width;
				if (depth === 0) {
					left = 0;
					width = 100;
				} else {
					left = (n.start - base) / span * 100;
					width = (n.dur / span) * 100;
					// Clamp into the canvas: nodes whose recorded end overshoots
					// the zoom root (lazily-resolved SQL timings can) stay inside.
					if (left < 0) { width += left; left = 0; }
					if (left > 99.85) { left = 99.85; width = Math.min(width, 0.15); }
					if (width < 0.12) { width = 0.12; }
					if (left + width > 100) { width = 100 - left; }
				}
				frameFor(n, depth, left, width, n.label);

				// Children: runs of consecutive micro-siblings (each too narrow
				// to read at this zoom) collapse into one aggregate frame, so
				// dense traces show "N operations · X ms" instead of a smear.
				var kids = n.children, i = 0;
				while (i < kids.length) {
					var c = kids[i];
					if ((c.dur / span) * 100 >= 0.25) {
						add(c, depth + 1);
						i++;
						continue;
					}
					var run = [c], j = i + 1;
					while (j < kids.length && (kids[j].dur / span) * 100 < 0.25) {
						run.push(kids[j]);
						j++;
					}
					if (run.length >= 3) {
						var runStart = (run[0].start - base) / span * 100;
						var runEnd = ((run[run.length - 1].start + run[run.length - 1].dur) - base) / span * 100;
						var runWidth = Math.max(runEnd - runStart, 0.12);
						var runLeft = Math.min(Math.max(runStart, 0), 100 - runWidth);
						var runDur = 0;
						var typeCount = {};
						run.forEach(function (r) {
							runDur += r.dur;
							typeCount[r.type] = (typeCount[r.type] || 0) + 1;
						});
						var domType = c.type;
						Object.keys(typeCount).forEach(function (t) {
							if (typeCount[t] > (typeCount[domType] || 0)) { domType = t; }
						});
						var agg = {
							id: n.id, parent: n.parent, label: run.length + ' operations · ' + fmtMs(runDur),
							type: domType, start: run[0].start, dur: runDur,
							self: runDur, meta: { detail: run.length + ' aggregated operations (' + fmtMs(runDur) + ' total) — click to zoom into ' + (n === currentRoot ? 'this range' : n.label.slice(0, 40)) },
							children: [], depth: depth + 1
						};
						// Clicking an aggregate zooms into its REAL parent node —
						// the fake aggregate has no children and would render blank.
						frameFor(agg, depth + 1, runLeft, runWidth, agg.label, n);
					} else {
						run.forEach(function (r) { add(r, depth + 1); });
					}
					i = j;
				}
			})(currentRoot, 0);
			// Frames narrower than a readable label render as clean color
			// chips — hide the text instead of showing a single letter.
			// (Skip when rendered into a hidden panel: widths would read 0.)
			if (host.clientWidth > 0) {
				made.forEach(function (el) {
					if (el.getBoundingClientRect().width < 36) {
						el.classList.add('rtv-frame-plain');
					}
				});
			}
			// Root sits at the bottom: start scrolled there.
			host.scrollTop = host.scrollHeight;
			renderRuler();
		}

		function shade(hex, pct) {
			var n = parseInt(hex.slice(1), 16), amt = Math.round(2.55 * pct);
			var r = Math.min(255, Math.max(0, (n >> 16) + amt));
			var g = Math.min(255, Math.max(0, ((n >> 8) & 255) + amt));
			var b = Math.min(255, Math.max(0, (n & 255) + amt));
			return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
		}

		/* ---------- flow: interactive node graph ---------- */
		var flowCollapsed = {};
		var flowView = { x: 0, y: 0, k: 1 };
		var FNW = 200, FNH = 58, FXGAP = 218, FYGAP = 116;

		/**
		 * Focus the graph on the initial request: keep the request, its direct
		 * children and grandchildren visible; everything deeper collapses into
		 * "+N" badges the admin can expand while navigating.
		 */
		function flowFocusReset() {
			flowCollapsed = {};
			nodes.forEach(function (n) {
				if (n.depth >= 2 && n.children.length) {
					flowCollapsed[n.id] = true;
				}
			});
		}
		function enterFlow() {
			flowFocusReset();
		}

		function flowLines(n) {
			var l1 = '', l2 = '';
			var arrow = n.label.indexOf(' → ');
			if (n.type === 'http') {
				var m = n.label.match(/^(HTTP \w+)(?: \(short-circuited\))? (\S+)/);
				if (m) {
					l1 = m[1] + (n.label.indexOf('short-circuited') !== -1 ? ' (cached)' : '');
					try { l2 = decodeURIComponent(m[2]).replace(/^https?:\/\//, ''); } catch (e) { l2 = m[2]; }
				} else { l1 = n.label; }
			} else if (n.type === 'query') {
				l1 = 'SQL';
				l2 = n.label.replace(/^SQL:\s*/, '');
			} else if (n.type === 'request') {
				var rm = n.label.match(/^REST (\w+) (.*)$/);
				l1 = 'REST ' + (rm ? rm[1] : '');
				l2 = rm ? rm[2] : n.label;
			} else if (arrow !== -1) {
				l1 = n.label.slice(0, arrow);
				l2 = n.label.slice(arrow + 3);
			} else { l1 = n.label; }
			function cut(s, max) { s = String(s); return s.length > max ? s.slice(0, max - 1) + '…' : s; }
			return [cut(l1, 30), cut(l2, 30)];
		}

		function flowApply() {
			var g = $('rtv-flow').querySelector('g');
			if (g) { g.setAttribute('transform', 'translate(' + flowView.x + ',' + flowView.y + ') scale(' + flowView.k + ')'); }
		}
		function flowFit() {
			var g = $('rtv-flow').querySelector('g');
			if (!g) { return; }
			try {
				var bb = g.getBBox();
				var W = 1400, H = 760;
				var k = Math.min(W / (bb.width + 60), H / (bb.height + 40), 1.2);
				flowView = { k: k, x: (W - bb.width * k) / 2 - bb.x * k, y: Math.max(8, (H - bb.height * k) / 2 - bb.y * k) };
			} catch (e) { flowView = { k: 1, x: 0, y: 0 }; }
			flowApply();
		}

		function renderFlow() {
			var svg = $('rtv-flow');
			svg.innerHTML = '';
			var W = 1400;

			var pos = {}, cursor = 0, depthMax = 0;
			(function layout(n, depth) {
				depthMax = Math.max(depthMax, depth);
				var kids = (flowCollapsed[n.id] || !n.children.length) ? [] : n.children;
				var xs = [];
				kids.forEach(function (c) { layout(c, depth + 1); xs.push(pos[c.id].x); });
				var x = kids.length ? (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2 : cursor;
				if (!kids.length) { cursor += 1; }
				pos[n.id] = { x: x, y: depth };
			})(root, 0);

			var ox = (W - Math.max(1, cursor - 1) * FXGAP) / 2;
			function nx(n) { return ox + pos[n.id].x * FXGAP - FNW / 2; }
			function ny(n) { return 20 + pos[n.id].y * FYGAP; }

			var gRoot = document.createElementNS(NS, 'g');
			svg.appendChild(gRoot);

			var defs = document.createElementNS(NS, 'defs');
			var mk = document.createElementNS(NS, 'marker');
			mk.setAttribute('id', 'rtv-arrow'); mk.setAttribute('viewBox', '0 0 10 10');
			mk.setAttribute('refX', 9); mk.setAttribute('refY', 5);
			mk.setAttribute('markerWidth', 7); mk.setAttribute('markerHeight', 7);
			mk.setAttribute('orient', 'auto-start-reverse');
			var mkPath = document.createElementNS(NS, 'path');
			mkPath.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
			mkPath.setAttribute('fill', '#39435a');
			mk.appendChild(mkPath);
			defs.appendChild(mk);
			gRoot.insertBefore(defs, gRoot.firstChild);

			(function edges(n) {
				if (flowCollapsed[n.id]) { return; }
				n.children.forEach(function (c) {
					var x1 = nx(n) + FNW / 2, y1 = ny(n) + FNH;
					var x2 = nx(c) + FNW / 2, y2 = ny(c);
					var p = document.createElementNS(NS, 'path');
					p.setAttribute('d', 'M' + x1 + ',' + y1 + ' C' + x1 + ',' + (y1 + 44) + ' ' + x2 + ',' + (y2 - 44) + ' ' + x2 + ',' + (y2 - 7));
					p.setAttribute('class', 'gedge');
					p.setAttribute('marker-end', 'url(#rtv-arrow)');
					p.setAttribute('data-to', c.id);
					gRoot.appendChild(p);
					edges(c);
				});
			})(root);

			(function nodesIn(n) {
				var x = nx(n), y = ny(n);
				var gn = document.createElementNS(NS, 'g');
				gn.setAttribute('class', 'gnode');
				gn.setAttribute('data-id', n.id);
				gn.setAttribute('transform', 'translate(' + x + ',' + y + ')');

				var box = document.createElementNS(NS, 'rect');
				box.setAttribute('class', 'box');
				box.setAttribute('width', FNW); box.setAttribute('height', FNH);
				box.setAttribute('rx', 9);
				gn.appendChild(box);

				var accent = document.createElementNS(NS, 'rect');
				accent.setAttribute('width', 4); accent.setAttribute('height', FNH - 12);
				accent.setAttribute('x', 6); accent.setAttribute('y', 6);
				accent.setAttribute('rx', 2);
				accent.setAttribute('fill', colorOf(n.type));
				gn.appendChild(accent);

				var lines = flowLines(n);
				var t1 = document.createElementNS(NS, 'text');
				t1.setAttribute('class', 'l1'); t1.setAttribute('x', 18); t1.setAttribute('y', 20);
				t1.textContent = lines[0];
				var t2 = document.createElementNS(NS, 'text');
				t2.setAttribute('class', 'l2'); t2.setAttribute('x', 18); t2.setAttribute('y', 35);
				t2.textContent = lines[1];
				var td = document.createElementNS(NS, 'text');
				td.setAttribute('class', 'dur'); td.setAttribute('x', FNW - 10); td.setAttribute('y', FNH - 8);
				td.setAttribute('text-anchor', 'end');
				td.textContent = fmtMs(n.dur) + (n.children.length ? ' · ' + n.children.length + ' ops' : '');
				gn.appendChild(t1); gn.appendChild(t2); gn.appendChild(td);

				if (flowCollapsed[n.id] && n.children.length) {
					var chipR = document.createElementNS(NS, 'rect');
					chipR.setAttribute('x', FNW - 44); chipR.setAttribute('y', 8);
					chipR.setAttribute('width', 36); chipR.setAttribute('height', 16);
					chipR.setAttribute('rx', 8); chipR.setAttribute('fill', colorOf(n.type));
					var chipT = document.createElementNS(NS, 'text');
					chipT.setAttribute('class', 'badge');
					chipT.setAttribute('x', FNW - 26); chipT.setAttribute('y', 19.5);
					chipT.setAttribute('text-anchor', 'middle');
					chipT.textContent = '+' + n.children.length;
					gn.appendChild(chipR); gn.appendChild(chipT);
				}

				gn.addEventListener('click', function (e) {
					e.stopPropagation();
					if (n.children.length) { flowCollapsed[n.id] = !flowCollapsed[n.id]; renderFlow(); }
				});
				gn.addEventListener('mousemove', function (e) { showTip(n, e); });
				gn.addEventListener('mouseleave', hideTip);
				gRoot.appendChild(gn);

				if (!flowCollapsed[n.id]) { n.children.forEach(nodesIn); }
			})(root);

			flowFit();
		}

		/* ---------- canvas fullscreen: only the graph canvas, not the page ---------- */
		function toggleCanvasFullscreen(canvas) {
			var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
			if (fsEl) {
				var exit = document.exitFullscreen || document.webkitExitFullscreen;
				if (exit) { exit.call(document); }
			} else {
				var req = canvas.requestFullscreen || canvas.webkitRequestFullscreen;
				if (req) { req.call(canvas); }
			}
		}
		function wireCanvasFullscreen(btnId, canvasId) {
			var btn = $(btnId);
			if (!btn) { return; }
			btn.addEventListener('click', function () { toggleCanvasFullscreen($(canvasId)); });
		}
		wireCanvasFullscreen('rtv-time-fs', 'rtv-g-time');
		wireCanvasFullscreen('rtv-wf-fs', 'rtv-wf-wrap');
		wireCanvasFullscreen('rtv-flow-fs', 'rtv-g-flow');

		// The tooltip lives outside the canvases; browsers only render the
		// fullscreened subtree, so shuttle the tip into it while active.
		function onCanvasFullscreenChange() {
			var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
			if (fsEl) { fsEl.appendChild(tip); } else { viewerEl.appendChild(tip); }
		}
		document.addEventListener('fullscreenchange', onCanvasFullscreenChange);
		document.addEventListener('webkitfullscreenchange', onCanvasFullscreenChange);

		(function flowPanZoom() {
			var svg = $('rtv-flow');
			var drag = null;
			svg.addEventListener('mousedown', function (e) {
				drag = { x: e.clientX, y: e.clientY, vx: flowView.x, vy: flowView.y };
				svg.classList.add('dragging');
			});
			window.addEventListener('mousemove', function (e) {
				if (!drag) { return; }
				var r = 1400 / Math.max(1, svg.clientWidth);
				flowView.x = drag.vx + (e.clientX - drag.x) * r;
				flowView.y = drag.vy + (e.clientY - drag.y) * r;
				flowApply();
			});
			window.addEventListener('mouseup', function () { drag = null; svg.classList.remove('dragging'); });
			svg.addEventListener('wheel', function (e) {
				e.preventDefault();
				var f = e.deltaY < 0 ? 1.12 : 1 / 1.12;
				var nk = Math.min(2.6, Math.max(0.25, flowView.k * f));
				var rect = svg.getBoundingClientRect();
				var mx = (e.clientX - rect.left) * (1400 / Math.max(1, rect.width));
				var my = (e.clientY - rect.top) * (760 / Math.max(1, rect.height));
				flowView.x = mx - (mx - flowView.x) * (nk / flowView.k);
				flowView.y = my - (my - flowView.y) * (nk / flowView.k);
				flowView.k = nk;
				flowApply();
			}, { passive: false });
			$('rtv-flow-in').addEventListener('click', function () { flowView.k = Math.min(2.6, flowView.k * 1.2); flowApply(); });
			$('rtv-flow-out').addEventListener('click', function () { flowView.k = Math.max(0.25, flowView.k / 1.2); flowApply(); });
			$('rtv-flow-fit').addEventListener('click', flowFit);
			$('rtv-flow-focus').addEventListener('click', function () { enterFlow(); renderFlow(); });
		})();

		/* ---------- graph dispatcher ---------- */
		function renderGraph() {
			var isFlow = (gmode === 'flow');
			$('rtv-g-time').hidden = isFlow;
			$('rtv-g-flow').hidden = !isFlow;
			flameHost.hidden = isFlow;
			if (isFlow) { renderFlow(); } else { renderTimeGraph(); }
			renderCrumbs();
			applyFilters();
		}

		function zoomTo(n) {
			currentRoot = n;
			renderGraph();
			renderWf();
		}

		$('rtv-gmodes').addEventListener('click', function (e) {
			var b = e.target.closest('button');
			if (!b) { return; }
			gmode = b.getAttribute('data-g');
			document.querySelectorAll('#rtv-gmodes button').forEach(function (x) { x.classList.toggle('on', x === b); });
			savePrefs({ graph: gmode });
			if (gmHint) { gmHint.textContent = 'Saved ✓'; setTimeout(function () { gmHint.textContent = 'Graph style'; }, 1200); }
			if (gmode === 'flow') { enterFlow(); }
			renderGraph();
		});
		crumbs.addEventListener('click', function (e) {
			var c = e.target.closest('.rtv-crumb');
			if (c) { zoomTo(nodes[+c.getAttribute('data-id')]); }
		});
		$('rtv-reset').addEventListener('click', function () { zoomTo(root); });
		flameHost.addEventListener('click', function () { zoomTo(root); });

		/* ---------- waterfall ---------- */
		var collapsed = {};
		function renderWf() {
			var wf = $('rtv-wf');
			wf.innerHTML = '';
			var base = currentRoot.start;
			var grid = '';
			for (var i = 0; i < 10; i++) { grid += '<i></i>'; }

			(function addRow(n) {
				var hidden = false;
				var a = (n.parent !== null && nodes[n.parent]) ? nodes[n.parent] : null;
				while (a) {
					if (collapsed[a.id]) { hidden = true; break; }
					a = (a.parent !== null && nodes[a.parent]) ? nodes[a.parent] : null;
				}
				if (hidden) { n.children.forEach(addRow); return; }

				var row = document.createElement('div');
				row.className = 'rtv-wf-row';
				if (!activeTypes[n.type]) { row.style.opacity = '.25'; }
				var caret = n.children.length ? '<span class="rtv-caret" data-id="' + n.id + '">' + (collapsed[n.id] ? '▸' : '▾') + '</span>' : '<span class="rtv-caret"></span>';
				var left = n.start / total * 100;
				var width = Math.max(0.25, n.dur / total * 100);
				var selfW = n.dur > 0 ? n.self / n.dur * 100 : 0;
				row.innerHTML =
					'<div class="rtv-wf-name" style="padding-left:' + (n.depth * 16) + 'px">' + caret +
					'<span class="rtv-wf-dot" style="background:' + colorOf(n.type) + '"></span>' +
					'<span class="rtv-wf-nm">' + esc(n.label) + '</span></div>' +
					'<div class="rtv-wf-track"><div class="rtv-wf-grid">' + grid + '</div>' +
					'<div class="rtv-wf-bar" style="margin-left:' + pct(left) + '%;width:' + pct(width) + '%;background:' + colorOf(n.type) + '">' +
					(selfW > 1 ? '<span class="rtv-wf-self" style="width:' + pct(selfW) + '%"></span>' : '') + '</div></div>' +
					'<div class="rtv-wf-dur"><b>' + fmtMs(n.dur) + '</b> <span>· self ' + fmtMs(n.self) + '</span></div>';
				row.addEventListener('mousemove', function (e) { showTip(n, e); });
				row.addEventListener('mouseleave', hideTip);
				wf.appendChild(row);
				n.children.forEach(addRow);
			})(root);

			wf.querySelectorAll('.rtv-caret[data-id]').forEach(function (el) {
				el.addEventListener('click', function (e) {
					e.stopPropagation();
					var id = +el.getAttribute('data-id');
					collapsed[id] = !collapsed[id];
					renderWf();
				});
			});
		}

		/* ---------- aggregated call tree ---------- */
		function renderTree() {
			var agg = {};
			nodes.forEach(function (n) {
				if (!agg[n.label]) { agg[n.label] = { label: n.label, type: n.type, count: 0, total: 0, self: 0 }; }
				agg[n.label].count++;
				agg[n.label].total += n.dur;
				agg[n.label].self += n.self;
			});
			var rowsArr = Object.keys(agg).map(function (k) { return agg[k]; })
				.filter(function (r) { return activeTypes[r.type]; })
				.sort(function (a, b) { return b.total - a.total; });
			$('rtv-tree').innerHTML = rowsArr.map(function (r) {
				var share = r.total / total * 100;
				return '<div class="rtv-tree-row">' +
					'<div class="nm"><span class="rtv-wf-dot" style="background:' + colorOf(r.type) + '"></span><span>' + esc(r.label) + '</span></div>' +
					'<div class="num"><b>' + fmtMs(r.total) + '</b></div>' +
					'<div class="num">' + fmtMs(r.self) + '</div>' +
					'<div class="rtv-tree-share"><i style="width:' + pct(Math.min(100, share)) + '%;background:' + colorOf(r.type) + '"></i></div>' +
					'</div>';
			}).join('');
		}

		/* ---------- tabs ---------- */
		document.querySelectorAll('.rtv-tab').forEach(function (t) {
			t.addEventListener('click', function () {
				document.querySelectorAll('.rtv-tab').forEach(function (x) { x.classList.remove('on'); });
				t.classList.add('on');
				var view = t.getAttribute('data-view');
				['graph', 'waterfall', 'tree'].forEach(function (v) {
					$('rtv-view-' + v).hidden = (v !== view);
				});
				savePrefs({ tab: view });
				// Re-render on activation: the graph may have been built while
				// its panel was hidden (zero widths break label measuring / fit).
				if (view === 'graph') { renderGraph(); }
				if (view === 'waterfall') { renderWf(); }
				if (view === 'tree') { renderTree(); }
			});
		});

		/* ---------- boot ---------- */
		if (gmode === 'flow') { enterFlow(); }
		var startTab = (PREFS.tab === 'waterfall' || PREFS.tab === 'tree') ? PREFS.tab : 'graph';
		if (startTab !== 'graph') {
			var tb = document.querySelector('.rtv-tab[data-view="' + startTab + '"]');
			if (tb) { tb.click(); }
		}
		renderGraph();
	}

	/* ---------- boot ---------- */
	document.addEventListener('DOMContentLoaded', function () {
		if (window.wp && window.wp.apiFetch && window.wp.apiFetch.createNonceMiddleware && ADMIN.nonce) {
			window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(ADMIN.nonce));
		}
		initCurl();
		initTraceList();
		initExplorer();
		initDemo();
		initViewer();
	});
})();
