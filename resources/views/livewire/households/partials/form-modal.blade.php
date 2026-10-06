<x-modal name="household" :title="$title">
    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="h-name" class="label">{{ __('Nom du ménage') }}</label>
            <input wire:model="form.name" id="h-name" class="input" placeholder="{{ __('Exemple : Famille KAHINDO') }}">
            @error('form.name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="h-district" class="label">{{ __('Quartier') }}</label><input wire:model="form.district" id="h-district" class="input"></div>
            <div><label for="h-city" class="label">{{ __('Ville') }}</label><input wire:model="form.city" id="h-city" class="input"></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
            <div><label for="h-street" class="label">{{ __('Avenue') }}</label><input wire:model="form.street" id="h-street" class="input"></div>
            <div><label for="h-number" class="label">{{ __('Numéro') }}</label><input wire:model="form.house_number" id="h-number" class="input"></div>
        </div>
        <div>
            <label for="h-phone" class="label">{{ __('Téléphone du ménage') }}</label>
            <input wire:model="form.phone" id="h-phone" type="tel" inputmode="tel" class="input">
            @error('form.phone') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'household' })">{{ __('Annuler') }}</button>
            <button class="btn-primary">{{ __('Enregistrer') }}</button>
        </div>
    </form>
</x-modal>
