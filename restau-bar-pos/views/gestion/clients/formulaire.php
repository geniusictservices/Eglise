<?php
/**
 * Création ou modification d'un client (clients.modifier).
 * @var ?array $client null pour un nouveau client
 * @var array $valeurs nom, telephone, email, anniversaire, langue, type, notes, plafond, plafond_devise_id, canal, relances, bloque
 * @var array<string, string> $erreurs
 * @var array<int, array> $devises devises actives
 */

use App\Core\View;
use App\Repositories\GestionDetteRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$action = $client ? '/gestion/clients/' . (int) $client['id'] . '/modifier' : '/gestion/clients/nouveau';
$retour = $client ? '/gestion/clients/' . (int) $client['id'] : '/gestion/clients';
$invalide = static fn (string $champ, string $aide = ''): string => isset($erreurs[$champ])
    ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . ($aide !== '' ? ' ' . $aide : '') . '"'
    : ($aide !== '' ? ' aria-describedby="' . $aide . '"' : '');
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
$facultatif = '<span class="tre-facultatif">' . e(t('tresorerie.facultatif')) . '</span>';
$devisePlafond = $devises[(int) $valeurs['plafond_devise_id']] ?? reset($devises) ?: null;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="<?= e($retour) ?>">← <?= e($client ? $client['nom'] : t('gclients.titre')) ?></a></p>
<h1><?= e($client ? t('gclients.modifier_titre', ['nom' => $client['nom']]) : t('gclients.nouveau')) ?></h1>
<p class="aide"><?= e(t('gclients.formulaire_aide')) ?></p>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= e($action) ?>" class="tre-formulaire" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">

    <section class="bloc">
        <h2><?= e(t('gclients.section_identite')) ?></h2>
        <div class="champ">
            <label for="nom"><?= e(t('gclients.champ_nom')) ?></label>
            <input id="nom" name="nom" maxlength="128" required autocomplete="off" value="<?= e($valeurs['nom']) ?>"
                   placeholder="<?= e(t('gclients.champ_nom_exemple')) ?>"<?= $invalide('nom') ?>>
            <?= $erreurChamp('nom') ?>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="telephone"><?= e(t('gclients.champ_telephone')) ?> <?= $facultatif ?></label>
                <input id="telephone" name="telephone" type="tel" inputmode="tel" maxlength="32" autocomplete="off" value="<?= e($valeurs['telephone']) ?>"
                       placeholder="+243 97 000 0000"<?= $invalide('telephone', 'telephone-aide') ?>>
                <p class="champ__aide" id="telephone-aide"><?= e(t('gclients.champ_telephone_aide')) ?></p>
                <?= $erreurChamp('telephone') ?>
            </div>
            <div class="champ">
                <label for="email"><?= e(t('gclients.champ_email')) ?> <?= $facultatif ?></label>
                <input id="email" name="email" type="email" maxlength="128" autocomplete="off" value="<?= e($valeurs['email']) ?>"
                       placeholder="<?= e(t('gclients.champ_email_exemple')) ?>"<?= $invalide('email') ?>>
                <?= $erreurChamp('email') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="type"><?= e(t('gclients.type')) ?></label>
                <select id="type" name="type"<?= $invalide('type', 'type-aide') ?>>
                    <?php foreach (GestionDetteRepository::TYPES_SAISIS as $type): ?>
                        <option value="<?= e($type) ?>" <?= $valeurs['type'] === $type ? 'selected' : '' ?>><?= e(t('gclients.type_' . $type)) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="champ__aide" id="type-aide"><?= e(t('gclients.type_aide')) ?></p>
                <?= $erreurChamp('type') ?>
            </div>
            <div class="champ">
                <label for="anniversaire"><?= e(t('gclients.champ_anniversaire')) ?> <?= $facultatif ?></label>
                <input id="anniversaire" name="anniversaire" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($valeurs['anniversaire']) ?>"<?= $invalide('anniversaire') ?>>
                <?= $erreurChamp('anniversaire') ?>
            </div>
        </div>
        <div class="champ">
            <label for="notes"><?= e(t('gclients.champ_notes')) ?> <?= $facultatif ?></label>
            <textarea id="notes" name="notes" rows="3" maxlength="5000" placeholder="<?= e(t('gclients.champ_notes_exemple')) ?>"<?= $invalide('notes') ?>><?= e($valeurs['notes']) ?></textarea>
            <?= $erreurChamp('notes') ?>
        </div>
    </section>

    <section class="bloc">
        <h2><?= e(t('gclients.section_messages')) ?></h2>
        <div class="tre-paire">
            <fieldset class="tre-groupe-champs">
                <legend><?= e(t('gclients.champ_langue')) ?></legend>
                <div class="tre-moyens tre-moyens--compact det-choix-2">
                    <?php foreach (['fr', 'en'] as $langue): ?>
                        <label class="tre-moyen"><input type="radio" name="langue" value="<?= $langue ?>" <?= $valeurs['langue'] === $langue ? 'checked' : '' ?>><span><?= e(t('gclients.langue_' . $langue)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="tre-groupe-champs">
                <legend><?= e(t('gclients.champ_canal')) ?></legend>
                <div class="tre-moyens tre-moyens--compact det-choix-2">
                    <?php foreach (['sms', 'whatsapp'] as $canal): ?>
                        <label class="tre-moyen"><input type="radio" name="canal" value="<?= $canal ?>" <?= $valeurs['canal'] === $canal ? 'checked' : '' ?>><span><?= e(t('messagerie.canal_' . $canal)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
        <label class="coche det-coche">
            <input type="checkbox" name="relances" value="1" <?= $valeurs['relances'] ? 'checked' : '' ?>>
            <span><?= e(t('gclients.champ_relances_case')) ?></span>
        </label>
        <p class="champ__aide"><?= e(t('gclients.champ_relances_aide')) ?></p>
    </section>

    <section class="bloc">
        <h2><?= e(t('gclients.section_credit')) ?></h2>
        <div class="det-plafond">
            <div class="champ">
                <label for="plafond"><?= e(t('gclients.plafond')) ?> <?= $facultatif ?></label>
                <div class="tre-montant">
                    <input id="plafond" name="plafond" inputmode="decimal" autocomplete="off" value="<?= e($valeurs['plafond']) ?>"
                           placeholder="<?= e($devisePlafond ? App\Controllers\Gestion\TresorerieSaisie::exemple($devisePlafond) : '') ?>"<?= $invalide('plafond', 'plafond-aide') ?>>
                </div>
                <?= $erreurChamp('plafond') ?>
            </div>
            <div class="champ">
                <label for="plafond_devise_id"><?= e(t('gestion.devise')) ?></label>
                <select id="plafond_devise_id" name="plafond_devise_id"<?= $invalide('plafond_devise_id') ?>>
                    <?php foreach ($devises as $did => $devise): ?>
                        <option value="<?= (int) $did ?>" <?= (int) $valeurs['plafond_devise_id'] === (int) $did ? 'selected' : '' ?>><?= e($devise['code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('plafond_devise_id') ?>
            </div>
        </div>
        <p class="champ__aide" id="plafond-aide"><?= e(t('gclients.plafond_aide')) ?></p>
        <label class="coche det-coche">
            <input type="checkbox" name="bloque" value="1" <?= $valeurs['bloque'] ? 'checked' : '' ?>>
            <span><?= e(t('gclients.champ_bloque_case')) ?></span>
        </label>
        <p class="champ__aide"><?= e(t('gclients.champ_bloque_aide')) ?></p>
    </section>

    <div class="tre-actions">
        <button class="bouton" type="submit"><?= e($client ? t('gclients.enregistrer_modifications') : t('gclients.creer')) ?></button>
        <a class="bouton bouton--secondaire" href="<?= e($retour) ?>"><?= e(t('tresorerie.annuler')) ?></a>
    </div>
</form>
