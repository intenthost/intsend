<?php

declare(strict_types=1);

namespace IntentMail;

/**
 * Response — JSON response factory, Resend-compatible shape.
 */
final class Response
{
    // ── Success ──────────────────────────────

    public static function ok(array $data, int $status = 200): never
    {
        self::send($data, $status);
    }

    // ── Errors ───────────────────────────────

    public static function error(string $name, string $message, int $status): never
    {
        self::send([
            'statusCode' => $status,
            'name'       => $name,
            'message'    => $message,
        ], $status);
    }

    public static function unauthorized(string $message = 'API key is invalid or missing.'): never
    {
        self::error('unauthorized', $message, 401);
    }

    public static function badRequest(string $message): never
    {
        self::error('validation_error', $message, 422);
    }

    public static function notFound(string $message = 'Resource not found.'): never
    {
        self::error('not_found', $message, 404);
    }

    public static function methodNotAllowed(): never
    {
        self::error('method_not_allowed', 'HTTP method not allowed.', 405);
    }

    public static function serverError(string $message = 'Internal server error.'): never
    {
        self::error('internal_server_error', $message, 500);
    }

    // ── Core sender ──────────────────────────

    private static function send(array $data, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

