<?php

namespace App\Controllers;

use App\Database;
use App\Views;

class StatusController
{
    private static function collect(): array
    {
        $setupRan = is_file('/tmp/osc-setup-ran');
        $setupRanAt = $setupRan ? trim(file_get_contents('/tmp/osc-setup-ran')) : null;

        $uptimeSeconds = null;
        if (is_readable('/proc/uptime')) {
            $raw = trim(file_get_contents('/proc/uptime'));
            $uptimeSeconds = (float) explode(' ', $raw)[0];
        }

        return [
            'php_version' => PHP_VERSION,
            'loaded_extensions' => get_loaded_extensions(),
            'has_pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'db_reachable' => Database::isReachable(),
            'db_error' => Database::lastError(),
            'setup_ran' => $setupRan,
            'setup_ran_at' => $setupRanAt,
            'hostname' => gethostname(),
            'uptime_seconds' => $uptimeSeconds,
            'test_env_var' => getenv('TEST_ENV_VAR') ?: null,
            'server_time_utc' => gmdate('c'),
        ];
    }

    public static function json(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::collect(), JSON_PRETTY_PRINT);
    }

    public static function html(): void
    {
        $status = self::collect();
        $extensions = implode(', ', $status['loaded_extensions']);
        $dbLine = $status['db_reachable']
            ? '<span style="color:green">reachable</span>'
            : '<span style="color:#c53030">not reachable (' . Views::e($status['db_error']) . ')</span>';
        $setupLine = $status['setup_ran']
            ? 'yes, at ' . Views::e($status['setup_ran_at']) . ' UTC'
            : '<span style="color:#c53030">no marker file found</span>';

        $body = <<<HTML
<h1>Status</h1>
<table>
<tr><th>PHP version</th><td>{$status['php_version']}</td></tr>
<tr><th>pdo_pgsql loaded</th><td>{$status['has_pdo_pgsql']}</td></tr>
<tr><th>Database reachable</th><td>{$dbLine}</td></tr>
<tr><th>setup.sh ran</th><td>{$setupLine}</td></tr>
<tr><th>Hostname</th><td>{$status['hostname']}</td></tr>
<tr><th>Container uptime (seconds)</th><td>{$status['uptime_seconds']}</td></tr>
<tr><th>TEST_ENV_VAR from parameter store</th><td>{$status['test_env_var']}</td></tr>
<tr><th>Server time (UTC)</th><td>{$status['server_time_utc']}</td></tr>
</table>
<h2>Loaded extensions</h2>
<p><code>{$extensions}</code></p>
<p><a href="/status.json">View as JSON</a></p>
HTML;

        Views::layout('Status', $body);
    }
}
