@props(['user', 'size' => 'size-9', 'tone' => 'bg-ochre-500 text-on-accent'])
{{-- La photo de profil de l'utilisateur, ou ses initiales. Le nom du fichier change à chaque nouvelle photo : le navigateur ne garde pas l'ancienne. --}}
@if ($user->photo_path)
    <img src="{{ route('users.photo', ['user' => $user, 'v' => substr(md5($user->photo_path), 0, 8)]) }}" alt="" {{ $attributes->merge(['class' => "$size shrink-0 rounded-full object-cover"]) }} loading="lazy">
@else
    <span {{ $attributes->merge(['class' => "grid $size shrink-0 place-items-center rounded-full font-semibold $tone"]) }}>{{ $user->initials() }}</span>
@endif
