<x-modal name="department" :title="$title">
    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="d-name" class="label">{{ __('Nom') }}</label>
            <input wire:model="form.name" id="d-name" class="input" placeholder="{{ __('Exemple : Chorale Les Messagers') }}">
            @error('form.name') <p class="error">{{ $message }}</p> @enderror
        </div>
        @unless ($system ?? false)
            <fieldset>
                <legend class="label">{{ __('Type') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['ministry' => __('Ministère : chorale, jeunesse, mamans…'), 'administrative' => __('Service administratif : finances, secrétariat…')] as $value => $label)
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border-[1.5px] p-3 text-sm', 'border-ink-700 bg-ink-50' => ($form['kind'] ?? '') === $value, 'border-sand-300' => ($form['kind'] ?? '') !== $value])>
                            <input type="radio" wire:model.live="form.kind" value="{{ $value }}" class="mt-0.5 size-4">
                            <span><span class="block font-semibold text-ink-800">{{ __(\App\Models\Department::KINDS[$value]) }}</span><span class="text-sand-700">{{ \Illuminate\Support\Str::after($label, ': ') }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endunless
        <div>
            <label for="d-description" class="label">{{ __('Mission') }}</label>
            <textarea wire:model="form.description" id="d-description" rows="2" class="input" placeholder="{{ __('En une ou deux phrases : ce que fait ce département.') }}"></textarea>
        </div>
        <fieldset>
            <legend class="label">{{ __('Couleur') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach ($colors as $key => $label)
                    <label class="cursor-pointer" title="{{ __($label) }}">
                        <input type="radio" wire:model.live="form.color" value="{{ $key }}" class="peer sr-only">
                        <span @class(["block size-9 rounded-full ring-offset-2 peer-checked:ring-2 peer-checked:ring-ink-700 peer-focus-visible:ring-2 peer-focus-visible:ring-ochre-500",
                            'bg-ink-700' => $key === 'ink', 'bg-ochre-500' => $key === 'ochre', 'bg-terra-500' => $key === 'terra', 'bg-leaf-500' => $key === 'leaf', 'bg-sand-500' => $key === 'sand'])></span>
                        <span class="sr-only">{{ __($label) }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'department' })">{{ __('Annuler') }}</button>
            <button class="btn-primary">{{ __('Enregistrer') }}</button>
        </div>
    </form>
</x-modal>
