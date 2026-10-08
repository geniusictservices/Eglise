{{--
    Livret de présentation de Waumini pour les responsables d'églises : 20 pages A5,
    à partager en PDF ou à imprimer en livret plié (php artisan waumini:livret, puis
    node scripts/livret/pdf.mjs). Les captures viennent de la communauté de démonstration.
--}}
@php
    $phone = fn (string $id, string $alt, ?string $width = null) => '<figure class="phone"'.($width ? ' style="width: '.$width.'"' : '').'><img src="captures/mobile/'.$id.'.jpg" alt="'.e($alt).'"></figure>';
    $laptop = fn (string $id, string $alt) => '<figure class="laptop"><div class="screen"><img src="captures/bureau/'.$id.'.jpg" alt="'.e($alt).'"></div></figure>';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Waumini · Livret de présentation</title>
<style>
@font-face { font-family: Lexend; font-weight: 400; src: url(polices/lexend-400.woff2) format('woff2'); }
@font-face { font-family: Lexend; font-weight: 500; src: url(polices/lexend-500.woff2) format('woff2'); }
@font-face { font-family: Lexend; font-weight: 600; src: url(polices/lexend-600.woff2) format('woff2'); }
@font-face { font-family: Lexend; font-weight: 700; src: url(polices/lexend-700.woff2) format('woff2'); }
@font-face { font-family: Lexend; font-weight: 400; src: url(polices/lexend-ext-400.woff2) format('woff2'); unicode-range: U+0100-024F, U+1E00-1EFF; }

@page { size: 148mm 210mm; margin: 0; }
:root {
    --ink: #2C2F6B; --ink-900: #181A42; --ink-50: #EEEFF8; --ink-100: #DADCF0; --ink-300: #8D91C9;
    --ochre: #E39B2C; --ochre-50: #FEF6E9; --ochre-100: #FCE7C4; --ochre-700: #8F5A0C;
    --terra: #C2522D; --leaf: #2E7A5A; --leaf-50: #E7F3EC;
    --sand-50: #FFFAF3; --sand-100: #FBF2E5; --sand-200: #F0E6D6; --sand-500: #8F8577; --sand-700: #5E574E;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #d9d4cc; font-family: Lexend, 'Segoe UI', sans-serif; color: var(--ink-900); font-size: 8.6pt; line-height: 1.5;
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { position: relative; width: 148mm; height: 210mm; overflow: hidden; background: var(--sand-50); padding: 13mm 12mm 14mm;
    margin: 8mm auto; display: flex; flex-direction: column; break-after: page; }
@media print { body { background: none; } .page { margin: 0; } }
section.page:last-of-type { break-after: auto; }

h1, h2, h3 { font-weight: 600; color: var(--ink); margin: 0; letter-spacing: -0.01em; }
h2 { font-size: 17pt; line-height: 1.15; margin-bottom: 3mm; }
h3 { font-size: 9.5pt; margin-bottom: 1mm; }
p { margin: 0 0 2.2mm; }
.kicker { font-size: 7pt; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: var(--ochre-700); margin-bottom: 1.5mm;
    display: flex; align-items: center; gap: 2mm; }
.kicker::before { content: ''; width: 6mm; height: 1.2mm; border-radius: 1mm; background: var(--ochre); }
.lead { font-size: 9.6pt; color: var(--sand-700); line-height: 1.45; }
strong { font-weight: 600; color: var(--ink); }
ul.points { list-style: none; padding: 0; margin: 0 0 2mm; }
ul.points li { position: relative; padding-left: 4.5mm; margin-bottom: 1.4mm; }
ul.points li::before { content: ''; position: absolute; left: 0.6mm; top: 1.55mm; width: 1.8mm; height: 1.8mm; border-radius: 50%; background: var(--ochre); }
.icon { width: 4.6mm; height: 4.6mm; flex-shrink: 0; }

.folio { position: absolute; left: 12mm; right: 12mm; bottom: 6.5mm; display: flex; justify-content: space-between; align-items: center;
    font-size: 6.5pt; color: var(--sand-500); border-top: 0.25mm solid var(--sand-200); padding-top: 1.6mm; }
.folio .n { font-weight: 600; color: var(--ink); }

/* Le motif wax de Waumini. */
.wax { background-color: var(--ink);
    background-image:
        radial-gradient(circle at 12px 12px, var(--ochre) 0 5px, transparent 5.5px),
        radial-gradient(circle at 36px 36px, var(--terra) 0 5px, transparent 5.5px),
        radial-gradient(circle at 36px 12px, transparent 0 7px, rgb(255 255 255 / 0.18) 7px 8.5px, transparent 9px),
        radial-gradient(circle at 12px 36px, transparent 0 7px, rgb(255 255 255 / 0.18) 7px 8.5px, transparent 9px);
    background-size: 48px 48px; }
.veil { position: absolute; inset: 0; background: linear-gradient(180deg, rgb(44 47 107 / 0.72), rgb(44 47 107 / 0.95) 55%, rgb(24 26 66 / 0.98)); }
.band { height: 3mm; margin: 0 -12mm; }

/* Les cadres des captures. */
figure { margin: 0; }
.phone { width: 41mm; flex-shrink: 0; border-radius: 5.5mm; padding: 1.4mm; background: var(--ink-900); box-shadow: 0 2mm 5mm rgb(24 26 66 / 0.25); }
.phone img { display: block; width: 100%; aspect-ratio: 1 / 2; object-fit: cover; object-position: top; border-radius: 4.2mm; }
.phone.short img { aspect-ratio: 1 / 1.62; }
.laptop { width: 100%; }
.laptop .screen { border-radius: 2.5mm 2.5mm 0 0; padding: 1.4mm 1.4mm 1.2mm; background: var(--ink-900); }
.laptop .screen img { display: block; width: 100%; border-radius: 0.8mm; }
.laptop::after { content: ''; display: block; height: 2.2mm; margin: 0 -4mm; border-radius: 0 0 3mm 3mm; background: linear-gradient(#c9c3b9, #a8a196); }
.paper { background: #fff; border-radius: 1.5mm; box-shadow: 0 1.5mm 5mm rgb(24 26 66 / 0.18); overflow: hidden; }
.paper img { display: block; width: 100%; }
.paper.fade { -webkit-mask-image: linear-gradient(180deg, #000 78%, transparent); mask-image: linear-gradient(180deg, #000 78%, transparent); }
.caption { font-size: 6.6pt; color: var(--sand-500); text-align: center; margin-top: 1.6mm; }

.row { display: flex; gap: 5mm; align-items: flex-start; }
.row > .text { flex: 1; min-width: 0; }
/* Les images ne doivent jamais dépasser du bas de la page : Chrome les renverrait à la page suivante. */
.shots .phone { width: var(--w, 46mm); }
.shots { display: flex; gap: 7mm; justify-content: center; align-items: flex-start; margin-top: auto; padding-bottom: 4mm; }
.grow { flex: 1; }
.card { background: #fff; border: 0.3mm solid var(--sand-200); border-radius: 2.5mm; padding: 3mm; }
.note { background: var(--ochre-50); border-left: 1.2mm solid var(--ochre); border-radius: 0 2mm 2mm 0; padding: 2.4mm 3mm; font-size: 8pt; }
.note.leaf { background: var(--leaf-50); border-color: var(--leaf); }
.steps { counter-reset: step; list-style: none; padding: 0; margin: 0; }
.steps li { counter-increment: step; position: relative; padding-left: 7mm; margin-bottom: 2mm; }
.steps li::before { content: counter(step); position: absolute; left: 0; top: -0.2mm; width: 4.8mm; height: 4.8mm; border-radius: 50%;
    background: var(--ink); color: #fff; font-size: 7pt; font-weight: 600; display: grid; place-items: center; }

/* Couverture. */
.cover { padding: 0; color: #fff; }
.cover .inner { position: relative; height: 100%; padding: 16mm 13mm 12mm; display: flex; flex-direction: column; }
.cover .logo svg { height: 13mm; width: auto; }
.cover h1 { color: #fff; font-size: 27pt; line-height: 1.08; margin-top: 15mm; }
.cover h1 em { font-style: normal; color: var(--ochre); }
.cover .sub { font-size: 10.5pt; line-height: 1.45; color: rgb(255 255 255 / 0.85); margin-top: 5mm; max-width: 100mm; }
.cover .phones { position: absolute; right: 8mm; bottom: 0; display: flex; gap: 4mm; align-items: flex-end; }
.cover .phones .phone { width: 36mm; padding-bottom: 0; border-radius: 5.5mm 5.5mm 0 0; background: #0d0e26; box-shadow: 0 0 8mm rgb(0 0 0 / 0.4); }
.cover .phones .phone img { aspect-ratio: 1 / 1.45; border-radius: 4.2mm 4.2mm 0 0; }
.cover .phones .phone:first-child img { aspect-ratio: 1 / 1.1; }
.cover .by { max-width: 50mm; margin-top: auto; font-size: 7.5pt; color: rgb(255 255 255 / 0.75); position: relative; z-index: 1; }
.cover .by strong { color: #fff; display: block; font-size: 9pt; }

/* Grilles. */
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 3.4mm; }
.mod { background: #fff; border: 0.3mm solid var(--sand-200); border-radius: 2.5mm; padding: 2.6mm 2.8mm; }
.mod .head { display: flex; align-items: center; gap: 2mm; margin-bottom: 1mm; }
.mod .ico { width: 7mm; height: 7mm; border-radius: 2mm; display: grid; place-items: center; background: var(--ochre-100); color: var(--ochre-700); flex-shrink: 0; }
.mod .ico.ink { background: var(--ink-50); color: var(--ink); }
.mod .ico.leaf { background: var(--leaf-50); color: var(--leaf); }
.mod h3 { margin: 0; font-size: 8.6pt; line-height: 1.2; }
.mod p { margin: 0; font-size: 7.4pt; color: var(--sand-700); line-height: 1.4; }

.facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2mm; margin-top: auto; }
.fact { background: var(--ink); color: #fff; border-radius: 2.5mm; padding: 2.6mm 2mm; text-align: center; font-size: 6.8pt; line-height: 1.3; }
.fact .icon { color: var(--ochre); margin: 0 auto 1mm; display: block; }

table.roles { width: 100%; border-collapse: separate; border-spacing: 0 1.6mm; font-size: 7.8pt; }
table.roles td { background: #fff; padding: 2.2mm 2.6mm; vertical-align: top; border-top: 0.3mm solid var(--sand-200); border-bottom: 0.3mm solid var(--sand-200); }
table.roles td:first-child { border-left: 0.3mm solid var(--sand-200); border-radius: 2.5mm 0 0 2.5mm; width: 33mm; font-weight: 600; color: var(--ink); }
table.roles td:last-child { border-right: 0.3mm solid var(--sand-200); border-radius: 0 2.5mm 2.5mm 0; color: var(--sand-700); }
table.roles td .icon { vertical-align: -1.2mm; margin-right: 1.4mm; color: var(--ochre-700); width: 4mm; height: 4mm; }

.pain { display: flex; gap: 3mm; align-items: flex-start; padding: 3.4mm 0; border-bottom: 0.3mm dashed var(--sand-200); }
.pain:last-child { border-bottom: 0; }
.pain .ico { width: 8mm; height: 8mm; border-radius: 50%; display: grid; place-items: center; background: #FCEEE8; color: var(--terra); flex-shrink: 0; }
.pain p { margin: 0; }
.pain strong { display: block; }

.flow { display: flex; align-items: center; gap: 1mm; margin: 2mm 0 3mm; }
.flow span { flex: 1; text-align: center; font-size: 6.6pt; font-weight: 600; color: var(--ink); background: var(--ink-50); border-radius: 1.5mm; padding: 1.6mm 0.5mm; }
.flow span.on { background: var(--ochre); color: #2A1B04; }
.flow i { color: var(--sand-500); font-style: normal; font-size: 7pt; }

.packs { display: grid; grid-template-columns: 1fr 1fr; gap: 2.2mm; }
.pack { border-radius: 2.5mm; padding: 2.4mm 2.8mm; background: #fff; border: 0.3mm solid var(--sand-200); }
.pack.featured { border: 0.5mm solid var(--ochre); }
.pack h3 { margin: 0; font-size: 10pt; }
.pack .mean { font-size: 6.6pt; color: var(--ochre-700); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
.pack p { margin: 1mm 0 0; font-size: 7.2pt; color: var(--sand-700); line-height: 1.35; }

.back { padding: 0; color: #fff; }
.back .inner { position: relative; height: 100%; padding: 16mm 13mm 12mm; display: flex; flex-direction: column; }
.back h2 { color: #fff; font-size: 19pt; }
.back .qr { background: #fff; border-radius: 3mm; padding: 2.5mm; width: 36mm; }
.back .qr svg { display: block; width: 100%; height: auto; }
.back .contact { display: grid; gap: 2.4mm; font-size: 9pt; }
.back .contact div { display: flex; align-items: center; gap: 2.6mm; }
.back .contact .icon { color: var(--ochre); }
.back .logo svg { height: 9mm; width: auto; }

.toc { list-style: none; padding: 0; margin: 0; columns: 2; column-gap: 6mm; font-size: 7.8pt; }
.toc li { display: flex; gap: 2mm; padding: 1.1mm 0; border-bottom: 0.25mm dotted var(--sand-200); break-inside: avoid; }
.toc li b { color: var(--ochre-700); font-weight: 600; width: 5mm; }
</style>
</head>
<body>

{{-- 1. Couverture --}}
<section class="page cover wax">
    <div class="veil"></div>
    <div class="phones">
        {!! $phone('99-espace-membre', 'L’espace d’un membre') !!}
        {!! $phone('03-tableau-de-bord', 'Le tableau de bord') !!}
    </div>
    <div class="inner">
        <div class="logo">{!! $logoWhite !!}</div>
        <h1>Votre église,<br>bien tenue,<br><em>au creux de la main.</em></h1>
        <p class="sub">Waumini est le logiciel de gestion pensé pour les églises et les communautés de foi de la RDC&nbsp;: membres, finances, budget, documents et vie de la communauté, sur téléphone et ordinateur.</p>
        <p class="by"><strong>Livret de présentation</strong>pour les pasteurs et les responsables<br>Conçu à Goma par Genius ICT</p>
    </div>
</section>

{{-- 2. Mot aux responsables --}}
<section class="page">
    <div class="kicker">À nos pères et mères dans la foi</div>
    <h2>Chers pasteurs, chers responsables,</h2>
    <p class="lead">Vous portez une communauté : des fidèles à connaître, des offrandes à garder fidèlement, des ouvriers à payer, des comptes à rendre à l’assemblée et au siège.</p>
    <p>Trop souvent, tout cela repose sur des cahiers qui s’abîment, des fichiers Excel éparpillés et la mémoire de quelques personnes dévouées. Quand le trésorier change ou qu’un registre se perd, c’est l’histoire de l’église qui s’efface.</p>
    <p><strong>Waumini</strong> («&nbsp;les fidèles&nbsp;» en swahili) est né de ce constat, ici, à Goma. Ce n’est pas un projet sur papier&nbsp;: <strong>l’outil existe, il fonctionne</strong>, et ce livret vous le montre avec de vraies images de l’application. Les noms et les chiffres que vous y verrez sont ceux d’une communauté fictive de démonstration, la «&nbsp;Communauté Évangélique de la Paix&nbsp;».</p>
    <p>Prenez le temps de le parcourir, puis essayez-le vous-même&nbsp;: la démonstration est ouverte à tous, gratuitement.</p>
    <p style="margin-top: 3mm"><strong>L’équipe Genius ICT</strong><br><span style="color: var(--sand-500)">Goma, Nord-Kivu</span></p>

    <div class="card" style="margin-top: auto; margin-bottom: 3mm">
        <h3 style="margin-bottom: 2mm">Dans ce livret</h3>
        <ol class="toc">
            <li><b>3</b>Ce que vivent nos églises</li>
            <li><b>4</b>Waumini en bref</li>
            <li><b>5</b>Chacun son rôle</li>
            <li><b>6</b>Le tableau de bord</li>
            <li><b>7</b>Le registre des membres</li>
            <li><b>8</b>Les finances</li>
            <li><b>9</b>Le culte du dimanche</li>
            <li><b>10</b>Mobile money et promesses</li>
            <li><b>11</b>Les dépenses</li>
            <li><b>12</b>Budget et plan d’action</li>
            <li><b>13</b>Rapports et paie</li>
            <li><b>14</b>La vie de la communauté</li>
            <li><b>15</b>Les documents officiels</li>
            <li><b>16</b>Le suivi pastoral</li>
            <li><b>17</b>Dénominations et site web</li>
            <li><b>18</b>Confiance et sécurité</li>
            <li><b>19</b>Comment commencer</li>
        </ol>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 3. Le constat --}}
<section class="page">
    <div class="kicker">Le constat</div>
    <h2>Ce que vivent beaucoup de nos églises</h2>
    <p class="lead">Des serviteurs fidèles font de leur mieux avec les moyens du bord. Mais les outils de papier ont leurs limites.</p>
    <div style="margin-top: 1mm">
        <div class="pain"><span class="ico"><x-icon name="book-open" class="icon" /></span><p><strong>Le registre est dans des cahiers</strong>Retrouver un membre, sa famille, sa date de baptême prend des heures. Un cahier mouillé, et tout est perdu.</p></div>
        <div class="pain"><span class="ico"><x-icon name="banknote" class="icon" /></span><p><strong>Les offrandes sont comptées sur des feuilles volantes</strong>En dollars et en francs, avec le taux qui change. Les écarts se découvrent trop tard.</p></div>
        <div class="pain"><span class="ico"><x-icon name="wallet" class="icon" /></span><p><strong>Les dépenses laissent peu de traces</strong>Qui a demandé, qui a autorisé, où est le justificatif&nbsp;? La confiance s’use.</p></div>
        <div class="pain"><span class="ico"><x-icon name="file-text" class="icon" /></span><p><strong>Les attestations se refont à la main</strong>Baptême, mariage, recommandation&nbsp;: chaque document se tape à nouveau, et rien ne prouve qu’il est authentique.</p></div>
        <div class="pain"><span class="ico"><x-icon name="megaphone" class="icon" /></span><p><strong>Les annonces se perdent</strong>Entre les groupes WhatsApp et le tableau d’affichage, beaucoup de fidèles ne sont pas au courant.</p></div>
        <div class="pain"><span class="ico"><x-icon name="network" class="icon" /></span><p><strong>Le siège attend les rapports</strong>Chaque mois, les chiffres des paroisses sont recopiés et additionnés à la main.</p></div>
    </div>
    <div class="note" style="margin-top: auto; margin-bottom: 3mm"><strong>Waumini réunit tout cela au même endroit</strong>, accessible depuis le téléphone de chaque responsable, avec une trace claire de qui a fait quoi.</div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 4. Waumini en bref --}}
<section class="page">
    <div class="kicker">Waumini en bref</div>
    <h2>Un seul outil pour toute la vie de l’église</h2>
    <div class="grid2" style="margin-top: 1mm">
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="users" class="icon" /></span><h3>Registre des membres</h3></div><p>Fidèles, ménages, départements, parcours spirituel, carte de membre.</p></div>
        <div class="mod"><div class="head"><span class="ico"><x-icon name="coins" class="icon" /></span><h3>Finances</h3></div><p>Caisses, banques et mobile money, en dollars et en francs, avec reçus.</p></div>
        <div class="mod"><div class="head"><span class="ico"><x-icon name="clipboard-check" class="icon" /></span><h3>Dépenses à signatures</h3></div><p>Demande, contrôle, approbation, décaissement, justificatif.</p></div>
        <div class="mod"><div class="head"><span class="ico leaf"><x-icon name="milestone" class="icon" /></span><h3>Budget et plan d’action</h3></div><p>La vision, les objectifs et le budget voté, suivis au jour le jour.</p></div>
        <div class="mod"><div class="head"><span class="ico"><x-icon name="hand-coins" class="icon" /></span><h3>Paie des ouvriers</h3></div><p>Salaires, primes, retenues, avances et bulletins de paie.</p></div>
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="calendar-days" class="icon" /></span><h3>Vie de la communauté</h3></div><p>Groupes, calendrier, présences au culte, annonces partagées sur WhatsApp.</p></div>
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="badge-check" class="icon" /></span><h3>Documents officiels</h3></div><p>Attestations avec QR code vérifiable, anciens registres recopiés.</p></div>
        <div class="mod"><div class="head"><span class="ico leaf"><x-icon name="heart-handshake" class="icon" /></span><h3>Suivi pastoral</h3></div><p>Visites, malades, catéchumènes, demandes de prière, anniversaires.</p></div>
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="network" class="icon" /></span><h3>Dénominations</h3></div><p>Siège, régions, paroisses&nbsp;: chiffres consolidés et quotes-parts.</p></div>
        <div class="mod"><div class="head"><span class="ico leaf"><x-icon name="globe" class="icon" /></span><h3>Site web de l’église</h3></div><p>Une vitrine en ligne&nbsp;: cultes, annonces, prédications, dons.</p></div>
    </div>
    <div class="facts" style="margin-bottom: 3mm">
        <div class="fact"><x-icon name="smartphone" class="icon" />Téléphone, tablette et ordinateur</div>
        <div class="fact"><x-icon name="circle-dollar-sign" class="icon" />Dollar et franc congolais</div>
        <div class="fact"><x-icon name="send" class="icon" />M-Pesa, Airtel, Orange Money</div>
        <div class="fact"><x-icon name="shield-check" class="icon" />Chacun voit selon son rôle</div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 5. Chacun son rôle --}}
<section class="page">
    <div class="kicker">Pour qui&nbsp;?</div>
    <h2>Chacun son rôle, chacun son espace</h2>
    <p class="lead">Chaque personne se connecte avec son numéro de téléphone et ne voit que ce que son rôle lui permet. Les rôles se règlent selon l’organisation de votre église.</p>
    <table class="roles">
        <tr><td><x-icon name="church" class="icon" />Le pasteur</td><td>Voit l’essentiel d’un coup d’œil, approuve les dépenses, suit le budget, les fidèles et le travail pastoral.</td></tr>
        <tr><td><x-icon name="coins" class="icon" />Le trésorier</td><td>Saisit la collecte, les recettes et les dépenses, vérifie les paiements mobile money, prépare la paie et les rapports.</td></tr>
        <tr><td><x-icon name="file-text" class="icon" />Le secrétaire</td><td>Tient le registre, délivre les attestations, recopie les anciens registres, publie les annonces et le site web.</td></tr>
        <tr><td><x-icon name="users-round" class="icon" />Les responsables</td><td>Départements et groupes&nbsp;: leurs membres, leurs rencontres et présences, leurs besoins de budget et demandes de dépense.</td></tr>
        <tr><td><x-icon name="user" class="icon" />Les membres</td><td>Leur carte, leurs dons et reçus, le programme, les annonces&nbsp;; ils demandent une attestation ou la prière.</td></tr>
        <tr><td><x-icon name="network" class="icon" />Le siège et les régions</td><td>Voient les chiffres additionnés de leurs paroisses, sans rien ressaisir, et suivent les quotes-parts.</td></tr>
    </table>
    <div class="note leaf" style="margin-top: auto; margin-bottom: 3mm">Waumini parle le langage de votre église&nbsp;: «&nbsp;Berger&nbsp;» ou «&nbsp;Pasteur&nbsp;», «&nbsp;Ministère&nbsp;» ou «&nbsp;Département&nbsp;», les mots se règlent dans les paramètres. Vos couleurs et votre logo aussi.</div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 6. Tableau de bord --}}
<section class="page">
    <div class="kicker">Le tableau de bord</div>
    <h2>L’église d’un coup d’œil</h2>
    <p class="lead">En ouvrant Waumini, chacun trouve les chiffres et les actions qui le concernent&nbsp;: membres, trésorerie, taux du jour, ce qui attend sa décision.</p>
    {!! $laptop('03-tableau-de-bord', 'Le tableau de bord sur ordinateur') !!}
    <div class="row" style="margin-top: 5mm">
        <div class="text">
            <h3>Sur le téléphone comme sur l’ordinateur</h3>
            <ul class="points">
                <li>Waumini s’ouvre dans le navigateur et <strong>s’installe comme une application</strong> sur Android, iPhone et Windows.</li>
                <li>Pas de serveur à acheter, pas de logiciel à entretenir&nbsp;: les mises à jour arrivent toutes seules.</li>
                <li>Les <strong>notifications</strong> préviennent le pasteur d’une dépense à approuver, le trésorier d’un paiement à vérifier.</li>
                <li>Connexion par mot de passe ou par l’<strong>empreinte digitale</strong> du téléphone.</li>
            </ul>
        </div>
        {!! $phone('03-tableau-de-bord', 'Le tableau de bord sur téléphone', '34mm') !!}
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 7. Registre et carte --}}
<section class="page">
    <div class="kicker">Le registre des membres</div>
    <h2>Connaître chaque fidèle par son nom</h2>
    <p class="lead">Le registre de l’église, toujours à jour et jamais perdu&nbsp;: chaque fidèle avec sa famille, ses fonctions et son parcours.</p>
    <ul class="points">
        <li><strong>Fiche complète</strong>&nbsp;: identité, contacts, ménage, départements, fonctions, baptême, mariage, historique.</li>
        <li><strong>Numéro de membre</strong> attribué automatiquement, au format de votre église.</li>
        <li>Waumini <strong>signale les doublons</strong> avant qu’ils n’entrent dans le registre.</li>
        <li>Votre liste Excel s’<strong>importe en quelques minutes</strong>&nbsp;; chaque ligne est vérifiée avant l’import.</li>
        <li>La <strong>carte de membre</strong> s’imprime au format carte bancaire, avec un QR code qui prouve qu’elle est authentique.</li>
    </ul>
    <div class="shots">
        <div>{!! $phone('29-membre-fiche', 'La fiche d’un membre') !!}<p class="caption">La fiche d’un membre</p></div>
        <div>{!! $phone('36-carte', 'La carte de membre') !!}<p class="caption">Sa carte de membre</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 8. Finances --}}
<section class="page">
    <div class="kicker">Les finances</div>
    <h2>Chaque franc, chaque dollar à sa place</h2>
    <p class="lead">Toutes les caisses de l’église au même endroit, dans les deux monnaies, avec le solde exact à tout moment.</p>
    <ul class="points">
        <li><strong>Caisses, comptes bancaires et mobile money</strong>, chacun avec son solde en dollars et en francs congolais.</li>
        <li>Le <strong>taux du jour</strong> enregistré par le trésorier&nbsp;: la trésorerie totale est convertie automatiquement.</li>
        <li>Recettes classées par catégorie&nbsp;: dîmes, offrandes, actions de grâce, dons, cotisations…</li>
        <li><strong>Virements et change</strong> entre caisses, sans erreur de calcul.</li>
        <li>Un <strong>reçu</strong> pour chaque don, à imprimer en A4 ou sur une petite imprimante de caisse.</li>
        <li>Aucune opération n’est effacée en silence&nbsp;: une correction laisse toujours une trace.</li>
    </ul>
    <div style="margin-top: 2mm">{!! $laptop('39-finances', 'Les finances') !!}</div>
    <div class="note" style="margin-top: auto; margin-bottom: 3mm"><strong>À la fin du mois</strong>, le trésorier clôture la période&nbsp;: les chiffres sont figés, le rapport est prêt pour le comité.</div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 9. Le culte du dimanche --}}
<section class="page">
    <div class="kicker">Le culte du dimanche</div>
    <h2>La collecte, comptée à deux et enregistrée tout de suite</h2>
    <p class="lead">Après le culte, l’équipe de comptage remplit la feuille de collecte directement sur le téléphone.</p>
    <ul class="points">
        <li>Comptage <strong>billet par billet</strong>, en dollars et en francs&nbsp;: le total se calcule seul.</li>
        <li>Offrandes, dîmes, enveloppes&nbsp;: chaque montant à sa ligne, les <strong>écarts signalés</strong> aussitôt.</li>
        <li>Les <strong>personnes qui ont compté</strong> sont nommées&nbsp;; le procès-verbal s’imprime pour être signé.</li>
        <li>La collecte validée entre dans la caisse choisie, sans ressaisie.</li>
    </ul>
    <div class="shots" style="align-items: center">
        <div>{!! $phone('45-collecte', 'La feuille de collecte', '44mm') !!}<p class="caption">La feuille de collecte</p></div>
        <div style="width: 68mm"><figure class="paper"><img src="captures/extraits/recu.jpg" alt="Un reçu"></figure><p class="caption">Le reçu remis au donateur&nbsp;: A4, 80 mm ou 58 mm</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 10. Mobile money et promesses --}}
<section class="page">
    <div class="kicker">Mobile money et promesses</div>
    <h2>Les dons à distance, vérifiés un par un</h2>
    <p class="lead">Beaucoup de fidèles donnent par M-Pesa, Airtel Money ou Orange Money. Waumini les aide à le faire, et le trésorier à s’y retrouver.</p>
    <div class="row">
        {!! $phone('48-declarations', 'Vérifier un paiement', '40mm') !!}
        <div class="text">
            <ol class="steps">
                <li>Le fidèle envoie son don au numéro de l’église, puis le <strong>déclare</strong> depuis son espace ou le site de l’église, avec l’identifiant de la transaction.</li>
                <li>Le trésorier reçoit une notification, <strong>vérifie</strong> sur le téléphone de l’église que l’argent est bien arrivé.</li>
                <li>Il <strong>valide</strong>&nbsp;: la recette entre dans le bon compte et le fidèle reçoit son reçu. Un même identifiant ne peut pas servir deux fois.</li>
            </ol>
            <h3 style="margin-top: 3mm">Les promesses pour les projets</h3>
            <p>Construction, véhicule, convention&nbsp;: chaque promesse va à son projet et se suit versement après versement, avec ce qui reste à donner.</p>
        </div>
    </div>
    <div style="margin: auto auto 4mm; width: 104mm">{!! $laptop('110-site-don', 'La page des dons du site de l’église') !!}<p class="caption">La page «&nbsp;Faire un don&nbsp;» du site de l’église&nbsp;: les numéros mobile money, puis la déclaration.</p></div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 11. Dépenses --}}
<section class="page">
    <div class="kicker">Les dépenses</div>
    <h2>Pas de sortie d’argent sans signatures</h2>
    <p class="lead">Chaque dépense suit le circuit choisi par votre église. Rien ne sort de la caisse sans les bonnes autorisations.</p>
    <div class="flow"><span>Demande</span><i>›</i><span>Contrôle</span><i>›</i><span class="on">Approbation</span><i>›</i><span>Décaissement</span><i>›</i><span>Justificatif</span></div>
    <div style="width: 112mm; margin: 0 auto">{!! $laptop('51-depense-signature', 'Une dépense en cours d’approbation') !!}</div>
    <div class="row" style="margin-top: 4mm">
        <div class="text">
            <ul class="points">
                <li>Le responsable du département <strong>demande</strong>, le trésorier <strong>contrôle</strong>, le pasteur ou le comité <strong>signe</strong>.</li>
                <li>Chaque signature est datée et nominative&nbsp;: <strong>l’historique</strong> reste consultable.</li>
                <li>Waumini compare la demande au <strong>budget voté</strong> et signale un dépassement.</li>
                <li>Les <strong>avances</strong> doivent être justifiées, factures photographiées à l’appui&nbsp;: Waumini rappelle celles qui tardent.</li>
            </ul>
        </div>
        {!! $phone('49-depenses', 'La liste des dépenses', '34mm') !!}
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 12. Projets et budget --}}
<section class="page">
    <div class="kicker">Projets et budget</div>
    <h2>Une vision, des projets, un budget</h2>
    <p class="lead">La parcelle, le temple, la convention&nbsp;: pour chaque projet, Waumini sait ce qui est prévu, promis, reçu, dépensé, et où il en est.</p>
    <div class="grid2" style="gap: 4mm">
        <div>
            <h3>Les projets</h3>
            <p>Sur un an ou plusieurs, chaque projet a ses tranches, ses promesses et son argent, qui ne paie que lui. Son avancement se calcule par ses indicateurs&nbsp;: jeunes formés, terrain acheté, argent collecté. Le siège peut confier un projet à ses paroisses, chacune avec sa part.</p>
        </div>
        <div>
            <h3>Le budget de l’exercice</h3>
            <p>Les départements proposent, la finance arbitre, le pasteur approuve. Chaque recette dit ce qu’elle apporte, chaque dépense ce qu’elle consomme&nbsp;; un budget en déficit ne se présente pas. Ensuite, chaque dépense est contrôlée.</p>
        </div>
    </div>
    <div class="shots" style="--w: 49mm">
        <div>{!! $phone('68-projets', 'Les projets') !!}<p class="caption">La vision et ses projets</p></div>
        <div>{!! $phone('69-indicateurs', 'Les indicateurs d’un projet') !!}<p class="caption">L’avancement par les indicateurs</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 13. Rapports et paie --}}
<section class="page">
    <div class="kicker">Rapports et paie</div>
    <h2>Rendre compte, simplement</h2>
    <p class="lead">Le rapport financier du mois ou de l’année se prépare en un geste, prêt à être présenté au conseil ou à l’assemblée.</p>
    {!! $laptop('57-rapport', 'Le rapport financier') !!}
    <div class="row" style="margin-top: 4mm">
        <div class="text">
            <h3>La paie des ouvriers</h3>
            <ul class="points">
                <li>Pasteurs, sentinelles, chantres, enseignants&nbsp;: chacun avec son salaire, ses primes et ses retenues.</li>
                <li>Paie mensuelle ou par prestation, <strong>approuvée</strong> avant d’être payée.</li>
                <li><strong>Avances sur salaire</strong> retenues automatiquement sur les paies suivantes.</li>
                <li>Un <strong>bulletin de paie</strong> clair pour chaque bénéficiaire.</li>
            </ul>
            <p style="margin-top: 2mm">Rapports et listes s’exportent aussi vers <strong>Excel</strong> et en <strong>PDF</strong>.</p>
        </div>
        {!! $phone('76-bulletin', 'Un bulletin de paie', '34mm') !!}
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 14. Vie de la communauté --}}
<section class="page">
    <div class="kicker">La vie de la communauté</div>
    <h2>Rassembler, informer, compter les présents</h2>
    <p class="lead">Cultes, réunions, répétitions, évangélisations&nbsp;: tout le programme de l’église au même endroit.</p>
    <ul class="points">
        <li><strong>Le calendrier</strong> des activités, avec inscriptions et activités qui se répètent chaque semaine.</li>
        <li><strong>Les présences</strong> au culte (hommes, femmes, enfants, visiteurs) et aux rencontres des groupes, pour voir qui s’éloigne.</li>
        <li><strong>Les groupes</strong>&nbsp;: chorales, cellules, jeunesse, mamans, avec leur responsable et leurs cotisations.</li>
        <li><strong>Les annonces</strong> arrivent dans l’espace des membres et se partagent sur WhatsApp en un toucher.</li>
    </ul>
    <div class="shots">
        <div>{!! $phone('82-calendrier', 'Le calendrier') !!}<p class="caption">Le calendrier</p></div>
        <div>{!! $phone('86-annonces', 'Les annonces') !!}<p class="caption">Une annonce partagée sur WhatsApp</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 15. Documents officiels --}}
<section class="page">
    <div class="kicker">Les documents officiels</div>
    <h2>Des attestations qu’on ne peut pas falsifier</h2>
    <p class="lead">Attestations de baptême, de mariage, de membre, lettres de recommandation&nbsp;: préparées en quelques secondes à partir du registre.</p>
    <ul class="points">
        <li>Les <strong>modèles</strong> de l’église, à son en-tête, se remplissent tout seuls avec les informations du fidèle.</li>
        <li>Chaque document reçoit un <strong>numéro</strong> et un <strong>QR code</strong>. Scanné avec n’importe quel téléphone, il confirme que le document est authentique et n’a pas été annulé.</li>
        <li>Les <strong>anciens registres papier</strong> (baptêmes, mariages) se recopient petit à petit&nbsp;; les actes anciens peuvent ensuite être réédités.</li>
        <li>Un membre peut <strong>demander son attestation</strong> depuis son espace&nbsp;; le secrétariat est prévenu.</li>
    </ul>
    <div class="shots" style="align-items: center">
        <div style="width: 70mm"><figure class="paper fade"><img src="captures/extraits/attestation.jpg" alt="Une attestation de baptême"></figure><p class="caption">Une attestation, prête à imprimer</p></div>
        <div>{!! $phone('91-verification', 'La vérification d’un document', '40mm') !!}<p class="caption">Son QR code, scanné</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 16. Suivi pastoral et espace membre --}}
<section class="page">
    <div class="kicker">Le suivi pastoral</div>
    <h2>Prendre soin de chaque brebis</h2>
    <p class="lead">Waumini aide l’équipe pastorale à n’oublier personne, et donne à chaque fidèle un lien avec son église.</p>
    <div class="grid2" style="gap: 4mm">
        <div>
            <h3>Pour l’équipe pastorale</h3>
            <ul class="points">
                <li>Les personnes accompagnées&nbsp;: malades, deuils, catéchumènes, nouveaux venus.</li>
                <li>Des <strong>notes confidentielles</strong>, lisibles par leur seul auteur.</li>
                <li>Les <strong>demandes de prière</strong> reçues des membres.</li>
                <li>Les <strong>anniversaires</strong> à souhaiter.</li>
            </ul>
        </div>
        <div>
            <h3>Pour chaque membre</h3>
            <ul class="points">
                <li>Sa <strong>carte de membre</strong> toujours sur lui.</li>
                <li>Le programme, les annonces de ses groupes.</li>
                <li>Ses dons et ses reçus&nbsp;; déclarer un don mobile money.</li>
                <li>Demander la prière ou une attestation.</li>
            </ul>
        </div>
    </div>
    <div class="shots">
        <div>{!! $phone('96-suivi-pastoral', 'Le suivi pastoral') !!}<p class="caption">Le suivi pastoral</p></div>
        <div>{!! $phone('99-espace-membre', 'L’espace membre') !!}<p class="caption">L’espace d’un membre</p></div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 17. Dénominations et site vitrine --}}
<section class="page">
    <div class="kicker">Au-delà de la paroisse</div>
    <h2>Pour les dénominations et pour le monde extérieur</h2>
    <div class="row">
        <div class="text">
            <h3>Siège, régions, paroisses</h3>
            <p>Waumini épouse l’organisation de votre communauté&nbsp;: siège, régions, districts, paroisses, annexes. Chaque niveau gère ses affaires, et les niveaux supérieurs voient les <strong>chiffres additionnés</strong> de ceux qui en dépendent, sans rien ressaisir&nbsp;: membres, recettes, dépenses, présences.</p>
            <ul class="points">
                <li><strong>Quotes-parts</strong> dues au siège calculées et suivies.</li>
                <li><strong>Transfert d’un membre</strong> d’une paroisse à l’autre, avec sa fiche.</li>
                <li>Les réglages du registre peuvent être <strong>imposés par le siège</strong> à toutes les paroisses.</li>
            </ul>
        </div>
        {!! $phone('102-consolidation', 'La consolidation', '37mm') !!}
    </div>
    <div class="row" style="margin-top: 4mm">
        {!! $phone('109-site-accueil', 'Le site de l’église', '37mm') !!}
        <div class="text">
            <h3>Le site web de l’église</h3>
            <p>Chaque église peut publier sa <strong>vitrine en ligne</strong>, sans informaticien&nbsp;: horaires des cultes, activités, annonces, prédications en audio ou vidéo, plan d’accès et une page pour <strong>donner par mobile money</strong>. Le contenu vient directement de Waumini&nbsp;: une annonce publiée est aussitôt en ligne.</p>
        </div>
    </div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 18. Confiance et sécurité --}}
<section class="page">
    <div class="kicker">Confiance et sécurité</div>
    <h2>Les données de l’église restent à l’église</h2>
    <p class="lead">Les informations sur vos fidèles et vos finances sont précieuses. Waumini a été conçu pour les protéger.</p>
    <div class="grid2">
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="lock" class="icon" /></span><h3>Accès personnels</h3></div><p>Chaque utilisateur a son propre compte. Pas de mot de passe partagé, des droits selon le rôle.</p></div>
        <div class="mod"><div class="head"><span class="ico ink"><x-icon name="history" class="icon" /></span><h3>Journal d’audit</h3></div><p>Qui a créé, modifié ou supprimé quoi, et quand. Le journal ne peut pas être effacé.</p></div>
        <div class="mod"><div class="head"><span class="ico leaf"><x-icon name="archive" class="icon" /></span><h3>Sauvegardes chaque nuit</h3></div><p>Toutes les données de l’église sont sauvegardées automatiquement, chaque nuit.</p></div>
        <div class="mod"><div class="head"><span class="ico leaf"><x-icon name="download" class="icon" /></span><h3>Vos données vous appartiennent</h3></div><p>Le registre et les rapports financiers s’exportent vers Excel à tout moment.</p></div>
        <div class="mod"><div class="head"><span class="ico"><x-icon name="eye-off" class="icon" /></span><h3>Notes confidentielles</h3></div><p>Les notes pastorales ne sont lisibles que par leur auteur.</p></div>
        <div class="mod"><div class="head"><span class="ico"><x-icon name="handshake" class="icon" /></span><h3>Support avec votre accord</h3></div><p>Genius ICT n’entre dans votre espace que si vous l’autorisez, pour une durée choisie, en lecture seule.</p></div>
    </div>
    <div style="margin: auto auto 4mm; width: 106mm">{!! $laptop('22-acces-support', 'L’accès du support') !!}<p class="caption">L’église ouvre l’accès au support pour quelques jours, et voit chaque visite.</p></div>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 19. Comment commencer --}}
<section class="page">
    <div class="kicker">Comment commencer</div>
    <h2>Essayez, puis lancez-vous accompagnés</h2>
    <ol class="steps">
        <li><strong>Essayez la démonstration.</strong> Sur le site de Waumini, touchez «&nbsp;Essayer la démo&nbsp;»&nbsp;: vous recevez votre propre copie de la communauté de démonstration et passez d’un rôle à l’autre (pasteur, trésorière, secrétaire, membre). Rien à installer.</li>
        <li><strong>Inscrivez votre église&nbsp;: 30 jours d’essai gratuit.</strong> Toutes les fonctions, sans engagement, avec vos vraies données.</li>
        <li><strong>Laissez-vous accompagner.</strong> Genius ICT aide à reprendre votre registre Excel, vos caisses et vos soldes, puis forme chaque équipe sur son propre téléphone&nbsp;: secrétariat, trésorerie, pasteur et comité.</li>
    </ol>
    <h3 style="margin: 2mm 0 2mm">Quatre offres, selon votre église</h3>
    <div class="packs">
        @foreach ($packs as $key => $pack)
            <div @class(['pack', 'featured' => $pack['featured'] ?? false])>
                <span class="mean">{{ $pack['meaning'] }}</span>
                <h3>{{ $pack['name'] }}</h3>
                <p>{{ $pack['for'] }}</p>
                <p style="color: var(--ink)">{{ implode(' · ', $pack['modules']) }}</p>
            </div>
        @endforeach
    </div>
    <p style="margin-top: 2.5mm; font-size: 7.6pt; color: var(--sand-700)">Le tarif dépend de l’offre et de la taille de la communauté&nbsp;; l’abonnement annuel offre deux mois. Paiement par mobile money. Demandez-nous une proposition.</p>
    <footer class="folio"><span>Waumini · Livret de présentation</span><span class="n"></span></footer>
</section>

{{-- 20. Dos --}}
<section class="page back wax">
    <div class="veil"></div>
    <div class="inner">
        <div class="logo">{!! $logoWhiteHorizontal !!}</div>
        <h2 style="margin-top: 14mm">Voyez par vous-même.</h2>
        <p style="font-size: 10pt; color: rgb(255 255 255 / 0.85); max-width: 95mm">Scannez ce code avec l’appareil photo de votre téléphone pour ouvrir la démonstration de Waumini.</p>
        <div style="display: flex; gap: 5mm; align-items: center; margin-top: 4mm">
            <div class="qr">{!! $qr !!}</div>
            <div style="font-size: 8pt; color: rgb(255 255 255 / 0.85)">ou rendez-vous sur<br><strong style="color: #fff; font-size: 9.5pt; word-break: break-all">{{ $siteLabel }}</strong></div>
        </div>
        <div style="margin-top: auto">
            <p style="font-weight: 600; font-size: 10pt; margin-bottom: 3mm; color: var(--ochre)">Parlons de votre église</p>
            <div class="contact">
                <div><x-icon name="building-2" class="icon" />Genius ICT · Goma, Nord-Kivu, RDC</div>
                @if ($phoneNumber)<div><x-icon name="phone" class="icon" />{{ $phoneNumber }} (appel et WhatsApp)</div>@endif
                <div><x-icon name="mail" class="icon" />{{ $email }}</div>
                <div><x-icon name="globe" class="icon" />{{ $siteLabel }}</div>
            </div>
            <p style="margin-top: 7mm; font-size: 6.6pt; color: rgb(255 255 255 / 0.55)">Les captures de ce livret montrent une communauté de démonstration fictive. Édition de {{ $edition }}.</p>
        </div>
    </div>
</section>

<script>
    // Numéros de page, posés une fois pour toutes : le livret imposé change l'ordre des pages.
    document.querySelectorAll('.page').forEach(function (page, i) {
        var n = page.querySelector('.folio .n');
        if (n) n.textContent = i + 1;
    });
</script>
</body>
</html>
