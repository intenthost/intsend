# Intsend

A lightweight, high-performance headless REST API that bridges standard HTTP requests to cPanel SMTP servers using a Resend-compatible payload format.

Designed for internal microservices, serverless functions, background workers, and automated email sending without databases, dashboards, or external SaaS platform fees.

---

## Key Features

- **100% Headless**: Every route returns clean, structured JSON.
- **Zero Database / Zero SQL**: Entirely stateless. No tracking databases, tables, or storage overhead.
- **Resend-Compatible**: Drop-in compatible payload format (`POST /emails`).
- **Full Caller Control**: The caller controls all email metadata (`from`, `reply_to`, `to`, `cc`, `bcc`, `subject`, `html`, `text`, custom headers, attachments, and tags). The server imposes zero overrides.
- **Wildcard Sender Support**: Configure `ALLOWED_DOMAIN=yourdomain.com` in `.env` to allow sending from any `*@yourdomain.com` mailbox (`sales@...`, `support@...`, `alerts@...`, `any.name@...`).
- **Flexible Reply-To**: Full support for both name and email (e.g. `"Support Desk <help@externaldomain.com>"` or array of reply-to addresses).
- **Bearer Token Auth**: Protects endpoints with a single pre-shared `API_KEY`.

---

## Directory Structure

```text
.
├── .env                  # Configuration & SMTP credentials (keep private, do not commit)
├── .env.example          # Environment template
├── .htaccess             # Apache/LiteSpeed rewrite rules & protection
├── composer.json         # Dependency definitions
├── index.php             # Application entrypoint & error handler
├── install.php           # 1-Click zero-SSH web installer for cPanel
├── README.md             # Documentation
├── src/
│   ├── Env.php           # Lightweight .env parser
│   ├── Mailer.php        # PHPMailer SMTP handler
│   ├── Response.php      # Resend-compatible JSON responses
│   └── Router.php        # Fast request dispatcher
└── vendor/               # Dependencies & autoloader (installed via Composer or install.php)
```

---

## How Dependencies Work on cPanel (No SSH Required)

Most shared cPanel hosting does not have SSH or Composer available in the terminal. **Intent Mail provides two seamless ways to install dependencies:**

### Option A: 1-Click Zero-SSH Web Installer (`install.php`)
1. Upload the repository files to your cPanel directory (e.g. `public_html/` or `/home/username/email`).
2. Visit `https://email.yourdomain.com/install.php` in your browser.
3. The script will automatically download the official PHPMailer package, configure the PSR-4 autoloader inside `vendor/autoload.php`, and automatically delete itself once complete!

### Option B: Using Composer (If SSH / Terminal is available)
Run:
```bash
composer install --no-dev --optimize-autoloader
```

---

## Configuration (`.env`)

Copy `.env.example` to `.env` and supply your SMTP credentials:

```dotenv
# ── API Security ──────────────────────────────
# Bearer token required in the Authorization header
API_KEY=your_secret_api_key_here

# ── Allowed Sender Domain (Wildcard) ──────────
# Any *@this-domain will be accepted in the "from" field
# Example: nvme.in allows support@nvme.in, alerts@nvme.in, etc.
ALLOWED_DOMAIN=yourdomain.com

# ── cPanel SMTP ───────────────────────────────
# When hosted on the same cPanel server, use 127.0.0.1 on port 25
# For external SMTP, specify mail.yourdomain.com on port 587 (tls) or 465 (ssl)
SMTP_HOST=127.0.0.1
SMTP_PORT=25
SMTP_USERNAME=your_cpanel_username
SMTP_PASSWORD=your_cpanel_password
SMTP_ENCRYPTION=
# Options: tls | ssl | (leave empty for unencrypted localhost connections)

# ── Debug Mode ────────────────────────────────
# Set to 1 to enable verbose SMTP debugging in server logs
SMTP_DEBUG=0
```

---

## API Endpoints

### 1. Health Check

```http
GET / HTTP/1.1
Host: email.yourdomain.com
```

#### Response (`200 OK`)
```json
{
    "service": "Intsend",
    "version": "1.0.0",
    "status": "ok"
}
```

---

### 2. Send Email

```http
POST /emails HTTP/1.1
Host: email.yourdomain.com
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
```

#### Authentication
Requests must supply the API key in either of these headers:
- `Authorization: Bearer <API_KEY>`
- `X-API-Key: <API_KEY>`

#### Request Body (`application/json`)

```json
{
  "from": "Acme Notifications <alerts@yourdomain.com>",
  "to": ["customer@example.com"],
  "subject": "Invoice #1042 Ready",
  "html": "<h1>Invoice Ready</h1><p>Hi Alex, your invoice is ready for download.</p>",
  "text": "Hi Alex, your invoice is ready for download.",
  "reply_to": "Customer Care <support@yourdomain.com>",
  "cc": ["manager@example.com"],
  "bcc": ["archive@yourdomain.com"],
  "headers": {
    "X-Entity-Ref-ID": "inv_98234"
  },
  "attachments": [
    {
      "filename": "invoice.pdf",
      "content": "JVBERi0xLjQKJcOkw7zDtsOf..."
    }
  ],
  "tags": [
    { "name": "category", "value": "billing" }
  ]
}
```

#### Field Reference

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `from` | string | **Yes** | Sender address. Supports `"Name <user@domain>"` or `"user@domain"`. Must match `@ALLOWED_DOMAIN`. |
| `to` | string \| string[] | **Yes** | Single recipient or list of recipients. Accepts `"Name <email>"` or `"email"`. |
| `subject` | string | **Yes** | Subject line of the email. |
| `html` | string | **Optional\*** | HTML formatted email body (\*at least one of `html` or `text` is required). |
| `text` | string | **Optional\*** | Plain text email body fallback. |
| `reply_to` | string \| string[] | **Optional** | Custom Reply-To. Supports both `"Name <email>"` and `"email"`. Can be any external or internal address. |
| `cc` | string \| string[] | **Optional** | Carbon copy recipient(s). |
| `bcc` | string \| string[] | **Optional** | Blind carbon copy recipient(s). |
| `headers` | object | **Optional** | Custom key-value SMTP headers. |
| `attachments` | array | **Optional** | Array of objects: `[{"filename": "file.pdf", "content": "<base64_string>"}]`. |
| `tags` | array | **Optional** | Metadata tags added as `X-Tag-{name}: {value}` headers. |

#### Success Response (`200 OK`)

```json
{
    "id": "im_1a1d4d23623_3313890e2541b96e",
    "from": "Acme Notifications <alerts@yourdomain.com>",
    "to": [
        "customer@example.com"
    ],
    "created_at": "2026-11-13T12:11:48Z"
}
```

#### Error Responses

##### `401 Unauthorized`
```json
{
    "statusCode": 401,
    "name": "unauthorized",
    "message": "API key is invalid or missing."
}
```

##### `422 Unprocessable Content`
```json
{
    "statusCode": 422,
    "name": "validation_error",
    "message": "\"from\" domain \"otherdomain.com\" is not allowed. Only *@yourdomain.com addresses are permitted."
}
```

---

### 3. Check Status (Stateless)

```http
GET /emails/{id} HTTP/1.1
Host: email.yourdomain.com
Authorization: Bearer YOUR_API_KEY
```

#### Response (`200 OK`)
```json
{
    "id": "im_1a1d4d23623_3313890e2541b96e",
    "object": "email",
    "status": "sent",
    "note": "Intsend is stateless. Email tracking is not stored."
}
```

---

## Code Examples

### cURL

```bash
curl -X POST https://email.yourdomain.com/emails \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "Billing Team <billing@yourdomain.com>",
    "reply_to": "Support Helpdesk <help@anydomain.com>",
    "to": ["user@example.com"],
    "subject": "Monthly Statement",
    "html": "<p>Your statement is now available.</p>"
  }'
```

### Node.js / TypeScript (fetch)

```typescript
const response = await fetch("https://email.yourdomain.com/emails", {
  method: "POST",
  headers: {
    "Authorization": "Bearer YOUR_API_KEY",
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    from: "Sales Department <sales@yourdomain.com>",
    reply_to: "Lead Rep <rep@yourdomain.com>",
    to: ["prospect@example.com"],
    subject: "Following up on your request",
    html: "<p>Thanks for getting in touch!</p>",
  }),
});

const data = await response.json();
console.log(data);
```

### Python (requests)

```python
import requests

payload = {
    "from": "Security Alert <security@yourdomain.com>",
    "reply_to": "Security Team <soc@yourdomain.com>",
    "to": ["admin@example.com"],
    "subject": "New Login Detected",
    "text": "A new login was recorded on your account.",
}

headers = {
    "Authorization": "Bearer YOUR_API_KEY",
    "Content-Type": "application/json",
}

res = requests.post("https://email.yourdomain.com/emails", json=payload, headers=headers)
print(res.json())
```
