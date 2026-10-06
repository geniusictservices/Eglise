<div>
    <x-page-header :title="__('Coordonnées et réglages')" :description="__('Ces coordonnées apparaissent sur le site public, dans l’application (abonnement, aide) et dans les modèles Excel.')" />

    <form wire:submit="save" class="grid gap-5 lg:grid-cols-[1.3fr_1fr]">
        <section class="card space-y-4 p-5 sm:p-6">
            <h2 class="text-lg">{{ __('Coordonnées de Genius ICT') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="company" class="label">{{ __('Société') }}</label><input wire:model="contact.company" id="company" class="input">@error('contact.company') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="city" class="label">{{ __('Ville') }}</label><input wire:model="contact.city" id="city" class="input"></div>
            </div>
            <div>
                <label for="phone" class="label">{{ __('Numéro WhatsApp') }}</label>
                <input wire:model="contact.phone" id="phone" type="tel" inputmode="tel" class="input" placeholder="0812 345 678">
                <p class="hint">{{ __('Utilisé par les boutons « Écrire sur WhatsApp » : souscription, demande d’aide, site public.') }}</p>
                @error('contact.phone') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="email" class="label">{{ __('E-mail') }}</label><input wire:model="contact.email" id="email" type="email" class="input">@error('contact.email') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="website" class="label">{{ __('Site web') }}</label><input wire:model="contact.website" id="website" class="input"></div>
            </div>
            <div><label for="payment" class="label">{{ __('Où payer l’abonnement') }}</label><textarea wire:model="contact.payment" id="payment" rows="2" class="input" placeholder="{{ __('M-Pesa 0812 345 678 · Airtel Money 0970 000 000 (Genius ICT)') }}"></textarea><p class="mt-1 text-xs text-sand-700">{{ __('Affiché aux communautés sur leur page Abonnement, au moment de déclarer leur paiement.') }}</p></div>
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm font-semibold text-leaf-600 hover:underline"><x-icon name="message-circle" class="size-4" /> {{ __('Tester le lien WhatsApp') }}</a>
            @endif
        </section>

        <section class="card space-y-4 self-start p-5 sm:p-6">
            <h2 class="text-lg">{{ __('Essai et délai de grâce') }}</h2>
            <div>
                <label for="trialDays" class="label">{{ __('Durée de l’essai gratuit (jours)') }}</label>
                <input wire:model="trialDays" id="trialDays" type="number" min="7" max="90" class="input">
                <p class="hint">{{ __('Pour les nouvelles inscriptions.') }}</p>
            </div>
            <div>
                <label for="graceDays" class="label">{{ __('Délai de grâce avant la lecture seule (jours)') }}</label>
                <input wire:model="graceDays" id="graceDays" type="number" min="0" max="90" class="input">
            </div>
            <button class="btn-primary w-full"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button>
        </section>
    </form>
</div>
