# osc-php-portal

A small contacts portal, built to look like a typical small business PHP web app.
Used to test the OSC PHP My App runner (`eyevinn-php-runner`) with a realistic
Postgres-backed app: login, CSRF-protected forms, CRUD, file upload, and a
`/status` page that reports what the runtime actually looks like.

## Stack

- Plain PHP 8.3, no framework. Front controller at `public/index.php`.
- Composer with two real packages: `ramsey/uuid` (contact ids) and `nesbot/carbon`
  (human readable timestamps).
- Postgres via PDO (`pdo_pgsql`), installed by `setup.sh` since the stock runner
  image does not ship it.
- `setup.sh` also runs the idempotent migration in `db/migrate.php` and seeds one
  test login.

## Routes

- `/login`, `/logout`
- `/contacts` list, `/contacts/new`, `/contacts/{id}/edit`, `/contacts/{id}/delete`
- `/contacts/{id}/upload` file upload
- `/status` HTML status page, `/status.json` machine readable version
- anything else: pretty 404

## Environment

Read from the OSC parameter store at container start:

- `DATABASE_URL` postgres connection string
- `TEST_ENV_VAR` a plain test value, shown on `/status` to prove parameter store
  values reach the app

## Uploads and persistence

There is no persistent disk on a My App. File uploads are stored as `bytea` in
Postgres, which is the durable choice. `setup.sh` also drops a local marker file
in `/tmp` at container start, used by `/status` to detect a restart and to show
that anything written only to local disk (including PHP's default session
storage) does not survive one.
