# OSC PHP My App Break Test Report - osc-php-portal (Phase 2)

Date: 2026-09-28
Scope: items 1 (load), 2 (resource limits), 3 (uploads), 4 (fatal errors/crash), 6 (restart under load), 7 (extensions). Item 5 (deploy failures) and the Gitea test were handed to a second agent mid-session; see `DEPLOY-FAILURE-REPORT-2026-09-28.md` for those.

Workspace confirmed as `simonwork` (Simon's own test workspace) before every write, re-checked repeatedly through this session via `get-active-workspace`.

Goal: find where the platform fails and how (clear error vs silent), not to polish our own app. Every finding below is labeled **PLATFORM BUG**, **PLATFORM LIMIT** (by design, worth documenting), or **OUR APP**.

## Resources used

- Baseline app (kept, unaffected by any of this): `phpportal`, `phpportaldb`, `phpportalcfg` — same as Phase 1.
- Throwaway app: `phpstress` (branches `stress` then `extensions` on `github.com/simfrisk/osc-php-portal`), deleted at the end of this session.
- No new database, parameter store, or Gitea instance was created by this agent for items 1-4/6/7.

## 1. Load test

Ran with Apache Bench (`ab`), ramped 10 -> 25 -> 50 -> 100 concurrent connections, capped at ~30-60s per stage (well under the "few minutes" bound), against the live `phpportal` app.

| Target | Concurrency | Result | p50 / p95 / p99 | Real errors |
|---|---|---|---|---|
| `/status.json` (public, touches DB with `SELECT 1`) | 10 | 66.7 req/s | 109 / 202 / 828 ms | 0 (all "Failed requests" from `ab` were body-length mismatches caused by the changing `uptime_seconds` field, not real failures — confirmed 0 Connect/Receive/Exceptions) |
| `/status.json` | 25 | 155 req/s | 135 / 233 / 289 ms | 0 |
| `/status.json` | 50 | 189 req/s | 237 / 386 / 527 ms | 0 |
| `/status.json` | 100 | 215 req/s | 403 / 697 / 1739 ms (max 5728 ms) | 0 (Connect: 0, Receive: 0, Exceptions: 0) |
| `/contacts` (authenticated, one shared session, heavier query with a subselect) | 10 | 50 req/s | 190 / 279 / 321 ms | 0 |
| `/contacts` | 25 | 43 req/s | 526 / 946 / 1155 ms | 0 |
| `/contacts` | 100 | 50 req/s | 1983 / 2294 / 5545 ms | 0 |

**Finding (OUR APP / general PHP fact, worth telling Tobias)**: at concurrency 100 on `/contacts`, request rate stayed flat around 50/s regardless of concurrency, and p50 latency climbed to ~2 seconds. Root cause: PHP's default file-based session handler takes an exclusive lock on the session file for the duration of each request, so concurrent requests sharing one session queue up single-file rather than running in parallel. This is standard PHP behavior everywhere, not an OSC platform limitation, but it matters for a real app: any page that fires multiple concurrent requests per session (typical for AJAX-heavy UIs) will serialize on this runner exactly as it would on any other mod_php host. Worth telling Tobias if his app does that.

**Verdict**: no platform errors found under load up to the 100-connection cap. `phpportal` and its logs (checked via `get-my-app-logs`) showed clean `200` responses throughout; the app was healthy immediately after each run.

## 2. Resource limits

All run against the throwaway `phpstress` app, never against `phpportal`.

Documented limits (`get-runtime-limits` for `php-runner`, flagged by the tool itself as "not independently verified against live Elastx"): memory 2Gi limit / 256Mi request, no CPU limit, nginx body size 1MiB, read/send timeout 60s, connect timeout 5s.

Live-measured PHP ini defaults on the runner image (`ini_get` via a stress endpoint): `memory_limit=128M`, `max_execution_time=30`, `upload_max_filesize=2M`, `post_max_size=8M`, `max_file_uploads=20`, `log_errors=0`, `display_errors=0`.

| Test | Result | Evidence |
|---|---|---|
| Memory allocation ramp (40/48/56/60/100 MB via repeated `str_repeat`) | PASS at 40-60MB, fails at 100MB — **matches PHP's own 128M `memory_limit`**, not the documented 2Gi container limit. Root cause: the allocation pattern's internal overhead roughly doubles reported peak usage (measured peaks: 40MB alloc -> 80MB peak, 60MB alloc -> 120MB peak), so ~64MB of raw allocation hits the 128M ceiling. | `peak=125829120` (120MB) succeeded at mb=60; mb=100 truncated mid-response with no further output |
| **Silent PHP fatal (OUR APP finding with a real platform angle)** | The 100MB allocation request returned **HTTP 200** with a truncated body and **nothing in `get-my-app-logs`** — no fatal error, no warning, nothing. Root cause confirmed via a deterministic repro (`ini_set('memory_limit','16M')` then over-allocate): **`log_errors` is `0` (Off) by default on this runner image.** PHP's real "Allowed memory size exhausted" fatal is only ever written via `log_errors`, so with it off, a genuine OOM-style fatal is invisible both to the client (200, silently truncated) and to `get-my-app-logs` (nothing at all) unless the app installs its own exception/error handler that calls `error_log()` directly (which bypasses the `log_errors` gate). Our own `set_exception_handler`/`set_error_handler` in `public/index.php` DID show up in logs for other tests (see next row) because they call `error_log()` explicitly — the runner's default configuration provides **zero** visibility into uncaught engine-level fatals. | mb=100 request: no matching log line at all, confirmed by fetching logs immediately after with no filter |
| Deterministic fatal via our own exception handler (undefined function call) | Caught by our handler, logged correctly via `error_log()`, e.g. `Unhandled exception: Call to undefined function ... in StressController.php:58` visible in `get-my-app-logs`. But the HTTP response was **200, not 500**, because the endpoint had already called `flush()` before the fatal, so `http_response_code(500)` inside the handler silently failed ("headers already sent") — also logged verbatim: `PHP error [2]: http_response_code(): Cannot set response code - headers already sent`. | Full log lines captured, see report body above |
| CPU burn, 10 seconds | Completed cleanly, no throttling observed (matches `cpu.limit: null` in the documented runtime limits) | `Done. x=8003458047.1273`, app stayed responsive throughout |
| Sleep 30s (under documented 60s proxy timeout) | Completed normally, full response received | `Woke up after 30s` |
| Sleep 75s (over documented 60s proxy timeout) | Connection was cut by an intermediate proxy at ~50 seconds — **not the documented 60s**, closer to 50s — with only `Sleeping 75s...` sent and no "Woke up" line. HTTP status was already 200 (headers sent before the cut). App recovered instantly for the next request. | `curl` total time 50.13s then connection closed; `/stress/ok` returned 200 immediately after |
| Hard crash: `posix_kill(getmypid(), SIGKILL)` on the request-handling worker | Client saw HTTP 200 with a truncated body (same "headers already sent" pattern as above). The app recovered **immediately** — next request succeeded with no delay. Apache's prefork model just lost one child worker and kept serving from the others; the container/pod was never affected. | `Terminating the PHP process immediately...` then connection drop; immediate follow-up request returned 200 |

**Overall pattern across ALL "mid-response" failure modes tested** (memory exhaustion, our own caught fatal, hard SIGKILL): the client **always** sees HTTP 200 with a truncated body, never a clean 5xx, because in every case some output had already been flushed before the failure. This is a general PHP/HTTP fact (once headers are sent, the status code can't change), not unique to OSC, but it means **any PHP app on this runner that streams output before finishing its work will show "silent" failures to callers** — worth telling Tobias explicitly since it affects how his app should be built (buffer output until you know the request will succeed) more than it reflects on the platform itself.

**PLATFORM finding worth flagging as a candidate**: `log_errors=0` by default on the php-runner image means uncaught engine-level errors (fatals, warnings, deprecations) are invisible in `get-my-app-logs` unless the app installs its own handler. Recommend the runner ship with `log_errors=On` (writing to stderr/stdout, which the platform already captures) while keeping `display_errors=Off` for security — this would give every PHP app on the runner baseline crash visibility without any code changes.

## 3. Upload size limits

Tested with an unauthenticated stress endpoint on `phpstress` (`/stress/upload`) that echoes back what PHP actually received, sweeping file sizes from 500KB to 50MB in single requests (no load, no risk).

| Upload size | Result | Notes |
|---|---|---|
| 500 KB, 900 KB, 1000 KB, 1100 KB, 2 MB | All succeeded, file reached PHP intact | Content-Length and file size matched exactly each time |
| 3 MB | Rejected by PHP: `$_FILES['file']['error'] = 1` (`UPLOAD_ERR_INI_SIZE`, exceeds `upload_max_filesize=2M`) | Clean, standard PHP error code, easy for an app to detect and report to the user |
| 10 MB and 50 MB | Rejected by PHP at the whole-request level (`post_max_size=8M`) — **and a raw PHP warning leaked into the response body**: `<br />\n<b>Warning</b>:  POST Content-Length of 10485974 bytes exceeds the limit of 8388608 bytes in <b>Unknown</b> on line <b>0</b><br />` | See finding below |

**PLATFORM BUG candidate**: the "POST Content-Length exceeds the limit" warning is emitted by PHP's SAPI **before the application's own script runs**, during request initialization. It leaks to the client verbatim **regardless of the app's `display_errors=0` setting** (confirmed: `ini_get('display_errors')` reports `"0"` in the exact same request, yet the warning still appears in the body). Any app on this runner accepting file uploads larger than `post_max_size` will leak this internal PHP warning to end users no matter how carefully the app itself is written, since the app never gets a chance to run. Not sensitive information (no stack trace, no file paths), but it does contradict the platform's implicit promise of clean error handling and looks unprofessional in a real product.

**PLATFORM DOCS MISMATCH**: `get-runtime-limits` claims an nginx ingress body size limit of "1MiB" (with its own caveat that this figure is "not independently verified"). Live testing directly contradicts this: uploads up to 50MB reached PHP intact at the request-parsing level (Content-Length fully received by nginx and handed to PHP) with no 413 or ingress-level rejection at any size tested. The actual, real, binding limit is **PHP's own `post_max_size` (8M) and `upload_max_filesize` (2M)** — there does not appear to be a separate, lower ingress cap in practice. Recommend correcting or re-verifying the `get-runtime-limits` tool's nginx `bodySize` figure.

**For Tobias**: 2MB per file / 8MB total request are quite small for a real business app handling photos or PDFs. Since `AllowOverride All` is enabled (`.htaccess` works, confirmed in Phase 1), a `.htaccess` with `php_value upload_max_filesize 20M` / `php_value post_max_size 25M` should work with mod_php — not tested this round, worth a quick check before recommending it as the fix.

## 4. Fatal error / crash recovery

Covered above under Resource Limits (the fatal-error and SIGKILL rows) since they were run as part of the same test pass. Summary:

- A caught fatal (via our own exception handler): logged correctly, but response status silently stayed 200 due to output already being flushed — **OUR APP** pattern to fix in a template for future apps (buffer before writing).
- An uncaught, engine-level fatal (real memory exhaustion): completely silent, no client-visible error, no log line — **PLATFORM finding** (see `log_errors=0` above).
- A hard SIGKILL of the worker process: app recovered instantly, no pod restart, no visible disruption to subsequent requests — **PLATFORM PASS**, Apache's prefork model isolates a single worker crash cleanly.

## 6. Restart / redeploy under light load

Ran a continuous probe against `phpportal`'s `/status.json` every 0.2s (200 requests over ~40s) while calling `restart-my-app` (no `rebuild` flag) partway through.

| Metric | Result |
|---|---|
| Total requests | 200 |
| Failed requests | **1** (a single `502`) |
| Downtime window | Under ~600ms — one dropped request between two successful ones roughly 300ms apart |
| Contact/upload data | Survived (separate Postgres instance, unaffected) |
| App HA mode | `haEnabled` reported as "unknown (backend did not return field)" by `get-my-app` — could not confirm whether HA was silently active |

**PLATFORM PASS, worth telling Tobias**: even without explicitly enabling HA mode, a plain `restart-my-app` produced a near-zero-downtime deploy for this app — a single missed request out of 200 probed at 5/sec. This is good news for a small business app that can tolate the rare dropped request but would suffer from a multi-second outage on every deploy. Note the small caveat: `get-my-app`'s `HA Mode` field did not resolve to a real value, so this can't be attributed with certainty to a documented mechanism versus a lucky fast pod swap — worth Simon confirming with the platform team whether zero-downtime restarts are guaranteed or just usually fast.

## 7. Extensions (gd, intl, zip)

A typical small-business PHP app (Laravel or WordPress-style) commonly needs `gd` (image processing), `intl` (number/date formatting), and `zip` (archives) in addition to a database driver. None of these ship in the stock runner image, same as `pdo_pgsql` in Phase 1.

Added a `setup.sh` that installs the required system libraries (`libpng-dev`, `libjpeg-dev`, `libfreetype6-dev`, `libzip-dev`, `libicu-dev`) via `apt-get`, then compiles `gd`, `intl`, `zip`, and `pdo_pgsql` via `docker-php-ext-install`, and deployed it to the throwaway app.

| Metric | Result |
|---|---|
| Extensions loaded after build | `gd`, `intl`, `pdo_pgsql`, `zip` all confirmed present in `/status.json`'s `loaded_extensions` list |
| Total time, ref switch to fully ready | ~91 seconds wall clock (from triggering `update-my-app-source-ref` to `wait-for-app-ready` returning `ready:true`) |
| Build failures | None — `apt-get`, `docker-php-ext-configure`, and `docker-php-ext-install` all completed without error |

**PLATFORM LIMIT worth documenting clearly for Tobias**: extension compilation happens in `setup.sh`, which runs **on every container start, not just the first deploy** — confirmed already in Phase 1 (the `/tmp` marker file and hence "setup ran" timestamp changes on every restart). This means a real app needing `gd`+`intl`+`zip`+a DB driver pays roughly this same ~60-90 second compile tax on **every single restart or redeploy**, not only the first one, because there is no build cache or persistent layer for compiled extensions between container recreations. For a small business app that gets redeployed frequently (bug fixes, content updates), this adds meaningful latency to every deploy cycle compared to a platform that bakes extensions into a custom image layer. Worth mentioning to Tobias as a real trade-off of the current runner design, not a blocker.

## Summary table

| # | Test | Verdict |
|---|---|---|
| 1 | Load ramp to 100 concurrent | PASS — no platform errors; PHP session-file locking causes expected serialization on shared-session concurrent access (OUR APP / general PHP fact) |
| 2 | Memory allocation | PLATFORM BUG candidate — `log_errors=0` by default hides real OOM fatals from both client and logs |
| 2 | CPU burn | PASS — no limit enforced, no issues |
| 2 | Sleep past proxy timeout | PLATFORM LIMIT — actual cutoff closer to 50s than the documented 60s; worth re-verifying the exact number |
| 2 | Hard crash (SIGKILL) | PASS — instant recovery via Apache prefork, no pod impact |
| 3 | Upload size | PLATFORM BUG candidate — internal PHP warning leaks to client regardless of `display_errors`; PLATFORM DOCS MISMATCH — real limit is PHP's own 2M/8M, not the documented 1MiB ingress cap |
| 4 | Fatal error visibility | Covered in item 2 |
| 6 | Restart under light load | PASS — near-zero downtime observed (1 of 200 requests failed) |
| 7 | Extension install (gd/intl/zip) | PASS functionally, but PLATFORM LIMIT — recompiles on every restart, adds ~60-90s to every deploy cycle |

## Candidates for a ticket (not filed, per instructions)

1. `log_errors=0` by default on the php-runner PHP image hides genuine fatal errors from `get-my-app-logs` — recommend defaulting to On.
2. The "POST Content-Length exceeds the limit" PHP warning leaks to the client regardless of the app's `display_errors` setting, since it fires before the app's script runs.
3. `get-runtime-limits`'s documented nginx ingress body size ("1MiB") does not match observed behavior (uploads up to 50MB reached PHP intact); the tool's own caveat already flags this as unverified — recommend either verifying and correcting it, or removing the unverified figure.
4. The documented 60s nginx read/send timeout appears to actually cut connections closer to 50s in practice — worth re-verifying the exact figure.

See `DEPLOY-FAILURE-REPORT-2026-09-28.md` for additional, more severe candidates found by the second agent (an app-record orphaning bug and a misleading-200-on-failed-build bug).
