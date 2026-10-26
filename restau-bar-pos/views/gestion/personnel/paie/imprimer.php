<?php
/**
 * Bulletin de paie à imprimer (A4, toujours sur fond blanc) : employeur, employé, période, présence,
 * gains, retenues, net par devise (devise locale d'abord, dollar ensuite), paiements, signatures.
 * @var array $bulletin
 * @var ?array $employe
 * @var string $nomClient
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Repositories\PaieRepository;

$b = $bulletin;
$devises = P::devises();
$gains = array_filter($b['lignes'], static fn ($l) => in_array($l['type'], PaieRepository::GAINS, true));
$retenues = array_filter($b['lignes'], static fn ($l) => !in_array($l['type'], PaieRepository::GAINS, true));
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="jour">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(t('pers.bulletin_titre', ['nom' => $b['employe_nom']]) . ' · ' . P::periode($b['debut'], $b['fin'])) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/gestion-personnel.css">
</head>
<body class="per-impression">
<p class="per-impression__aide"><button class="bouton" type="button" onclick="window.print()"><?= e(t('pers.imprimer')) ?></button></p>
<article class="per-feuille">
    <header class="per-feuille__tete">
        <div>
            <p class="per-feuille__employeur"><?= e($nomClient) ?></p>
            <p><?= e($b['etablissement_nom']) ?><?= $b['etablissement_adresse'] ? ' · ' . e($b['etablissement_adresse']) : '' ?><?= $b['etablissement_ville'] ? ', ' . e($b['etablissement_ville']) : '' ?></p>
        </div>
        <div class="per-feuille__titre">
            <h1><?= e(t('pers.bulletin_de_paie')) ?></h1>
            <p><?= e(P::periode($b['debut'], $b['fin'])) ?></p>
            <p class="per-feuille__statut"><?= e(t('pers.bulletin_' . $b['statut'])) ?> · n° <?= (int) $b['id'] ?></p>
        </div>
    </header>
    <section class="per-feuille__infos">
        <dl>
            <div><dt><?= e(t('pers.col_employe')) ?></dt><dd><?= e($b['employe_nom']) ?></dd></div>
            <div><dt><?= e(t('pers.champ_poste')) ?></dt><dd><?= e((string) ($b['poste'] ?: '—')) ?></dd></div>
            <div><dt><?= e(t('pers.champ_contrat')) ?></dt><dd><?= e(t('pers.contrat_' . $b['contrat'])) ?> · <?= e(t('pers.periode_' . $b['periode'])) ?></dd></div>
            <div><dt><?= e(t('pers.champ_debut')) ?></dt><dd><?= e(P::jour($b['employe_debut'])) ?></dd></div>
        </dl>
        <dl>
            <div><dt><?= e(t('pers.col_heures_travaillees')) ?></dt><dd><?= e(P::nombre($b['heures_travaillees'])) ?> h / <?= e(P::nombre($b['heures_planifiees'])) ?> h</dd></div>
            <div><dt><?= e(t('pers.col_jours_travailles')) ?></dt><dd><?= (int) $b['jours_travailles'] ?> / <?= (int) $b['creneaux_planifies'] ?></dd></div>
            <div><dt><?= e(t('pers.col_retards')) ?></dt><dd><?= (int) $b['retards'] ?> (<?= e(P::duree((int) $b['retard_minutes'])) ?>)</dd></div>
            <div><dt><?= e(t('pers.absences_injustifiees')) ?></dt><dd><?= (int) $b['absences_injustifiees'] ?></dd></div>
        </dl>
    </section>
    <table class="per-feuille__lignes">
        <thead><tr><th scope="col"><?= e(t('pers.libelle')) ?></th><th scope="col" class="nombre"><?= e(t('pers.quantite')) ?></th><th scope="col" class="nombre"><?= e(t('pers.gains')) ?></th><th scope="col" class="nombre"><?= e(t('pers.retenues')) ?></th></tr></thead>
        <tbody>
            <?php foreach ($gains as $l): ?>
                <tr><td><?= e(P::libelleLigne($l)) ?></td><td class="nombre"><?= $l['quantite'] !== null && $l['type'] !== 'commission' ? e(P::nombre($l['quantite'])) : '' ?></td><td class="nombre"><?= e(P::montant($l['montant'], (int) $l['devise_id'])) ?></td><td></td></tr>
            <?php endforeach; ?>
            <?php foreach ($retenues as $l): ?>
                <tr><td><?= e(P::libelleLigne($l)) ?></td><td class="nombre"><?= $l['quantite'] !== null ? e(P::nombre($l['quantite'])) : '' ?></td><td></td><td class="nombre"><?= e(P::montant($l['montant'], (int) $l['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><th scope="row" colspan="2"><?= e(t('pers.totaux')) ?></th><td class="nombre"><?= e(P::montants($b['totaux']['gains'])) ?></td><td class="nombre"><?= e(P::montants($b['totaux']['retenues'])) ?></td></tr>
        </tfoot>
    </table>
    <section class="per-feuille__net">
        <h2><?= e(t('pers.net_a_payer')) ?></h2>
        <p class="per-feuille__net-montant"><?= e(P::montants($b['totaux']['net'], P::montant(0, (int) array_key_first($devises)))) ?></p>
    </section>
    <?php if ($b['paiements'] !== []): ?>
        <section class="per-feuille__paiements">
            <h2><?= e(t('pers.paiements')) ?></h2>
            <ul>
                <?php foreach ($b['paiements'] as $p): if ($p['statut'] !== 'valide') continue; ?>
                    <?php foreach ($p['lignes'] as $l): ?><li><?= e(A::dateHeure($p['date_paiement'])) ?> · <?= e(t('tresorerie.type_' . $l['compte_type'])) ?> · <?= e($l['compte_nom']) ?> · <?= e(P::montant($l['montant'], (int) $l['devise_id'])) ?></li><?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
    <?php if ($b['note']): ?><p class="per-feuille__note"><?= e($b['note']) ?></p><?php endif; ?>
    <footer class="per-feuille__signatures">
        <div><p><?= e(t('pers.signature_employeur')) ?></p></div>
        <div><p><?= e(t('pers.signature_employe')) ?></p></div>
    </footer>
</article>
</body>
</html>
