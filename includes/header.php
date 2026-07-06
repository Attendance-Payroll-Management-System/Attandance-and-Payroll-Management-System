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
        text: isDark ? '#a1a1aa' : '#64748b',
        tooltipBg: isDark ? 'rgba(24,24,27,0.95)' : 'rgba(255,255,255,0.95)',
        tooltipBorder: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
        tooltipText: isDark ? '#ffffff' : '#0f172a'
    };
}
</script>
<style>
:root:not(.dark) .glass-strong.text-white,
:root:not(.dark) .glass-strong .text-white { color: #0f172a !important; }
:root:not(.dark) .glass-strong.text-zinc-400,
:root:not(.dark) .glass-strong.text-zinc-300,
:root:not(.dark) .glass-strong .text-zinc-400,
:root:not(.dark) .glass-strong .text-zinc-300 { color: #475569 !important; }
:root:not(.dark) .glass-strong.text-zinc-500,
:root:not(.dark) .glass-strong .text-zinc-500 { color: #94a3b8 !important; }
:root:not(.dark) .glass-strong.bg-white\/\[0\.06\],
:root:not(.dark) .glass-strong.bg-white\/10,
:root:not(.dark) .glass-strong.bg-white\/\[0\.04\],
:root:not(.dark) .glass-strong .bg-white\/\[0\.06\],
:root:not(.dark) .glass-strong .bg-white\/10,
:root:not(.dark) .glass-strong .bg-white\/\[0\.04\] { background-color: #f1f5f9 !important; }
:root:not(.dark) .glass-strong.border-white\/10,
:root:not(.dark) .glass-strong.border-white\/\[0\.06\],
:root:not(.dark) .glass-strong .border-white\/10,
:root:not(.dark) .glass-strong .border-white\/\[0\.06\] { border-color: #e2e8f0 !important; }
:root:not(.dark) .glass-strong button.text-white { color: #ffffff !important; }
:root:not(.dark) .glass-strong button.hover\:text-white:hover { color: #475569 !important; }
:root:not(.dark) .glass-strong .divide-white\/\[0\.06\] > :not([hidden]) ~ :not([hidden]) { border-color: #e2e8f0 !important; }
:root:not(.dark) .glass-strong .hover\:bg-white\/5:hover { background-color: #f1f5f9 !important; }
</style>
