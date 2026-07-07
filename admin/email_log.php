<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$mailDir = __DIR__ . '/../storage/emails';
$emails = [];

if (is_dir($mailDir)) {
    $files = glob($mailDir . '/*.html');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $filename = basename($file);

        // Parse metadata from HTML comments
        preg_match('/<!-- TO: (.+?) -->/', $content, $toMatch);
        preg_match('/<!-- SUBJECT: (.+?) -->/', $content, $subMatch);
        preg_match('/<!-- DATE: (.+?) -->/', $content, $dateMatch);

        // Extract employee name from "Dear <strong>Name</strong>"
        $empName = '';
        if (preg_match('/Dear\s+<strong>(.+?)<\/strong>/', $content, $nameMatch)) {
            $empName = $nameMatch[1];
        }

        $pdfFile = str_replace('.html', '_slip.pdf', $file);
        $hasPdf = file_exists($pdfFile);

        $emails[] = [
            'filename' => $filename,
            'to' => $toMatch[1] ?? 'Unknown',
            'subject' => $subMatch[1] ?? 'No subject',
            'date' => $dateMatch[1] ?? 'Unknown',
            'employee_name' => $empName,
            'has_pdf' => $hasPdf,
            'pdf_file' => $pdfFile,
            'size' => filesize($file),
        ];
    }
    usort($emails, fn($a, $b) => strcmp($b['date'], $a['date']));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Email Log</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Email Log"; $page_subtitle = "View all sent salary slip emails and their PDF attachments";
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-7xl mx-auto" x-data="{ search: '' }">

                <!-- Summary -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8 animate-fade-in-up stagger-1">
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Total Emails</span>
                        <div class="text-lg font-bold mt-1"><?php echo count($emails); ?></div>
                    </div>
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">With PDF</span>
                        <div class="text-lg font-bold text-emerald-400 mt-1"><?php echo count(array_filter($emails, fn($e) => $e['has_pdf'])); ?></div>
                    </div>
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Storage</span>
                        <div class="text-lg font-bold text-white mt-1"><?php echo count($emails) > 0 ? number_format(array_sum(array_column($emails, 'size')) / 1024, 1) . ' KB' : '0 KB'; ?></div>
                    </div>
                </div>

                <!-- Search Filter -->
                <div class="mb-6 animate-fade-in-up stagger-1">
                    <div class="relative max-w-md w-full">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500 text-sm"></i>
                        <input type="text" x-model="search" placeholder="Filter by employee name or email..."
                            class="w-full bg-white/[0.06] border border-white/10  text-sm rounded-xl pl-10 pr-4 py-2.5 outline-none focus:ring-2 focus:ring-violet-500/30 placeholder-zinc-500">
                    </div>
                </div>

                <!-- Email List -->
                <?php if (empty($emails)): ?>
                    <div class="glass-strong rounded-2xl p-12 text-center animate-fade-in-up">
                        <i class="fa-solid fa-envelope-open text-4xl text-zinc-600 mb-3 block"></i>
                        <p class="text-zinc-400 font-medium">No emails sent yet</p>
                        <p class="text-zinc-500 text-sm mt-1">Go to <a href="salary_slip.php" class="text-violet-400 hover:text-violet-300 font-semibold">Salary Slips</a> to send salary slips to employees.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4 animate-fade-in-up stagger-2">
                        <template x-for="(email, index) in emails" :key="index">
                            <div class="glass-strong rounded-2xl overflow-hidden card-hover"
                                x-show="!search || email.name.toLowerCase().includes(search.toLowerCase()) || email.to.toLowerCase().includes(search.toLowerCase())"
                                x-transition x-data="{ open: false }">
                                <div class="p-5 flex items-center justify-between cursor-pointer" @click="open = !open">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center shrink-0">
                                            <i class="fa-solid fa-check text-emerald-400 text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-medium text-white text-sm" x-text="email.subject"></div>
                                            <div class="text-xs text-zinc-500 mt-0.5">
                                                <span class="text-violet-400" x-text="email.name"></span>
                                                &middot; <span x-text="email.to"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-right hidden sm:block">
                                            <div class="text-xs text-zinc-500" x-text="email.dateShort"></div>
                                            <div class="text-[10px] text-zinc-600" x-text="email.time"></div>
                                        </div>
                                        <template x-if="email.has_pdf">
                                            <a :href="'view_email.php?file=' + encodeURIComponent(email.filename) + '&action=pdf'" title="Download PDF" onclick="event.stopPropagation()" class="w-9 h-9 rounded-lg bg-amber-500/20 text-amber-400 hover:bg-amber-500/40 flex items-center justify-center transition">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </a>
                                        </template>
                                        <i class="fa-solid fa-chevron-down text-zinc-500 transition-transform duration-200 text-sm" :class="{ 'rotate-180': open }"></i>
                                    </div>
                                </div>
                                <div x-show="open" x-transition class="border-t border-white/[0.06] p-5">
                                    <div class="bg-slate-50 dark:bg-white/[0.03] rounded-xl p-4">
                                        <div class="flex items-center gap-2 mb-3">
                                            <i class="fa-solid fa-envelope text-violet-400 text-xs"></i>
                                            <span class="text-xs font-semibold text-zinc-500 uppercase">Email Preview</span>
                                        </div>
                                        <div class="bg-white dark:bg-black/20 rounded-lg p-4 border border-slate-200 dark:border-white/[0.06]" x-html="email.body"></div>
                                    </div>
                                    <div class="flex items-center gap-3 mt-4 text-xs text-zinc-500">
                                        <span><i class="fa-solid fa-calendar mr-1"></i><span x-text="email.dateFull"></span></span>
                                        <span><i class="fa-solid fa-weight-hanging mr-1"></i><span x-text="email.sizeText"></span></span>
                                        <template x-if="email.has_pdf">
                                            <span class="text-emerald-400"><i class="fa-solid fa-file-pdf mr-1"></i>PDF attached</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('emailSearch', () => ({
                                search: '',
                                emails: <?php
                                        $emailJson = array_map(function ($e) {
                                            $body = file_get_contents($e['filename']);
                                            $body = preg_replace('/<!--.*?-->/s', '', $body);
                                            return [
                                                'name' => $e['employee_name'],
                                                'to' => $e['to'],
                                                'subject' => $e['subject'],
                                                'dateShort' => date('M d, Y', strtotime($e['date'])),
                                                'time' => date('H:i:s', strtotime($e['date'])),
                                                'dateFull' => $e['date'],
                                                'has_pdf' => $e['has_pdf'],
                                                'filename' => $e['filename'],
                                                'sizeText' => number_format($e['size']) . ' bytes',
                                                'body' => $body,
                                            ];
                                        }, $emails);
                                        echo json_encode($emailJson);
                                        ?>
                            }));
                        });
                    </script>
                <?php endif; ?>
            </div>
        </main>
        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>

</html>