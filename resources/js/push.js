// Recevoir les nouveautés sur ce téléphone (ou cet ordinateur), même application fermée.
const toKey = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

// Adresse de Waumini (racine du site ou sous-dossier), donnée par la page.
const base = document.querySelector('meta[name="app-url"]')?.content.replace(/\/$/, '') ?? '';

const send = (method, body) => fetch(`${base}/nouveautes/telephone`, {
    method,
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(body),
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushToggle', (publicKey) => ({
        supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && Boolean(publicKey),
        enabled: false,
        denied: false,
        busy: false,
        error: null,
        async init() {
            if (!this.supported) return;
            this.denied = Notification.permission === 'denied';
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            this.enabled = Boolean(subscription);
            // Le serveur doit connaître l'abonnement de ce téléphone (après un changement de compte, par exemple).
            if (subscription) send('POST', subscription.toJSON()).catch(() => {});
        },
        async toggle() {
            this.busy = true;
            this.error = null;
            try {
                const registration = await navigator.serviceWorker.ready;
                const current = await registration.pushManager.getSubscription();
                if (this.enabled && current) {
                    await send('DELETE', { endpoint: current.endpoint });
                    await current.unsubscribe();
                    this.enabled = false;
                } else {
                    const permission = await Notification.requestPermission();
                    this.denied = permission === 'denied';
                    if (permission !== 'granted') throw new Error('Autorisez les notifications pour Waumini dans les réglages du navigateur.');
                    const subscription = current ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(publicKey) });
                    const json = subscription.toJSON();
                    const encoding = (PushManager.supportedContentEncodings ?? ['aes128gcm']).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
                    const response = await send('POST', { ...json, contentEncoding: encoding });
                    if (!response.ok) throw new Error('Le serveur n’a pas enregistré ce téléphone. Réessayez.');
                    this.enabled = true;
                }
            } catch (error) {
                this.error = error?.message ?? 'Une erreur est survenue.';
            }
            this.busy = false;
        },
    }));
});

// Le nombre de nouveautés sur l'icône de l'application installée.
window.wauminiBadge = (count) => {
    if (!('setAppBadge' in navigator)) return;
    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
};
