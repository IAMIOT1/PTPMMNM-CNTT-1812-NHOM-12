(() => {
    const storageKey = 'tms-theme';
    const themeToggleSelector = '[data-theme-toggle]';

    const readTheme = () => {
        try {
            return localStorage.getItem(storageKey) === 'light' ? 'light' : 'dark';
        } catch (error) {
            console.warn('[TMS theme] Could not read the saved theme preference.', error);
            return 'dark';
        }
    };

    const saveTheme = (theme) => {
        try {
            localStorage.setItem(storageKey, theme);
        } catch (error) {
            console.warn('[TMS theme] Could not save the theme preference.', error);
        }
    };

    const applyTheme = (theme) => {
        const isDark = theme === 'dark';
        document.body.classList.toggle('tms-dark', isDark);
        document.body.classList.toggle('tms-light', !isDark);

        document.querySelectorAll(themeToggleSelector).forEach((toggle) => {
            toggle.setAttribute('aria-pressed', String(isDark));
            const moonIcon = toggle.querySelector('[data-theme-icon="moon"]');
            const sunIcon = toggle.querySelector('[data-theme-icon="sun"]');
            if (moonIcon) moonIcon.hidden = isDark;
            if (sunIcon) sunIcon.hidden = !isDark;
        });
    };

    applyTheme(readTheme());

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const toggle = target.closest(themeToggleSelector);
        if (!toggle) return;

        const nextTheme = document.body.classList.contains('tms-dark') ? 'light' : 'dark';
        saveTheme(nextTheme);
        applyTheme(nextTheme);
    });
})();
