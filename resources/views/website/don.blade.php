@extends('website.layout', ['title' => __('Faire un don')])

@section('content')
    @include('website.partials.title', ['title' => __('Faire un don'), 'intro' => __('Soutenez l’œuvre par mobile money, puis déclarez votre don : la trésorerie le vérifie et l’enregistre.')])
    <div class="mx-auto grid max-w-6xl gap-8 px-4 pt-10 sm:px-6 lg:grid-cols-[2fr_3fr]">
        <section class="min-w-0 space-y-4">
            @if ($website->giving_text)<x-website.text :text="$website->giving_text" class="text-ink-900" />@endif
            <h2 class="text-lg font-semibold text-ink-800">{{ __('1. Envoyez votre don') }}</h2>
            @forelse ($accounts as $account)
                <div class="rounded-2xl border border-sand-200 bg-white p-4">
                    <p class="text-sm font-semibold text-ochre-600">{{ $account->provider ?: $account->name }}</p>
                    @if ($account->account_number)<p class="mt-1 font-mono text-xl font-semibold tracking-wide text-ink-800">{{ $account->account_number }}</p>@endif
                    @if ($account->holder)<p class="text-sm text-sand-700">{{ __('Au nom de :h', ['h' => $account->holder]) }}</p>@endif
                </div>
            @empty
                <p class="text-sand-700">{{ __('Les numéros seront bientôt publiés. Renseignez-vous auprès de la trésorerie.') }}</p>
            @endforelse
        </section>

        <section class="min-w-0 rounded-3xl border border-sand-200 bg-white p-5 sm:p-7">
            <h2 class="mb-1 text-lg font-semibold text-ink-800">{{ __('2. Déclarez votre don') }}</h2>
            @if (session('given'))
                <div class="mt-3 rounded-2xl bg-leaf-50 p-4 text-leaf-700" role="status">
                    <p class="font-semibold">{{ __('Merci ! Votre don est déclaré.') }}</p>
                    <p class="mt-1 text-sm">{{ __('La trésorerie va le vérifier avec l’ID de la transaction. Que Dieu vous bénisse.') }}</p>
                </div>
            @else
                <p class="mb-5 text-sm text-sand-700">{{ __('Recopiez l’ID de la transaction reçu par SMS : c’est lui qui permet de retrouver votre envoi.') }}</p>
                <form method="POST" action="{{ route('website.give', $organization->slug) }}" class="space-y-4">
                    @csrf
                    <div class="hidden" aria-hidden="true"><label>{{ __('Site web') }} <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="g-name" class="label">{{ __('Votre nom') }}</label><input name="name" id="g-name" value="{{ old('name') }}" required class="input" autocomplete="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label for="g-phone" class="label">{{ __('Votre téléphone') }}</label><input name="phone" id="g-phone" type="tel" value="{{ old('phone') }}" required class="input" autocomplete="tel" placeholder="0990 000 000">@error('phone')<p class="error">{{ $message }}</p>@enderror</div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                        <div><label for="g-amount" class="label">{{ __('Montant') }}</label><input name="amount" id="g-amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required class="input tabular">@error('amount')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label for="g-cur" class="label">{{ __('Devise') }}</label><select name="currency" id="g-cur" class="input">@foreach ($currencies as $c)<option @selected(old('currency') === $c)>{{ $c }}</option>@endforeach</select></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="g-op" class="label">{{ __('Envoyé par') }}</label><select name="operator" id="g-op" class="input">@foreach ($operators as $op)<option @selected(old('operator') === $op)>{{ $op }}</option>@endforeach</select></div>
                        <div><label for="g-date" class="label">{{ __('Le') }}</label><input name="paid_on" id="g-date" type="date" value="{{ old('paid_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required class="input">@error('paid_on')<p class="error">{{ $message }}</p>@enderror</div>
                    </div>
                    <div><label for="g-ref" class="label">{{ __('ID de la transaction') }}</label><input name="reference" id="g-ref" value="{{ old('reference') }}" required class="input font-mono uppercase" placeholder="MP240612.1532.A12345">@error('reference')<p class="error">{{ $message }}</p>@enderror</div>
                    @if ($categories->isNotEmpty())
                        <div><label for="g-cat" class="label">{{ __('Pour') }}</label><select name="category_id" id="g-cat" class="input"><option value="">{{ __('Offrande') }}</option>@foreach ($categories as $cat)<option value="{{ $cat->id }}" @selected((int) old('category_id') === $cat->id)>{{ $cat->name }}</option>@endforeach</select></div>
                    @endif
                    <div><label for="g-msg" class="label">{{ __('Message') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input name="message" id="g-msg" value="{{ old('message') }}" maxlength="255" class="input"></div>
                    <button class="btn-primary w-full sm:w-auto"><x-icon name="send" class="size-4" /> {{ __('Déclarer mon don') }}</button>
                </form>
            @endif
        </section>
    </div>
@endsection
