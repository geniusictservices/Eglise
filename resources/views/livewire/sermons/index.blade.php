<div>
    <x-page-header :title="__('Prédications')" :description="__('Les messages publiés sur le site vitrine : un lien vers la vidéo YouTube ou Facebook, ou un audio léger.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle prédication') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
        <section class="card min-w-0 self-start overflow-hidden">
            <ul class="divide-y divide-sand-100">
                @forelse ($sermons as $s)
                    <li wire:key="s-{{ $s->id }}" class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <span class="icon-tile shrink-0 bg-ink-50 text-ink-700"><x-icon :name="$s->audio_path ? 'mic' : 'video'" class="size-5" /></span>
                        <div class="min-w-0 flex-1 basis-48">
                            <p class="font-semibold text-ink-800">{{ $s->title }}@unless ($s->is_published) <span class="badge ml-1 bg-sand-100 text-sand-700">{{ __('Masquée') }}</span>@endunless</p>
                            <p class="text-sm text-sand-700">{{ collect([$s->preached_on->translatedFormat('j M Y'), $s->preacher, $s->passage, $s->platform() ?? ($s->audio_path ? __('Audio, :n Mo', ['n' => number_format(($s->audio_size ?? 0) / 1048576, 1, ',', ' ')]) : null)])->filter()->implode(' · ') }}</p>
                        </div>
                        @if ($canManage)
                            <div class="flex gap-1">
                                <button type="button" wire:click="edit({{ $s->id }})" class="btn-ghost !px-2.5" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                                <button type="button" wire:click="delete({{ $s->id }})" wire:confirm="{{ __('Supprimer cette prédication ?') }}" class="btn-ghost !px-2.5 text-terra-700" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-sand-700">{{ __('Aucune prédication publiée.') }}</li>
                @endforelse
            </ul>
            @if ($sermons->hasPages())<div class="border-t border-sand-100 px-5 py-3">{{ $sermons->links() }}</div>@endif
        </section>

        <aside class="card min-w-0 space-y-4 self-start p-5 sm:p-6">
            <h2 class="flex items-center gap-2 text-lg"><x-icon name="book-open" class="size-5 text-ochre-600" /> {{ __('Guide') }}</h2>
            <div class="space-y-1 text-sm">
                <p class="font-semibold text-ink-800">{{ __('Une vidéo') }}</p>
                <p class="text-sand-700">{{ __('Publiez le culte sur YouTube ou sur la page Facebook de l’église, puis copiez le lien de la vidéo (bouton « Partager » › « Copier le lien ») et collez-le ici. La vidéo reste chez YouTube ou Facebook : le site la montre sans rien télécharger chez vous.') }}</p>
            </div>
            <div class="space-y-1 text-sm">
                <p class="font-semibold text-ink-800">{{ __('Un audio léger') }}</p>
                <p class="text-sand-700">{{ __('Enregistrez le message avec le dictaphone du téléphone, puis réduisez-le en MP3 « voix » (32 à 64 kbit/s, mono) avec une application comme « MP3 Converter » ou Audacity. Une heure de prédication tient alors en moins de 15 Mo, et s’écoute même avec une connexion lente.') }}</p>
            </div>
            <div class="space-y-1 text-sm">
                <p class="font-semibold text-ink-800">{{ __('Le résumé') }}</p>
                <p class="text-sand-700">{{ __('Quelques lignes : le texte biblique, les points principaux, une application pour la semaine.') }}</p>
            </div>
            @if ($website?->is_published && $website->hasPage('predications'))
                <a href="{{ route('website.page', [$organization->slug, 'predications']) }}" target="_blank" rel="noopener" class="btn-secondary w-full"><x-icon name="external-link" class="size-4" /> {{ __('Voir sur le site') }}</a>
            @elseif (! $website?->is_published)
                <p class="rounded-xl bg-ochre-50 p-3 text-sm text-ochre-700">{{ __('Le site vitrine n’est pas encore publié.') }}</p>
            @endif
        </aside>
    </div>

    @if ($canManage)
        <x-modal name="sermon" :title="$editingId ? __('Modifier la prédication') : __('Nouvelle prédication')">
            <form wire:submit="save" class="space-y-4">
                <div><label for="s-title" class="label">{{ __('Titre') }}</label><input wire:model="form.title" id="s-title" class="input" placeholder="{{ __('Marcher par la foi') }}">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="s-by" class="label">{{ __('Prédicateur') }}</label><input wire:model="form.preacher" id="s-by" class="input"></div>
                    <div><label for="s-date" class="label">{{ __('Date') }}</label><input wire:model="form.preached_on" id="s-date" type="date" class="input">@error('form.preached_on') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="s-pass" class="label">{{ __('Texte biblique') }}</label><input wire:model="form.passage" id="s-pass" class="input" placeholder="{{ __('Hébreux 11.1-8') }}"></div>
                <div><label for="s-video" class="label">{{ __('Lien de la vidéo') }} <span class="font-normal text-sand-700">{{ __('(YouTube ou Facebook)') }}</span></label><input wire:model="form.video_url" id="s-video" type="url" class="input" placeholder="https://youtu.be/…">@error('form.video_url') <p class="error">{{ $message }}</p> @enderror</div>
                <div>
                    <label for="s-audio" class="label">{{ __('Ou un audio') }} <span class="font-normal text-sand-700">{{ __('(MP3, 15 Mo au plus)') }}</span></label>
                    <input wire:model="audio" id="s-audio" type="file" accept="audio/*" class="block w-full min-w-0 text-sm">
                    <div wire:loading wire:target="audio" class="mt-1 text-sm text-sand-700">{{ __('Envoi du fichier…') }}</div>
                    @error('audio') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div><label for="s-sum" class="label">{{ __('Résumé') }}</label><textarea wire:model="form.summary" id="s-sum" rows="4" class="input"></textarea></div>
                <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="form.is_published" class="size-4"> {{ __('Visible sur le site') }}</label>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'sermon' })">{{ __('Annuler') }}</button><button class="btn-primary" wire:loading.attr="disabled" wire:target="audio">{{ __('Enregistrer') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
