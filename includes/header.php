<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="preconnect" href="https://cdnjs.cloudflare.com">
<link rel="dns-prefetch" href="https://cdn.tailwindcss.com">
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
<link rel="stylesheet" href="../assets/css/app.css">
<script src="../assets/js/app.js"></script>
<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
<script>
tailwind.config = { darkMode: 'class' }
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    var theme = localStorage.getItem('aura-theme');
    if (theme === 'light') {
        document.documentElement.classList.remove('dark');
    } else if (theme === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            document.documentElement.classList.remove('dark');
        } else {
            document.documentElement.classList.add('dark');
        }
    }
})();
</script>
<script>
// Chart.js global defaults for theme awareness
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.plugins.legend.labels.usePointStyle = true;
function getChartColors() {
    var isDark = document.documentElement.classList.contains('dark');
    return {
        grid: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.06)',
        text: isDark ? '#94A3B8' : '#64748B',
        tooltipBg: isDark ? 'rgba(15,23,42,0.95)' : 'rgba(255,255,255,0.95)',
        tooltipBorder: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
        tooltipText: isDark ? '#F1F5F9' : '#1E293B'
    };
}
</script>
<style>
/* ═══════════════════════════════════════════════════════════════
   COMPREHENSIVE LIGHT MODE OVERRIDES
   Navy/Indigo color system — fixes all dark-mode-only Tailwind
   classes when the system is in light mode.
   ═══════════════════════════════════════════════════════════════ */

/* -- Glass backgrounds -- */
:root:not(.dark) .glass-strong,
:root:not(.dark) .glass {
  background: rgba(255,255,255,0.95);
  border-color: rgba(30,58,138,0.08);
  backdrop-filter: blur(20px);
}

/* -- Element backgrounds -- */
:root:not(.dark) .bg-white\/\[0\.06\],
:root:not(.dark) .bg-white\/10,
:root:not(.dark) .bg-white\/\[0\.04\],
:root:not(.dark) .bg-white\/\[0\.02\] { background-color: #F8FAFC !important; }

/* Force dark body background across all pages */
:root.dark .bg-\[\#0B1120\],
:root.dark .bg-\[\#0a0a0f\],
:root.dark body { background-color: #0B1120 !important; }

/* -- Body background: subtle slate gradient -- */
:root:not(.dark) body {
  background: #F8FAFC !important;
}

/* -- Text colors -- */
:root:not(.dark) .text-white { color: #1E293B !important; }
:root:not(.dark) .text-zinc-300 { color: #64748B !important; }
:root:not(.dark) .text-zinc-400 { color: #64748B !important; }
:root:not(.dark) .text-zinc-500 { color: #94A3B8 !important; }
:root:not(.dark) .text-zinc-600 { color: #64748B !important; }

/* -- Status colors -- */
:root:not(.dark) .text-emerald-400 { color: #16A34A !important; }
:root:not(.dark) .text-emerald-500 { color: #15803D !important; }
:root:not(.dark) .text-amber-400 { color: #D97706 !important; }
:root:not(.dark) .text-amber-500 { color: #B45309 !important; }
:root:not(.dark) .text-rose-400 { color: #DC2626 !important; }
:root:not(.dark) .text-rose-500 { color: #BE123C !important; }
:root:not(.dark) .text-red-400 { color: #DC2626 !important; }
:root:not(.dark) .text-red-500 { color: #B91C1C !important; }
:root:not(.dark) .text-violet-400 { color: #4F46E5 !important; }
:root:not(.dark) .text-violet-500 { color: #4338CA !important; }
:root:not(.dark) .text-blue-400 { color: #2563EB !important; }
:root:not(.dark) .text-blue-500 { color: #1D4ED8 !important; }
:root:not(.dark) .text-indigo-400 { color: #4F46E5 !important; }
:root:not(.dark) .text-cyan-400 { color: #0891B2 !important; }
:root:not(.dark) .text-pink-400 { color: #DB2777 !important; }
:root:not(.dark) .text-purple-400 { color: #7C3AED !important; }
:root:not(.dark) .text-orange-400 { color: #EA580C !important; }
:root:not(.dark) .text-fuchsia-400 { color: #C026D3 !important; }
:root:not(.dark) .text-sky-400 { color: #0284C7 !important; }

/* -- Border colors -- */
:root:not(.dark) .border-white\/10,
:root:not(.dark) .border-white\/\[0\.06\],
:root:not(.dark) .border-white\/\[0\.08\] { border-color: #E2E8F0 !important; }

:root:not(.dark) .border-black\/\[0\.06\],
:root:not(.dark) .border-black\/\[0\.04\] { border-color: #E2E8F0 !important; }

/* -- Divide colors -- */
:root:not(.dark) .divide-white\/\[0\.06\] > :not([hidden]) ~ :not([hidden]) { border-color: #E2E8F0 !important; }

/* -- Hover states -- */
:root:not(.dark) .hover\:bg-white\/5:hover,
:root:not(.dark) .hover\:bg-white\/\[0\.02\]:hover,
:root:not(.dark) .hover\:bg-white\/\[0\.04\]:hover { background-color: #F1F5F9 !important; }

:root:not(.dark) .hover\:text-white:hover { color: #1E293B !important; }

/* -- Badge backgrounds -- */
:root:not(.dark) .bg-emerald-500\/20 { background-color: rgba(34,197,94,0.15) !important; }
:root:not(.dark) .bg-amber-500\/20 { background-color: rgba(245,158,11,0.15) !important; }
:root:not(.dark) .bg-rose-500\/20 { background-color: rgba(239,68,68,0.15) !important; }
:root:not(.dark) .bg-red-500\/20 { background-color: rgba(239,68,68,0.15) !important; }
:root:not(.dark) .bg-violet-500\/20 { background-color: rgba(30,58,138,0.15) !important; }
:root:not(.dark) .bg-blue-500\/20 { background-color: rgba(37,99,235,0.15) !important; }
:root:not(.dark) .bg-indigo-500\/20 { background-color: rgba(79,70,229,0.15) !important; }
:root:not(.dark) .bg-purple-500\/20 { background-color: rgba(124,58,237,0.15) !important; }
:root:not(.dark) .bg-pink-500\/20 { background-color: rgba(236,72,153,0.15) !important; }
:root:not(.dark) .bg-cyan-500\/20 { background-color: rgba(6,182,212,0.15) !important; }
:root:not(.dark) .bg-orange-500\/20 { background-color: rgba(249,115,22,0.15) !important; }
:root:not(.dark) .bg-sky-500\/20 { background-color: rgba(14,165,233,0.15) !important; }

:root:not(.dark) .bg-emerald-500\/10 { background-color: rgba(34,197,94,0.10) !important; }
:root:not(.dark) .bg-amber-500\/10 { background-color: rgba(245,158,11,0.10) !important; }
:root:not(.dark) .bg-rose-500\/10 { background-color: rgba(239,68,68,0.10) !important; }
:root:not(.dark) .bg-red-500\/10 { background-color: rgba(239,68,68,0.10) !important; }
:root:not(.dark) .bg-violet-500\/10 { background-color: rgba(30,58,138,0.10) !important; }
:root:not(.dark) .bg-blue-500\/10 { background-color: rgba(37,99,235,0.10) !important; }
:root:not(.dark) .bg-purple-500\/10 { background-color: rgba(124,58,237,0.10) !important; }
:root:not(.dark) .bg-orange-500\/10 { background-color: rgba(249,115,22,0.10) !important; }
:root:not(.dark) .bg-pink-500\/10 { background-color: rgba(236,72,153,0.10) !important; }
:root:not(.dark) .bg-cyan-500\/10 { background-color: rgba(6,182,212,0.10) !important; }
:root:not(.dark) .bg-sky-500\/10 { background-color: rgba(14,165,233,0.10) !important; }

/* -- Gradient backgrounds -- */
:root:not(.dark) .bg-gradient-to-r.from-violet-500\/20.to-fuchsia-500\/10 { background: linear-gradient(to right, rgba(30,58,138,0.12), rgba(79,70,229,0.08)) !important; }
:root:not(.dark) .bg-gradient-to-r.from-violet-500\/5.to-fuchsia-500\/5 { background: linear-gradient(to right, rgba(30,58,138,0.06), rgba(79,70,229,0.06)) !important; }

/* -- Form inputs -- */
:root:not(.dark) input[type="text"],
:root:not(.dark) input[type="number"],
:root:not(.dark) input[type="email"],
:root:not(.dark) input[type="password"],
:root:not(.dark) input[type="date"],
:root:not(.dark) input[type="time"],
:root:not(.dark) select,
:root:not(.dark) textarea {
  background-color: #F1F5F9 !important;
  border-color: #E2E8F0 !important;
  color: #1E293B !important;
}
:root:not(.dark) input:focus,
:root:not(.dark) select:focus,
:root:not(.dark) textarea:focus {
  border-color: #4F46E5 !important;
  box-shadow: 0 0 0 3px rgba(79,70,229,0.12) !important;
  background-color: #FFFFFF !important;
}
:root:not(.dark) input::placeholder,
:root:not(.dark) textarea::placeholder { color: #94A3B8 !important; }
:root:not(.dark) select option { background: #FFFFFF; color: #1E293B; }

/* -- Table header -- */
:root:not(.dark) table thead { background: linear-gradient(135deg, #1E3A8A, #4F46E5) !important; }
:root:not(.dark) table thead th { color: #FFFFFF !important; }
:root:not(.dark) table tbody tr:hover { background-color: #F1F5F9 !important; }

/* -- Card hover -- */
:root:not(.dark) .card-hover:hover { box-shadow: 0 8px 30px rgba(30,58,138,0.08); }

/* -- Theme toggle button -- */
:root:not(.dark) .theme-toggle-btn { background: #F1F5F9; border-color: #E2E8F0; color: #4F46E5; }
:root:not(.dark) .theme-toggle-btn:hover { background: #E2E8F0; color: #1E3A8A; }

/* -- Scrollbar -- */
:root:not(.dark) ::-webkit-scrollbar-thumb { background: #CBD5E1; }
:root:not(.dark) ::-webkit-scrollbar-thumb:hover { background: #94A3B8; }

/* -- Admin Mobile Menu Light Mode -- */
:root:not(.dark) #adminMobileMenu {
  background: #FFFFFF;
  box-shadow: 4px 0 40px rgba(30,58,138,0.12);
}
:root:not(.dark) #adminMobileMenu nav a {
  color: #475569;
}
:root:not(.dark) #adminMobileMenu nav a:hover {
  background-color: #F1F5F9;
  color: #1E293B;
}
:root:not(.dark) #adminMobileMenu nav a.active,
:root:not(.dark) #adminMobileMenu nav a[href] .bg-indigo-500 {
  background-color: rgba(79,70,229,0.12);
  color: #4F46E5;
}

/* -- Employee Bottom Nav Light Mode -- */
:root:not(.dark) .emp-bottom-nav {
  background: rgba(255,255,255,0.95);
  border-top-color: #E2E8F0;
  box-shadow: 0 -4px 24px rgba(30,58,138,0.06);
}
:root:not(.dark) .emp-bottom-nav-icon { color: #94A3B8; }
:root:not(.dark) .emp-bottom-nav-item.active .emp-bottom-nav-icon { color: #FFFFFF; }
:root:not(.dark) .emp-bottom-nav-label { color: #94A3B8; }
:root:not(.dark) .emp-bottom-nav-item.active .emp-bottom-nav-label { color: #4F46E5; }
:root:not(.dark) .emp-bottom-nav-item:hover .emp-bottom-nav-icon-wrap { background: rgba(79,70,229,0.06); }
</style>
