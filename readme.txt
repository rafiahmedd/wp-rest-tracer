=== REST API Tracer ===
Contributors: rafiahmedd
Tags: rest-api, debugging, profiling, flame-graph, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Record REST API endpoints by namespace, trace everything between request and response, and see it as an interactive flame graph with per-operation timings.

== Description ==

REST API Tracer answers "what exactly happens inside this endpoint, and where does the time go?"

1. **Endpoint discovery** — enter a namespace (e.g. `wc/v3`, `myshop/v1`) and the plugin lists every route under it: methods, callback, permission callback and required args.
2. **Tracing** — when a matching request comes in, the tracer wraps the whole request lifecycle in a nested call tree:
   * every hook / filter callback that runs (via transparent callback wrapping),
   * every SQL query (with caller, per-query wall time),
   * every outbound HTTP request (URL, method, status, duration),
   * the REST handler itself (permission + callback bracket) and the total request time.
3. **Visual diagram** — each trace is stored and rendered in wp-admin as an interactive **flame graph** (click to zoom, search, hover for timings) plus a **waterfall** timeline with per-operation durations, memory and a summary of time by operation type. Traces can be exported as JSON or a GraphViz DOT diagram.

**Trigger modes**

* *Header mode (default, safe for production)*: only requests carrying `X-REST-Tracer: <your token>` are traced.
* *Always mode*: every request to a watched namespace is traced.

Send the header manually, e.g.:

`curl -X POST https://site.com/wp-json/myshop/v1/orders -H "X-REST-Tracer: YOUR_TOKEN" ...`

**What gets measured** — every operation the tracer records has an inclusive duration (entry → exit) and the flame graph computes self-time per node, so you can see exactly which hook, query or HTTP call consumes the request time.

**Scope note** — this is a pure-PHP tracer: it captures everything that flows through WordPress' hook system, WPDB and the HTTP API, plus the REST dispatch brackets. Raw internal PHP calls that never pass through those layers (e.g. a plain `str_replace()` in a controller) are not individually visible — that level of detail requires a PHP profiler extension (Xdebug / Tideways), which is a possible future integration.

== Installation ==

1. Upload the `wp-rest-tracer` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **Tools → REST Tracer → Settings**, enter the namespaces you want to watch (comma separated) and copy the trigger token.
4. Hit an endpoint with the `X-REST-Tracer` header (or switch to Always mode), then open **Tools → REST Tracer → Traces**.

== Frequently Asked Questions ==

= Is it safe to leave active on production? =

Yes, in the default Header mode. Nothing is traced (and near-zero overhead is added) unless a request carries the secret header token. Tracing itself adds per-hook overhead, so avoid long "Always" sessions on busy traffic.

= Will it slow down my endpoints? =

Only during an actual trace. Wrapping hook callbacks adds a small constant cost per hook invocation plus two `microtime()` calls — negligible for debugging sessions.

= Where are traces stored? =

In a custom table (`wp_rest_tracer_traces`), gz-compressed when large, auto-pruned to the retention limit (default: last 50 traces).

= My plugin removes its hooks with remove_action() and something behaves oddly while tracing =

Callback wrapping re-registers callbacks as tracing wrappers. Hook removals executed *during* a traced request (rare) may not match the wrapper. Wrapping only lasts for the duration of the single traced request.

== Changelog ==
= 1.0.0 =
* Initial release: namespace endpoint explorer, header/always trigger modes, nested call-tree tracer (hooks, SQL, HTTP, REST brackets), flame graph + waterfall viewer, JSON/DOT export, demo endpoint.
