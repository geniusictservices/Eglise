{{-- Messages de confirmation : session('status') ou $this->dispatch('notify', message: '...') --}}
<div x-data="{
        items: [],
        push(message, type = 'success') {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            setTimeout(() => this.items = this.items.filter(i => i.id !== id), 4500);
        }
    }"
    x-init="@if (session('status')) push(@js(session('status'))) @endif"
    @notify.window="push($event.detail.message ?? $event.detail[0]?.message, $event.detail.type ?? 'success')"
    class="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2 px-4 lg:bottom-6 lg:items-end lg:px-8"
    aria-live="polite">
    <template x-for="item in items" :key="item.id">
        <div x-transition.opacity class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl px-4 py-3 text-sm font-bold shadow-lg shadow-ink-900/15"
             :class="item.type === 'error' ? 'bg-terra-600 text-white' : 'bg-ink-700 text-white'">
            <svg class="mt-0.5 size-4 shrink-0 text-ochre-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span x-text="item.message"></span>
        </div>
    </template>
</div>
