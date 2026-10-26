<?php
/**
 * Boutons « Exporter » d'un rapport : CSV (mêmes filtres que l'écran, ?export=csv) et Imprimer / PDF
 * (feuille d'impression A4 sans navigation ; « Enregistrer au format PDF » dans la fenêtre d'impression).
 * Sans JavaScript, « Imprimer » ouvre la page en présentation d'impression (?imprimer=1).
 * @var ?string $titre titre imprimé en tête (par défaut : celui de la page)
 * @var ?string $periode texte de la période imprimé en tête (« du 20/10/2026 au 26/10/2026 »)
 * @var bool $csv false : pas d'export CSV (seulement l'impression)
 */
$imprimer = isset($_GET['imprimer']);
$csv ??= true;
?>
<link rel="stylesheet" href="/assets/css/rapports-export.css">
<link rel="stylesheet" href="/assets/css/rapports-impression.css" media="<?= $imprimer ? 'all' : 'print' ?>">
<script src="/assets/js/gestion-rapports.js" defer></script>
<div class="rap-export" role="group" aria-label="<?= e(t('rapports.exporter')) ?>">
    <span class="rap-export__titre" aria-hidden="true"><?= e(t('rapports.exporter')) ?></span>
    <?php if ($csv): ?>
        <a class="bouton bouton--secondaire bouton--petit rap-export__bouton" href="<?= e(urlAvec(['export' => 'csv', 'imprimer' => null, 'page' => null])) ?>" download data-export="csv">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>
            <span><?= e(t('rapports.csv')) ?></span>
        </a>
    <?php endif; ?>
    <a class="bouton bouton--secondaire bouton--petit rap-export__bouton" href="<?= e(urlAvec(['imprimer' => '1', 'export' => null])) ?>" data-imprimer>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/></svg>
        <span><?= e(t('rapports.imprimer')) ?></span>
    </a>
</div>
<?php if ($imprimer): ?>
    <p class="rap-retour-impression"><a class="lien" href="<?= e(urlAvec(['imprimer' => null])) ?>"><?= e(t('rapports.retour_ecran')) ?></a></p>
<?php endif; ?>
<div class="rap-impression-entete">
    <strong><?= e(App\Core\Config::get('parametres.nom_client', '')) ?></strong>
    <span><?= e($titre ?? '') ?></span>
    <?php if (!empty($periode)): ?><span><?= e($periode) ?></span><?php endif; ?>
    <span><?= e(t('rapports.imprime_le', ['date' => App\Controllers\Gestion\TresorerieAffichage::dateHeure(date('Y-m-d H:i:s'))])) ?></span>
</div>
