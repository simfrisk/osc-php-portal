<?php

namespace App\Controllers;

// Deliberately dangerous endpoints, used only to find OSC My App platform limits
// (memory ceiling, CPU handling, proxy timeouts, crash recovery) on a throwaway
// app. Never deployed on the real phpportal app.
class StressController
{
    public static function memory(): void
    {
        $mb = isset($_GET['mb']) ? (int) $_GET['mb'] : 64;
        header('Content-Type: text/plain');
        echo "Allocating {$mb} MB...\n";
        flush();
        $chunks = [];
        for ($i = 0; $i < $mb; $i++) {
            $chunks[] = str_repeat('x', 1024 * 1024);
            if ($i % 32 === 0) {
                echo "at {$i} MB, memory_get_usage=" . memory_get_usage(true) . "\n";
                flush();
            }
        }
        echo "Done, allocated {$mb} MB, peak=" . memory_get_peak_usage(true) . "\n";
    }

    public static function cpu(): void
    {
        $seconds = isset($_GET['seconds']) ? (int) $_GET['seconds'] : 10;
        header('Content-Type: text/plain');
        echo "Burning CPU for {$seconds}s...\n";
        flush();
        $end = microtime(true) + $seconds;
        $x = 0;
        while (microtime(true) < $end) {
            $x += sqrt((float) random_int(1, 1000000));
        }
        echo "Done. x={$x}\n";
    }

    public static function sleep(): void
    {
        $seconds = isset($_GET['seconds']) ? (int) $_GET['seconds'] : 30;
        header('Content-Type: text/plain');
        echo "Sleeping {$seconds}s...\n";
        flush();
        sleep($seconds);
        echo "Woke up after {$seconds}s\n";
    }

    public static function fatal(): void
    {
        header('Content-Type: text/plain');
        echo "About to trigger a fatal error...\n";
        flush();
        // Calling an undefined function is a catchable-by-nothing fatal error in
        // PHP 8, not a normal exception, so it exercises the true fatal-error path.
        undefinedFunctionCausesFatalError();
    }

    public static function crash(): void
    {
        header('Content-Type: text/plain');
        echo "Terminating the PHP process immediately...\n";
        flush();
        // posix_kill on our own pid, hardest crash short of a segfault, to see
        // whether Apache and the pod both recover.
        if (function_exists('posix_getpid') && function_exists('posix_kill')) {
            posix_kill(posix_getpid(), 9);
        } else {
            exit(1);
        }
    }

    public static function ok(): void
    {
        header('Content-Type: text/plain');
        echo "stress app ok, pid=" . getmypid() . "\n";
    }

    public static function iniReport(): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'max_input_time' => ini_get('max_input_time'),
            'log_errors' => ini_get('log_errors'),
            'error_log' => ini_get('error_log'),
            'display_errors' => ini_get('display_errors'),
        ], JSON_PRETTY_PRINT);
    }

    // Deterministic memory_limit fatal: lowers the limit at runtime, then
    // allocates past it, to see whether PHP's own fatal error is visible
    // to the client and to get-my-app-logs.
    public static function memoryLimitFatal(): void
    {
        ini_set('memory_limit', '16M');
        header('Content-Type: text/plain');
        echo "memory_limit lowered to 16M, allocating past it...\n";
        flush();
        $chunks = [];
        for ($i = 0; $i < 64; $i++) {
            $chunks[] = str_repeat('x', 1024 * 1024);
        }
        echo "Should not reach here.\n";
    }
}
