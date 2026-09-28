<?php
// Idempotent migration and seed script. Run by setup.sh at container start, before
// Apache starts serving requests. Safe to run every time the container boots.

$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl === false || $databaseUrl === '') {
    fwrite(STDERR, "migrate.php: DATABASE_URL is not set, skipping migration\n");
    exit(0);
}

$parts = parse_url($databaseUrl);
if ($parts === false || !isset($parts['host'])) {
    fwrite(STDERR, "migrate.php: DATABASE_URL could not be parsed\n");
    exit(1);
}

$host = $parts['host'];
$port = $parts['port'] ?? 5432;
$dbName = ltrim($parts['path'] ?? '', '/');
$user = $parts['user'] ?? '';
$pass = $parts['pass'] ?? '';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "migrate.php: could not connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS contacts (
    id SERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS uploads (
    id SERIAL PRIMARY KEY,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    data BYTEA NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL);

// Seed one test login, only if the users table is empty. The password is fixed
// on purpose so the integrator report can hand it to Simon in plaintext.
$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count === 0) {
    $testPassword = 'PortalTest2026!';
    $hash = password_hash($testPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?) ON CONFLICT (username) DO NOTHING');
    $stmt->execute(['testuser', $hash]);
    fwrite(STDOUT, "migrate.php: seeded test user 'testuser'\n");
}

fwrite(STDOUT, "migrate.php: migration complete\n");
