<div>
    <a href="{{ route('admin.legal') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Textes juridiques') }}</a>
    <x-page-header :title="__($label)" :description="__('Nouvelle version, après la version :v en vigueur. Le texte s’écrit en Markdown : ## pour un titre, - pour une liste, **gras**.', ['v' => $current->version])" />

    <div class="mb-4 flex gap-1 rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        <button type="button" role="tab" wire:click="$set('preview', false)" aria-selected="{{ $preview ? 'false' : 'true' }}" @class(['flex-1 rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => ! $preview, 'text-ink-600' => $preview])>{{ __('Texte') }}</button>
        <button type="button" role="tab" wire:click="$set('preview', true)" aria-selected="{{ $preview ? 'true' : 'false' }}" @class(['flex-1 rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $preview, 'text-ink-600' => ! $preview])>{{ __('Aperçu') }}</button>
    </div>

    <form wire:submit="publish" class="space-y-4">
        <div>
            <label for="title" class="label">{{ __('Titre') }}</label>
            <input wire:model="title" id="title" class="input">
            @error('title') <p class="error">{{ $message }}</p> @enderror
        </div>
        @if ($preview)
            <article class="manuel card p-5 sm:p-8"><h1>{{ $title }}</h1>{!! $html !!}</article>
        @else
            <div>
                <label for="body" class="label">{{ __('Texte') }}</label>
                <textarea wire:model="body" id="body" rows="24" class="input font-mono text-sm leading-relaxed"></textarea>
                @error('body') <p class="error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="summary" class="label">{{ __('Ce qui change dans cette version') }}</label>
            <input wire:model="summary" id="summary" class="input" placeholder="{{ __('Exemple : mise à jour selon l’avis du juriste, article 4 sur les prix') }}">
            @error('summary') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap justify-end gap-2">
            @if ($hasDraft)
                <button type="button" wire:click="discardDraft" wire:confirm="{{ __('Abandonner ce brouillon ?') }}" class="btn-ghost text-terra-600">{{ __('Abandonner le brouillon') }}</button>
            @endif
            <button type="button" wire:click="saveDraft" class="btn-secondary">{{ __('Enregistrer le brouillon') }}</button>
            <button type="submit" class="btn-primary" wire:confirm="{{ __('Publier cette version ? Elle remplacera la version en vigueur sur le site et dans l’application.') }}"><x-icon name="upload" class="size-4" /> {{ __('Publier') }}</button>
        </div>
    </form>
</div>
