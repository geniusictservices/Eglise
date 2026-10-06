@php use App\Support\Money; use App\Models\DocumentRequest; @endphp
<div>
    @if (! $member)
        <x-page-header :title="__('Mon espace')" />
        <div class="card mx-auto max-w-xl p-6 text-center">
            <x-icon name="contact-round" class="mx-auto mb-3 size-10 text-ochre-500" />
            <p class="font-semibold text-ink-800">{{ __('Votre compte n’est pas encore relié à votre fiche de membre.') }}</p>
            <p class="mt-1 text-sm text-sand-700">{{ __('Demandez au secrétariat de relier votre compte à votre fiche : vous retrouverez ici votre carte, vos dons et reçus, vos promesses et vos demandes.') }}</p>
        </div>
    @else
        <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
            <div class="flex flex-wrap items-center gap-4">
                @include('livewire.members.partials.avatar', ['member' => $member, 'size' => 'size-16 text-lg'])
                <div class="min-w-0 flex-1 basis-40">
                    <p class="font-mono text-sm text-ochre-300">{{ $member->number }}</p>
                    <h1 class="text-2xl font-semibold text-white">{{ __('Bonjour :p', ['p' => $member->first_name ?: $member->last_name]) }}</h1>
                    <p class="text-sm text-ink-100">{{ collect([$member->status?->name, $member->joined_on ? __('depuis :y', ['y' => $member->joined_on->year]) : null])->filter()->implode(' · ') }}</p>
                </div>
                <a href="{{ route('members.card', $member) }}" class="btn-accent !min-h-0 w-full justify-center !py-2 sm:w-auto"><x-icon name="id-card" class="size-4" /> {{ __('Ma carte') }}</a>
            </div>
            @if ($departments->isNotEmpty() || $groups->isNotEmpty())
                <p class="mt-4 flex flex-wrap gap-1.5 border-t border-white/15 pt-3">
                    @foreach ($departments as $d)<span class="rounded-full bg-white/15 px-2.5 py-1 text-xs">{{ $d->name }}</span>@endforeach
                    @foreach ($groups as $g)<span class="rounded-full bg-white/15 px-2.5 py-1 text-xs">{{ $g->name }}</span>@endforeach
                </p>
            @endif
        </section>

        @if ($canWrite)
            <div class="mb-5 grid grid-cols-2 gap-3">
                <button type="button" @click="$dispatch('open-modal', { name: 'prayer' })" class="card flex items-center gap-3 p-4 text-left transition hover:border-ochre-300"><x-icon name="heart-handshake" class="size-6 text-ochre-600" /><span class="text-sm font-semibold text-ink-800">{{ __('Demander la prière') }}</span></button>
                <button type="button" @click="$dispatch('open-modal', { name: 'document' })" class="card flex items-center gap-3 p-4 text-left transition hover:border-ochre-300"><x-icon name="file-text" class="size-6 text-ochre-600" /><span class="text-sm font-semibold text-ink-800">{{ __('Demander une attestation') }}</span></button>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="card min-w-0 p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Le programme') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Les deux prochaines semaines') }}</p>
                <ul class="divide-y divide-sand-100">
                    @forelse ($agenda as $o)
                        @php $registered = $registrations->first(fn ($r) => $r->event_id === $o['event']->id && $r->occurs_on->isSameDay($o['date'])); @endphp
                        <li><a href="{{ route('events.show', ['event' => $o['event'], 'date' => $o['date']->toDateString()]) }}" class="flex items-center gap-3 py-2.5 hover:bg-sand-50">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-50 text-center leading-none text-ink-700"><span><span class="block text-[10px] uppercase">{{ $o['date']->translatedFormat('D') }}</span><span class="text-base font-semibold">{{ $o['date']->format('d') }}</span></span></span>
                            <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-ink-800">{{ $o['event']->title }}</span><span class="block truncate text-sm text-sand-700">{{ collect([$o['event']->hours(), $o['event']->place])->filter()->implode(' · ') }}</span></span>
                            @if ($registered)<span class="badge bg-leaf-50 text-leaf-600">{{ __('Inscrit') }}</span>@elseif ($o['event']->registration)<span class="badge bg-ochre-100 text-ochre-700">{{ __('Sur inscription') }}</span>@endif
                        </a></li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Rien au programme ces deux semaines.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card min-w-0 p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Annonces') }}</h2>
                <ul class="space-y-3">
                    @forelse ($announcements as $a)
                        <li><a href="{{ route('announcements.show', $a) }}" class="block rounded-xl hover:bg-sand-50"><span class="block font-semibold text-ink-800">{{ $a->title }}</span><span class="line-clamp-2 text-sm text-sand-700">{{ $a->body }}</span></a></li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucune annonce en cours.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card min-w-0 p-5 sm:p-6">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg">{{ __('Mes dons et contributions') }}</h2>
                    @if ($canWrite)<button type="button" wire:click="openGift" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="smartphone" class="size-4" /> {{ __('Déclarer un don') }}</button>@endif
                </div>
                @if ($yearTotals->isNotEmpty())<p class="mb-3 text-sm text-sand-700">{{ __('En :y : :t', ['y' => now()->year, 't' => $yearTotals->implode(' + ')]) }}</p>@endif
                @foreach ($declarations as $d)
                    <p @class(['mb-2 rounded-xl px-3 py-2 text-sm', 'bg-ochre-50 text-ochre-700' => $d->status === 'pending', 'bg-terra-50 text-terra-700' => $d->status === 'rejected'])>
                        {{ Money::format($d->amount, $d->currency) }} · {{ $d->operator }} · {{ $d->status === 'pending' ? __('en cours de vérification') : __('non retrouvé : :r', ['r' => $d->reject_reason]) }}
                    </p>
                @endforeach
                <ul class="divide-y divide-sand-100">
                    @forelse ($gifts as $t)
                        <li class="flex items-center gap-3 py-2.5">
                            <span class="w-16 shrink-0 text-sm text-sand-700">{{ $t->occurred_on->translatedFormat('j M y') }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm text-ink-800">{{ $t->category?->name ?? __('Contribution') }}</span>
                            <span class="font-semibold text-ink-800 tabular">{{ Money::format($t->amount, $t->currency) }}</span>
                            @if ($t->receipt_number)<a href="{{ route('finances.receipt', $t) }}" class="rounded-lg p-2 text-ink-600 hover:bg-sand-100" aria-label="{{ __('Reçu :n', ['n' => $t->receipt_number]) }}"><x-icon name="printer" class="size-4" /></a>@endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Aucune contribution enregistrée à votre nom.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card min-w-0 p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Mes promesses') }}</h2>
                <ul class="space-y-3">
                    @forelse ($pledges as $row)
                        @php $p = $row['pledge']; $pr = $row['progress']; @endphp
                        <li>
                            <div class="flex items-baseline justify-between gap-2"><span class="truncate text-sm font-semibold text-ink-800">{{ $p->campaign?->name ?? ($p->in_kind_description ?: __('Promesse')) }}</span><span class="text-sm text-sand-700 tabular">{{ Money::format((string) $pr['received'], $p->currency) }} / {{ Money::format((string) $pr['promised'], $p->currency) }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-sand-100"><div class="h-full rounded-full bg-leaf-500" style="width: {{ $pr['percent'] }}%"></div></div>
                            @if (! $pr['remaining']->isZero())<p class="mt-1 text-xs text-sand-700">{{ __('Reste :m', ['m' => Money::format((string) $pr['remaining'], $p->currency)]) }}@if ($pr['next_due']) · {{ __('prochaine échéance le :d', ['d' => $pr['next_due']->translatedFormat('j M')]) }}@endif</p>@else<p class="mt-1 text-xs font-semibold text-leaf-600">{{ __('Honorée. Merci !') }}</p>@endif
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucune promesse.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card min-w-0 p-5 sm:p-6 lg:col-span-2">
                <h2 class="mb-3 text-lg">{{ __('Mes demandes') }}</h2>
                <ul class="divide-y divide-sand-100">
                    @foreach ($requests as $r)
                        <li class="flex flex-wrap items-center gap-2 py-2.5"><x-icon name="file-text" class="size-4 text-ink-500" /><span class="min-w-0 flex-1 text-sm text-ink-800">{{ $r->type?->name }} <span class="text-sand-600">· {{ $r->created_at->translatedFormat('j M') }}</span></span>
                            <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $r->status === 'pending', 'bg-leaf-50 text-leaf-600' => $r->status === 'issued', 'bg-terra-50 text-terra-700' => $r->status === 'refused'])>{{ __(DocumentRequest::STATUSES[$r->status]) }}</span>
                            @if ($r->refusal_reason)<span class="basis-full pl-6 text-xs text-terra-700">{{ $r->refusal_reason }}</span>@endif
                        </li>
                    @endforeach
                    @foreach ($prayers as $p)
                        <li class="flex flex-wrap items-center gap-2 py-2.5"><x-icon name="heart-handshake" class="size-4 text-ink-500" /><span class="min-w-0 flex-1 text-sm text-ink-800">{{ $p->subject }} <span class="text-sand-600">· {{ $p->created_at->translatedFormat('j M') }}</span></span>
                            <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $p->status === 'open', 'bg-leaf-50 text-leaf-600' => $p->status !== 'open'])>{{ $p->status === 'open' ? __('Confiée') : __('Portée dans la prière') }}</span>
                            @if ($p->answer)<span class="basis-full pl-6 text-xs italic text-leaf-600">« {{ $p->answer }} »</span>@endif
                        </li>
                    @endforeach
                    @if ($requests->isEmpty() && $prayers->isEmpty())<li class="py-2 text-sm text-sand-700">{{ __('Aucune demande pour l’instant.') }}</li>@endif
                </ul>
            </section>
        </div>

        @if ($canWrite)
            <x-modal name="prayer" :title="__('Demander la prière')">
                <form wire:submit="askPrayer" class="space-y-4">
                    <p class="text-sm text-sand-700">{{ __('Votre demande est confiée à l’équipe pastorale seulement.') }}</p>
                    <div><label for="ms-subject" class="label">{{ __('Sujet') }}</label><input wire:model="prayer.subject" id="ms-subject" class="input" placeholder="{{ __('Exemple : la santé de mon père') }}">@error('prayer.subject') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="ms-body" class="label">{{ __('Ce que vous voulez confier') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><textarea wire:model="prayer.body" id="ms-body" rows="4" class="input"></textarea></div>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'prayer' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Envoyer') }}</button></div>
                </form>
            </x-modal>
            <x-modal name="gift" :title="__('Déclarer un don mobile money')">
                <form wire:submit="declareGift" class="space-y-4">
                    @if ($mobileAccounts->isNotEmpty())
                        <p class="text-sm text-sand-700">{{ __('Envoyez votre don sur :n, puis recopiez l’ID de la transaction reçu par SMS.', ['n' => $mobileAccounts->map(fn ($a) => trim($a->provider.' '.$a->account_number))->implode(__(' ou '))]) }}</p>
                    @endif
                    <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <div><label for="ms-g-amount" class="label">{{ __('Montant') }}</label><input wire:model="gift.amount" id="ms-g-amount" type="number" step="0.01" min="0" class="input tabular">@error('gift.amount') <p class="error">{{ $message }}</p> @enderror</div>
                        <div><label for="ms-g-cur" class="label">{{ __('Devise') }}</label><select wire:model="gift.currency" id="ms-g-cur" class="input"><option>USD</option><option>CDF</option></select></div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="ms-g-op" class="label">{{ __('Envoyé par') }}</label><select wire:model="gift.operator" id="ms-g-op" class="input">@foreach ($operators as $op)<option>{{ $op }}</option>@endforeach</select></div>
                        <div><label for="ms-g-date" class="label">{{ __('Le') }}</label><input wire:model="gift.paid_on" id="ms-g-date" type="date" class="input">@error('gift.paid_on') <p class="error">{{ $message }}</p> @enderror</div>
                    </div>
                    <div><label for="ms-g-ref" class="label">{{ __('ID de la transaction') }}</label><input wire:model="gift.reference" id="ms-g-ref" class="input font-mono uppercase">@error('gift.reference') <p class="error">{{ $message }}</p> @enderror</div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="ms-g-cat" class="label">{{ __('Pour') }}</label><select wire:model="gift.category_id" id="ms-g-cat" class="input"><option value="">{{ __('Offrande') }}</option>@foreach ($giftCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                        @if ($pledges->isNotEmpty())
                            <div><label for="ms-g-pl" class="label">{{ __('Ou pour ma promesse') }}</label><select wire:model="gift.pledge_id" id="ms-g-pl" class="input"><option value="">—</option>@foreach ($pledges as $row)<option value="{{ $row['pledge']->id }}">{{ $row['pledge']->campaign?->name ?? __('Promesse') }}</option>@endforeach</select></div>
                        @endif
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'gift' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Déclarer') }}</button></div>
                </form>
            </x-modal>
            <x-modal name="document" :title="__('Demander une attestation')">
                <form wire:submit="askDocument" class="space-y-4">
                    <div><label for="ms-type" class="label">{{ __('Document') }}</label><select wire:model="request.type" id="ms-type" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($requestable as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>@error('request.type') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="ms-msg" class="label">{{ __('Pour quoi faire ?') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="request.message" id="ms-msg" class="input" placeholder="{{ __('Exemple : inscription à l’université') }}"></div>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'document' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Envoyer la demande') }}</button></div>
                </form>
            </x-modal>
        @endif
    @endif
</div>
