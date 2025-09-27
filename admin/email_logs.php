<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

$success_message = '';
$error_message = '';

// --- Handle Cancellation of a Queued Email ---
if (isset($_GET['action']) && $_GET['action'] === 'cancel_queued' && isset($_GET['id'])) {
    $id_to_cancel = $_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM email_queue WHERE id = ?");
    if ($stmt->execute([$id_to_cancel])) {
        // Redirect to show a success message and prevent resubmission.
        header('Location: email_logs.php?view=queue&cancel_status=success');
        exit;
    } else {
        header('Location: email_logs.php?view=queue&cancel_status=error');
        exit;
    }
}

// --- Display cancellation status messages ---
if (isset($_GET['cancel_status'])) {
    if ($_GET['cancel_status'] === 'success') {
        $success_message = 'The selected email has been successfully cancelled and removed from the queue.';
    } else {
        $error_message = 'There was an error cancelling the email. Please try again.';
    }
}


// --- Fetching Data & Filtering Logic ---
$view = $_GET['view'] ?? 'history'; // Default to 'history'

// Get all sending profiles for the filter dropdown
$profiles_stmt = $pdo->query("SELECT id, profile_name FROM sending_profiles ORDER BY profile_name");
$profiles = $profiles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination settings
$limit = 25; // Records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter parameters
$filter_profile = $_GET['profile_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_ip = $_GET['ip_address'] ?? '';
$search_term = $_GET['search'] ?? '';

// Build the WHERE clause for the query
$where_clauses = [];
$params = [];

// The table alias 'l' is used for logs, and 'q' for queue.
$table_alias = ($view === 'history') ? 'l' : 'q';

if (!empty($filter_profile)) {
    $where_clauses[] = "$table_alias.profile_id = ?";
    $params[] = $filter_profile;
}
if ($view === 'history' && !empty($filter_status)) {
    $where_clauses[] = "l.status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_ip)) {
    $where_clauses[] = "$table_alias.ip_address LIKE ?";
    $params[] = "%" . $filter_ip . "%";
}
if (!empty($search_term)) {
    $where_clauses[] = "($table_alias.recipient_email LIKE ? OR $table_alias.subject LIKE ?)";
    $params[] = "%" . $search_term . "%";
    $params[] = "%" . $search_term . "%";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : '';

// --- Determine which table to query ---
if ($view === 'history') {
    // --- Count total records for pagination ---
    $count_sql = "SELECT COUNT(*) FROM email_logs l " . $where_sql;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // --- Fetch records for the current page ---
    $logs_sql = "SELECT l.*, p.profile_name FROM email_logs l
                 LEFT JOIN sending_profiles p ON l.profile_id = p.id
                 $where_sql
                 ORDER BY l.submitted_at DESC
                 LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $logs_stmt = $pdo->prepare($logs_sql);
    $logs_stmt->execute($params);
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // 'queue' view
    // --- Count total records for pagination ---
    $count_sql = "SELECT COUNT(*) FROM email_queue q " . $where_sql;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // --- Fetch records for the current page ---
    $logs_sql = "SELECT q.*, p.profile_name FROM email_queue q
                 LEFT JOIN sending_profiles p ON q.profile_id = p.id
                 $where_sql
                 ORDER BY q.send_at ASC
                 LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $logs_stmt = $pdo->prepare($logs_sql);
    $logs_stmt->execute($params);
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
}


// --- Page Title ---
$pageTitle = $view === 'queue' ? 'Email Queue' : 'Email History';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Success and Error Messages for Cancellation -->
    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success_message) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($error_message) ?></span>
        </div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($pageTitle) ?></h1>
        <button id="export-btn" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 <?= $view === 'queue' ? 'hidden' : '' ?>">
            Export to CSV
        </button>
    </div>

    <!-- Filter Form -->
    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <form action="email_logs.php" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            <div>
                <label for="view-select" class="block text-sm font-medium text-gray-700">View</label>
                <select id="view-select" name="view" class="p-2 border rounded-md w-full" onchange="this.form.submit()">
                    <option value="history" <?= ($view === 'history') ? 'selected' : '' ?>>History</option>
                    <option value="queue" <?= ($view === 'queue') ? 'selected' : '' ?>>Queue</option>
                </select>
            </div>
            <div>
                <label for="search-input" class="block text-sm font-medium text-gray-700">Search</label>
                <input id="search-input" type="text" name="search" placeholder="Recipient or subject..." value="<?= htmlspecialchars($search_term) ?>" class="p-2 border rounded-md w-full" onchange="this.form.submit()">
            </div>
            <div>
                <label for="profile-select" class="block text-sm font-medium text-gray-700">Profile</label>
                <select id="profile-select" name="profile_id" class="p-2 border rounded-md w-full" onchange="this.form.submit()">
                    <option value="">All Profiles</option>
                    <?php foreach ($profiles as $profile): ?>
                        <option value="<?= $profile['id'] ?>" <?= ($filter_profile == $profile['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($profile['profile_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status-select" class="block text-sm font-medium text-gray-700">Status</label>
                <select id="status-select" name="status" class="p-2 border rounded-md w-full" <?= $view === 'queue' ? 'disabled' : '' ?> onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="sent" <?= ($filter_status == 'sent') ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= ($filter_status == 'failed') ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div>
                <label for="ip-input" class="block text-sm font-medium text-gray-700">IP Address</label>
                <input id="ip-input" type="text" name="ip_address" placeholder="Filter by IP..." value="<?= htmlspecialchars($filter_ip) ?>" class="p-2 border rounded-md w-full" onchange="this.form.submit()">
            </div>
            <!-- The submit button has been removed, as the form now auto-submits on any filter change. -->
        </form>
    </div>

    <!-- Dynamic Logs Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full leading-normal" id="logs-table">
            <thead>
                <tr>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Recipient</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Subject</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Profile</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">IP Address</th>
                    <?php if ($view === 'history'): ?>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Details</th>
                    <?php else: // Queue View ?>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Scheduled At</th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Submitted At</th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">No records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($row['recipient_email']) ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($row['subject']) ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($row['profile_name'] ?? 'N/A') ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($row['ip_address']) ?></td>
                            <?php if ($view === 'history'): ?>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                    <?php if ($row['status'] == 'sent'): ?>
                                        <span class="relative inline-block px-3 py-1 font-semibold text-green-900 leading-tight">
                                            <span aria-hidden class="absolute inset-0 bg-green-200 opacity-50 rounded-full"></span>
                                            <span class="relative">Sent</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="relative inline-block px-3 py-1 font-semibold text-red-900 leading-tight">
                                            <span aria-hidden class="absolute inset-0 bg-red-200 opacity-50 rounded-full"></span>
                                            <span class="relative">Failed</span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= date('Y-m-d H:i', strtotime($row['sent_at'] ?? $row['submitted_at'])) ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                    <button onclick="showDetails(this)"
                                            data-status-info="<?= htmlspecialchars($row['status_info'] ?? 'N/A') ?>"
                                            data-debug-info="<?= htmlspecialchars($row['debug_info'] ?? 'No debug information recorded.') ?>"
                                            class="text-blue-500 hover:underline">View</button>
                                </td>
                            <?php else: // Queue View ?>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= date('Y-m-d H:i', strtotime($row['send_at'])) ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= date('Y-m-d H:i', strtotime($row['submitted_at'])) ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                    <a href="email_logs.php?action=cancel_queued&id=<?= $row['id'] ?>&view=queue"
                                       class="text-red-600 hover:text-red-900"
                                       onclick="return confirm('Are you sure you want to permanently cancel this email? This cannot be undone.');">Cancel</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="px-5 py-5 bg-white border-t flex flex-col xs:flex-row items-center xs:justify-between">
        <span class="text-xs xs:text-sm text-gray-900">
            Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> Entries
        </span>
        <div class="inline-flex mt-2 xs:mt-0">
             <?php
                // Build query string for pagination links
                $query_params = $_GET;
                unset($query_params['page']);
                $query_string = http_build_query($query_params);
            ?>
            <a href="?page=<?= max(1, $page - 1) ?>&<?= $query_string ?>" class="text-sm bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-4 rounded-l <?= $page <= 1 ? 'opacity-50 cursor-not-allowed' : '' ?>">
                Prev
            </a>
            <a href="?page=<?= min($total_pages, $page + 1) ?>&<?= $query_string ?>" class="text-sm bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-4 rounded-r <?= $page >= $total_pages ? 'opacity-50 cursor-not-allowed' : '' ?>">
                Next
            </a>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="details-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg leading-6 font-medium text-gray-900 text-center mb-4">Log Details</h3>
            <div class="grid grid-cols-1 gap-y-4">
                <div>
                    <h4 class="text-md font-semibold text-gray-800">Status Info</h4>
                    <p id="status-info-text" class="text-sm text-gray-700 bg-gray-100 p-3 rounded-md break-words mt-1"></p>
                </div>
                <div>
                    <h4 class="text-md font-semibold text-gray-800">SMTP Debug Log</h4>
                    <pre id="debug-info-text" class="text-xs text-gray-600 bg-gray-900 text-white p-4 rounded-md break-words whitespace-pre-wrap overflow-x-auto max-h-80 mt-1"></pre>
                </div>
            </div>
            <div class="items-center px-4 py-3 mt-4 text-right">
                <button id="close-modal" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showDetails(button) {
    const statusInfo = button.getAttribute('data-status-info');
    const debugInfo = button.getAttribute('data-debug-info');

    document.getElementById('status-info-text').innerText = statusInfo;
    document.getElementById('debug-info-text').innerText = debugInfo;

    document.getElementById('details-modal').classList.remove('hidden');
}

document.getElementById('close-modal').addEventListener('click', function() {
    document.getElementById('details-modal').classList.add('hidden');
});


function downloadCSV(csv, filename) {
    let csvFile;
    let downloadLink;
    csvFile = new Blob([csv], { type: "text/csv" });
    downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function exportTableToCSV(filename) {
    let csv = [];
    const rows = document.querySelectorAll("#logs-table tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        // Skip the last column (Info button)
        for (let j = 0; j < cols.length - 1; j++) {
            // Clean the text content
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s)/gm, " ");
            data = data.replace(/"/g, '""'); // Escape double quotes
            row.push('"' + data + '"');
        }
        csv.push(row.join(","));
    }
    downloadCSV(csv.join("\n"), filename);
}

document.getElementById('export-btn').addEventListener('click', function() {
    const date = new Date().toISOString().slice(0, 10);
    exportTableToCSV(`email_logs_${date}.csv`);
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
