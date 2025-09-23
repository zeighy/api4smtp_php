<?php
require_once __DIR__ . '/includes/auth_check.php';
$pageTitle = "Help & Documentation";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Help & Documentation</h1>

    <div class="bg-white p-8 rounded-lg shadow-lg space-y-8">

        <!-- Introduction -->
        <section>
            <h2 class="text-2xl font-semibold text-gray-700 border-b-2 pb-2 mb-4">Introduction</h2>
            <p class="text-gray-600">
                This application is a self-hosted SMTP mailer designed to send emails via a public API. It allows you to manage multiple SMTP sending profiles, generate API tokens for authentication, and track the status of every email sent.
            </p>
        </section>

        <!-- How to Use -->
        <section>
            <h2 class="text-2xl font-semibold text-gray-700 border-b-2 pb-2 mb-4">How to Use</h2>
            <div class="space-y-6">
                <div>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">1. Create a Sending Profile</h3>
                    <p class="text-gray-600">
                        Go to the <a href="profiles.php" class="text-blue-500 hover:underline">Profiles</a> page. A profile contains the SMTP credentials for a single email address you want to send from. Fill in all the fields (Host, Port, Username, Password, etc.) accurately. The SMTP password is encrypted before being stored in the database.
                    </p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">2. Generate an API Token</h3>
                    <p class="text-gray-600">
                        Once a profile is created, click the "Tokens" button next to it. On the token management page, you can generate new API tokens.
                        <br>
                        <strong class="font-semibold text-red-600">Important:</strong> For security, a new token is displayed only once upon creation. Copy it immediately and store it securely in your third-party application.
                    </p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">3. Send an Email via API</h3>
                    <p class="text-gray-600">
                        Your application can now send a POST request to the API endpoint. The request must be in JSON format and include the API token in the Authorization header.
                    </p>
                    <p class="text-gray-600 mt-2"><strong>Endpoint:</strong> <code>/api/v1/send.php</code></p>
                    <pre class="bg-gray-800 text-white p-4 rounded-md mt-2 overflow-x-auto"><code>curl -X POST 'https://yourdomain.com/api/v1/send.php' \
--header 'Authorization: Bearer YOUR_API_TOKEN' \
--header 'Content-Type: application/json' \
--data-raw '{
    "profile_id": 1,
    "to_email": "recipient@example.com",
    "subject": "Your Subject Here",
    "body_html": "&lt;h1&gt;Hello World!&lt;/h1&gt;&lt;p&gt;This is an HTML email.&lt;/p&gt;",
    "body_text": "Hello World! This is a plain text email."
}'</code></pre>
                    <p class="text-gray-600 mt-2">A successful request will return a status of "queued" and a unique <code>message_id</code>.</p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">4. Check Email Status via API</h3>
                    <p class="text-gray-600">
                        You can query the status of a sent email using its <code>message_id</code>.
                    </p>
                    <p class="text-gray-600 mt-2"><strong>Endpoint:</strong> <code>/api/v1/status.php?message_id=YOUR_MESSAGE_ID</code></p>
                    <pre class="bg-gray-800 text-white p-4 rounded-md mt-2 overflow-x-auto"><code>curl 'https://yourdomain.com/api/v1/status.php?message_id=YOUR_MESSAGE_ID' \
--header 'Authorization: Bearer YOUR_API_TOKEN'</code></pre>
                    <p class="text-gray-600 mt-2">The token used must belong to the same profile that sent the email.</p>
                </div>
            </div>
        </section>

        <!-- Cron Job Setup -->
        <section>
            <h2 class="text-2xl font-semibold text-gray-700 border-b-2 pb-2 mb-4">Cron Job Setup</h2>
            <p class="text-gray-600 mb-4">
                This application relies on two background scripts that must be run automatically by a cron job on your server.
            </p>
            <h3 class="text-lg font-semibold text-gray-700">Example Crontab Entries:</h3>
            <pre class="bg-gray-800 text-white p-4 rounded-md mt-2 overflow-x-auto"><code># Process the email sending queue every minute
* * * * * /usr/bin/php /path/to/your/app/background/process_queue.php >> /dev/null 2>&1

# Prune old email history logs once per day at midnight
0 0 * * * /usr/bin/php /path/to/your/app/background/prune_logs.php >> /dev/null 2>&1
</code></pre>
            <p class="text-gray-600 mt-2">
                Make sure to replace <code>/usr/bin/php</code> with the correct path to your PHP executable and <code>/path/to/your/app/</code> with the absolute path to the project directory.
            </p>
        </section>

        <!-- Security Considerations -->
        <section>
            <h2 class="text-2xl font-semibold text-gray-700 border-b-2 pb-2 mb-4">Security Considerations</h2>
            <ul class="list-disc list-inside text-gray-600 space-y-2">
                <li><strong class="font-semibold text-gray-800">Admin Directory:</strong> Protect the <code>/admin/</code> directory on your web server. If possible, restrict access to it by IP address.</li>
                <li><strong class="font-semibold text-gray-800">Strong Credentials:</strong> Use a strong and unique password for the admin login.</li>
                <li><strong class="font-semibold text-gray-800">API Tokens:</strong> Treat API tokens like passwords. Store them securely and do not expose them in client-side code.</li>
                <li><strong class="font-semibold text-gray-800">File Structure:</strong> For the best security, your web server's document root (publicly accessible folder) should point only to the necessary files (e.g., `index.php` for a front-end router, or the `api` folder). Sensitive files like <code>config.php</code> and the <code>background</code> and <code>vendor</code> directories should be placed outside of the public web root to prevent direct access.</li>
                <li><strong class="font-semibold text-gray-800">HTTPS:</strong> Always serve the API over HTTPS (SSL/TLS) to encrypt the communication between your client and this server.</li>
            </ul>
        </section>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
