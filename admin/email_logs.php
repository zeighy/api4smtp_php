<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

// --- Fetching Data & Filtering Logic ---

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

if (!empty($filter_profile)) {
    $where_clauses[] = "l.profile_id = ?";
    $params[] = $filter_profile;
}
if (!empty($filter_status)) {
    $where_clauses[] = "l.status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_ip)) {
    $where_clauses[] = "l.ip_address LIKE ?";
    $params[] = "%" . $filter_ip . "%";
}
if (!empty($search_term)) {
    $where_clauses[] = "(l.recipient_email LIKE ? OR l.subject LIKE ?)";
    $params[] = "%" . $search_term . "%";
    $params[] = "%" . $search_term . "%";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : '';

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

// --- Page Title ---
$pageTitle = "Email History";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Email History</h1>
        <button id="export-btn" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">
            Export to CSV
        </button>
    </div>

    <!-- Filter Form -->
    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <form action="email_logs.php" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <input type="text" name="search" placeholder="Search recipient/subject..." value="<?= htmlspecialchars($search_term) ?>" class="p-2 border rounded-md">
            <select name="profile_id" class="p-2 border rounded-md">
                <option value="">All Profiles</option>
                <?php foreach ($profiles as $profile): ?>
                    <option value="<?= $profile['id'] ?>" <?= ($filter_profile == $profile['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($profile['profile_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="p-2 border rounded-md">
                <option value="">All Statuses</option>
                <option value="sent" <?= ($filter_status == 'sent') ? 'selected' : '' ?>>Sent</option>
                <option value="failed" <?= ($filter_status == 'failed') ? 'selected' : '' ?>>Failed</option>
            </select>
            <input type="text" name="ip_address" placeholder="Filter by IP..." value="<?= htmlspecialchars($filter_ip) ?>" class="p-2 border rounded-md">
            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md">Filter</button>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full leading-normal" id="logs-table">
            <thead>
                <tr>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Recipient</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Subject</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Profile</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">IP Address</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Details</th>
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
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($row['ip_address']) ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= date('Y-m-d H:i', strtotime($row['sent_at'] ?? $row['submitted_at'])) ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <button onclick="showDetails(this)"
                                        data-status-info="<?= htmlspecialchars($row['status_info'] ?? 'N/A') ?>"
                                        data-debug-info="<?= htmlspecialchars($row['debug_info'] ?? 'No debug information recorded.') ?>"
                                        class="text-blue-500 hover:underline">View</button>
                            </td>
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
