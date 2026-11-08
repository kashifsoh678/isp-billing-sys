<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * PWA meta tags + manifest link + service worker registration.
 * Include inside the <head> of every page:  <?php include __DIR__ . '/pwa.php'; ?>
 * Portal pages should use the relative path: <?php include '../pwa.php'; ?>
 */
?>
<!-- PWA -->
<meta name="theme-color" content="#001f3f">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ISP Billing">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/svg+xml" href="icon-192.svg">
<link rel="apple-touch-icon" href="icon-192.svg">
<script>
(function() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('service-worker.js', { scope: './' })
                .then(function(reg) {
                    // poll for updates every hour while page open
                    setInterval(function() { reg.update(); }, 60 * 60 * 1000);
                })
                .catch(function(err) { console.warn('SW register failed:', err); });

            // listen for new SW activation, prompt user to refresh
            navigator.serviceWorker.addEventListener('controllerchange', function() {
                if (window.__sw_reload_done) return;
                window.__sw_reload_done = true;
                // soft refresh — only on visibility change to avoid mid-action reload
                document.addEventListener('visibilitychange', function once() {
                    if (document.visibilityState === 'visible') {
                        document.removeEventListener('visibilitychange', once);
                        window.location.reload();
                    }
                });
            });
        });
    }

    // "Install" hint for mobile (small toast — no library)
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        if (sessionStorage.getItem('pwa_install_dismissed') === '1') return;
        const t = document.createElement('div');
        t.style.cssText = 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#001f3f;color:#fff;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;z-index:99999;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;gap:10px;align-items:center;max-width:90vw;';
        t.innerHTML = '<i class="fas fa-mobile-screen"></i> Install ISP Billing on your phone'
            + ' <button style="background:#0074D9;border:0;color:#fff;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:700">Install</button>'
            + ' <button style="background:transparent;border:0;color:#cbd5e1;cursor:pointer;font-size:18px">×</button>';
        document.body.appendChild(t);
        const [btnInstall, btnDismiss] = t.querySelectorAll('button');
        btnInstall.onclick = async function() {
            t.remove();
            if (deferredPrompt) { deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; }
        };
        btnDismiss.onclick = function() { sessionStorage.setItem('pwa_install_dismissed','1'); t.remove(); };
        setTimeout(function(){ if (t.parentNode) t.remove(); }, 12000);
    });
})();
</script>
<!-- /PWA -->
