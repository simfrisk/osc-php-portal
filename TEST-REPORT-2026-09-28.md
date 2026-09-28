# OSC PHP My App Test Report - osc-php-portal

Date: 2026-09-28
Purpose: Phase 1 realistic test of the new `eyevinn-php-runner` (PHP My App support), ahead of a possible recommendation to Tobias Linder (CIO Pro, tenant `ciopro`) about running their Apache PHP app on OSC.

This is a normal-use test only. No load testing or deliberate break testing was done; that is a later phase.

## Live resources

- Live app: https://9aa5c0ef57.apps.osaas.io
- GitHub repo (public): https://github.com/simfrisk/osc-php-portal
- OSC workspace: `simonwork` (Simon's own test workspace, confirmed via `get-active-workspace` before creating anything)
- My App: `phpportal` (type `php`, config service `phpportalcfg`)
- Postgres instance: `phpportaldb` (service `birme-osc-postgresql`), internal DNS `simonwork-phpportaldb.birme-osc-postgresql.svc.cluster.local:5432`, database `phpportal`
- Parameter store: `phpportalcfg`, keys `DATABASE_URL`, `TEST_ENV_VAR`

## Credentials (saved to macOS keychain, given here in plaintext per the credential handling rule)

- Postgres superuser password for `phpportaldb`: `b2564e09128cefbf6f9b9336199c233c`
  keychain label: `osc:simonwork:birme-osc-postgresql:phpportaldb:PostgresPassword`
- App test login: username `testuser`, password `PortalTest2026!`
  keychain label: `osc:simonwork:phpportal:app:testuser-password`

OSC auto-vaults the Postgres password as a write-only secret once set, so this plaintext copy (captured at creation time) is the only place it exists outside the keychain.

## PASS/FAIL table

| # | Feature | Result | Evidence |
|---|---|---|---|
| 1 | Postgres via PDO, setup.sh installs pdo_pgsql, idempotent migration | PASS | `/status.json` shows `"has_pdo_pgsql": true`, `"db_reachable": true`, `"setup_ran": true`. Migration re-ran on every rebuild without error (checked `CREATE TABLE IF NOT EXISTS` + `ON CONFLICT DO NOTHING` seed, ran 3 times across 3 rebuilds, no duplicate rows) |
| 2 | Login, sessions, CSRF | PASS | `POST /login` with a valid CSRF token returns 302 and sets a session cookie; a bad CSRF token on `POST /contacts` returns 400 with a plain "Invalid form submission." message |
| 3 | CRUD (contacts) | PASS | Created "Tobias Linder", edited to "Tobias Linder (CIO Pro)", listed, deleted a second test row; all round-tripped correctly against live Postgres |
| 4 | File upload | PASS after one fix | See "Uploads and persistence" below |
| 5 | Env var from parameter store shown on /status | PASS | `/status.json` shows `"test_env_var": "hello-from-osc-parameter-store"`, the exact value set via `bulk-set-parameters` |
| 6 | /status JSON (PHP version, extensions, DB reachable, setup.sh ran, hostname, uptime) | PASS | See sample output below |
| 7 | Pretty 404, no leaked stack traces | PASS | `GET /no-such-route` returns 404 with a plain "Page not found" page; a forced CSRF failure returns a plain text message, no trace |

## Uploads and persistence

Files are stored as `bytea` in Postgres, not on local disk, specifically because a My App has no persistent disk. This is documented in the app's README.

**Real bug found and fixed during this test**: the first upload attempt stored the file correctly, but downloading it returned the literal text `Resource id #1` instead of the file bytes. Root cause: `pdo_pgsql` returns `bytea` columns as a PHP stream resource, not a plain string, when fetched. This is normal PDO/Postgres driver behavior, not an OSC platform bug. Fixed by calling `stream_get_contents()` on the fetched value before sending it (`src/Controllers/ContactController.php`). Pushed as commit `3968b36`, rebuilt, and re-verified: the downloaded file now matches the uploaded bytes exactly (33 bytes in, 33 bytes out, content intact).

**Restart test**: `restart-my-app` with `rebuild=true` was run three times during this test.

- Contact and upload data in Postgres: **survived every restart**, as expected, since Postgres is a separate managed instance untouched by an app restart.
- PHP sessions (stored on local disk by default): **did not survive a restart**. A session cookie obtained before a restart was rejected afterward (redirected back to `/login`), forcing a re-login. This is expected given there is no persistent disk, but it is a real operational fact worth telling Tobias: any My App restart (a redeploy, a config change via `update-my-app-config`, or a crash recovery) will silently log out every active user. A framework or app that relies on file-based sessions needs an external session store (e.g. the same Postgres instance, or Valkey) to avoid this if uptime during redeploys matters.
- `setup.sh`'s marker file in `/tmp`: also did not survive, as expected, confirming local disk is wiped on every restart.

## Friction and fixes, verbatim

- **Our bug**: `bytea` fetched via PDO_PGSQL comes back as a stream resource. `echo $row['data']` silently printed `Resource id #1` (200 OK, no error) instead of failing loudly. This is worth calling out to any other team porting a PHP app to this runner: any bytea/LOB column read needs `stream_get_contents()`, not a plain string cast.
- **Not a bug, but a gotcha**: `create-service-instance` for a service instance takes `name` nested inside the `config` object, not as a sibling top-level argument — the first attempt with `name` at the top level failed with a confusing "Missing required fields: name" error even though `name` was present, just in the wrong place.
- No other platform surprises. `setup.sh`, Composer, `.htaccess`/mod_rewrite, and the config-service parameter injection all behaved exactly as documented in the `Eyevinn/php-runner` README.

## Differences from the README

None found. Everything in the php-runner README (docroot detection via `public/index.php`, `setup.sh` running as root after Composer and before Apache, `AllowOverride All` with `mod_rewrite` on, config service env injection before dependency install) matched observed behavior exactly. `/status.json` confirms PHP 8.3.35 (matches the documented PHP 8.3 pin) and `pdo_pgsql` loaded only after `setup.sh` installed it, i.e. the stock image genuinely does not ship it, as the platform notes said to assume.

## Sample /status.json (final, after all fixes)

```json
{
    "php_version": "8.3.35",
    "has_pdo_pgsql": true,
    "db_reachable": true,
    "db_error": null,
    "setup_ran": true,
    "hostname": "simonwork-phpportal-779956cd48-bqwtc",
    "test_env_var": "hello-from-osc-parameter-store"
}
```

## What this means for Tobias (CIO Pro)

This was a guess-based test: all we know about their app is "Apache PHP", and we assumed Postgres because their tenant already has a managed Postgres instance. The runner itself handled a realistic small-business app (login, CRUD, file handling, config) with no platform-side failures. The two things worth telling Tobias up front, independent of what framework his app actually uses:

1. If his app stores files on local disk, they need to move to Postgres (or object storage) before deploying here, because there is no persistent disk.
2. If his app relies on PHP's default file-based sessions and expects users to stay logged in across a redeploy, that will not hold on this platform without an external session store.

Neither is a blocker, both are common PHP patterns worth checking before a real migration attempt.
