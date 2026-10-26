<?php
/**
 * Transferts entre comptes : formulaire (compte et devise de chaque côté ; même compte et deux devises = change)
 * et liste de la période.
 * @var ?string $flash
 * @var array $valeurs
 * @var array<string, string> $erreurs
 * @var string[] $notes
 * @var string[] $avertissements
 * @var array<int, array> $comptes comptes actifs
 * @var array<int, array> $devises
 * @var array $periode
 * @var array $transferts
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
$devisesJs = array_map([A::class, 'deviseJs'], $devises);
// Devise affichée d'un côté : celle choisie, sinon la seule que le compte tient
$deviseCote = static function (string $cote) use ($comptes, $devises, $valeurs): ?array {
    $compte = $valeurs['compte_' . $cote . '_id'] !== null ? ($comptes[$valeurs['compte_' . $cote . '_id']] ?? null) : null;
    $deviseId = $valeurs['devise_' . $cote . '_id'];
    if ($compte !== null && $deviseId === null) {
        $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
        $deviseId = $tenues[0] ?? null;
    }
    return $deviseId !== null ? ($devises[$deviseId] ?? null) : null;
};
$compteCote = static fn (string $cote): ?array => $valeurs['compte_' . $cote . '_id'] !== null ? ($comptes[$valeurs['compte_' . $cote . '_id']] ?? null) : null;
$tousLesComptes = $comptes;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('transferts.titre')) ?></h1>
        <p class="aide"><?= e(t('transferts.aide')) ?></p>
    </div>
</div>


<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="bloc" id="nouveau-transfert" aria-labelledby="tre-nouveau-transfert-titre">
    <h2 id="tre-nouveau-transfert-titre"><?= e(t('transferts.nouveau')) ?></h2>
    <?php if (count($comptes) < 2): ?>
        <p class="aide"><?= e(t('transferts.deux_comptes')) ?></p>
        <p><a class="bouton bouton--secondaire" href="/gestion/tresorerie/comptes/nouveau">+ <?= e(t('tresorerie.nouveau_compte')) ?></a></p>
    <?php else: ?>
        <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
        <?php foreach ($notes as $note): ?>
            <p class="alerte tre-note" role="status"><?= e($note) ?></p>
        <?php endforeach; ?>

        <form method="post" action="/gestion/tresorerie/transferts#nouveau-transfert" class="tre-formulaire" id="formulaire-transfert" novalidate
              data-devises="<?= e((string) json_encode($devisesJs)) ?>"
              data-texte-solde="<?= e(t('transferts.solde_js')) ?>"
              data-texte-decouvert="<?= e(t('transferts.decouvert_js')) ?>"
              data-texte-propose="<?= e(t('transferts.propose_js')) ?>"
              data-texte-taux="<?= e(t('transferts.taux_js')) ?>"
              data-texte-sans-taux="<?= e(t('transferts.sans_taux_js')) ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">

            <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>

            <div class="tre-transfert">
                <div class="tre-transfert__cote">
                    <div class="champ">
                        <label for="compte_source_id"><?= e(t('transferts.champ_source')) ?></label>
                        <select id="compte_source_id" name="compte_source_id" data-compte="source"<?= $invalide('compte_source_id') ?>>
                            <option value=""><?= e(t('tresorerie.choisir_compte')) ?></option>
                            <?= View::rendre('gestion/tresorerie/_options-comptes', ['comptes' => $tousLesComptes, 'devises' => $devises, 'choisi' => $valeurs['compte_source_id']]) ?>
                        </select>
                        <?= $erreurChamp('compte_source_id') ?>
                    </div>
                    <div class="tre-transfert__montant">
                        <div class="champ tre-champ-devise">
                            <label for="devise_source_id"><?= e(t('transferts.champ_devise_source')) ?></label>
                            <select id="devise_source_id" name="devise_source_id" data-devise="source"<?= $invalide('devise_source_id') ?>>
                                <?= View::rendre('gestion/tresorerie/_options-devises', ['compte' => $compteCote('source'), 'devises' => $devises, 'choisie' => $deviseCote('source')['id'] ?? null]) ?>
                            </select>
                        </div>
                        <div class="champ">
                            <label for="montant_source"><?= e(t('transferts.champ_montant_source')) ?></label>
                            <div class="tre-montant">
                                <input id="montant_source" name="montant_source" inputmode="decimal" autocomplete="off"
                                       value="<?= e($valeurs['montant_source']) ?>"<?= $invalide('montant_source') ?>>
                                <span class="tre-montant__devise" data-symbole="source"><?= e($deviseCote('source')['symbole'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>
                    <?= $erreurChamp('devise_source_id') ?>
                    <?= $erreurChamp('montant_source') ?>
                    <p class="champ__aide tre-transfert__solde" data-solde="source" aria-live="polite"></p>
                </div>

                <div class="tre-transfert__fleche" aria-hidden="true">→</div>

                <div class="tre-transfert__cote">
                    <div class="champ">
                        <label for="compte_destination_id"><?= e(t('transferts.champ_destination')) ?></label>
                        <select id="compte_destination_id" name="compte_destination_id" data-compte="destination"<?= $invalide('compte_destination_id') ?>>
                            <option value=""><?= e(t('tresorerie.choisir_compte')) ?></option>
                            <?= View::rendre('gestion/tresorerie/_options-comptes', ['comptes' => $tousLesComptes, 'devises' => $devises, 'choisi' => $valeurs['compte_destination_id']]) ?>
                        </select>
                        <?= $erreurChamp('compte_destination_id') ?>
                    </div>
                    <div class="tre-transfert__montant">
                        <div class="champ tre-champ-devise">
                            <label for="devise_destination_id"><?= e(t('transferts.champ_devise_destination')) ?></label>
                            <select id="devise_destination_id" name="devise_destination_id" data-devise="destination"<?= $invalide('devise_destination_id') ?>>
                                <?= View::rendre('gestion/tresorerie/_options-devises', ['compte' => $compteCote('destination'), 'devises' => $devises, 'choisie' => $deviseCote('destination')['id'] ?? null]) ?>
                            </select>
                        </div>
                        <div class="champ" data-bloc-destination>
                            <label for="montant_destination"><?= e(t('transferts.champ_montant_destination')) ?></label>
                            <div class="tre-montant">
                                <input id="montant_destination" name="montant_destination" inputmode="decimal" autocomplete="off"
                                       value="<?= e($valeurs['montant_destination']) ?>"
                                       aria-describedby="aide-destination<?= isset($erreurs['montant_destination']) ? ' erreur-montant_destination" aria-invalid="true' : '' ?>">
                                <span class="tre-montant__devise" data-symbole="destination"><?= e($deviseCote('destination')['symbole'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>
                    <?= $erreurChamp('devise_destination_id') ?>
                    <p class="champ__aide tre-transfert__solde" data-solde="destination" aria-live="polite"></p>
                    <div class="tre-transfert__proposition" data-bloc-proposition>
                        <p class="champ__aide" id="aide-destination" data-proposition aria-live="polite"><?= e(t('transferts.champ_montant_destination_aide')) ?></p>
                        <p class="tre-taux-applique" data-taux-applique aria-live="polite"></p>
                        <button type="button" class="lien tre-reprendre" data-reprendre hidden><?= e(t('transferts.reprendre_propose')) ?></button>
                        <?= $erreurChamp('montant_destination') ?>
                    </div>
                </div>
            </div>

            <div class="tre-paire">
                <div class="champ">
                    <label for="date"><?= e(t('tresorerie.champ_date')) ?></label>
                    <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date'] !== '' ? $valeurs['date'] : date('Y-m-d\TH:i')) ?>"<?= $invalide('date') ?>>
                    <?= $erreurChamp('date') ?>
                </div>
                <div class="champ">
                    <label for="motif"><?= e(t('transferts.champ_motif')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                    <input id="motif" name="motif" maxlength="255" value="<?= e($valeurs['motif']) ?>" placeholder="<?= e(t('transferts.champ_motif_exemple')) ?>"<?= $invalide('motif') ?>>
                    <?= $erreurChamp('motif') ?>
                </div>
            </div>

            <div class="tre-actions">
                <button class="bouton" type="submit"><?= e(t('transferts.enregistrer')) ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="bloc" id="liste" aria-labelledby="tre-liste-transferts">
    <h2 id="tre-liste-transferts"><?= e(t('transferts.liste')) ?></h2>
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/tresorerie/transferts#liste']) ?>
    <?php if ($transferts === []): ?>
        <p class="aide"><?= e(t('transferts.aucun')) ?></p>
    <?php else: ?>
        <div class="defile tre-defile">
            <table class="tableau tre-transferts">
                <thead>
                <tr>
                    <th><?= e(t('tresorerie.col_date')) ?></th>
                    <th><?= e(t('transferts.col_comptes')) ?></th>
                    <th class="nombre"><?= e(t('transferts.col_envoye')) ?></th>
                    <th class="nombre"><?= e(t('transferts.col_recu')) ?></th>
                    <th><?= e(t('transferts.col_motif')) ?></th>
                    <th><span class="visuellement-cache"><?= e(t('tresorerie.col_actions')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($transferts as $tr):
                    $ds = $devises[(int) $tr['devise_source_id']] ?? null;
                    $dd = $devises[(int) $tr['devise_destination_id']] ?? null;
                    $change = $ds && $dd && (int) $ds['id'] !== (int) $dd['id'];
                    $interne = (int) $tr['compte_source_id'] === (int) $tr['compte_destination_id'];
                    ?>
                    <tr id="transfert-<?= (int) $tr['id'] ?>">
                        <td class="nombre tre-journal__date"><?= e(A::date($tr['date_transfert'])) ?></td>
                        <td>
                            <a href="/gestion/tresorerie/comptes/<?= (int) $tr['compte_source_id'] ?>"><?= e($tr['source_nom']) ?></a>
                            → <a href="/gestion/tresorerie/comptes/<?= (int) $tr['compte_destination_id'] ?>"><?= e($tr['destination_nom']) ?></a>
                            <?php if ($interne && $ds && $dd): ?><span class="badge badge--glace"><?= e(t('transferts.badge_change', ['de' => $ds['code'], 'vers' => $dd['code']])) ?></span><?php endif; ?>
                            <?php if ($tr['auteur']): ?><span class="tre-sous-texte"><?= e(t('tresorerie.par', ['nom' => $tr['auteur']])) ?></span><?php endif; ?>
                        </td>
                        <td class="nombre montant" data-libelle="<?= e(t('transferts.col_envoye')) ?> :"><?= $ds ? e(A::montant($tr['montant_source'], $ds)) : '' ?></td>
                        <td class="nombre montant" data-libelle="<?= e(t('transferts.col_recu')) ?> :">
                            <?= $dd ? e(A::montant($tr['montant_destination'], $dd)) : '' ?>
                            <?php if ($change): ?><span class="tre-sous-texte"><?= e(A::taux($ds['code'], $dd['code'], (float) $tr['taux'])) ?></span><?php endif; ?>
                        </td>
                        <td><?= e((string) $tr['motif']) ?></td>
                        <td>
                            <details class="tre-supprimer tre-supprimer--ligne">
                                <summary class="lien"><?= e(t('transferts.annuler')) ?></summary>
                                <form method="post" action="/gestion/tresorerie/transferts/<?= (int) $tr['id'] ?>/supprimer">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <p><?= e(t('transferts.annuler_texte')) ?></p>
                                    <button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('transferts.annuler_oui')) ?></button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script src="/assets/js/gestion-tresorerie.js" defer></script>
