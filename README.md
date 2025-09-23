# PHP SMTP Mailer - API & Web UI
A self-hosted, robust PHP application for sending emails via API on your SMTP server. It features a secure API for integration with third-party applications and a web-based admin panel for managing sending profiles, API tokens, and monitoring email activity.

NOTE: Not a replacement for more robust solutions like SendGrid, MailJet, MailGun, or similar services. Sending bulk email through your email server can get it blacklisted and prevent it from being able to deliver legitimate non-bulk emails to real recipients.

-----

## Overview
This application serves as a centralized email-sending service. Instead of embedding SMTP credentials and logic into multiple applications, you can have them all communicate with this service's simple API. It queues emails to ensure that API requests are fast and sending happens reliably in the background.

## Key Features

- Multiple Sending Profiles: Configure and send from multiple different SMTP accounts (e.g., sales@example.com, support@example.com).

- Secure API: A token-based API to queue emails for sending and check their status.

- Admin Web UI: A password-protected interface to manage the entire application.

  - Dashboard with at-a-glance statistics.

  - Full CRUD (Create, Read, Update, Delete) for sending profiles.

  - API token management per profile.

  - Detailed email history with filtering and search.

  - IP-based rate limiting monitoring and management.

- Background Processing: Emails are sent by a background script (cron job), making API responses fast and resilient to SMTP server delays.

- Rate Limiting: Protect your service from abuse with configurable, IP-based rate limiting on the API.

- Secure Credential Storage: SMTP passwords are encrypted in the database.

- Log Management: Automatically prunes old email history to keep the database lean.

```
Folder Structure
/
├── admin/                 # Private Admin Web UI
│   ├── includes/          # Header, Footer, Auth Check
│   ├── assets/            # (Future use for CSS/JS)
│   ├── ... (pages)
├── api/                   # Public API endpoints
│   ├── v1/
│   │   ├── send.php
│   │   └── status.php
├── background/            # Scripts for cron jobs
│   ├── process_queue.php
│   └── prune_logs.php
├── vendor/                # Composer dependencies (PHPMailer)
├── config.php             # Core configuration
├── composer.json          # Dependency list
├── database_schema.sql    # Initial DB setup
├── .htaccess              # Apache configuration
└── README.md              # This file
```

# Setup Instructions

**Prerequisites**
- PHP 8.0+ (with pdo_mysql extension)

- MariaDB or MySQL Database Server

- Composer for dependency management

- A web server (Apache, Nginx, etc.)

- Access to schedule cron jobs (sudo/root)


1. Installation
- Clone the repository:

```
git clone https://github.com/zeighy/api4smtp_php
cd api4smtp_php
```

- Install PHP dependencies:

```
composer install
```

This will download PHPMailer and create the vendor/ directory.


2. Database Setup

- Create a new database in your MySQL/MariaDB server.

- Import the schema using the provided SQL file. You can use a tool like phpMyAdmin or the command line:

```
mysql -u your_db_user -p your_database_name < database_schema.sql
```

This will create all the necessary tables.


3. Configuration

Rename `config.php.example` to `config.php` (if you have an example file, otherwise create it).

Edit `config.php` and fill in your database credentials:

```
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
```

Crucially, generate a secure encryption key. You can use an online generator or a command-line tool. Do not use the default key.
```
// Example command to generate a key:
// openssl rand -base64 32
define('ENCRYPTION_KEY', 'your-super-secret-32-byte-random-string');
```

4. Web Server Configuration (Apache Example)

For security, it is highly recommended to configure your web server so that **only the `api/` directory is publicly accessible.**
The `admin/` directory should be protected, for example by IP whitelist or HTTP Basic Authentication in addition to the application's own login.
Alternatively, you can also just not expose `admin/` to the public internet but is accessible via Tailscale, ZeroTier, or Cloudflare Zero Trust.

5. Cron Job Setup

The application relies on background tasks to function. You need to schedule two scripts to run automatically.

Process Email Queue (runs frequently): This script sends the emails. Schedule it to run every minute.

```
* * * * * /usr/bin/php /path/to/your/project/background/process_queue.php >> /dev/null 2>&1
```

Prune Old Logs (runs daily): This script cleans the database. Schedule it to run once a day.

```
0 2 * * * /usr/bin/php /path/to/your/project/background/prune_logs.php >> /dev/null 2>&1
```

(This example runs at 2:00 AM every day)

# Usage

## Admin Panel

Access the admin panel by navigating to `https://yourdomain.com/admin/login.php`  assuming it is publicly accessible, or `http://192.168.0.99/admin/login.php` if only exposed to a local network. 

**Default Credentials:** The initial login is stored in the settings table. By default, it is:

- Username: admin
- Password: password

** IMPORTANT: Change the default password immediately from the Settings page after your first login. **

# API Documentation

## Authentication

The API uses Bearer Token authentication. You must include an Authorization header with every request.

`Authorization: Bearer st-your-generated-api-token`

Tokens are generated from the Admin Panel under Profiles -> Manage Tokens.

- Endpoint: Queue an Email
- URL: api/v1/send.php
- Method: POST
- Content-Type: application/json
- Request Body (JSON):

| Parameter   | Type    | Required | Description                             |
| :---------- | :------ | :------- | :-------------------------------------- |
| `profile_id`  | integer | Yes      | The ID of the sending profile to use.   |
| `to_email`    | string  | Yes      | The recipient's email address.          |
| `subject`     | string  | Yes      | The subject of the email.               |
| `body_html`   | string  | No* | The HTML content of the email.          |
| `body_text`   | string  | No* | The plain-text version of the email.    |
| `cc_email`    | string  | No       | A CC recipient's email address.         |
| `bcc_email`   | string  | No       | A BCC recipient's email address.        |



*At least one of body_html or body_text must be provided.

cURL Example:

```
curl -X POST https://yourdomain.com/api/v1/send.php \
-H "Authorization: Bearer st-your-generated-api-token" \
-H "Content-Type: application/json" \
-d '{
    "profile_id": 1,
    "to_email": "jane.doe@example.com",
    "subject": "Your Invoice",
    "body_html": "<h1>Hello!</h1><p>Please see your invoice attached.</p>",
    "body_text": "Hello! Please see your invoice attached."
}'
```

Success Response (202 Accepted):

```
{
    "status": "queued",
    "message_id": "a1b2c3d4e5f67890a1b2c3d4e5f67890"
}
```

Error Response (e.g., 403 Forbidden):

```
{
    "status": "error",
    "message": "Forbidden. The provided token is not valid for the specified profile_id."
}
```

- Endpoint: Check Email Status
- URL: api/v1/status.php
- Method: GET
- URL Parameters: message_id (required)

cURL Example:

```
curl -X GET "https://yourdomain.com/api/v1/status.php?message_id=a1b2c3d4e5f67890a1b2c3d4e5f67890" \
-H "Authorization: Bearer st-your-generated-api-token"
```

Possible Responses (200 OK):

Status: Queued

```
{
    "message_id": "a1b2c3d4e5f67890a1b2c3d4e5f67890",
    "status": "queued",
    "recipient": "jane.doe@example.com",
    "queued_at": "2023-10-27 14:30:00"
}
```

Status: Sent

```
{
    "message_id": "a1b2c3d4e5f67890a1b2c3d4e5f67890",
    "status": "sent",
    "recipient": "jane.doe@example.com",
    "sent_at": "2023-10-27 14:31:02"
}
```

Status: Failed

```
{
    "message_id": "a1b2c3d4e5f67890a1b2c3d4e5f67890",
    "status": "failed",
    "recipient": "jane.doe@example.com",
    "failed_at": "2023-10-27 14:31:05",
    "error_message": "SMTP Error: Could not connect to SMTP host."
}
```

## Attachments and Inline Images

The current version of the API does not support file attachments or embedding inline images (CID attachments).

The `/api/v1/send` endpoint is designed to accept a simple JSON payload with text-based content only and does not process file data, such as base64-encoded images.

**For Developers:** To implement this feature, the API endpoint (api/v1/send.php) and the background processor (background/process_queue.php) would need to be modified to handle file data. A possible implementation would involve extending the JSON payload to include an attachments array.

Example Extended Payload:

```
{
    "profile_id": 1,
    "to_email": "jane.doe@example.com",
    "subject": "Email with Inline Image",
    "body_html": "<h1>Hello!</h1><p>Here is our logo: <img src=\"cid:companylogo\"></p>",
    "body_text": "Hello! Here is our logo.",
    "attachments": [
        {
            "filename": "logo.png",
            "content_b64": "iVBORw0KGgoAAAANSUhEUg...",
            "cid": "companylogo"
        }
    ]
}
```

In this example, content_b64 would be the base64-encoded string of the image, and the cid would link it to the <img> tag in the HTML body.


# Security Considerations

- Admin Panel: The admin panel is the key to the application. Protect the /admin directory with additional server-level security, such as an IP whitelist in your .htaccess or Nginx config.

- API Tokens: Treat API tokens like passwords. Do not expose them in client-side code. Store them securely on your application servers.

- Encryption Key: The ENCRYPTION_KEY in `config.php` is used to encrypt and decrypt SMTP passwords. If this key is lost, you will have to re-enter all SMTP passwords. If it is compromised, an attacker with database access can decrypt your passwords. Back it up securely.

- Environment Variable for Keys (Recommended): For enhanced security, avoid hardcoding the ENCRYPTION_KEY in `config.php` where it might be committed to version control. Instead, configure your server to provide it as an environment variable (e.g., using SetEnv in Apache's configuration or .htaccess). You would then modify `config.php` to read the key, for example: `define('ENCRYPTION_KEY', getenv('SMTP_MAILER_ENC_KEY'));`. This separates your code from your secrets.

- HTTPS: Always run this application over HTTPS to protect all transmitted data, including login credentials and API tokens.

- File Permissions: Ensure file permissions are set correctly. Files should not be world-writable.

# License

This application is provided free of charge and carries no warranty or support for personal, non-commercial, and home-lab use. 

If you wish to use this software in a commercial, business, or production environment, please send an email to ivan@terrantech.ca for licensing information.

_ In most cases of commercial or business use, you are likely going to be using more robust solutions like SendGrid, MailGun, MailJet or similar services instead of something like this. (not an endorsement, just examples) Additionally, sending bulk email through your email server will either get you banned, blacklisted, or catgorized as spam/junk which will reduce your ability to send legitimate non-bulk emails or prevent delivery altogether. Use of this app does not replace proper email marketing tools and platforms. _
