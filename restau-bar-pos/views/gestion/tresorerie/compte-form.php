<?php
/**
 * Création ou modification d'un compte de trésorerie.
 * @var ?array $compte null pour un nouveau compte
 * @var array $valeurs
 * @var array<string, string> $erreurs par champ (devise-<id> pour une devise, devises pour la liste)
 * @var array<int, array> $choixDevises devises proposées (actives, plus celles que le compte tient encore)
 * @var array $etablissements
 */

use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$action = $compte ? '/gestion/tresorerie/comptes/' . (int) $compte['id'] . '/modifier' : '/gestion/tresorerie/comptes/nouveau';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
$operateurs = ['Vodacom M-Pesa', 'Airtel Money', 'Orange Money', 'Africell Money', 'Rawbank', 'Equity BCDC', 'TMB', 'Ecobank', 'Access Bank', 'FBNBank', 'Sofibanque', 'UBA'];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<p class="tre-retour">
    <?php if ($compte): ?>
        <a class="lien" href="/gestion/tresorerie/comptes/<?= (int) $compte['id'] ?>">← <?= e($compte['nom']) ?></a>
    <?php else: ?>
        <a class="lien" href="/gestion/tresorerie">← <?= e(t('tresorerie.titre')) ?></a>
    <?php endif; ?>
</p>
<h1><?= e($compte ? t('tresorerie.modifier_compte', ['nom' => $compte['nom']]) : t('tresorerie.nouveau_compte')) ?></h1>
<p class="aide"><?= e(t('tresorerie.compte_aide')) ?></p>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<section class="bloc">
    <form method="post" action="<?= e($action) ?>" class="tre-formulaire" id="formulaire-compte" novalidate>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">

        <fieldset class="tre-groupe-champs"<?= isset($erreurs['type']) ? ' aria-describedby="erreur-type"' : '' ?>>
            <legend><?= e(t('tresorerie.champ_type')) ?></legend>
            <div class="tre-moyens">
                <?php foreach (App\Repositories\GestionTresorerieRepository::TYPES as $type): ?>
                    <label class="tre-moyen">
                        <input type="radio" name="type" value="<?= e($type) ?>" <?= $valeurs['type'] === $type ? 'checked' : '' ?>>
                        <span><?= View::rendre('gestion/tresorerie/_icone', ['type' => $type]) ?><?= e(t('tresorerie.type_' . $type)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?= $erreurChamp('type') ?>
        </fieldset>

        <div class="champ">
            <label for="nom"><?= e(t('tresorerie.champ_nom')) ?></label>
            <input id="nom" name="nom" required maxlength="128" autocomplete="off" value="<?= e($valeurs['nom']) ?>"
                   placeholder="<?= e(t('tresorerie.champ_nom_exemple')) ?>"<?= $invalide('nom') ?>>
            <p class="champ__aide"><?= e(t('tresorerie.champ_nom_aide')) ?></p>
            <?= $erreurChamp('nom') ?>
        </div>

        <div class="tre-paire">
            <div class="champ">
                <label for="operateur"><?= e(t('tresorerie.champ_operateur')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="operateur" name="operateur" maxlength="128" list="liste-operateurs" autocomplete="off"
                       value="<?= e((string) $valeurs['operateur']) ?>"<?= $invalide('operateur') ?>>
                <datalist id="liste-operateurs">
                    <?php foreach ($operateurs as $operateur): ?><option value="<?= e($operateur) ?>"></option><?php endforeach; ?>
                </datalist>
                <p class="champ__aide"><?= e(t('tresorerie.champ_operateur_aide')) ?></p>
                <?= $erreurChamp('operateur') ?>
            </div>
            <div class="champ">
                <label for="numero"><?= e(t('tresorerie.champ_numero')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="numero" name="numero" maxlength="64" autocomplete="off" value="<?= e((string) $valeurs['numero']) ?>"
                       placeholder="+243 81 000 0000"<?= $invalide('numero') ?>>
                <p class="champ__aide"><?= e(t('tresorerie.champ_numero_aide')) ?></p>
                <?= $erreurChamp('numero') ?>
            </div>
        </div>

        <div class="champ">
            <label for="etablissement_id"><?= e(t('gestion.etablissement')) ?></label>
            <select id="etablissement_id" name="etablissement_id"<?= $invalide('etablissement_id') ?>>
                <option value=""><?= e(t('tresorerie.tous_etablissements')) ?></option>
                <?php foreach ($etablissements as $etab): ?>
                    <option value="<?= (int) $etab['id'] ?>" <?= (int) ($valeurs['etablissement_id'] ?? 0) === (int) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $erreurChamp('etablissement_id') ?>
        </div>

        <fieldset class="tre-groupe-champs" id="devises"<?= isset($erreurs['devises']) ? ' aria-describedby="erreur-devises"' : '' ?>>
            <legend><?= e(t('tresorerie.champ_devises')) ?></legend>
            <p class="champ__aide tre-devises__aide"><?= e(t('tresorerie.champ_devises_aide')) ?></p>
            <div class="tre-devises">
                <?php foreach ($choixDevises as $deviseId => $devise):
                    $saisie = $valeurs['devises'][$deviseId] ?? ['tenue' => false, 'solde_initial' => ''];
                    $existante = $compte['devises'][$deviseId] ?? null;
                    // Une devise qui a des mouvements (hors solde initial) ne peut plus être retirée
                    $verrouillee = $existante !== null && $existante['tenue'] && $existante['nb_mouvements'] > 0;
                    $cle = 'devise-' . $deviseId;
                    $idSolde = 'devise-' . $deviseId . '-solde';
                    ?>
                    <div class="tre-devise<?= $saisie['tenue'] ? ' tre-devise--tenue' : '' ?>" data-devise-ligne>
                        <label class="coche tre-devise__coche">
                            <input type="checkbox" name="devises[<?= (int) $deviseId ?>][tenue]" value="1" data-devise-tenue
                                <?= $saisie['tenue'] ? 'checked' : '' ?><?= $verrouillee ? ' disabled aria-describedby="' . $cle . '-verrou"' : '' ?>>
                            <span><strong><?= e($devise['code']) ?></strong> · <?= e(champ($devise, 'nom')) ?></span>
                        </label>
                        <?php if ($verrouillee): ?>
                            <input type="hidden" name="devises[<?= (int) $deviseId ?>][tenue]" value="1">
                        <?php endif; ?>
                        <div class="champ tre-devise__solde" data-devise-solde<?= $saisie['tenue'] ? '' : ' hidden' ?>>
                            <label for="<?= $idSolde ?>"><?= e(t('tresorerie.solde_initial_en', ['code' => $devise['code']])) ?></label>
                            <div class="tre-montant">
                                <input id="<?= $idSolde ?>" name="devises[<?= (int) $deviseId ?>][solde_initial]" inputmode="decimal" autocomplete="off"
                                       value="<?= e((string) $saisie['solde_initial']) ?>" placeholder="0"
                                       <?= isset($erreurs[$cle]) ? 'aria-invalid="true" aria-describedby="erreur-' . $cle . '"' : '' ?>>
                                <span class="tre-montant__devise"><?= e($devise['symbole']) ?></span>
                            </div>
                        </div>
                        <?php if ($verrouillee): ?>
                            <p class="champ__aide tre-devise__verrou" id="<?= $cle ?>-verrou"><?= e(t('tresorerie.devise_verrouillee')) ?></p>
                        <?php endif; ?>
                        <?= $erreurChamp($cle) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?= $erreurChamp('devises') ?>
        </fieldset>

        <div class="champ tre-champ-moyen">
            <label for="solde_initial_le"><?= e(t('tresorerie.champ_solde_initial_le')) ?></label>
            <input type="date" id="solde_initial_le" name="solde_initial_le" value="<?= e((string) $valeurs['solde_initial_le']) ?>"
                   aria-describedby="aide-solde-initial-le<?= isset($erreurs['solde_initial_le']) ? ' erreur-solde_initial_le" aria-invalid="true' : '' ?>">
            <p class="champ__aide" id="aide-solde-initial-le"><?= e(t('tresorerie.champ_solde_initial_aide')) ?></p>
            <?= $erreurChamp('solde_initial_le') ?>
        </div>

        <div class="champ tre-champ-court">
            <label for="position"><?= e(t('tresorerie.champ_position')) ?></label>
            <input id="position" name="position" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= e((string) $valeurs['position']) ?>"<?= $invalide('position') ?>>
            <p class="champ__aide"><?= e(t('tresorerie.champ_position_aide')) ?></p>
            <?= $erreurChamp('position') ?>
        </div>

        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e($compte ? t('tresorerie.enregistrer') : t('tresorerie.creer_compte')) ?></button>
            <a class="bouton bouton--secondaire" href="<?= $compte ? '/gestion/tresorerie/comptes/' . (int) $compte['id'] : '/gestion/tresorerie' ?>"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </form>
</section>

<?php if ($compte): ?>
    <section class="bloc">
        <h2><?= e(t('tresorerie.etat_titre')) ?></h2>
        <p><?= e($compte['active'] ? t('tresorerie.etat_actif') : t('tresorerie.etat_inactif')) ?></p>
        <div class="tre-actions">
            <form method="post" action="/gestion/tresorerie/comptes/<?= (int) $compte['id'] ?>/<?= $compte['active'] ? 'desactiver' : 'activer' ?>">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <button class="bouton bouton--secondaire" type="submit"><?= e($compte['active'] ? t('tresorerie.desactiver') : t('tresorerie.activer')) ?></button>
            </form>
        </div>
        <?php if ((int) $compte['nb_mouvements'] === 0): ?>
            <details class="tre-supprimer">
                <summary class="lien"><?= e(t('tresorerie.supprimer_compte')) ?></summary>
                <p><?= e(t('tresorerie.supprimer_compte_texte', ['nom' => $compte['nom']])) ?></p>
                <form method="post" action="/gestion/tresorerie/comptes/<?= (int) $compte['id'] ?>/supprimer">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton bouton--danger" type="submit"><?= e(t('tresorerie.supprimer_oui')) ?></button>
                </form>
            </details>
        <?php else: ?>
            <p class="aide"><?= e(t('tresorerie.supprimer_impossible')) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script src="/assets/js/gestion-tresorerie.js" defer></script>
