(function () {
    try {
        const theme = localStorage.getItem('nixor_theme') || 'theme-default';
        if (theme && theme.startsWith('theme-') && theme !== 'theme-default') {
            document.documentElement.classList.add(theme);
        }
    } catch (e) {
        console.warn('Failed to initialize theme:', e);
    }
})();
