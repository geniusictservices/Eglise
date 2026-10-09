<x-layouts.site :title="__('La mémoire de votre communauté')" :description="__('Waumini : registre des fidèles, finances en dollars et en francs, budget et plan d’action, promesses, documents et communication, en un seul outil pensé pour les églises de la RDC.')">
    @php
        $modules = [
            ['icon' => 'users', 'tone' => 'bg-ink-700 text-white', 'title' => __('Membres et ménages'), 'text' => __('Chaque fidèle a sa fiche, son numéro, ses étapes de vie (baptême, mariage…). Le registre papier est repris une fois pour toutes.')],
            ['icon' => 'coins', 'tone' => 'bg-ochre-500 text-on-accent', 'title' => __('Finances'), 'text' => __('Caisses en dollars et en francs, collecte du culte, dîmes avec reçu, dépenses validées. Chaque franc a une caisse, une date et un auteur.')],
            ['icon' => 'hand-coins', 'tone' => 'bg-terra-500 text-white', 'title' => __('Promesses'), 'text' => __('Promis, versé, reste à verser, pour un projet, une collecte ou une contribution régulière. Relance en un clic sur WhatsApp.')],
            ['icon' => 'scroll-text', 'tone' => 'bg-leaf-500 text-white', 'title' => __('Plan d’action et budget'), 'text' => __('Les départements proposent leurs besoins, la finance arbitre, le budget est adopté puis suivi. Les objectifs avancent en pourcentage.')],
            ['icon' => 'wallet', 'tone' => 'bg-ink-700 text-white', 'title' => __('Paie'), 'text' => __('Chaque communauté définit qui elle paie et comment : gains, retenues, devise, bulletins si elle le souhaite.')],
            ['icon' => 'calendar-days', 'tone' => 'bg-ochre-500 text-on-accent', 'title' => __('Groupes et activités'), 'text' => __('Chorales, jeunesse, mamans, cellules : réunions, présences, calendrier des cultes, notifications aux membres.')],
            ['icon' => 'file-text', 'tone' => 'bg-terra-500 text-white', 'title' => __('Documents et registres'), 'text' => __('Attestations en une minute, imprimées et signées, authentifiées par QR code. Les anciens registres sont repris.')],
            ['icon' => 'heart-handshake', 'tone' => 'bg-leaf-500 text-white', 'title' => __('Suivi pastoral'), 'text' => __('Visites, malades, deuils, catéchumènes, demandes de prière. Les notes confidentielles restent au pasteur.')],
        ];
    @endphp

    {{-- Accroche --}}
    <section class="wax wax-veil wax-veil-strong overflow-hidden text-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 pb-16 pt-12 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:pb-20 lg:pt-16">
            <div class="space-y-6">
                <p class="text-sm font-semibold uppercase tracking-[0.14em] text-ochre-300">{{ __('La mémoire de votre communauté') }}</p>
                <h1 class="text-[2rem] font-semibold leading-[1.15] text-white sm:text-5xl sm:leading-[1.1]">{{ __('Le registre de vos fidèles, vos finances en toute transparence, votre année préparée ensemble.') }}</h1>
                <p class="max-w-xl text-lg text-ink-100">{{ __('Waumini réunit en un seul outil tout ce que votre église tient aujourd’hui dans des cahiers, des fichiers Excel et des groupes WhatsApp. Sur le téléphone comme sur l’ordinateur de l’église.') }}</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="btn-accent !px-6 !py-3 text-base">{{ __('Essayer gratuitement 30 jours') }}</a>
                    <a href="{{ route('demo.show') }}" class="btn border-[1.5px] border-white/50 !px-6 !py-3 text-base text-white hover:bg-white/10"><x-icon name="play" class="size-5" /> {{ __('Essayer la démo') }}</a>
                </div>
                <p class="text-sm text-ink-200">{{ __('Sans engagement · Vos données restent les vôtres · Fait à Goma par Genius ICT') }}</p>
            </div>
            <div class="relative mx-auto w-full max-w-lg">
                <img src="{{ asset('images/site/bureau-03-tableau-de-bord.png') }}" alt="{{ __('Tableau de bord de Waumini sur ordinateur') }}" class="w-full rounded-2xl border-4 border-white/20 shadow-2xl shadow-black/40" loading="eager">
                <img src="{{ asset('images/site/mobile-03-tableau-de-bord.png') }}" alt="{{ __('Tableau de bord de Waumini sur téléphone') }}" class="absolute -bottom-8 -left-4 w-[34%] rounded-[22px] border-[5px] border-ink-900 shadow-2xl shadow-black/40 sm:-left-8">
            </div>
        </div>
    </section>

    {{-- Constat --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <p class="eyebrow">{{ __('Le constat') }}</p>
        <h2 class="mt-2 max-w-3xl text-3xl">{{ __('Chaque responsable fait de son mieux, mais l’information est dispersée et fragile.') }}</h2>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                [__('Où est le registre de 2014 ?'), __('Quand un secrétaire ou un trésorier change, l’histoire de l’église part avec lui.')],
                [__('« Et l’argent, il est passé où ? »'), __('Les rapports financiers sont longs à produire et difficiles à vérifier. Le doute s’installe, même quand tout est en ordre.')],
                [__('Une attestation en trois jours'), __('Retrouver le bon registre, recopier à la main, faire signer : le membre attend, le secrétaire s’épuise.')],
                [__('Les annonces n’arrivent pas'), __('Personne n’a la liste complète des numéros, et la moitié des membres apprend l’assemblée générale le lendemain.')],
                [__('Le siège attend les rapports'), __('Chaque paroisse envoie son rapport papier, dans son format, souvent en retard.')],
                [__('Les outils étrangers ne conviennent pas'), __('Trop chers, en anglais, sans mobile money, sans franc congolais, inutilisables quand la connexion tombe.')],
            ] as [$t, $d])
                <div class="card p-5">
                    <h3 class="text-lg">{{ $t }}</h3>
                    <p class="mt-1.5 text-sand-700">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Trois promesses --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <p class="eyebrow">{{ __('Ce que Waumini change') }}</p>
            <h2 class="mt-2 text-3xl">{{ __('Trois promesses à votre communauté') }}</h2>
            <div class="mt-8 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['ink', __('Rien ne se perd'), __('Chaque membre, chaque baptême, chaque franc, chaque décision est enregistré, daté et retrouvable en quelques secondes. Même dans dix ans, même après trois changements de trésorier.')],
                    ['ochre', __('Tout est transparent'), __('Chaque entrée et chaque sortie est tracée et justifiée. Le rapport de l’assemblée se produit en un clic, et chacun peut le vérifier.')],
                    ['terra', __('Chacun est atteint'), __('Annonces, programmes et rappels arrivent sur le téléphone des membres, dans leur langue. Et votre église est visible en ligne.')],
                ] as $i => [$tone, $t, $d])
                    <div class="space-y-3">
                        <span @class(['grid size-12 place-items-center rounded-2xl text-xl font-semibold',
                            'bg-ink-700 text-white' => $tone === 'ink', 'bg-ochre-500 text-on-accent' => $tone === 'ochre', 'bg-terra-500 text-white' => $tone === 'terra'])>{{ $i + 1 }}</span>
                        <h3 class="text-xl">{{ $t }}</h3>
                        <p class="text-sand-700">{{ $d }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Fonctionnalités --}}
    <section id="fonctionnalites" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6">
        <p class="eyebrow">{{ __('Fonctionnalités') }}</p>
        <h2 class="mt-2 max-w-3xl text-3xl">{{ __('Tout ce que tient une communauté, dans un seul outil') }}</h2>
        <p class="mt-3 max-w-2xl text-sand-700">{{ __('Vous activez les modules dont vous avez besoin. Waumini se déploie module par module avec les communautés pilotes.') }}</p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($modules as $module)
                <div class="card space-y-3 p-5">
                    <span class="icon-tile {{ $module['tone'] }}"><x-icon :name="$module['icon']" class="size-5" /></span>
                    <h3 class="text-lg">{{ $module['title'] }}</h3>
                    <p class="text-sm text-sand-700">{{ $module['text'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Terrain --}}
    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
            <div>
                <p class="eyebrow">{{ __('Conçu pour le terrain') }}</p>
                <h2 class="mt-2 text-3xl">{{ __('Sur un simple téléphone Android, comme sur l’ordinateur de l’église') }}</h2>
                <ul class="mt-6 space-y-4">
                    @foreach ([
                        ['smartphone', __('Rien à télécharger depuis un magasin : Waumini s’installe en un toucher sur Android, iPhone et Windows.')],
                        ['coins', __('Le dollar et le franc congolais, avec le taux du jour saisi par votre trésorier. Les autres devises aussi.')],
                        ['fingerprint', __('Connexion par numéro de téléphone, puis par empreinte ou par visage.')],
                        ['languages', __('En français, et bientôt en swahili, lingala, kikongo et tshiluba, selon le choix de chacun.')],
                        ['wifi-off', __('Pensé pour les connexions lentes ; la collecte du dimanche pourra se saisir même sans réseau.')],
                    ] as [$icon, $text])
                        <li class="flex gap-4">
                            <span class="icon-tile bg-ochre-100 text-ochre-700"><x-icon :name="$icon" class="size-5" /></span>
                            <p class="pt-2">{{ $text }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <img src="{{ asset('images/site/mobile-13-utilisateurs.png') }}" alt="{{ __('Liste des utilisateurs sur téléphone') }}" class="rounded-[22px] border-[5px] border-ink-900 shadow-xl" loading="lazy">
                <img src="{{ asset('images/site/mobile-19-devises.png') }}" alt="{{ __('Taux du jour sur téléphone') }}" class="mt-10 rounded-[22px] border-[5px] border-ink-900 shadow-xl" loading="lazy">
            </div>
        </div>
    </section>

    {{-- Dénominations --}}
    <section id="denominations" class="mx-auto grid max-w-6xl scroll-mt-20 items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
        <img src="{{ asset('images/site/bureau-09-hierarchie.png') }}" alt="{{ __('Hiérarchie d’une dénomination dans Waumini') }}" class="order-last rounded-2xl border border-sand-200 shadow-xl lg:order-first" loading="lazy">
        <div>
            <p class="eyebrow">{{ __('Églises indépendantes et dénominations') }}</p>
            <h2 class="mt-2 text-3xl">{{ __('Le siège voit toutes ses paroisses, chaque paroisse garde la main') }}</h2>
            <ul class="mt-6 space-y-3 text-sand-700">
                <li class="flex gap-3"><x-icon name="check" class="mt-1 size-5 text-leaf-500" /> {{ __('Vos niveaux, vos mots : siège, région, secteur, paroisse, annexe… ou diocèse et doyenné.') }}</li>
                <li class="flex gap-3"><x-icon name="check" class="mt-1 size-5 text-leaf-500" /> {{ __('Chaque paroisse tient ses registres, son budget et ses caisses ; les niveaux supérieurs consolident sans rien ressaisir.') }}</li>
                <li class="flex gap-3"><x-icon name="check" class="mt-1 size-5 text-leaf-500" /> {{ __('Une paroisse inscrite seule peut rejoindre son siège plus tard : ses données la suivent.') }}</li>
                <li class="flex gap-3"><x-icon name="check" class="mt-1 size-5 text-leaf-500" /> {{ __('Le siège choisit : il paie pour toutes ses paroisses, ou chacune paie pour elle-même.') }}</li>
            </ul>
            <p class="mt-6 rounded-2xl bg-ochre-50 p-4 text-sm text-ink-800">{{ __('Église, mosquée ou autre communauté de foi : renommez les libellés (« Pasteur » en « Imam », « Culte » en « Prière »…) et Waumini parle votre langage.') }}</p>
        </div>
    </section>

    {{-- Sécurité --}}
    <section id="securite" class="scroll-mt-20 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <p class="eyebrow">{{ __('Sécurité et confidentialité') }}</p>
            <h2 class="mt-2 max-w-3xl text-3xl">{{ __('Chacun voit exactement ce qui le concerne, rien de plus') }}</h2>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['shield-check', __('Des rôles sur mesure'), __('Le trésorier voit les caisses, la secrétaire le registre, le responsable de chorale son groupe. Vous composez les rôles en cochant des cases.')],
                    ['history', __('Un journal inaltérable'), __('Qui a fait quoi, quand, avec les valeurs avant et après. Personne ne peut effacer une ligne, et l’intégrité se vérifie en un clic.')],
                    ['download', __('Vos données vous appartiennent'), __('Elles restent exportables à tout moment, même en cas de retard de paiement : l’accès passe simplement en lecture.')],
                    ['lock', __('Le support entre avec votre accord'), __('Un agent Genius ICT ne voit votre communauté que si vous l’autorisez, et vous pouvez retirer cet accord à tout instant.')],
                ] as [$icon, $t, $d])
                    <div class="space-y-3">
                        <span class="icon-tile bg-ink-700 text-white"><x-icon :name="$icon" class="size-5" /></span>
                        <h3 class="text-lg">{{ $t }}</h3>
                        <p class="text-sm text-sand-700">{{ $d }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Démarrer --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <p class="eyebrow">{{ __('Accompagnement') }}</p>
        <h2 class="mt-2 text-3xl">{{ __('Comment votre communauté démarre') }}</h2>
        <ol class="mt-8 grid gap-4 md:grid-cols-4">
            @foreach ([
                [__('Créez le compte'), __('En deux minutes, depuis votre téléphone. L’essai gratuit de 30 jours commence aussitôt.')],
                [__('Invitez les responsables'), __('Secrétaire, trésorier, responsables de départements : chacun reçoit son accès et son rôle.')],
                [__('Reprenez vos registres'), __('Vos fichiers Excel et vos cahiers, avec l’appui de Genius ICT pour le tri et les doublons.')],
                [__('Formez l’équipe'), __('Un manuel illustré dans l’application, et Genius ICT à Goma pour vous accompagner.')],
            ] as $i => [$t, $d])
                <li class="card p-5">
                    <span class="grid size-9 place-items-center rounded-full bg-ochre-500 font-semibold text-on-accent">{{ $i + 1 }}</span>
                    <h3 class="mt-3 text-lg">{{ $t }}</h3>
                    <p class="mt-1 text-sm text-sand-700">{{ $d }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Démonstration --}}
    <section id="demonstration" class="wax wax-veil wax-veil-strong scroll-mt-16 text-white">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.1fr]">
            <div class="space-y-4">
                <h2 class="text-3xl text-white">{{ __('Parlons de votre communauté') }}</h2>
                <p class="text-ink-100">{{ __('Nous venons vous présenter Waumini sur place, avec vos propres registres. Deux ou trois communautés pilotes sont accompagnées gratuitement pendant toute la phase de lancement : leurs besoins façonnent le produit.') }}</p>
                <a href="{{ route('register') }}" class="btn-accent !px-6 !py-3 text-base">{{ __('Ou créez votre compte maintenant') }}</a>
            </div>
            <livewire:site.demo-request />
        </div>
    </section>
</x-layouts.site>
