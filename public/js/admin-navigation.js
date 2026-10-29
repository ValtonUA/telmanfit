(() => {
    if (window.__telmanfitAdminNavigation) return;
    window.__telmanfitAdminNavigation = true;

    // History snapshot Livewire не виконує серверний MFA/ACL gate.
    // Native reload зберігає history pointer і Forward, перевіряючи доступ заново.
    document.addEventListener('livewire:navigate', (event) => {
        if (!event.detail?.history || !event.detail?.cached) return;
        event.preventDefault();
        document.documentElement.style.visibility = 'hidden';
        window.location.reload();
    });

    // BFCache браузера також не є доказом чинної адміністративної сесії.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.documentElement.style.visibility = 'hidden';
        window.location.reload();
    });
})();
