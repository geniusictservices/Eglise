@props(['title', 'eyebrow' => null, 'description' => null])
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endif
        <h1 class="page-title mt-1">{{ $title }}</h1>
        @if ($description)<p class="mt-1.5 max-w-2xl text-sand-700">{{ $description }}</p>@endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>
