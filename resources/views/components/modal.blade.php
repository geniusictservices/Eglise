@props(['name', 'title', 'maxWidth' => 'max-w-lg'])
{{-- Fenêtre : s'ouvre avec $dispatch('open-modal', { name: '...' }) ; plein écran en bas sur téléphone. --}}
<div x-data="{ show: false }"
     x-on:open-modal.window="if ($event.detail.name === '{{ $name }}') show = true"
     x-on:close-modal.window="if ($event.detail.name === '{{ $name }}') show = false"
     x-on:keydown.escape.window="show = false"
     x-cloak x-show="show" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="modal-{{ $name }}-title">
    <div x-show="show" x-transition.opacity class="absolute inset-0 bg-ink-900/50" @click="show = false"></div>
    <div x-show="show" x-trap.noscroll="show"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-8 opacity-0 sm:translate-y-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
         class="relative max-h-[92dvh] w-full {{ $maxWidth }} overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-7" style="padding-bottom: max(1.25rem, env(safe-area-inset-bottom))">
        <div class="mb-5 flex items-start justify-between gap-4">
            <h2 id="modal-{{ $name }}-title" class="text-xl font-semibold">{{ $title }}</h2>
            <button type="button" class="-mr-2 -mt-1 rounded-lg p-2 text-sand-500 hover:bg-sand-100" @click="show = false" aria-label="{{ __('Fermer') }}"><x-icon name="x" /></button>
        </div>
        {{ $slot }}
    </div>
</div>
