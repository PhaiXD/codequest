// ========================================
// CodeQuest — Theme Toggle
// ========================================

(function() {
    'use strict';
    
    const THEME_KEY = 'cq_theme';
    
    // Get saved theme or default to dark
    function getSavedTheme() {
        return localStorage.getItem(THEME_KEY) || 
               document.cookie.replace(/(?:(?:^|.*;\s*)cq_theme\s*=\s*([^;]*).*$)|^.*$/, '$1') || 
               'dark';
    }
    
    // Apply theme
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem(THEME_KEY, theme);
        document.cookie = `cq_theme=${theme};path=/;max-age=${365*24*60*60}`;
        
        // Update toggle icon
        const toggleBtns = document.querySelectorAll('.theme-toggle');
        toggleBtns.forEach(btn => {
            const sunIcon = btn.querySelector('.icon-sun');
            const moonIcon = btn.querySelector('.icon-moon');
            if (sunIcon && moonIcon) {
                if (theme === 'light') {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'block';
                } else {
                    sunIcon.style.display = 'block';
                    moonIcon.style.display = 'none';
                }
            }
        });
        
        // Sync with server if logged in
        syncThemeWithServer(theme);
    }
    
    // Toggle theme
    function toggleTheme() {
        const current = getSavedTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
    }
    
    // Sync with server
    function syncThemeWithServer(theme) {
        fetch('api/user_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_theme', theme: theme })
        }).catch(() => {}); // Silent fail
    }
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        // Apply saved theme
        const theme = getSavedTheme();
        applyTheme(theme);
        
        // Bind toggle buttons
        document.querySelectorAll('.theme-toggle').forEach(btn => {
            btn.addEventListener('click', toggleTheme);
        });
    });
    
    // Also apply immediately (before DOM ready) to prevent flash
    const theme = getSavedTheme();
    document.documentElement.setAttribute('data-bs-theme', theme);
    
    // Expose globally
    window.CQTheme = {
        toggle: toggleTheme,
        apply: applyTheme,
        get: getSavedTheme
    };
})();