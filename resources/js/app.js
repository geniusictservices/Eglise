import './passkeys';

// Installation de l'application (PWA) : Android, Windows et ordinateurs.
// Livewire charge Alpine ; on y déclare un « store » partagé avant son démarrage.
let deferredPrompt = null;

document.addEventListener('alpine:init', () => {
    window.Alpine.store('pwa', {
        canInstall: false,
        installed: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        async install() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            this.canInstall = false;
        },
    });
});

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    if (window.Alpine) window.Alpine.store('pwa').canInstall = true;
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    if (window.Alpine) {
        window.Alpine.store('pwa').canInstall = false;
        window.Alpine.store('pwa').installed = true;
    }
});
