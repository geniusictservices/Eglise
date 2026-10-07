@extends('website.layout', ['title' => __('Demande de prière')])

@section('content')
    @include('website.partials.title', ['title' => __('Demande de prière'), 'intro' => __('« Ne vous inquiétez de rien ; mais en toute chose faites connaître vos besoins à Dieu par des prières. » Philippiens 4.6')])
    <div class="mx-auto grid max-w-5xl gap-8 px-4 pt-10 sm:px-6 lg:grid-cols-[2fr_3fr]">
        <section class="min-w-0 space-y-4 text-ink-900">
            <p class="text-lg">{{ __('Confiez-nous votre sujet de prière : l’équipe pastorale priera pour vous.') }}</p>
            <ul class="space-y-3">
                <li class="flex gap-3"><x-icon name="lock" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> {{ __('Votre demande reste confidentielle : seule l’équipe pastorale la lit. Elle n’est jamais publiée.') }}</li>
                <li class="flex gap-3"><x-icon name="phone" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> {{ __('Laissez votre numéro si vous souhaitez être rappelé.') }}</li>
            </ul>
        </section>
        <section class="min-w-0 rounded-3xl border border-sand-200 bg-white p-5 sm:p-7">
            @if (session('sent'))
                @include('website.partials.form-sent', ['title' => __('Votre demande est confiée.'), 'text' => __('Nous prions pour vous. Que le Seigneur vous bénisse et vous garde.')])
            @else
                <form method="POST" action="{{ route('website.pray', $organization->slug) }}" class="space-y-4">
                    @csrf
                    <div class="hidden" aria-hidden="true"><label>{{ __('Site web') }} <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="p-name" class="label">{{ __('Votre nom') }}</label><input name="name" id="p-name" value="{{ old('name') }}" required maxlength="150" class="input" autocomplete="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label for="p-phone" class="label">{{ __('Téléphone') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input name="phone" id="p-phone" type="tel" value="{{ old('phone') }}" maxlength="20" class="input" autocomplete="tel" placeholder="0990 000 000"></div>
                    </div>
                    <div><label for="p-subject" class="label">{{ __('Votre sujet de prière') }}</label><input name="subject" id="p-subject" value="{{ old('subject') }}" required maxlength="160" class="input" placeholder="{{ __('La santé de ma mère, un travail, un examen…') }}">@error('subject')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="p-msg" class="label">{{ __('Ce que vous voulez nous confier') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><textarea name="message" id="p-msg" rows="5" maxlength="2000" class="input">{{ old('message') }}</textarea></div>
                    <button class="btn-primary w-full sm:w-auto"><x-icon name="heart-handshake" class="size-4" /> {{ __('Confier ma demande') }}</button>
                </form>
            @endif
        </section>
    </div>
@endsection
