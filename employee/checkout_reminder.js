(function () {
    'use strict';

    const POLL_INTERVAL_NORMAL = 60000;
    const POLL_INTERVAL_URGENT = 15000;
    const URGENT_AFTER_MINUTE = 45;

    let currentReminder = null;
    let pollTimer = null;
    let lastLevel = null;

    function getMMTHour() {
        const now = new Date();
        const mmtOffset = 6.5 * 60;
        const utc = now.getTime() + now.getTimezoneOffset() * 60000;
        const mmt = new Date(utc + mmtOffset * 60000);
        return { hour: mmt.getHours(), minute: mmt.getMinutes() };
    }

    function shouldPoll() {
        const { hour, minute } = getMMTHour();
        return hour >= 16 && hour < 22;
    }

    function getPollInterval() {
        const { hour, minute } = getMMTHour();
        if (hour === 16 && minute >= URGENT_AFTER_MINUTE) return POLL_INTERVAL_URGENT;
        if (hour >= 17) return POLL_INTERVAL_URGENT;
        return POLL_INTERVAL_NORMAL;
    }

    async function checkReminder() {
        if (!shouldPoll()) {
            hideReminderBanner();
            return;
        }

        try {
            const res = await fetch('../ajax/checkout_reminder.php?action=check', {
                credentials: 'same-origin'
            });
            if (!res.ok) return;
            const data = await res.json();
            if (data.status !== 'ok') return;

            if (!data.eligible) {
                hideReminderBanner();
                updateBadge(0);
                lastLevel = null;
                return;
            }

            currentReminder = data;
            showReminderBanner(data);
            updateBadge(data.unread_count || 1);

            if (data.level !== lastLevel && lastLevel !== null) {
                showToast(data.level, data.message);
            }
            lastLevel = data.level;
        } catch (e) {
            // silent
        }
    }

    function showReminderBanner(data) {
        const banner = document.getElementById('checkout-reminder-banner');
        if (!banner) return;

        const colors = {
            first: { bg: 'bg-blue-50 dark:bg-blue-500/10', border: 'border-blue-200 dark:border-blue-500/20', icon: 'fa-solid fa-bell text-blue-500', text: 'text-blue-700 dark:text-blue-300', btn: 'bg-blue-500 hover:bg-blue-600' },
            second: { bg: 'bg-amber-50 dark:bg-amber-500/10', border: 'border-amber-200 dark:border-amber-500/20', icon: 'fa-solid fa-triangle-exclamation text-amber-500', text: 'text-amber-700 dark:text-amber-300', btn: 'bg-amber-500 hover:bg-amber-600' },
            final: { bg: 'bg-red-50 dark:bg-red-500/10', border: 'border-red-200 dark:border-red-500/20', icon: 'fa-solid fa-circle-exclamation text-red-500', text: 'text-red-700 dark:text-red-300', btn: 'bg-red-500 hover:bg-red-600' }
        };
        const c = colors[data.level] || colors.first;

        banner.className = `${c.bg} border ${c.border} rounded-xl p-4 flex items-center gap-4`;
        banner.style.display = 'flex';
        banner.innerHTML = `
            <div class="w-10 h-10 rounded-full bg-white dark:bg-white/10 flex items-center justify-center shrink-0 shadow-sm">
                <i class="${c.icon} text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold ${c.text}">${escapeHtml(data.message)}</p>
                <p class="text-xs ${c.text} opacity-70 mt-0.5">Checked in at ${data.current_time || ''} &middot; Please check out now</p>
            </div>
            <a href="attendance.php" class="${c.btn} text-white text-xs font-semibold px-4 py-2 rounded-lg shrink-0 shadow-sm transition-colors">
                <i class="fa-solid fa-right-from-bracket mr-1"></i>Check Out
            </a>
            <button onclick="CheckoutReminder.dismiss(${data.reminder_id || 0})" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 shrink-0 transition-colors">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        `;
    }

    function hideReminderBanner() {
        const banner = document.getElementById('checkout-reminder-banner');
        if (banner) {
            banner.style.display = 'none';
            banner.innerHTML = '';
        }
    }

    function showToast(level, message) {
        const container = document.getElementById('checkout-toast-container');
        if (!container) return;

        const colors = {
            first: 'bg-blue-600',
            second: 'bg-amber-500',
            final: 'bg-red-600'
        };

        const toast = document.createElement('div');
        toast.className = `${colors[level] || colors.first} text-white px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 text-sm font-medium transform translate-x-full transition-transform duration-300`;
        toast.innerHTML = `
            <i class="fa-solid fa-bell"></i>
            <span class="flex-1">${escapeHtml(message)}</span>
            <button onclick="this.parentElement.remove()" class="text-white/70 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        `;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.remove('translate-x-full'));

        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.remove(), 300);
        }, 8000);
    }

    function updateBadge(count) {
        const badge = document.getElementById('checkout-reminder-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }

        const topbarBadge = document.getElementById('checkout-topbar-badge');
        if (topbarBadge) {
            topbarBadge.style.display = count > 0 ? 'block' : 'none';
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function startPolling() {
        checkReminder();
        scheduleNext();
    }

    function scheduleNext() {
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = setTimeout(function () {
            checkReminder();
            scheduleNext();
        }, getPollInterval());
    }

    window.CheckoutReminder = {
        dismiss: function (id) {
            if (!id) return;
            fetch('../ajax/checkout_reminder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=dismiss&reminder_id=' + id,
                credentials: 'same-origin'
            }).then(() => {
                hideReminderBanner();
                updateBadge(0);
                currentReminder = null;
                lastLevel = null;
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPolling);
    } else {
        startPolling();
    }
})();
