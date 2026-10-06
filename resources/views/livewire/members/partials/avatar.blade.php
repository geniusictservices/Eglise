@if ($member->photo_path)
    <img src="{{ route('members.photo', $member) }}" alt="" class="{{ $size }} shrink-0 rounded-full object-cover" loading="lazy">
@else
    <span @class(["grid $size shrink-0 place-items-center rounded-full font-display font-semibold", 'bg-terra-50 text-terra-600' => $member->gender === 'F', 'bg-ink-50 text-ink-700' => $member->gender !== 'F'])>{{ $member->initials() }}</span>
@endif
