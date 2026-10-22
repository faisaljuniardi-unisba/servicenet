(() => {
    const storageKey = 'netcare-theme-preference';
    const choices = new Set(['light', 'dark']);
    const savedPreference = () => {
        try {
            const saved = localStorage.getItem(storageKey);
            return choices.has(saved)
                ? saved
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch (_) {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
    };
    const applyTheme = (preference) => {
        const safePreference = choices.has(preference) ? preference : savedPreference();
        const theme = safePreference;
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themePreference = safePreference;
        window.dispatchEvent(new CustomEvent('netcarethemechange', { detail: { theme, preference: safePreference } }));
    };

    applyTheme(savedPreference());

    document.addEventListener('DOMContentLoaded', () => {
        const control = document.createElement('div');
        control.className = 'theme-control';
        control.innerHTML = `
            <button class="theme-toggle" type="button" aria-label="Ubah tema tampilan"><span class="theme-icon" aria-hidden="true"></span></button>`;

        const header = document.querySelector('.content-header');
        const sidebarHeader = document.querySelector('.sidebar-header');
        const serviceHeaderRow = document.querySelector('.container > header > div');
        const publicHeader = document.querySelector('.public-header');
        const loginContainer = document.querySelector('.login-container');
        const userInfo = header?.querySelector('.user-info');
        if (sidebarHeader) {
            // Di area admin, tema ditempatkan di header merek agar judul dan
            // identitas akun pada konten tetap sejajar.
            sidebarHeader.append(control);
            control.classList.add('theme-in-sidebar');
        } else if (serviceHeaderRow) {
            // Form Service memakai baris header sendiri; tempatkan kontrol pada
            // baris yang sama supaya tidak bertabrakan dengan tombol Admin.
            serviceHeaderRow.append(control);
            control.classList.add('theme-in-service-header');
        } else if (publicHeader) {
            publicHeader.append(control);
            control.classList.add('theme-in-public-header');
        } else if (loginContainer) {
            loginContainer.append(control);
            control.classList.add('theme-in-login');
        } else if (userInfo) {
            userInfo.prepend(control);
        } else if (header) {
            header.append(control);
        } else {
            control.classList.add('theme-floating');
            document.body.append(control);
        }

        const toggle = control.querySelector('.theme-toggle');
        const refreshToggleIcon = () => {
            const activeTheme = document.documentElement.dataset.theme;
            const icon = activeTheme === 'dark'
                ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2" fill="currentColor"></circle><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.55 1.55M17.15 17.15l1.55 1.55M18.7 5.3l-1.55 1.55M6.85 17.15L5.3 18.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>'
                : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.4 15.5A8.8 8.8 0 0 1 8.5 3.6 8.8 8.8 0 1 0 20.4 15.5Z" fill="currentColor"></path></svg>';
            toggle.querySelector('.theme-icon').innerHTML = icon;
            toggle.title = activeTheme === 'dark' ? 'Ubah tampilan tema' : 'Ubah tampilan tema';
        };
        toggle.addEventListener('click', () => {
            const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(storageKey, nextTheme);
            } catch (_) {
                // Tema tetap dapat dipakai pada sesi ini meski penyimpanan browser tidak tersedia.
            }
            applyTheme(nextTheme);
            refreshToggleIcon();
        });
        window.addEventListener('netcarethemechange', refreshToggleIcon);
        refreshToggleIcon();
    });
})();
