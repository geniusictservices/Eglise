<?php
/**
 * Nouveau remboursement, étape 2 : le client choisi, ce qu'il doit (dettes ouvertes, les plus anciennes d'abord),
 * puis l'établissement, le compte (groupé par type : ceux de l'établissement et les comptes communs), la devise
 * que ce compte tient, le montant et la date. Une clé (UUID) empêche de compter deux fois un double envoi.
 * @var array $client
 * @var list<array> $dettes ouvertes, dans la portée (dettes.rembourser)
 * @var array<int, float> $encours
 * @var array $valeurs etablissement_id, compte_id, devise_id, montant, date, cle
 * @var array<string, string> $erreurs
 * @var array $etablissements
 * @var array<int, array> $comptes
 * @var array<int, array> $devises devises de la trésorerie
 * @var array<int, array> $devisesAffichage
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ, string $aide = ''): string => isset($erreurs[$champ])
    ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . ($aide !== '' ? ' ' . $aide : '') . '"'
    : ($aide !== '' ? ' aria-describedby="' . $aide . '"' : '');
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$aujourdhui = date('Y-m-d');
$compteChoisi = $valeurs['compte_id'] !== null ? ($comptes[$valeurs['compte_id']] ?? null) : null;
$parType = [];
foreach ($comptes as $compte) {
    $parType[$compte['type']][] = $compte;
}
$codes = array_map(static fn (array $d) => $d['code'], $devises);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/remboursements/nouveau">← <?= e(t('gremb.autre_client')) ?></a></p>
<h1><?= e(t('gremb.nouveau')) ?></h1>

<?php if (isset($erreurs['client'])): ?>
    <p class="alerte alerte--erreur" role="alert" id="erreurs" tabindex="-1"><?= e($erreurs['client']) ?></p>
<?php else: ?>
    <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
<?php endif; ?>

<section class="bloc det-client-choisi" aria-labelledby="det-client-titre">
    <div class="det-client-choisi__tete">
        <div>
            <p class="surtitre"><?= e(t('gremb.client')) ?></p>
            <h2 id="det-client-titre"><a href="/gestion/clients/<?= (int) $client['id'] ?>"><?= e($client['nom']) ?></a></h2>
            <?php if ($client['telephone']): ?><p class="aide montant"><?= e(afficherTelephone($client['telephone'])) ?></p><?php endif; ?>
        </div>
        <div class="det-client-choisi__encours">
            <span class="surtitre"><?= e(t('gremb.doit')) ?></span>
            <strong class="montant" data-encours-total><?= e($encours !== [] ? D::montants($encours, $devisesAffichage) : t('gclients.rien_du')) ?></strong>
        </div>
    </div>
    <?php if ($dettes !== []): ?>
        <p class="aide"><?= e(t('gremb.ordre_aide')) ?></p>
        <ol class="det-lignes det-lignes--numerotees">
            <?php foreach ($dettes as $d): $retard = $d['echeance'] < $aujourdhui; ?>
                <li data-dette="<?= (int) $d['id'] ?>">
                    <span>
                        <strong><?= e(t('gdettes.fiche_titre', ['id' => $d['id']])) ?></strong>
                        <span class="tre-sous-texte"><?= e(A::jour($d['cree_le'])) ?> · <?= e($d['etablissement_nom']) ?><?= $d['vente_numero'] ? ' · ' . e($d['vente_numero']) : '' ?>
                            · <span class="<?= $retard ? 'det-retard' : '' ?>"><?= e(t($retard ? 'gdettes.echeance_depassee' : 'gdettes.echeance_le', ['date' => A::jour($d['echeance'])])) ?></span></span>
                    </span>
                    <strong class="montant"><?= e(D::montant($d['reste'], $d['devise_id'], $devisesAffichage)) ?></strong>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php if ($dettes !== []): ?>
<form method="post" action="/gestion/remboursements/nouveau" class="tre-formulaire det-form-remb" novalidate data-remboursement
      data-codes="<?= e((string) json_encode($codes)) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
    <input type="hidden" name="cle" value="<?= e($valeurs['cle']) ?>">

    <section class="bloc">
        <h2><?= e(t('gremb.section_paiement')) ?></h2>
        <?php if (count($etablissements) > 1): ?>
            <div class="champ">
                <label for="etablissement_id"><?= e(t('gremb.etablissement_encaisse')) ?></label>
                <select id="etablissement_id" name="etablissement_id" required data-etablissement<?= $invalide('etablissement_id') ?>>
                    <option value=""><?= e(t('gremb.choisir_etablissement')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= (int) $valeurs['etablissement_id'] === (int) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('etablissement_id') ?>
            </div>
        <?php elseif ($etablissements !== []): ?>
            <input type="hidden" name="etablissement_id" value="<?= (int) $etablissements[0]['id'] ?>" data-etablissement>
            <p class="aide"><?= e(t('gremb.encaisse_a', ['nom' => $etablissements[0]['nom']])) ?></p>
        <?php endif; ?>

        <div class="det-ligne-paiement">
            <div class="champ">
                <label for="compte_id"><?= e(t('gremb.col_compte')) ?></label>
                <select id="compte_id" name="compte_id" required data-compte<?= $invalide('compte_id', 'compte-aide') ?>>
                    <option value=""><?= e(t('gremb.choisir_compte')) ?></option>
                    <?php foreach (GestionTresorerieRepository::TYPES as $type): if (empty($parType[$type])) { continue; } ?>
                        <optgroup label="<?= e(t('tresorerie.type_' . $type)) ?>">
                            <?php foreach ($parType[$type] as $compte):
                                $tenues = array_values(array_map(static fn (array $l) => (int) $l['devise_id'], array_filter($compte['devises'], static fn (array $l) => $l['tenue'] && isset($devises[$l['devise_id']]))));
                                ?>
                                <option value="<?= (int) $compte['id'] ?>" data-etablissement="<?= $compte['etablissement_id'] !== null ? (int) $compte['etablissement_id'] : '' ?>"
                                        data-devises="<?= e((string) json_encode($tenues)) ?>"
                                        <?= (int) $valeurs['compte_id'] === (int) $compte['id'] ? 'selected' : '' ?>><?= e($compte['nom'] . ($compte['etablissement_id'] === null ? ' · ' . t('gremb.compte_commun') : '')) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <p class="champ__aide" id="compte-aide"><?= e(t('gremb.compte_aide')) ?></p>
                <?= $erreurChamp('compte_id') ?>
            </div>
            <div class="champ">
                <label for="devise_id"><?= e(t('gestion.devise')) ?></label>
                <select id="devise_id" name="devise_id" required data-devise<?= $invalide('devise_id') ?>>
                    <?= View::rendre('gestion/tresorerie/_options-devises', ['compte' => $compteChoisi, 'devises' => $devises, 'choisie' => $valeurs['devise_id']]) ?>
                </select>
                <?= $erreurChamp('devise_id') ?>
            </div>
            <div class="champ">
                <label for="montant"><?= e(t('gdettes.col_montant')) ?></label>
                <div class="tre-montant">
                    <input id="montant" name="montant" inputmode="decimal" autocomplete="off" required value="<?= e($valeurs['montant']) ?>"<?= $invalide('montant') ?>>
                </div>
                <?= $erreurChamp('montant') ?>
            </div>
        </div>
        <div class="champ tre-champ-moyen">
            <label for="date"><?= e(t('gremb.date_recu')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>" max="<?= e(date('Y-m-d\T23:59')) ?>"<?= $invalide('date', 'date-aide') ?>>
            <p class="champ__aide" id="date-aide"><?= e(t('gremb.date_aide')) ?></p>
            <?= $erreurChamp('date') ?>
        </div>
    </section>

    <div class="tre-actions">
        <button class="bouton" type="submit"><?= e(t('gremb.enregistrer')) ?></button>
        <a class="bouton bouton--secondaire" href="/gestion/clients/<?= (int) $client['id'] ?>"><?= e(t('tresorerie.annuler')) ?></a>
    </div>
</form>
<script src="/assets/js/gestion-dettes.js" defer></script>
<?php endif; ?>
