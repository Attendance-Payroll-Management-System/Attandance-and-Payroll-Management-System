<?php
/**
 * Employee Bottom Navigation Bar
 * Fixed bottom navigation for mobile HR system.
 * Only visible on mobile/tablet (hidden on desktop via CSS).
 */
$current_page = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');

// Bottom nav items — 5 core items per requirements
$nav_items = [
    ['page' => 'dashboard.php',        'icon' => 'house',              'label' => 'Dashboard',     'href' => 'dashboard.php'],
    ['page' => 'attendance.php',       'icon' => 'calendar-check',     'label' => 'Attendance',    'href' => 'attendance.php'],
    ['page' => 'leaverequest.php',     'icon' => 'paper-plane',        'label' => 'Leave',          'href' => 'leaverequest.php'],
    ['page' => 'notifications',        'icon' => 'bell',               'label' => 'Notify',         'href' => '#notifications'],
    ['page' => 'profile.php',          'icon' => 'circle-user',        'label' => 'Profile',        'href' => 'profile.php'],
];

// Pages that map to a parent nav item
$page_map = [
    'dashboard.php'          => 'dashboard.php',
    'attendance.php'         => 'attendance.php',
    'attendance_summary.php' => 'attendance.php',
    'attendanceall.php'      => 'attendance.php',
    'leaverequest.php'       => 'leaverequest.php',
    'overtimerequest.php'    => 'leaverequest.php',
    'company_policy.php'     => 'dashboard.php',
    'profile.php'            => 'profile.php',
    'payroll.php'            => 'profile.php',
    'change_password.php'    => 'profile.php',
    'notifications'          => 'notifications',
];

$active_page = $page_map[$current_page] ?? $current_page;
?>
<!-- Employee Bottom Navigation — Mobile Only (hidden on lg+ via CSS) -->
<nav class="emp-bottom-nav" id="empBottomNav">
    <div class="emp-bottom-nav-inner">
        <?php foreach ($nav_items as $item):
            $is_active = ($item['page'] === $active_page);
            // Special handling for notifications (4th item) — highlight when on dashboard
            if ($item['icon'] === 'bell') {
                $is_active = ($current_page === 'dashboard.php');
            }
        ?>
        <a href="<?php echo $item['href']; ?>" class="emp-bottom-nav-item <?php echo $is_active ? 'active' : ''; ?>">
            <div class="emp-bottom-nav-icon-wrap">
                <?php if ($is_active): ?>
                <div class="emp-bottom-nav-active-bg"></div>
                <?php endif; ?>
                <i class="fa-solid fa-<?php echo $item['icon']; ?> emp-bottom-nav-icon"></i>
            </div>
            <span class="emp-bottom-nav-label"><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</nav>
