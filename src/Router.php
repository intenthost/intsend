<?php

declare(strict_types=1);

namespace IntentMail;

/**
 * Router — minimal request dispatcher.
 *
 * Routes:
 *   POST   /emails          → send email
 *   GET    /emails/{id}     → echo id (stateless — we don't store emails)
 *   GET    /                → health check
 *   *                       → 404
 */
final class Router
{
    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Normalize path: strip query string, leading slash is kept
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->path = rtrim($uri ?: '/', '/') ?: '/';
    }

    public function dispatch(): never
    {
        // ── Health / root ─────────────────────────────────────────────────
        if ($this->path === '' || $this->path === '/') {
            if ($this->method !== 'GET') {
                Response::methodNotAllowed();
            }
            Response::ok([
                'service' => 'Intsend',
                'version' => '1.0.0',
                'status'  => 'ok',
            ]);
        }

        // ── POST /emails ──────────────────────────────────────────────────
        if ($this->path === '/emails' || $this->path === '/v1/emails') {
            if ($this->method !== 'POST') {
                Response::methodNotAllowed();
            }

            $this->requireAuth();

            $payload = $this->parseJsonBody();
            if ($payload === null) {
                Response::badRequest('Request body must be valid JSON.');
            }

            try {
                $mailer = new Mailer();
                $result = $mailer->send($payload);
                Response::ok($result, 200);
            } catch (\RuntimeException $e) {
                Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                Response::serverError($e->getMessage());
            }
        }

        // ── GET /emails/{id} ─────────────────────────────────────────────
        if (preg_match('#^(?:/v1)?/emails/([a-zA-Z0-9_\-]+)$#', $this->path, $m)) {
            if ($this->method !== 'GET') {
                Response::methodNotAllowed();
            }

            $this->requireAuth();

            // Stateless: we don't persist emails, so we echo back the id
            Response::ok([
                'id'     => $m[1],
                'object' => 'email',
                'status' => 'sent',
                'note'   => 'Intsend is stateless. Email tracking is not stored.',
            ]);
        }

        // ── 404 ──────────────────────────────────────────────────────────
        Response::notFound("No route for {$this->method} {$this->path}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Validate the Bearer token against API_KEY in .env.
     */
    private function requireAuth(): void
    {
        $apiKey = Env::get('API_KEY');

        if ($apiKey === '') {
            // Misconfigured server — block all requests
            Response::serverError('API_KEY is not configured.');
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['HTTP_X_API_KEY']
            ?? '';

        // Accept "Bearer <key>" or bare key in X-API-Key header
        $provided = preg_replace('/^Bearer\s+/i', '', $authHeader);
        $provided = trim($provided);

        if (!hash_equals($apiKey, $provided)) {
            Response::unauthorized();
        }
    }

    /**
     * Read and JSON-decode the raw request body.
     * Returns null on failure.
     */
    private function parseJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, associative: true);

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }
}

