@extends('website.layout', ['title' => __('Nouveau ? Faisons connaissance')])

@section('content')
    @include('website.partials.title', ['title' => __('Nouveau ? Faisons connaissance'), 'intro' => __('Vous êtes venu à un culte, vous venez d’arriver dans le quartier, ou vous cherchez une église ? Nous serions heureux de vous connaître.')])
    <div class="mx-auto grid max-w-5xl gap-8 px-4 pt-10 sm:px-6 lg:grid-cols-[2fr_3fr]">
        <section class="min-w-0 space-y-4 text-ink-900">
            <p class="text-lg">{{ __('Laissez-nous vos coordonnées : quelqu’un de l’équipe d’accueil vous appellera pour faire connaissance, répondre à vos questions et, si vous le souhaitez, vous rendre visite.') }}</p>
            @if ($website->hasPage('programme'))<a href="{{ route('website.page', [$organization->slug, 'programme']) }}" class="inline-flex items-center gap-1 font-semibold text-ink-700 hover:underline">{{ __('Voir les horaires des cultes') }} <x-icon name="arrow-right" class="size-4" /></a>@endif
        </section>
        <section class="min-w-0 rounded-3xl border border-sand-200 bg-white p-5 sm:p-7">
            @if (session('sent'))
                @include('website.partials.form-sent', ['title' => __('Merci, et bienvenue !'), 'text' => __('Nous vous appellerons dans les prochains jours. Au plaisir de vous voir au culte.')])
            @else
                <form method="POST" action="{{ route('website.welcome', $organization->slug) }}" class="space-y-4">
                    @csrf
                    <div class="hidden" aria-hidden="true"><label>{{ __('Site web') }} <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="b-name" class="label">{{ __('Votre nom') }}</label><input name="name" id="b-name" value="{{ old('name') }}" required maxlength="150" class="input" autocomplete="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label for="b-phone" class="label">{{ __('Votre téléphone') }}</label><input name="phone" id="b-phone" type="tel" value="{{ old('phone') }}" required maxlength="20" class="input" autocomplete="tel" placeholder="0990 000 000">@error('phone')<p class="error">{{ $message }}</p>@enderror</div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="b-q" class="label">{{ __('Votre quartier') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input name="neighbourhood" id="b-q" value="{{ old('neighbourhood') }}" maxlength="100" class="input"></div>
                        <div><label for="b-h" class="label">{{ __('Comment nous avez-vous connus ?') }}</label>
                            <select name="heard_from" id="b-h" class="input">
                                <option value="">{{ __('Choisir') }}</option>
                                @foreach ([__('Un ami ou un membre de la famille'), __('Je suis venu à un culte'), __('Les réseaux sociaux'), __('Une évangélisation'), __('Autrement')] as $h)<option @selected(old('heard_from') === $h)>{{ $h }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div><label for="b-msg" class="label">{{ __('Un mot pour nous') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><textarea name="message" id="b-msg" rows="3" maxlength="1000" class="input">{{ old('message') }}</textarea></div>
                    <label class="flex items-start gap-3 text-sm text-ink-800"><input type="checkbox" name="wants_visit" value="1" @checked(old('wants_visit')) class="mt-0.5 size-4"> {{ __('J’aimerais recevoir une visite ou un appel de l’équipe pastorale.') }}</label>
                    <button class="btn-primary w-full sm:w-auto"><x-icon name="send" class="size-4" /> {{ __('Envoyer') }}</button>
                </form>
            @endif
        </section>
    </div>
@endsection
