// Connexion par empreinte digitale, reconnaissance faciale ou Windows Hello (passkeys).
import { Passkeys } from '@laravel/passkeys';

const messages = {
    NotAllowedError: 'Opération annulée ou délai dépassé.',
    InvalidStateError: 'Cet appareil est déjà enregistré pour votre compte.',
    SecurityError: 'L’empreinte ne fonctionne que sur une adresse sécurisée (https).',
};

const explain = (error) => messages[error?.name] ?? error?.message ?? 'La connexion par empreinte a échoué.';

// Les adresses de la bibliothèque commencent par « / » : dans un sous-dossier (exemple.com/waumini/), on les préfixe.
const base = (document.querySelector('meta[name="app-url"]')?.content ?? '').replace(/\/$/, '');
const loginRoutes = { options: `${base}/passkeys/login/options`, submit: `${base}/passkeys/login` };
const registerRoutes = { options: `${base}/user/passkeys/options`, submit: `${base}/user/passkeys` };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('passkeyLogin', () => ({
        supported: Passkeys.isSupported(),
        loading: false,
        error: null,
        async login() {
            this.loading = true;
            this.error = null;
            try {
                const response = await Passkeys.verify({ remember: true, routes: loginRoutes });
                window.location.href = response?.redirect ?? `${base}/tableau-de-bord`;
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
                await Passkeys.register({ name, routes: registerRoutes });
                onDone?.();
            } catch (error) {
                this.error = explain(error);
            } finally {
                this.loading = false;
            }
        },
    }));
});
