<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth_check.php';

// --- Fetch Stats from Database ---

// Count total sending profiles
$stmt_profiles = $pdo->query("SELECT COUNT(*) FROM sending_profiles");
$profile_count = $stmt_profiles->fetchColumn();

// Count emails currently in the queue
$stmt_queue = $pdo->query("SELECT COUNT(*) FROM email_queue");
$queue_count = $stmt_queue->fetchColumn();

// Count emails sent today
$stmt_sent = $pdo->prepare("SELECT COUNT(*) FROM email_history WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
$stmt_sent->execute();
$sent_today = $stmt_sent->fetchColumn();

// Count emails that failed today
$stmt_failed = $pdo->prepare("SELECT COUNT(*) FROM email_history WHERE status = 'failed' AND DATE(sent_at) = CURDATE()");
$stmt_failed->execute();
$failed_today = $stmt_failed->fetchColumn();


$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- Start of page content -->
<div class="p-4">
    <h2 class="text-xl font-semibold text-gray-800 mb-4">System Overview</h2>
    
    <!-- Stats Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        
        <!-- Total Profiles Card -->
        <div class="bg-white p-6 rounded-lg shadow-md flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Total Profiles</p>
                <p class="text-3xl font-bold text-gray-900"><?= $profile_count ?></p>
            </div>
            <div class="bg-blue-100 p-3 rounded-full">
                <svg class="w-6 h-6 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
        </div>

        <!-- Emails in Queue Card -->
        <div class="bg-white p-6 rounded-lg shadow-md flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Emails in Queue</p>
                <p class="text-3xl font-bold text-gray-900"><?= $queue_count ?></p>
            </div>
            <div class="bg-yellow-100 p-3 rounded-full">
                 <svg class="w-6 h-6 text-yellow-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
        
        <!-- Emails Sent Today Card -->
        <div class="bg-white p-6 rounded-lg shadow-md flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Sent Today</p>
                <p class="text-3xl font-bold text-gray-900"><?= $sent_today ?></p>
            </div>
            <div class="bg-green-100 p-3 rounded-full">
                <svg class="w-6 h-6 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>

        <!-- Failed Today Card -->
        <div class="bg-white p-6 rounded-lg shadow-md flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Failed Today</p>
                <p class="text-3xl font-bold text-gray-900"><?= $failed_today ?></p>
            </div>
            <div class="bg-red-100 p-3 rounded-full">
                <svg class="w-6 h-6 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                     <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        </div>

    </div>
</div>
<!-- End of page content -->

<?php
include __DIR__ . '/includes/footer.php';
?>
