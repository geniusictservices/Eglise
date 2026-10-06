@php use App\Models\Meeting; $by = $m->participants->groupBy('attendance'); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Procès-verbal : :t', ['t' => $m->title])])
    <style>
        @page { size: A4; margin: 14mm; }
        @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; margin: 0 !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
            <a href="{{ route('meetings.show', $m) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Réunion') }}</a>
            <span class="flex-1"></span>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</button>
        </div>
    </div>
    <main class="sheet mx-auto my-8 max-w-3xl rounded-2xl bg-white p-8 text-sm shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />
        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ __('Procès-verbal') }}</h1>
        <p class="text-center font-semibold text-ink-800">{{ $m->title }}</p>
        <p class="mb-6 text-center text-sand-700">{{ __(Meeting::KINDS[$m->kind]) }}@if ($m->department) · {{ $m->department->name }}@endif · {{ $m->held_at->translatedFormat('l j F Y à H:i') }}@if ($m->place) · {{ $m->place }}@endif</p>

        @if ($m->chair || $m->secretary)
            <p class="mb-3">@if ($m->chair){{ __('Présidée par :n.', ['n' => $m->chair]) }}@endif @if ($m->secretary){{ __('Secrétaire de séance : :n.', ['n' => $m->secretary]) }}@endif</p>
        @endif
        @foreach (Meeting::ATTENDANCE as $k => $label)
            @if ($by->has($k))<p class="mb-1"><span class="font-semibold">{{ __($label) }}s ({{ $by[$k]->count() }}) :</span> {{ $by[$k]->map->displayName()->implode(', ') }}.</p>@endif
        @endforeach

        @if ($m->agenda)
            <h2 class="mb-1 mt-5 text-base font-semibold text-ink-800">{{ __('Ordre du jour') }}</h2>
            <p class="whitespace-pre-line">{{ $m->agenda }}</p>
        @endif
        <h2 class="mb-1 mt-5 text-base font-semibold text-ink-800">{{ __('Déroulement') }}</h2>
        <p class="whitespace-pre-line">{{ $m->minutes ?: '…' }}</p>

        @if ($m->decisions->isNotEmpty())
            <h2 class="mb-2 mt-5 text-base font-semibold text-ink-800">{{ __('Décisions') }}</h2>
            <ol class="list-decimal space-y-1 pl-5">
                @foreach ($m->decisions as $d)
                    <li>{{ $d->text }}@if ($d->responsible || $d->due_on) <span class="text-sand-700">({{ collect([$d->responsible, $d->due_on?->translatedFormat('j F Y')])->filter()->implode(', ') }})</span>@endif</li>
                @endforeach
            </ol>
        @endif

        <div class="mt-12 grid grid-cols-2 gap-10 break-inside-avoid">
            <div><p class="font-semibold text-ink-800">{{ __('Le président') }}</p><p class="text-sand-700">{{ $m->chair }}</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
            <div><p class="font-semibold text-ink-800">{{ __('Le secrétaire') }}</p><p class="text-sand-700">{{ $m->secretary }}</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
        </div>
    </main>
</body>
</html>
