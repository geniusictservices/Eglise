@php $text = $announcement->shareText(current_organization()); @endphp
<div class="flex flex-wrap gap-2" x-data="{ copied: false }">
    <a href="https://wa.me/?text={{ rawurlencode($text) }}" target="_blank" rel="noopener" class="btn !min-h-0 bg-[#25D366] !py-2 text-white hover:bg-[#1EBE5A]">
        <x-icon name="message-circle" class="size-4" /> {{ __('WhatsApp') }}
    </a>
    <button type="button" class="btn-ghost !min-h-0 !py-2" @click="navigator.clipboard.writeText(@js($text)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
        <x-icon name="check" class="size-4" x-show="copied" x-cloak /><x-icon name="file-text" class="size-4" x-show="! copied" />
        <span class="hidden sm:inline" x-text="copied ? @js(__('Copié')) : @js(__('Copier le texte'))"></span><span class="sr-only sm:hidden">{{ __('Copier le texte') }}</span>
    </button>
</div>
