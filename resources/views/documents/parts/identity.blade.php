{{-- Le nom de l'église et son identité juridique, tels qu'elle les a choisis. --}}
<div class="min-w-0">
    @isset($lines['parent'])<p class="wd-org-parent">{{ $lines['parent'] }}</p>@endisset
    <p class="wd-org-name">{{ $organization->name }}</p>
    @isset($lines['legal_name'])<p class="wd-org-legal" style="font-weight: 600">{{ $lines['legal_name'] }}</p>@endisset
    @if ($full ?? true)
        @foreach (array_intersect_key($lines, array_flip(['legal_form', 'legal_registration', 'ids'])) as $line)
            <p class="wd-org-legal">{{ $line }}</p>
        @endforeach
        @if ($identity->contactLines())<p class="wd-org-legal">{{ implode(' · ', $identity->contactLines()) }}</p>@endif
        @if ($identity->motto())<p class="wd-org-motto">{{ $identity->motto() }}</p>@endif
    @endif
</div>
