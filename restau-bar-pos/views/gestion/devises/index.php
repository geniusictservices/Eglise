<?php
/**
 * @var ?string $flash
 * @var array $devises
 * @var ?array $reference
 * @var array $prixReference
 * @var array $historique
 * @var array $enVigueur ids des taux en vigueur
 * @var string $maintenant
 * @var string $valableDepuis
 * @var string $valableDepuisDefaut
 * @var array $saisiesTaux
 * @var array $erreursTaux
 * @var string[] $erreurs
 * @var ?array $confirmation
 */

use App\Controllers\Gestion\DeviseAffichage;

$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$autres = array_values(array_filter($devises, static fn (array $d) => $d['active'] && !$d['est_reference']));
$exemple = $reference ? 10 * max(1, (float) $reference['arrondi']) : 10;
$exempleTexte = $reference ? $formateur->formater($exemple, $reference) : '';
$candidates = array_values(array_filter($devises, static fn (array $d) => !$d['est_reference'] && $d['active'] && $d['taux'] !== null));
?>
<link rel="stylesheet" href="/assets/css/gestion-devises.css">

<div class="dev-entete">
    <div>
        <h1><?= e(t('devises.titre')) ?></h1>
        <p class="aide"><?= e(t('devises.aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/devises/nouvelle">+ <?= e(t('devises.ajouter')) ?></a>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?= App\Core\View::rendre('gestion/devises/_erreurs', ['erreurs' => $erreurs]) ?>

<?php if ($confirmation !== null): $cible = $confirmation['devise']; ?>
    <section class="dev-confirmation" role="alertdialog" aria-labelledby="dev-confirmation-titre" id="confirmation">
        <?php if ($confirmation['type'] === 'desactiver'): ?>
            <h2 id="dev-confirmation-titre"><?= e(t('devises.confirmer_desactiver_titre', ['code' => $cible['code']])) ?></h2>
            <p><?= e(t('devises.confirmer_desactiver_texte', ['code' => $cible['code']])) ?></p>
            <ul>
                <?php foreach ($confirmation['etablissements'] as $etab): ?>
                    <li><?= e($etab['nom']) ?><?php if ($etab['par_defaut']): ?> · <strong><?= e(t('devises.devise_par_defaut')) ?></strong><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="aide"><?= e(t('devises.confirmer_desactiver_aide')) ?></p>
            <div class="dev-actions">
                <form method="post" action="/gestion/devises/<?= (int) $cible['id'] ?>/desactiver">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <input type="hidden" name="confirme" value="1">
                    <button class="bouton dev-bouton-danger" type="submit"><?= e(t('devises.desactiver_oui', ['code' => $cible['code']])) ?></button>
                </form>
                <a class="bouton bouton--secondaire" href="/gestion/devises#devise-<?= (int) $cible['id'] ?>"><?= e(t('devises.annuler')) ?></a>
            </div>
        <?php else: $ancienne = $confirmation['ancienne']; ?>
            <h2 id="dev-confirmation-titre"><?= e(t('devises.confirmer_reference_titre', ['code' => $cible['code'], 'ancienne' => $ancienne['code']])) ?></h2>
            <p><?= e(t('devises.confirmer_reference_texte', ['code' => $cible['code']])) ?></p>
            <ul class="dev-liste-taux">
                <?php foreach ($confirmation['nouveauxTaux'] as $code => $taux): ?>
                    <li class="nombre">1 <?= e($cible['code']) ?> = <?= e(DeviseAffichage::taux(round($taux, 6))) ?> <?= e($code) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="aide"><?= e(t('devises.confirmer_reference_aide')) ?></p>
            <div class="dev-actions">
                <form method="post" action="/gestion/devises/reference">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <input type="hidden" name="devise_id" value="<?= (int) $cible['id'] ?>">
                    <input type="hidden" name="confirme" value="1">
                    <button class="bouton" type="submit"><?= e(t('devises.reference_oui', ['code' => $cible['code']])) ?></button>
                </form>
                <a class="bouton bouton--secondaire" href="/gestion/devises"><?= e(t('devises.annuler')) ?></a>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($reference === null): ?>
    <section class="bloc">
        <h2><?= e(t('devises.premiers_pas')) ?></h2>
        <p><?= e(t('devises.premiers_pas_texte')) ?></p>
        <p><a class="bouton" href="/gestion/devises/nouvelle">+ <?= e(t('devises.ajouter_reference')) ?></a></p>
    </section>
<?php else: ?>

<section class="dev-reference" aria-labelledby="dev-reference-titre">
    <p class="dev-reference__surtitre" id="dev-reference-titre"><?= e(t('devises.reference_titre')) ?></p>
    <p class="dev-reference__nom">
        <span class="dev-reference__symbole" aria-hidden="true"><?= e($reference['symbole']) ?></span>
        <span><?= e(champ($reference, 'nom')) ?> <span class="dev-code">(<?= e($reference['code']) ?>)</span></span>
    </p>
    <p class="aide"><?= e(t('devises.reference_explication', ['code' => $reference['code']])) ?></p>

    <details class="dev-depliant">
        <summary class="bouton bouton--secondaire"><?= e(t('devises.changer_reference')) ?></summary>
        <div class="dev-depliant__corps">
            <?php if ($prixReference['prix'] > 0): ?>
                <p><?= e(t('devises.reference_bloquee', ['code' => $reference['code'], 'prix' => $prixReference['prix'], 'produits' => $prixReference['produits']])) ?></p>
                <p class="aide"><a class="lien" href="/gestion/catalogue"><?= e(t('devises.aller_catalogue')) ?></a></p>
            <?php elseif ($candidates === []): ?>
                <p><?= e(t('devises.reference_aucune_candidate')) ?></p>
            <?php else: ?>
                <p><?= e(t('devises.reference_changer_texte')) ?></p>
                <form method="post" action="/gestion/devises/reference#confirmation" class="formulaire formulaire--ligne">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ">
                        <label for="reference-devise"><?= e(t('devises.nouvelle_reference')) ?></label>
                        <select id="reference-devise" name="devise_id" required>
                            <?php foreach ($candidates as $devise): ?>
                                <option value="<?= (int) $devise['id'] ?>"><?= e($devise['code'] . ' · ' . champ($devise, 'nom')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="bouton bouton--secondaire" type="submit"><?= e(t('devises.continuer')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </details>
</section>

<section class="bloc dev-taux" id="taux" aria-labelledby="dev-taux-titre">
    <h2 id="dev-taux-titre"><?= e(t('devises.taux_du_jour')) ?></h2>
    <?php if ($autres === []): ?>
        <p class="aide"><?= e(t('devises.taux_aucune_devise')) ?></p>
    <?php else: ?>
        <p class="aide"><?= e(t('devises.taux_aide', ['code' => $reference['code']])) ?></p>
        <?php if ($erreursTaux !== []): ?>
            <?= App\Core\View::rendre('gestion/devises/_erreurs', ['erreurs' => array_values($erreursTaux)]) ?>
        <?php endif; ?>
        <form method="post" action="/gestion/devises/taux#taux" class="formulaire dev-taux__formulaire" id="formulaire-taux"
              data-reference-code="<?= e($reference['code']) ?>"
              data-exemple="<?= e((string) $exemple) ?>"
              data-exemple-texte="<?= e($exempleTexte) ?>"
              data-langue="<?= e(App\Core\I18n::langue()) ?>"
              data-texte-invalide="<?= e(t('devises.exemple_invalide')) ?>"
              data-texte-modifie="<?= e(t('devises.modifie')) ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <?php foreach ($autres as $devise):
                $id = (int) $devise['id'];
                $valeur = $saisiesTaux[$id] ?? DeviseAffichage::tauxSaisie($devise['taux']);
                $erreur = $erreursTaux['taux-' . $id] ?? null;
                ?>
                <div class="dev-ligne-taux" data-devise
                     data-decimales="<?= (int) $devise['decimales'] ?>"
                     data-arrondi="<?= e((string) (float) $devise['arrondi']) ?>"
                     data-symbole="<?= e($devise['symbole']) ?>"
                     data-avant="<?= $devise['symbole_avant'] ? '1' : '0' ?>"
                     data-initial="<?= e(DeviseAffichage::tauxSaisie($devise['taux'])) ?>">
                    <label class="dev-ligne-taux__nom" for="taux-<?= $id ?>">
                        <?= e(champ($devise, 'nom')) ?> <span class="dev-code"><?= e($devise['code']) ?></span>
                    </label>
                    <div class="dev-ligne-taux__saisie">
                        <span class="dev-ligne-taux__un" aria-hidden="true">1 <?= e($reference['code']) ?> =</span>
                        <input id="taux-<?= $id ?>" name="taux[<?= $id ?>]" inputmode="decimal" autocomplete="off"
                               value="<?= e($valeur) ?>" placeholder="<?= e(t('devises.taux_placeholder')) ?>"
                               aria-describedby="exemple-<?= $id ?> depuis-<?= $id ?>"
                               <?= $erreur ? 'aria-invalid="true"' : '' ?>>
                        <span class="dev-ligne-taux__code" aria-hidden="true"><?= e($devise['code']) ?></span>
                    </div>
                    <p class="dev-ligne-taux__exemple" id="exemple-<?= $id ?>" aria-live="polite">
                        <?php
                        $tauxExemple = $devise['taux'] !== null ? (float) $devise['taux'] : null;
                        if ($tauxExemple !== null):
                            ?><?= e($exempleTexte) ?> → <?= e($formateur->formater($formateur->arrondir($exemple * $tauxExemple, $devise), $devise)) ?><?php
                        else:
                            ?><?= e(t('devises.exemple_vide')) ?><?php
                        endif; ?>
                    </p>
                    <p class="dev-ligne-taux__depuis" id="depuis-<?= $id ?>">
                        <?php if ($devise['taux'] === null): ?>
                            <span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span>
                        <?php else: ?>
                            <?= e(t('devises.en_vigueur_depuis', ['date' => DeviseAffichage::dateHeure($devise['taux_depuis'])])) ?>
                        <?php endif; ?>
                        <span class="pastille dev-modifie" hidden><?= e(t('devises.modifie')) ?></span>
                    </p>
                    <?php if ($erreur): ?><p class="dev-erreur-champ"><?= e($erreur) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="dev-taux__pied">
                <div class="champ">
                    <label for="valable_depuis"><?= e(t('devises.valable_depuis')) ?></label>
                    <input type="datetime-local" id="valable_depuis" name="valable_depuis"
                           value="<?= e($valableDepuis !== '' ? $valableDepuis : $valableDepuisDefaut) ?>"
                           <?= isset($erreursTaux['valable_depuis']) ? 'aria-invalid="true"' : '' ?>>
                    <p class="aide"><?= e(t('devises.valable_depuis_aide')) ?></p>
                </div>
                <button class="bouton dev-taux__bouton" type="submit"><?= e(t('devises.enregistrer_taux')) ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php endif; ?>

<section class="bloc" id="liste" aria-labelledby="dev-liste-titre">
    <h2 id="dev-liste-titre"><?= e(t('devises.liste')) ?></h2>
    <div class="dev-defile">
        <table class="tableau dev-tableau">
            <thead>
            <tr>
                <th><?= e(t('devises.col_devise')) ?></th>
                <th><?= e(t('devises.col_affichage')) ?></th>
                <th><?= e(t('devises.col_decimales')) ?></th>
                <th><?= e(t('devises.col_arrondi')) ?></th>
                <th><?= e(t('devises.col_taux')) ?></th>
                <th><?= e(t('devises.col_position')) ?></th>
                <th><?= e(t('devises.col_etat')) ?></th>
                <th><span class="dev-cache"><?= e(t('devises.col_actions')) ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($devises as $devise): $id = (int) $devise['id']; $nbEtab = (int) $devise['nb_etablissements']; ?>
                <tr id="devise-<?= $id ?>" class="<?= $devise['active'] ? '' : 'dev-inactive' ?>">
                    <td class="dev-tableau__devise">
                        <strong class="dev-code"><?= e($devise['code']) ?></strong> · <?= e(champ($devise, 'nom')) ?>
                        <?php if ($devise['nom_en'] !== '' && $devise['nom_en'] !== $devise['nom_fr']): ?>
                            <span class="dev-sous-texte" lang="<?= App\Core\I18n::langue() === 'fr' ? 'en' : 'fr' ?>"><?= e(App\Core\I18n::langue() === 'fr' ? $devise['nom_en'] : $devise['nom_fr']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="nombre">
                        <?= e($formateur->formater(1234.5, $devise, false)) ?>
                        <span class="dev-sous-texte"><?= e(t($devise['symbole_avant'] ? 'devises.symbole_avant_court' : 'devises.symbole_apres_court', ['symbole' => $devise['symbole']])) ?></span>
                    </td>
                    <td class="nombre"><?= (int) $devise['decimales'] ?></td>
                    <td class="nombre"><?= (float) $devise['arrondi'] > 0 ? e(DeviseAffichage::arrondi($devise['arrondi'])) : e(t('devises.arrondi_aucun')) ?></td>
                    <td class="nombre">
                        <?php if ($devise['est_reference'] || $reference === null): ?>—
                        <?php elseif ($devise['taux'] === null): ?><span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span>
                        <?php else: ?>1 <?= e($reference['code']) ?> = <?= e(DeviseAffichage::taux($devise['taux'])) ?> <?= e($devise['code']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="nombre"><?= (int) $devise['position'] ?></td>
                    <td>
                        <?php if ($devise['est_reference']): ?>
                            <span class="dev-etat dev-etat--reference"><?= e(t('gestion.reference')) ?></span>
                        <?php elseif ($devise['active']): ?>
                            <span class="dev-etat dev-etat--active"><?= e(t('devises.active')) ?></span>
                        <?php else: ?>
                            <span class="dev-etat"><?= e(t('devises.inactive')) ?></span>
                        <?php endif; ?>
                        <span class="dev-sous-texte"><?= e($nbEtab === 0 ? t('devises.etablissements_0') : ($nbEtab === 1 ? t('devises.etablissements_1') : t('devises.etablissements_n', ['n' => $nbEtab]))) ?></span>
                    </td>
                    <td>
                        <div class="dev-actions dev-actions--ligne">
                            <a class="bouton bouton--secondaire dev-petit" href="/gestion/devises/<?= $id ?>"
                               aria-label="<?= e(t('devises.modifier_code', ['code' => $devise['code']])) ?>"><?= e(t('devises.modifier')) ?></a>
                            <?php if ($devise['est_reference']):
                                // La référence reste toujours active
                            elseif ($devise['active']): ?>
                                <form method="post" action="/gestion/devises/<?= $id ?>/desactiver#confirmation">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <button class="bouton bouton--secondaire dev-petit" type="submit"
                                            aria-label="<?= e(t('devises.desactiver_code', ['code' => $devise['code']])) ?>"><?= e(t('devises.desactiver')) ?></button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/gestion/devises/<?= $id ?>/activer">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <button class="bouton dev-petit" type="submit"
                                            aria-label="<?= e(t('devises.activer_code', ['code' => $devise['code']])) ?>"><?= e(t('devises.activer')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="aide"><?= e(t('devises.liste_aide')) ?></p>
</section>

<section class="bloc" id="historique" aria-labelledby="dev-historique-titre">
    <h2 id="dev-historique-titre"><?= e(t('devises.historique')) ?></h2>
    <?php if ($historique === []): ?>
        <p class="aide"><?= e(t('devises.historique_vide')) ?></p>
    <?php else: ?>
        <div class="dev-defile">
            <table class="tableau">
                <thead>
                <tr>
                    <th><?= e(t('devises.col_saisi_le')) ?></th>
                    <th><?= e(t('devises.col_devise')) ?></th>
                    <th><?= e(t('devises.col_taux_saisi')) ?></th>
                    <th><?= e(t('devises.col_valable')) ?></th>
                    <th><?= e(t('devises.col_par')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($historique as $ligne): ?>
                    <tr>
                        <td class="nombre"><?= e(DeviseAffichage::dateHeureBase($ligne['cree_le'])) ?></td>
                        <td><?= e($ligne['code']) ?></td>
                        <td class="nombre">1 <?= e($ligne['reference_code']) ?> = <?= e(DeviseAffichage::taux($ligne['taux'])) ?> <?= e($ligne['code']) ?></td>
                        <td class="nombre">
                            <?= e(DeviseAffichage::dateHeure($ligne['valable_depuis'])) ?>
                            <?php if (isset($enVigueur[(int) $ligne['id']])): ?>
                                <span class="pastille"><?= e(t('devises.en_vigueur')) ?></span>
                            <?php elseif ($ligne['valable_depuis'] > $maintenant): ?>
                                <span class="pastille pastille--alerte"><?= e(t('devises.a_venir')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ligne['auteur'] !== null ? e($ligne['auteur']) : '<span class="aide">' . e(t('devises.par_installation')) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script src="/assets/js/gestion-devises.js" defer></script>
