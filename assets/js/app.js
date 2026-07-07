function toggleTheme() {
    var html = document.documentElement;
    html.classList.add('theme-transitioning');
    var isDark = html.classList.contains('dark');
    if (isDark) {
        html.classList.remove('dark');
        localStorage.setItem('aura-theme', 'light');
    } else {
        html.classList.add('dark');
        localStorage.setItem('aura-theme', 'dark');
    }
    setTimeout(function() { html.classList.remove('theme-transitioning'); }, 500);
}

function animateCounter(el, target, duration) {
    var start = 0;
    var step = Math.max(1, Math.floor(target / (duration / 16)));
    var timer = setInterval(function() {
        start += step;
        if (start >= target) { start = target; clearInterval(timer); }
        el.textContent = start.toLocaleString();
    }, 16);
}

function showToast(message, type) {
    type = type || 'info';
    var icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    var colors = { success: 'text-emerald-400', error: 'text-rose-400', warning: 'text-amber-400', info: 'text-violet-400' };
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + ' ' + (colors[type] || colors.info) + '"></i><span>' + message + '</span>';
    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('toast-out'); setTimeout(function() { toast.remove(); }, 300); }, 4000);
}

function initPasswordToggles() {
    document.querySelectorAll('.pw-eye-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = toggle.getAttribute('data-target');
            var input = document.getElementById(targetId);
            var icon = toggle.querySelector('i');
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        toggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.click(); }
        });
    });
}

function initCounters() {
    document.querySelectorAll('[data-counter]').forEach(function(el) {
        var target = parseInt(el.getAttribute('data-counter')) || 0;
        animateCounter(el, target, 1200);
    });
}

function initLazyImages() {
    document.querySelectorAll('img[data-src]').forEach(function(img) {
        img.src = img.getAttribute('data-src');
        img.removeAttribute('data-src');
        img.addEventListener('load', function() { img.classList.add('loaded'); });
        if (img.complete) img.classList.add('loaded');
    });
}

function initSkeletonLoader() {
    document.querySelectorAll('[data-skeleton]').forEach(function(el) {
        var target = document.querySelector(el.getAttribute('data-skeleton'));
        if (target) {
            setTimeout(function() { el.style.display = 'none'; target.style.display = 'block'; }, 600);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initPasswordToggles();
    var alertMessages = document.querySelectorAll('[data-auto-dismiss]');
    alertMessages.forEach(function(el) {
        setTimeout(function() { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(function() { el.remove(); }, 300); }, 5000);
    });
    initCounters();
    initLazyImages();
    initSkeletonLoader();
    document.querySelectorAll('.stat-card').forEach(function(card, i) { card.style.animationDelay = (i * 0.08) + 's'; });
});
