@php use App\Models\PastoralCase; use App\Support\Phone; @endphp
<div>
    <x-page-header :title="__('Suivi pastoral')" :description="__('Les personnes que l’équipe pastorale accompagne : visites, malades, deuils, catéchumènes. Les notes confidentielles ne se lisent que par leur auteur.')">
        <x-slot:actions>
            @if ($canWrite)
                <button type="button" @click="$dispatch('open-modal', { name: 'prayer' })" class="btn-secondary"><x-icon name="heart-handshake" class="size-4" /> {{ __('Demande de prière') }}</button>
                <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau suivi') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['suivis' => __('Suivis'), 'priere' => $openPrayers ? __('Prière (:n)', ['n' => $openPrayers]) : __('Prière'), 'anniversaires' => __('Anniversaires')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'suivis')
        <div class="mb-4 flex flex-wrap gap-2">
            <button type="button" wire:click="$set('kind', '')" @class(['rounded-full px-3 py-1.5 text-sm font-semibold', 'bg-ink-700 text-white' => $kind === '', 'bg-white text-ink-700 ring-1 ring-sand-200' => $kind !== ''])>{{ __('Tous') }} <span class="tabular">{{ $counts->sum() }}</span></button>
            @foreach (PastoralCase::KINDS as $k => $l)
                @if ($counts[$k] ?? false)
                    <button type="button" wire:click="$set('kind', '{{ $k }}')" @class(['rounded-full px-3 py-1.5 text-sm font-semibold', 'bg-ink-700 text-white' => $kind === $k, 'bg-white text-ink-700 ring-1 ring-sand-200' => $kind !== $k])>{{ __($l) }} <span class="tabular">{{ $counts[$k] }}</span></button>
                @endif
            @endforeach
            <label class="ml-auto flex items-center gap-2 text-sm text-sand-700"><input type="checkbox" wire:model.live="closed" class="size-4"> {{ __('Suivis clos') }}</label>
        </div>
        <ul class="grid gap-3 md:grid-cols-2">
            @forelse ($cases as $c)
                <li wire:key="pc-{{ $c->id }}"><a href="{{ route('pastoral.show', $c) }}" @class(['card flex h-full items-start gap-3 p-4 transition hover:border-ochre-300', 'border-terra-300' => $c->isOverdue()])>
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ochre-50 text-ochre-600"><x-icon :name="PastoralCase::ICONS[$c->kind]" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-ink-800">{{ $c->personName() }}@if ($c->source === 'website') <span class="badge ml-1 bg-ink-50 text-ink-700"><x-icon name="globe" class="size-3" /> {{ __('Site web') }}</span>@endif</span>
                        <span class="block text-sm text-sand-700">{{ __(PastoralCase::KINDS[$c->kind]) }} · {{ $c->title }}</span>
                        <span class="mt-1 block text-xs text-sand-600">
                            @if ($c->next_on)<span @class(['font-semibold', 'text-terra-700' => $c->isOverdue(), 'text-ink-700' => ! $c->isOverdue()])>{{ $c->isOverdue() ? __('En retard : prévu le :d', ['d' => $c->next_on->translatedFormat('j M')]) : __('Prochaine visite : :d', ['d' => $c->next_on->translatedFormat('j M')]) }}</span> · @endif
                            {{ trans_choice(':count note|:count notes', $c->notes_count) }}@if ($c->assignee) · {{ $c->assignee->name }}@endif
                        </span>
                    </span>
                </a></li>
            @empty
                <li class="card p-8 text-center text-sm text-sand-700 md:col-span-2">{{ $closed ? __('Aucun suivi clos.') : __('Personne n’est suivi pour l’instant.') }}</li>
            @endforelse
        </ul>
    @elseif ($tab === 'priere')
        <ul class="space-y-2">
            @forelse ($prayers as $p)
                <li wire:key="pr-{{ $p->id }}" @class(['card flex flex-wrap items-start gap-3 p-4', 'opacity-70' => $p->status === 'answered'])>
                    <x-icon name="heart-handshake" @class(['mt-0.5 size-5', 'text-ochre-600' => $p->status === 'open', 'text-leaf-500' => $p->status !== 'open']) />
                    <span class="min-w-0 flex-1 basis-56">
                        <span class="block font-semibold text-ink-800">{{ $p->subject }}</span>
                        <span class="block text-sm text-sand-700">{{ $p->requesterName() }}@if ($p->requester_phone) · <a href="tel:{{ $p->requester_phone }}" class="font-semibold text-ink-700 hover:underline">{{ $p->requester_phone }}</a>@endif · {{ $p->created_at->translatedFormat('j M') }}@if ($p->source === 'website') <span class="badge ml-1 bg-ink-50 text-ink-700"><x-icon name="globe" class="size-3" /> {{ __('Site web') }}</span>@endif</span>
                        @if ($p->body)<span class="mt-1 block whitespace-pre-line text-sm text-ink-700">{{ $p->body }}</span>@endif
                        @if ($p->answer)<span class="mt-1 block text-sm italic text-leaf-600">« {{ $p->answer }} »</span>@endif
                    </span>
                    @if ($p->status === 'open' && $canWrite)<button type="button" wire:click="askAnswer({{ $p->id }})" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="check" class="size-4" /> {{ __('Porté dans la prière') }}</button>@endif
                </li>
            @empty
                <li class="card p-8 text-center text-sm text-sand-700">{{ __('Aucune demande de prière.') }}</li>
            @endforelse
        </ul>
    @else
        <ul class="divide-y divide-sand-100 rounded-[18px] border border-sand-200 bg-white">
            @forelse ($birthdays as $b)
                <li class="flex items-center gap-3 px-4 py-3">
                    <span @class(['grid size-12 shrink-0 place-items-center rounded-xl text-center leading-none', 'bg-ochre-100 text-ochre-700' => $b['date']->isToday(), 'bg-ink-50 text-ink-700' => ! $b['date']->isToday()])><span><span class="block text-base font-semibold">{{ $b['date']->format('d') }}</span><span class="text-[10px] uppercase">{{ $b['date']->translatedFormat('M') }}</span></span></span>
                    <a href="{{ route('members.show', $b['member']) }}" class="min-w-0 flex-1"><span class="block truncate font-semibold text-ink-800">{{ $b['member']->fullName() }}</span><span class="block text-sm text-sand-700">{{ $b['date']->isToday() ? __('Aujourd’hui, :n ans', ['n' => $b['age']]) : __(':d · :n ans', ['d' => ucfirst($b['date']->translatedFormat('l j F')), 'n' => $b['age']]) }}</span></a>
                    @if ($b['member']->phone)
                        <a href="https://wa.me/{{ ltrim($b['member']->phone, '+') }}?text={{ rawurlencode(__('Joyeux anniversaire :p ! Que le Seigneur vous bénisse. — :o', ['p' => $b['member']->first_name, 'o' => current_organization()->displayName()])) }}" target="_blank" rel="noopener" class="btn !min-h-0 bg-[#25D366] !px-3 !py-1.5 text-sm text-white"><x-icon name="message-circle" class="size-4" /> <span class="hidden sm:inline">{{ __('Souhaiter') }}</span></a>
                    @endif
                </li>
            @empty
                <li class="p-8 text-center text-sm text-sand-700">{{ __('Aucun anniversaire dans les 30 prochains jours (ou les dates de naissance ne sont pas renseignées).') }}</li>
            @endforelse
        </ul>
    @endif

    @if ($canWrite)
        <x-modal name="case" :title="__('Nouveau suivi')">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <p class="label">{{ __('Personne') }}</p>
                    @if ($chosen)
                        <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $chosen->officialName() }}</span><button type="button" wire:click="$set('form.member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
                    @else
                        <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher le membre') }}">
                        <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseMember({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2"><input wire:model="form.person_name" class="input" placeholder="{{ __('… ou le nom d’une autre personne') }}" aria-label="{{ __('Nom') }}"><input wire:model="form.person_phone" type="tel" class="input" placeholder="{{ __('Téléphone') }}" aria-label="{{ __('Téléphone') }}"></div>
                    @endif
                    @error('form.person_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div><label for="pc-kind" class="label">{{ __('Type') }}</label><select wire:model="form.kind" id="pc-kind" class="input">@foreach (PastoralCase::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                    <div><label for="pc-title" class="label">{{ __('Motif') }}</label><input wire:model="form.title" id="pc-title" class="input" placeholder="{{ __('Exemple : hospitalisée à l’hôpital CBCA') }}">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="pc-next" class="label">{{ __('Prochaine visite') }}</label><input wire:model="form.next_on" id="pc-next" type="date" class="input"></div>
                    <div><label for="pc-who" class="label">{{ __('Confié à') }}</label><select wire:model="form.assigned_to" id="pc-who" class="input"><option value="">{{ __('Personne en particulier') }}</option>@foreach ($team as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'case' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Ouvrir le suivi') }}</button></div>
            </form>
        </x-modal>

        <x-modal name="prayer" :title="__('Demande de prière')">
            <form wire:submit="savePrayer" class="space-y-4">
                <div><label for="py-name" class="label">{{ __('Confiée par') }}</label><input wire:model="prayer.requester_name" id="py-name" class="input">@error('prayer.requester_name') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="py-subject" class="label">{{ __('Sujet') }}</label><input wire:model="prayer.subject" id="py-subject" class="input" placeholder="{{ __('Exemple : guérison de sa mère') }}">@error('prayer.subject') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="py-body" class="label">{{ __('Détails') }}</label><textarea wire:model="prayer.body" id="py-body" rows="3" class="input"></textarea></div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'prayer' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
            </form>
        </x-modal>

        <x-modal name="answer" :title="__('Porté dans la prière')">
            <form wire:submit="answerPrayer" class="space-y-4">
                <p class="text-sm text-sand-700">{{ __('Un mot pour la personne, si vous le souhaitez. Si elle a un compte, elle le recevra dans ses nouveautés.') }}</p>
                <textarea wire:model="answer" rows="3" class="input" placeholder="{{ __('Exemple : Nous avons prié pour votre maman dimanche. Courage.') }}" aria-label="{{ __('Message') }}"></textarea>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'answer' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Valider') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
