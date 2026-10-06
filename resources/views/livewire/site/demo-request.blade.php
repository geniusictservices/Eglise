<div class="rounded-[22px] bg-white p-5 text-ink-900 shadow-2xl shadow-black/30 sm:p-7">
    @if ($sent)
        <div class="py-8 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-full bg-leaf-500 text-white"><x-icon name="check" class="size-7" /></span>
            <h3 class="mt-4 text-xl">{{ __('Merci, votre demande est bien reçue') }}</h3>
            <p class="mt-2 text-sand-700">{{ __('L’équipe Genius ICT vous appelle ou vous écrit sur WhatsApp pour convenir d’un rendez-vous.') }}</p>
        </div>
    @else
        <form wire:submit="submit" class="grid gap-4 sm:grid-cols-2">
            <h3 class="text-xl sm:col-span-2">{{ __('Demander une démonstration') }}</h3>
            <div>
                <label for="demo-name" class="label">{{ __('Votre nom') }}</label>
                <input wire:model="name" id="demo-name" class="input" autocomplete="name">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="demo-phone" class="label">{{ __('Téléphone ou WhatsApp') }}</label>
                <input wire:model="phone" id="demo-phone" type="tel" inputmode="tel" class="input" placeholder="0812 345 678" autocomplete="tel">
                @error('phone') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="demo-community" class="label">{{ __('Votre église ou communauté') }}</label>
                <input wire:model="community" id="demo-community" class="input">
                @error('community') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="demo-city" class="label">{{ __('Ville') }}</label>
                <input wire:model="city" id="demo-city" class="input" placeholder="Goma">
            </div>
            <div class="sm:col-span-2">
                <label for="demo-members" class="label">{{ __('Nombre de membres environ') }}</label>
                <select wire:model="members" id="demo-members" class="input">
                    <option value="">{{ __('Choisir…') }}</option>
                    @foreach (\App\Support\Platform::get('size_tiers') as $tier => $label)<option value="{{ $tier }}">{{ $label }}</option>@endforeach
                    <option value="denomination">{{ __('Dénomination avec plusieurs paroisses') }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="demo-message" class="label">{{ __('Message') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                <textarea wire:model="message" id="demo-message" rows="3" class="input" placeholder="{{ __('Ce qui vous intéresse le plus : registre, finances, attestations…') }}"></textarea>
            </div>
            <div class="hidden" aria-hidden="true">
                <label for="demo-website">Site web</label>
                <input wire:model="website" id="demo-website" tabindex="-1" autocomplete="off">
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="btn-primary w-full !py-3 text-base" wire:loading.attr="disabled">{{ __('Envoyer la demande') }}</button>
            </div>
        </form>
    @endif
</div>
