<div>
    <x-page-header :title="__('Annonces')" :description="__('Publiée ici, une annonce arrive dans les nouveautés de ceux qu’elle concerne. Partagez-la aussi dans le groupe WhatsApp de l’église.')">
        <x-slot:actions>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="megaphone" class="size-4" /> {{ __('Nouvelle annonce') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex gap-1 rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach ([0 => __('En cours'), 1 => __('Anciennes')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('archived', {{ $key ? 'true' : 'false' }})" aria-selected="{{ (int) $archived === $key ? 'true' : 'false' }}" @class(['flex-1 rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => (int) $archived === $key, 'text-ink-600' => (int) $archived !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="space-y-3">
        @forelse ($items as $a)
            <li wire:key="a-{{ $a->id }}" @class(['card p-5', 'border-ochre-300' => $a->pinned])>
                <div class="flex items-start gap-3">
                    <span @class(['grid size-11 shrink-0 place-items-center rounded-xl', 'bg-ochre-100 text-ochre-700' => $a->pinned, 'bg-ink-50 text-ink-700' => ! $a->pinned])><x-icon name="megaphone" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-sand-700">
                            @if ($a->pinned)<span class="font-semibold text-ochre-700">{{ __('Épinglée') }} · </span>@endif
                            {{ $a->published_at->translatedFormat('j M') }} · {{ $a->audienceLabel() }}@if ($a->author) · {{ $a->author->name }}@endif
                        </p>
                        <a href="{{ route('announcements.show', $a) }}" class="mt-0.5 block font-semibold text-ink-800 hover:underline">{{ $a->title }}</a>
                        @if ($a->event && $a->event_date)
                            <p class="mt-1 inline-flex items-center gap-1.5 text-sm text-ink-700"><x-icon name="calendar-days" class="size-4 text-ochre-600" /> {{ ucfirst($a->event_date->translatedFormat('l j F')) }}@if ($a->event->hours()) · {{ $a->event->hours() }}@endif</p>
                        @endif
                        <p class="mt-1 line-clamp-4 whitespace-pre-line text-sm text-ink-700">{{ $a->body }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @include('livewire.announcements.partials.share', ['announcement' => $a])
                            @if ($canCreate && \App\Support\AnnouncementAccess::canEdit(auth()->user(), current_organization(), $a))
                                <span class="ml-auto flex gap-1">
                                    <button type="button" wire:click="edit({{ $a->id }})" class="rounded-lg p-2 text-ink-500 hover:bg-sand-100" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" wire:click="delete({{ $a->id }})" wire:confirm="{{ __('Supprimer cette annonce ?') }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>
                                </span>
                            @endif
                        </div>
                        @if ($a->recipients && $canCreate)<p class="mt-2 text-xs text-sand-600">{{ trans_choice(':count personne prévenue dans Waumini|:count personnes prévenues dans Waumini', $a->recipients) }}</p>@endif
                    </div>
                </div>
            </li>
        @empty
            <li class="card p-8 text-center text-sm text-sand-700">{{ $archived ? __('Aucune ancienne annonce.') : __('Aucune annonce en cours.') }}</li>
        @endforelse
    </ul>
    <div class="mt-4">{{ $items->links() }}</div>

    @if ($canCreate)
        <x-modal name="announcement" :title="$editingId ? __('Modifier l’annonce') : __('Nouvelle annonce')">
            <form wire:submit="save" class="space-y-4">
                @if ($linkedEvent && ($form['event_date'] ?? null))
                    <p class="flex items-center gap-2 rounded-xl bg-ochre-50 px-3 py-2 text-sm text-ink-800"><x-icon name="calendar-days" class="size-4 text-ochre-600" /> {{ $linkedEvent->title }} · {{ ucfirst(\Illuminate\Support\Carbon::parse($form['event_date'])->translatedFormat('l j F')) }}</p>
                @endif
                <div><label for="an-title" class="label">{{ __('Titre') }}</label><input wire:model="form.title" id="an-title" class="input" placeholder="{{ __('Exemple : Pas de culte du soir ce dimanche') }}">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="an-body" class="label">{{ __('Message') }}</label><textarea wire:model="form.body" id="an-body" rows="5" class="input"></textarea>@error('form.body') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="an-aud" class="label">{{ __('Pour qui') }}</label>
                        <select wire:model.live="form.audience" id="an-aud" class="input">@foreach ($audiences as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        @error('form.audience') <p class="error">{{ $message }}</p> @enderror</div>
                    @if (($form['audience'] ?? 'all') === 'department')
                        <div><label for="an-dept" class="label">{{ __('Département') }}</label><select wire:model="form.department_id" id="an-dept" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>@error('form.department_id') <p class="error">{{ $message }}</p> @enderror</div>
                    @elseif (($form['audience'] ?? 'all') === 'group')
                        <div><label for="an-group" class="label">{{ __('Groupe') }}</label><select wire:model="form.group_id" id="an-group" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>@error('form.group_id') <p class="error">{{ $message }}</p> @enderror</div>
                    @endif
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="an-exp" class="label">{{ __('Visible jusqu’au') }}</label><input wire:model="form.expires_on" id="an-exp" type="date" class="input">@error('form.expires_on') <p class="error">{{ $message }}</p> @enderror</div>
                    @if ($full)<label class="flex items-center gap-3 self-end pb-3 text-sm"><input type="checkbox" wire:model="form.pinned" class="size-4"> {{ __('Épingler en haut') }}</label>@endif
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'announcement' })">{{ __('Annuler') }}</button>
                    <button class="btn-primary">{{ $editingId ? __('Enregistrer') : __('Publier') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
