// Connexion par empreinte digitale, reconnaissance faciale ou Windows Hello (passkeys).
import { Passkeys } from '@laravel/passkeys';

const messages = {
    NotAllowedError: 'Opération annulée ou délai dépassé.',
    InvalidStateError: 'Cet appareil est déjà enregistré pour votre compte.',
    SecurityError: 'L’empreinte ne fonctionne que sur une adresse sécurisée (https).',
};

const explain = (error) => messages[error?.name] ?? error?.message ?? 'La connexion par empreinte a échoué.';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('passkeyLogin', () => ({
        supported: Passkeys.isSupported(),
        loading: false,
        error: null,
        async login() {
            this.loading = true;
            this.error = null;
            try {
                const response = await Passkeys.verify({ remember: true });
                window.location.href = response?.redirect ?? '/tableau-de-bord';
            } catch (error) {
                this.error = explain(error);
                this.loading = false;
            }
        },
    }));

    window.Alpine.data('passkeyRegister', () => ({
        supported: Passkeys.isSupported(),
        loading: false,
        error: null,
        async register(name, onDone) {
            this.loading = true;
            this.error = null;
            try {
                await Passkeys.register({ name });
                onDone?.();
            } catch (error) {
                this.error = explain(error);
            } finally {
                this.loading = false;
            }
        },
    }));
});
