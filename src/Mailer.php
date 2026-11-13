<?php

declare(strict_types=1);

namespace IntentMail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Mailer — cPanel SMTP wrapper, Resend-payload compatible.
 *
 * Payload shape (all keys mirror Resend's /emails endpoint):
 * {
 *   "from"        : "Name <addr@domain>" | "addr@domain"   (required)
 *   "to"          : string | string[]                       (required)
 *   "subject"     : string                                  (required)
 *   "html"        : string                                  (at least one of html/text required)
 *   "text"        : string
 *   "cc"          : string | string[]
 *   "bcc"         : string | string[]
 *   "reply_to"    : string | string[]
 *   "headers"     : { "X-Custom": "value", ... }
 *   "attachments" : [{ "filename": "...", "content": "<base64>" }]
 *   "tags"        : [{ "name": "...", "value": "..." }]     (stored as X-Tag headers)
 * }
 */
final class Mailer
{
    private string $allowedDomain;

    public function __construct()
    {
        $this->allowedDomain = strtolower(trim(Env::get('ALLOWED_DOMAIN')));
    }

    /**
     * Send an email from the validated payload.
     *
     * @param  array $payload  Decoded JSON body
     * @return array           {id, from, to, created_at}
     * @throws \RuntimeException on validation or SMTP failure
     */
    public function send(array $payload): array
    {
        // ── 1. Validate & extract fields ─────────────────────────────────

        $fromRaw = trim($payload['from'] ?? '');
        if ($fromRaw === '') {
            throw new \RuntimeException('"from" is required.');
        }

        [$fromName, $fromAddr] = $this->parseAddress($fromRaw);
        $this->assertDomainAllowed($fromAddr);

        $to = $this->normalizeAddressList($payload['to'] ?? []);
        if (empty($to)) {
            throw new \RuntimeException('"to" must contain at least one recipient.');
        }

        $subject = trim($payload['subject'] ?? '');
        if ($subject === '') {
            throw new \RuntimeException('"subject" is required.');
        }

        $html = $payload['html'] ?? '';
        $text = $payload['text'] ?? '';

        if ($html === '' && $text === '') {
            throw new \RuntimeException('At least one of "html" or "text" is required.');
        }

        $cc       = $this->normalizeAddressList($payload['cc']       ?? []);
        $bcc      = $this->normalizeAddressList($payload['bcc']      ?? []);
        $replyTo  = $this->normalizeAddressList($payload['reply_to'] ?? []);
        $headers  = $payload['headers']     ?? [];
        $attachments = $payload['attachments'] ?? [];
        $tags     = $payload['tags']        ?? [];

        // ── 2. Build PHPMailer instance ───────────────────────────────────

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = Env::get('SMTP_HOST');
        $mail->Port       = Env::int('SMTP_PORT', 587);
        $mail->Username   = Env::get('SMTP_USERNAME');
        $mail->Password   = Env::get('SMTP_PASSWORD');
        $mail->SMTPAuth   = true;
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;

        $enc = strtolower(Env::get('SMTP_ENCRYPTION', ''));
        $mail->SMTPSecure = match($enc) {
            'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
            'tls'  => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        if ($mail->SMTPSecure === '') {
            $mail->SMTPAutoTLS = false;
        }

        $debugLevel = Env::int('SMTP_DEBUG', 0);
        $mail->SMTPDebug = $debugLevel > 0 ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;

        // ── 3. Addresses ─────────────────────────────────────────────────

        // Allow user full control over From name & address without server override
        $mail->setFrom($fromAddr, $fromName);

        foreach ($to as [$addr, $name]) {
            $mail->addAddress($addr, $name);
        }
        foreach ($cc as [$addr, $name]) {
            $mail->addCC($addr, $name);
        }
        foreach ($bcc as [$addr, $name]) {
            $mail->addBCC($addr, $name);
        }
        foreach ($replyTo as [$addr, $name]) {
            $mail->addReplyTo($addr, $name);
        }

        // ── 4. Content ───────────────────────────────────────────────────

        $mail->Subject = $subject;

        if ($html !== '') {
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
        } else {
            $mail->isHTML(false);
            $mail->Body = $text;
        }

        // ── 5. Custom headers ────────────────────────────────────────────

        if (is_array($headers)) {
            foreach ($headers as $hName => $hValue) {
                $mail->addCustomHeader((string) $hName, (string) $hValue);
            }
        }

        // Tags → X-Tag-{name}: value headers
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (isset($tag['name'])) {
                    $tagName  = preg_replace('/[^a-zA-Z0-9\-_]/', '', $tag['name']);
                    $tagValue = $tag['value'] ?? '';
                    $mail->addCustomHeader("X-Tag-{$tagName}", (string) $tagValue);
                }
            }
        }

        // ── 6. Attachments ───────────────────────────────────────────────

        foreach ($attachments as $att) {
            $filename = $att['filename'] ?? 'attachment';
            $content  = $att['content']  ?? '';
            if ($content === '') {
                continue;
            }
            $decoded = base64_decode($content, strict: true);
            if ($decoded === false) {
                throw new \RuntimeException("Attachment \"{$filename}\" has invalid base64 content.");
            }
            $mail->addStringAttachment($decoded, $filename);
        }

        // ── 7. Send ──────────────────────────────────────────────────────

        try {
            $mail->send();
        } catch (MailerException $e) {
            throw new \RuntimeException('SMTP error: ' . $mail->ErrorInfo);
        }

        // ── 8. Build response ─────────────────────────────────────────────

        $id = $this->generateId();

        return [
            'id'         => $id,
            'from'       => $fromRaw,
            'to'         => array_column($to, 0),   // plain addresses
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Parse "Display Name <addr@domain>" or bare "addr@domain".
     * Returns [name, address].
     */
    private function parseAddress(string $raw): array
    {
        if (preg_match('/^(.+?)\s*<([^>]+)>\s*$/', $raw, $m)) {
            return [trim($m[1]), strtolower(trim($m[2]))];
        }
        return ['', strtolower(trim($raw))];
    }

    /**
     * Normalize "to" / "cc" / "bcc" / "reply_to" to [[address, name], …].
     */
    private function normalizeAddressList(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (is_string($input)) {
            $input = [$input];
        }
        if (!is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $item) {
            if (is_string($item) && trim($item) !== '') {
                [$name, $addr] = $this->parseAddress(trim($item));
                if ($addr !== '') {
                    $out[] = [$addr, $name];
                }
            }
        }
        return $out;
    }

    /**
     * Ensure the from address belongs to the configured domain.
     */
    private function assertDomainAllowed(string $addr): void
    {
        if ($this->allowedDomain === '') {
            return; // No restriction configured
        }

        $parts = explode('@', $addr, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException("\"from\" address is not a valid email: {$addr}");
        }

        $domain = strtolower($parts[1]);
        if ($domain !== $this->allowedDomain) {
            throw new \RuntimeException(
                "\"from\" domain \"{$domain}\" is not allowed. " .
                "Only *@{$this->allowedDomain} addresses are permitted."
            );
        }
    }

    /**
     * Generate a sortable, URL-safe unique ID (Resend uses re_xxxx style).
     * Format: im_<timestamp_hex>_<random_hex>
     */
    private function generateId(): string
    {
        $ts  = dechex((int)(microtime(true) * 1000));
        $rnd = bin2hex(random_bytes(8));
        return "im_{$ts}_{$rnd}";
    }
}

