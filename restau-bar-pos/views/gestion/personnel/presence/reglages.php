<?php
/**
 * Personnel > Présence > Réglages d'un établissement : position GPS (avec « utiliser la position de cet appareil »),
 * rayon accepté, pointage obligatoire pour ouvrir la caisse ; règles communes (tolérance de retard, fin de journée,
 * majorations) pour qui gère tous les établissements.
 * @var ?string $flash
 * @var array $etablissements
 * @var ?array $etab
 * @var array<string, string> $erreurs
 * @var ?array $saisie
 * @var bool $peutGerer
 * @var bool $globaux
 * @var array<string, string> $regles
 */

use App\Core\View;

$val = static function (string $nom, $defaut) use ($saisie): string {
    return (string) ($saisie[$nom] ?? $defaut);
};
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$desactive = $peutGerer ? '' : ' disabled';
?>
<?= View::rendre('gestion/personnel/_onglets-presence', ['actif' => 'reglages']) ?>
<h1><?= e(t('pers.reglages_titre')) ?></h1>
<p class="aide"><?= e(t('pers.reglages_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab]) ?>

<?php if ($etab): ?>
<form method="post" action="/gestion/personnel/presence/reglages" class="tre-formulaire" novalidate id="reglages-pointage"
      data-texte-position="<?= e(t('pers.position_recherche')) ?>" data-texte-position-ok="<?= e(t('pers.position_trouvee')) ?>" data-texte-position-erreur="<?= e(t('pers.position_erreur')) ?>">
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
    <input type="hidden" name="etablissement_id" value="<?= (int) $etab['id'] ?>">
    <section class="bloc">
        <h2><?= e(t('pers.section_position', ['etablissement' => $etab['nom']])) ?></h2>
        <p class="aide"><?= e(t('pers.position_aide')) ?></p>
        <div class="tre-paire">
            <div class="champ">
                <label for="latitude"><?= e(t('pers.champ_latitude')) ?></label>
                <input id="latitude" name="latitude" inputmode="decimal" value="<?= e($val('latitude', $etab['latitude'] ?? '')) ?>" placeholder="-1.6792"<?= $invalide('latitude') . $desactive ?>>
                <?= $erreur('latitude') ?>
            </div>
            <div class="champ">
                <label for="longitude"><?= e(t('pers.champ_longitude')) ?></label>
                <input id="longitude" name="longitude" inputmode="decimal" value="<?= e($val('longitude', $etab['longitude'] ?? '')) ?>" placeholder="29.2228"<?= $invalide('longitude') . $desactive ?>>
                <?= $erreur('longitude') ?>
            </div>
        </div>
        <?php if ($peutGerer): ?>
            <p><button class="bouton bouton--secondaire" type="button" data-ma-position><?= e(t('pers.utiliser_ma_position')) ?></button> <span class="aide" data-position-etat aria-live="polite"></span></p>
        <?php endif; ?>
        <?php if (!empty($etab['latitude']) && !empty($etab['longitude'])): ?>
            <p><a class="lien" href="https://www.openstreetmap.org/?mlat=<?= e((string) $etab['latitude']) ?>&amp;mlon=<?= e((string) $etab['longitude']) ?>#map=18/<?= e((string) $etab['latitude']) ?>/<?= e((string) $etab['longitude']) ?>" target="_blank" rel="noopener"><?= e(t('pers.voir_carte')) ?></a></p>
        <?php endif; ?>
        <div class="champ">
            <label for="rayon"><?= e(t('pers.champ_rayon')) ?></label>
            <div class="tre-montant">
                <input id="rayon" name="rayon" inputmode="numeric" value="<?= e($val('rayon', $etab['pointage_rayon'] ?? 100)) ?>"<?= $invalide('rayon') . $desactive ?>>
                <span class="tre-montant__devise">m</span>
            </div>
            <p class="champ__aide"><?= e(t('pers.champ_rayon_aide')) ?></p>
            <?= $erreur('rayon') ?>
        </div>
        <label class="coche"><input type="checkbox" name="obligatoire" value="1" <?= ($saisie !== null ? !empty($saisie['obligatoire']) : !empty($etab['pointage_obligatoire'])) ? 'checked' : '' ?><?= $desactive ?>> <?= e(t('pers.champ_obligatoire')) ?></label>
        <p class="champ__aide"><?= e(t('pers.champ_obligatoire_aide')) ?></p>
    </section>

    <?php if ($globaux): ?>
        <section class="bloc">
            <h2><?= e(t('pers.section_regles')) ?></h2>
            <p class="aide"><?= e(t('pers.regles_aide')) ?></p>
            <div class="per-salaires">
                <?php foreach (['tolerance_retard' => 'min', 'majoration_heures_sup' => '×', 'majoration_ferie' => '×'] as $champ => $unite): ?>
                    <div class="champ">
                        <label for="<?= $champ ?>"><?= e(t('pers.champ_' . $champ)) ?></label>
                        <div class="tre-montant">
                            <input id="<?= $champ ?>" name="<?= $champ ?>" inputmode="decimal" value="<?= e($val($champ, App\Controllers\Gestion\TresorerieAffichage::saisie($regles[$champ]))) ?>"<?= $invalide($champ) ?>>
                            <span class="tre-montant__devise"><?= e($unite) ?></span>
                        </div>
                        <p class="champ__aide"><?= e(t('pers.champ_' . $champ . '_aide')) ?></p>
                        <?= $erreur($champ) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($peutGerer): ?>
        <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('pers.enregistrer')) ?></button></div>
    <?php endif; ?>
</form>
<?php endif; ?>
<script src="/assets/js/gestion-personnel.js" defer></script>
