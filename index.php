<?php

declare(strict_types=1);

/**
 * Intent Mail — Headless SMTP Email API
 *
 * Resend-compatible · cPanel SMTP · No DB · No Auth overhead
 * ──────────────────────────────────────────────────────────
 * All requests are routed here by .htaccess
 */

// ── Composer autoloader ──────────────────────────────────────────────────────
$autoloader = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'statusCode' => 500,
        'name'       => 'setup_required',
        'message'    => 'Composer dependencies are not installed. Run: composer install',
    ]);
    exit;
}

require $autoloader;

// ── Load .env ────────────────────────────────────────────────────────────────
\IntentMail\Env::load(__DIR__ . '/.env');

// ── Global error handler → JSON ──────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'statusCode' => 500,
        'name'       => 'internal_server_error',
        'message'    => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
});

set_error_handler(function (int $errno, string $errstr) {
    throw new \ErrorException($errstr, $errno);
});

// ── Dispatch ─────────────────────────────────────────────────────────────────
(new \IntentMail\Router())->dispatch();

