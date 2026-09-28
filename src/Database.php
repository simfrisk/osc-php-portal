<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private static ?string $lastError = null;

    public static function connect(): ?PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $databaseUrl = getenv('DATABASE_URL');
        if (!$databaseUrl) {
            self::$lastError = 'DATABASE_URL is not set';
            return null;
        }

        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['host'])) {
            self::$lastError = 'DATABASE_URL could not be parsed';
            return null;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 5432;
        $dbName = ltrim($parts['path'] ?? '', '/');
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            return null;
        }

        return self::$connection;
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function isReachable(): bool
    {
        $pdo = self::connect();
        if ($pdo === null) {
            return false;
        }
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
}
