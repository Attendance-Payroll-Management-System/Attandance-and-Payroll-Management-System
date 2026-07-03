document.addEventListener('DOMContentLoaded', function () {
    const sidebarToggle = document.querySelector('[x-data]')?.__x ?? null;
    const alertMessages = document.querySelectorAll('[data-auto-dismiss]');
    alertMessages.forEach(el => {
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }, 5000);
    });
});
