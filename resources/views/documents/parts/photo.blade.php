{{-- La photo du membre, au format identité ; dans l'aperçu, un cadre vide s'il n'en a pas. --}}
@if ($photo)
    <img src="{{ $photo }}" alt="" class="wd-photo">
@elseif ($photoFrame)
    <span class="wd-photo-empty">{{ __('Photo du membre') }}</span>
@endif
