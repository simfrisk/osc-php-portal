<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\StatusController;
use App\Views;

// Never leak stack traces to visitors. Errors and exceptions are logged to the
// Apache error log (visible via get-logs-for-instance) and the visitor gets a
// plain error page instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (\Throwable $e) {
    error_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<!doctype html><html><head><title>Something went wrong</title></head><body>'
        . '<h1>Something went wrong</h1><p>The error has been logged. Please try again.</p></body></html>';
});

set_error_handler(function ($severity, $message, $file, $line) {
    error_log("PHP error [{$severity}]: {$message} in {$file}:{$line}");
    return true;
});

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

// Simple explicit route table. Small app, no need for a routing library.
if ($path === '/' && $method === 'GET') {
    header('Location: /contacts');
    exit;
}

if ($path === '/login' && $method === 'GET') {
    AuthController::showLogin();
    exit;
}
if ($path === '/login' && $method === 'POST') {
    AuthController::login();
    exit;
}
if ($path === '/logout') {
    AuthController::logout();
    exit;
}

if ($path === '/contacts' && $method === 'GET') {
    ContactController::index();
    exit;
}
if ($path === '/contacts/new' && $method === 'GET') {
    ContactController::showNew();
    exit;
}
if ($path === '/contacts' && $method === 'POST') {
    ContactController::create();
    exit;
}

if (preg_match('#^/contacts/(\d+)/edit$#', $path, $m)) {
    if ($method === 'GET') {
        ContactController::showEdit((int) $m[1]);
        exit;
    }
    if ($method === 'POST') {
        ContactController::update((int) $m[1]);
        exit;
    }
}

if (preg_match('#^/contacts/(\d+)/delete$#', $path, $m) && $method === 'POST') {
    ContactController::delete((int) $m[1]);
    exit;
}

if (preg_match('#^/contacts/(\d+)/upload$#', $path, $m) && $method === 'POST') {
    ContactController::upload((int) $m[1]);
    exit;
}

if (preg_match('#^/contacts/(\d+)/upload/(\d+)$#', $path, $m) && $method === 'GET') {
    ContactController::download((int) $m[1], (int) $m[2]);
    exit;
}

if ($path === '/status' && $method === 'GET') {
    StatusController::html();
    exit;
}
if ($path === '/status.json' && $method === 'GET') {
    StatusController::json();
    exit;
}

// Pretty 404 for anything else.
http_response_code(404);
Views::layout('Not found', '<h1>404</h1><p>Page not found. Try <a href="/contacts">Contacts</a>.</p>');
