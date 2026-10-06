@php use App\Models\PastoralCase; use App\Models\PastoralNote; use App\Support\Phone; @endphp
<div>
    <a href="{{ route('pastoral.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Suivi pastoral') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="icon-tile size-14 bg-white/15 text-ochre-300"><x-icon :name="PastoralCase::ICONS[$case->kind]" class="size-7" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ __(PastoralCase::KINDS[$case->kind]) }} · {{ __('ouvert le :d', ['d' => $case->opened_on->translatedFormat('j F Y')]) }}@if ($case->status === 'closed') · {{ __('clos le :d', ['d' => $case->closed_on?->translatedFormat('j F Y')]) }}@endif</p>
                <h1 class="text-2xl font-semibold text-white">{{ $case->personName() }}</h1>
                <p class="mt-1 text-sm text-ink-100">{{ $case->title }}</p>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                @if ($case->phone())<a href="tel:{{ $case->phone() }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="phone" class="size-4" /> {{ __('Appeler') }}</a>@endif
                @if ($case->member_id)<a href="{{ route('members.show', $case->member_id) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="contact-round" class="size-4" /> {{ __('Fiche') }}</a>@endif
                @if ($canWrite)
                    @if ($case->status === 'open')
                        <button type="button" wire:click="close" wire:confirm="{{ __('Clore ce suivi ?') }}" class="btn-accent !min-h-0 !py-2"><x-icon name="check" class="size-4" /> {{ __('Clore') }}</button>
                    @else
                        <button type="button" wire:click="reopen" class="btn-accent !min-h-0 !py-2"><x-icon name="undo-2" class="size-4" /> {{ __('Rouvrir') }}</button>
                    @endif
                @endif
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <div class="min-w-0 space-y-5">
            @if ($canWrite && $case->status === 'open')
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ __('Ajouter une visite ou une note') }}</h2>
                    <form wire:submit="addNote" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div><label for="n-kind" class="label">{{ __('Quoi') }}</label><select wire:model="note.kind" id="n-kind" class="input">@foreach (PastoralNote::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                            <div><label for="n-date" class="label">{{ __('Date') }}</label><input wire:model="note.happened_on" id="n-date" type="date" max="{{ today()->toDateString() }}" class="input"></div>
                        </div>
                        <div><label for="n-body" class="label">{{ __('Note') }}</label><textarea wire:model="note.body" id="n-body" rows="4" class="input" placeholder="{{ __('Comment va la personne, ce qui a été partagé, ce qu’il faut faire…') }}"></textarea>@error('note.body') <p class="error">{{ $message }}</p> @enderror</div>
                        <div class="flex flex-wrap items-end gap-3">
                            <div><label for="n-next" class="label">{{ __('Prochaine visite') }}</label><input wire:model="note.next_on" id="n-next" type="date" class="input"></div>
                            @if ($canConfidential)<label class="mb-2.5 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="note.is_confidential" class="size-4"> <x-icon name="lock" class="size-4 text-ochre-600" /> {{ __('Confidentielle : moi seul') }}</label>@endif
                            <button class="btn-primary ml-auto">{{ __('Ajouter') }}</button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ trans_choice(':count note|:count notes', $notes->count()) }}</h2>
                <ol class="relative space-y-4 border-l-2 border-sand-200 pl-5">
                    @forelse ($notes as $n)
                        <li wire:key="n-{{ $n->id }}" class="relative">
                            <span class="absolute -left-[27px] top-1 grid size-4 place-items-center rounded-full bg-ochre-500 ring-4 ring-white"></span>
                            <p class="text-xs text-sand-700">{{ __(PastoralNote::KINDS[$n->kind]) }} · {{ $n->happened_on->translatedFormat('j F Y') }}@if ($n->author) · {{ $n->author->name }}@endif
                                @if ($n->is_confidential)<span class="badge ml-1 bg-ochre-100 text-ochre-700"><x-icon name="lock" class="size-3" /> {{ __('Confidentielle') }}</span>@endif</p>
                            @if ($n->readableBy(auth()->user()))
                                <p class="mt-1 whitespace-pre-line text-sm text-ink-800">{{ $n->body }}</p>
                            @else
                                <p class="mt-1 text-sm italic text-sand-600">{{ __('Note confidentielle de :a : elle seule peut la lire.', ['a' => $n->author?->name ?? __('son auteur')]) }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucune note pour l’instant.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="card p-5">
                <h2 class="mb-2 text-base">{{ __('Confié à') }}</h2>
                @if ($canWrite)
                    <select wire:change="assign($event.target.value || null)" class="input" aria-label="{{ __('Confié à') }}">
                        <option value="">{{ __('Personne en particulier') }}</option>
                        @foreach ($team as $u)<option value="{{ $u->id }}" @selected($case->assigned_to === $u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                @else
                    <p class="text-sm text-ink-800">{{ $case->assignee?->name ?? __('Personne en particulier') }}</p>
                @endif
                @if ($case->next_on)<p @class(['mt-3 text-sm font-semibold', 'text-terra-700' => $case->isOverdue(), 'text-ink-700' => ! $case->isOverdue()])>{{ __('Prochaine visite : :d', ['d' => $case->next_on->translatedFormat('l j F')]) }}</p>@endif
            </section>
            @if ($others->isNotEmpty())
                <section class="card p-5">
                    <h2 class="mb-2 text-base">{{ __('Autres suivis de cette personne') }}</h2>
                    <ul class="space-y-1.5 text-sm">@foreach ($others as $o)<li><a href="{{ route('pastoral.show', $o) }}" class="text-ink-700 hover:underline">{{ __(PastoralCase::KINDS[$o->kind]) }} · {{ $o->title }}</a> <span class="text-sand-600">{{ $o->opened_on->format('Y') }}</span></li>@endforeach</ul>
                </section>
            @endif
            <p class="rounded-[18px] border border-dashed border-sand-300 p-4 text-xs text-sand-700"><x-icon name="lock" class="mr-1 inline size-3.5" /> {{ __('Les notes confidentielles sont chiffrées. Seul leur auteur les lit : ni l’administrateur, ni le support de Genius ICT, ni aucun export.') }}</p>
        </aside>
    </div>
</div>
