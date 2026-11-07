<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Auth page chrome - theme toggle + the bits of JS every auth page needs.
 */
?>
<button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
    <i class="fas fa-moon" id="themeIcon"></i>
</button>

<script>
function initTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.body.classList.add('dark-mode');
        updateThemeIcon(true);
    }
}
function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeIcon(isDark);
}
function updateThemeIcon(isDark) {
    const i = document.getElementById('themeIcon');
    if (i) i.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}

// show / hide for any pwd field wrapped in .auth-input
function rsPwd(btn) {
    const inp = btn.parentNode.querySelector('input');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    btn.title = show ? 'Hide password' : 'Show password';
    inp.focus();
}

initTheme();
</script>
