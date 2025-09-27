<?php
// Get the current script name to determine the active page
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - SMTP Mailer' : 'SMTP Mailer Admin' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <div class="flex flex-col md:flex-row">
        <!-- Side-Nav -->
        <div class="bg-gray-800 shadow-xl h-16 fixed bottom-0 md:relative md:h-screen z-10 w-full md:w-64">
            <div class="md:mt-12 md:w-64 md:fixed md:left-0 md:top-0 content-center md:content-start text-left justify-between">
                 <div class="text-white text-xl p-4 font-bold hidden md:block">
                    SMTP Mailer
                </div>
                <ul class="list-reset flex flex-row md:flex-col py-0 md:py-3 px-1 md:px-2 text-center md:text-left">
                    <li class="mr-3 flex-1">
                        <a href="dashboard.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= $current_page == 'dashboard.php' ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-tachometer-alt fa-fw mr-3 <?= $current_page == 'dashboard.php' ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Dashboard</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1">
                        <a href="profiles.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= ($current_page == 'profiles.php' || $current_page == 'api_tokens.php') ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-envelope fa-fw mr-3 <?= ($current_page == 'profiles.php' || $current_page == 'api_tokens.php') ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Profiles</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1">
                        <a href="email_logs.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= $current_page == 'email_logs.php' ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-history fa-fw mr-3 <?= $current_page == 'email_logs.php' ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Email Logs</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1">
                        <a href="rate_limit.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= $current_page == 'rate_limit.php' ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-traffic-light fa-fw mr-3 <?= $current_page == 'rate_limit.php' ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Rate Limiting</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1">
                        <a href="settings.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= $current_page == 'settings.php' ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-cogs fa-fw mr-3 <?= $current_page == 'settings.php' ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Settings</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1">
                        <a href="help.php" class="block py-1 md:py-3 pl-1 align-middle no-underline hover:text-white border-b-2 <?= $current_page == 'help.php' ? 'text-blue-400 border-blue-400' : 'border-gray-800 hover:border-blue-400 text-gray-400' ?>">
                            <i class="fas fa-question-circle fa-fw mr-3 <?= $current_page == 'help.php' ? 'text-blue-400' : '' ?>"></i><span class="pb-1 md:pb-0 text-sm">Help</span>
                        </a>
                    </li>
                    <li class="mr-3 flex-1 md:absolute md:bottom-0 md:w-full md:left-0">
                         <a href="logout.php" class="block py-1 md:py-3 pl-1 align-middle text-gray-400 no-underline hover:text-white border-b-2 border-gray-800 hover:border-red-500">
                            <i class="fas fa-sign-out-alt fa-fw mr-3"></i><span class="pb-1 md:pb-0 text-sm">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 pb-24 md:pb-5">
            <div class="w-full bg-white shadow-md p-4 flex justify-between items-center">
                 <h2 class="text-xl font-bold text-gray-700"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h2>
                 <span></span> <!-- Can be used for user info or other header items -->
            </div>
            <div class="p-6">
                <!-- Main content of the page will be here -->

