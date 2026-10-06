<section @class(['border-b border-sand-200', 'bg-ink-50' => $website->theme !== 'solennel', 'bg-ink-900 text-white' => $website->theme === 'solennel'])>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-14">
        <h1 @class(['text-3xl font-bold sm:text-4xl', 'text-ink-800' => $website->theme !== 'solennel', 'font-serif font-semibold text-white' => $website->theme === 'solennel'])>{{ $title }}</h1>
        @isset($intro)<p @class(['mt-2 max-w-2xl text-lg', 'text-sand-700' => $website->theme !== 'solennel', 'text-white/80' => $website->theme === 'solennel'])>{{ $intro }}</p>@endisset
    </div>
</section>
