/* ═══════════════════════════════════════════════════════════════
   HNIN AKARI NWE HRMS — JavaScript v2.0
   Theme, Animations, Interactions, Loading
   ═══════════════════════════════════════════════════════════════ */

/* ─── THEME TOGGLE ─── */
function toggleTheme() {
    var html = document.documentElement;
    var body = document.body;

    var overlay = document.createElement('div');
    overlay.className = 'theme-transition-overlay';
    document.body.appendChild(overlay);

    requestAnimationFrame(function() {
        overlay.classList.add('active');
    });

    setTimeout(function() {
        var isDark = html.classList.contains('dark');
        if (isDark) {
            html.classList.remove('dark');
            localStorage.setItem('aura-theme', 'light');
        } else {
            html.classList.add('dark');
            localStorage.setItem('aura-theme', 'dark');
        }

        setTimeout(function() {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 600);
        }, 150);
    }, 250);
}

/* ─── ANIMATED COUNTER ─── */
function animateCounter(el, target, duration) {
    duration = duration || 1200;
    var start = 0;
    var startTime = null;

    function step(timestamp) {
        if (!startTime) startTime = timestamp;
        var progress = Math.min((timestamp - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        var current = Math.floor(eased * target);
        el.textContent = current.toLocaleString();
        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            el.textContent = target.toLocaleString();
        }
    }

    requestAnimationFrame(step);
}

/* ─── TOAST NOTIFICATIONS ─── */
function showToast(message, type) {
    type = type || 'info';
    var icons = {
        success: 'fa-circle-check',
        error: 'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };
    var colors = {
        success: 'text-emerald-400',
        error: 'text-rose-400',
        warning: 'text-amber-400',
        info: 'text-violet-400'
    };

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + ' ' + (colors[type] || colors.info) + '"></i><span>' + message + '</span>';
    document.body.appendChild(toast);

    setTimeout(function() {
        toast.classList.add('toast-out');
        setTimeout(function() { toast.remove(); }, 300);
    }, 4000);
}

/* ─── PASSWORD TOGGLE ─── */
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
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggle.click();
            }
        });
    });
}

/* ─── COUNTER ANIMATION ─── */
function initCounters() {
    document.querySelectorAll('[data-counter]').forEach(function(el) {
        var target = parseInt(el.getAttribute('data-counter')) || 0;
        animateCounter(el, target, 1200);
    });
}

/* ─── LAZY IMAGE LOADING ─── */
function initLazyImages() {
    document.querySelectorAll('img[data-src]').forEach(function(img) {
        img.src = img.getAttribute('data-src');
        img.removeAttribute('data-src');
        img.addEventListener('load', function() { img.classList.add('loaded'); });
        if (img.complete) img.classList.add('loaded');
    });
}

/* ─── SKELETON LOADER ─── */
function initSkeletonLoader() {
    document.querySelectorAll('[data-skeleton]').forEach(function(el) {
        var target = document.querySelector(el.getAttribute('data-skeleton'));
        if (target) {
            setTimeout(function() {
                el.style.display = 'none';
                target.style.display = 'block';
            }, 600);
        }
    });
}

/* ─── INTERSECTION OBSERVER — Scroll Animations ─── */
function initScrollAnimations() {
    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('[data-animate]').forEach(function(el) {
        observer.observe(el);
    });
}

/* ─── AUTO-DISMISS ALERTS ─── */
function initAutoDismiss() {
    document.querySelectorAll('[data-auto-dismiss]').forEach(function(el) {
        setTimeout(function() {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s';
            setTimeout(function() { el.remove(); }, 300);
        }, 5000);
    });
}

/* ─── STAGGER STAT CARDS ─── */
function initStatCardStagger() {
    document.querySelectorAll('.stat-card').forEach(function(card, i) {
        card.style.animationDelay = (i * 0.08) + 's';
    });
}

/* ─── SMOOTH PAGE LOAD ─── */
function initPageLoad() {
    document.body.classList.add('loaded');
}

/* ─── NAV ITEM RIPPLE EFFECT ─── */
function initNavRipple() {
    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            var ripple = document.createElement('span');
            ripple.style.cssText = 'position:absolute;border-radius:50%;background:rgba(79,70,229,0.15);transform:scale(0);animation:navRipple 0.4s ease-out;pointer-events:none;';

            var rect = item.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';

            item.appendChild(ripple);
            setTimeout(function() { ripple.remove(); }, 500);
        });
    });
}

/* ─── KEYBOARD SHORTCUT — Cmd/Ctrl+K for Theme Toggle ─── */
function initKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            toggleTheme();
        }
    });
}

/* ─── DOMContentLoaded ─── */
document.addEventListener('DOMContentLoaded', function () {
    initPasswordToggles();
    initAutoDismiss();
    initCounters();
    initLazyImages();
    initSkeletonLoader();
    initStatCardStagger();
    initScrollAnimations();
    initNavRipple();
    initKeyboardShortcuts();
    initPageLoad();
});
