<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';

// Ensure holidays table exists
$conn->query("CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    year YEAR NOT NULL,
    type VARCHAR(30) DEFAULT 'Public',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (holiday_date)
)");

// Add holiday
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_holiday'])) {
    $holiday_name = trim($_POST['holiday_name']);
    $holiday_date = $_POST['holiday_date'];
    $type = $_POST['type'] ?? 'Public';

    if (empty($holiday_name) || empty($holiday_date)) {
        $message = 'Please fill in all fields.';
        $message_type = 'error';
    } else {
        $year = date('Y', strtotime($holiday_date));
        $stmt = $conn->prepare("INSERT INTO holidays (holiday_name, holiday_date, year, type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssis', $holiday_name, $holiday_date, $year, $type);
        if ($stmt->execute()) {
            $message = 'Holiday added successfully.';
            $message_type = 'success';
        } else {
            $message = 'Error: ' . (strpos($stmt->error, 'Duplicate') !== false ? 'Date already exists as a holiday.' : $stmt->error);
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Delete holiday
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: holiday.php');
    exit;
}

$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$stmt = $conn->prepare("SELECT * FROM holidays WHERE year = ? ORDER BY holiday_date ASC");
$stmt->bind_param('i', $selected_year);
$stmt->execute();
$holidays = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$months = [];
for ($m = 1; $m <= 12; $m++) {
    $stmt = $conn->prepare("SELECT * FROM holidays WHERE year = ? AND MONTH(holiday_date) = ? ORDER BY holiday_date ASC");
    $stmt->bind_param('ii', $selected_year, $m);
    $stmt->execute();
    $months[$m] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Holiday Calendar</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Holiday Calendar";
            $page_subtitle = "Manage company holidays for the year.";
            ob_start();
        ?>
        <form method="GET" class="flex items-center gap-2">
            <select name="year" onchange="this.form.submit()" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8 mb-8">
                <div class="lg:col-span-3">
                    <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-calendar-days text-violet-400 mr-2"></i><?php echo $selected_year; ?> Holiday Calendar</h2>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($holidays); ?> holidays</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
                            <?php foreach ($months as $m => $month_holidays): ?>
                            <div class="border border-white/[0.06] rounded-xl overflow-hidden">
                                <div class="bg-indigo-500/10 px-4 py-2 font-bold text-sm text-indigo-400 border-b border-white/[0.06]">
                                    <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                </div>
                                <div class="p-3 space-y-2 min-h-[80px]">
                                    <?php if (empty($month_holidays)): ?>
                                    <p class="text-xs text-zinc-500 text-center py-2">No holidays</p>
                                    <?php else: ?>
                                        <?php foreach ($month_holidays as $h): ?>
                                        <div class="flex items-center justify-between group">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-bold text-indigo-400 w-6"><?php echo date('d', strtotime($h['holiday_date'])); ?></span>
                                                <div>
                                                    <span class="text-xs font-medium text-zinc-300"><?php echo htmlspecialchars($h['holiday_name']); ?></span>
                                                    <span class="text-[10px] text-zinc-500 ml-1">(<?php echo htmlspecialchars($h['type']); ?>)</span>
                                                </div>
                                            </div>
                                            <a href="?delete=<?php echo $h['id']; ?>&year=<?php echo $selected_year; ?>" onclick="return confirm('Delete this holiday?')" class="text-red-400 hover:text-red-400 opacity-0 group-hover:opacity-100 transition text-xs">
                                                <i class="fa-solid fa-times"></i>
                                            </a>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div>
                    <section class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="font-bold text-white text-lg mb-6"><i class="fa-solid fa-plus text-violet-400 mr-2"></i>Add Holiday</h2>
                        <form method="POST" class="space-y-4 text-sm">
                        <?php echo csrf_field(); ?>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Holiday Name</label>
                                <input type="text" name="holiday_name" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Date</label>
                                <input type="date" name="holiday_date" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Type</label>
                                <select name="type" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="Public">Public</option>
                                    <option value="Company">Company</option>
                                    <option value="Optional">Optional</option>
                                </select>
                            </div>
                            <button type="submit" name="add_holiday" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-plus"></i> Add Holiday
                            </button>
                        </form>
                    </section>
                </div>
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
