<?php
/**
 * Paramètres > Utilisateurs > Appareils de caisse partagée.
 * @var list<array> $appareils
 * @var ?string $flash
 * @var list<array> $etablissements établissements dont on peut régler les caisses
 */
use App\Core\View;
$jeton = e(App\Core\Session::jetonCsrf());
$date = static fn (?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';
?>
<div class="uti-tete">
    <div>
        <h1><?= e(t('appareils.titre')) ?></h1>
        <p class="aide"><?= e(t('appareils.aide')) ?></p>
    </div>
</div>
<?= View::rendre('gestion/utilisateurs/_onglets', ['actif' => 'appareils']) ?>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<?php foreach ($etablissements as $etab): ?>
    <section class="bloc uti-reglages" id="reglages-<?= (int) $etab['id'] ?>" aria-labelledby="reglages-titre-<?= (int) $etab['id'] ?>">
        <h2 id="reglages-titre-<?= (int) $etab['id'] ?>"><?= e($etab['nom']) ?></h2>
        <form method="post" action="/gestion/utilisateurs/appareils/reglages/<?= (int) $etab['id'] ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <fieldset class="uti-groupe">
                <legend><?= e(t('verrou.verrouillage')) ?></legend>
                <label class="coche">
                    <input type="checkbox" name="verrou_apres_encaissement" value="1" <?= $etab['verrou_apres_encaissement'] ? 'checked' : '' ?>>
                    <span><?= e(t('verrou.apres_encaissement')) ?><br><small class="uti-facultatif"><?= e(t('verrou.apres_encaissement_aide')) ?></small></span>
                </label>
                <div class="champ uti-champ-court">
                    <label for="minutes-<?= (int) $etab['id'] ?>"><?= e(t('verrou.minutes')) ?></label>
                    <select id="minutes-<?= (int) $etab['id'] ?>" name="verrou_minutes">
                        <?php foreach (App\Controllers\Gestion\AppareilController::MINUTES as $n): ?>
                            <option value="<?= $n ?>" <?= (int) $etab['verrou_minutes'] === $n ? 'selected' : '' ?>><?= e($n === 0 ? t('verrou.jamais') : t('verrou.n_minutes', ['n' => $n])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>
            <div class="uti-grille">
                <div class="champ">
                    <label for="message-fr-<?= (int) $etab['id'] ?>"><?= e(t('verrou.message_fr')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                    <textarea id="message-fr-<?= (int) $etab['id'] ?>" name="message_verrou_fr" maxlength="255" rows="2" lang="fr"><?= e($etab['message_verrou_fr'] ?? '') ?></textarea>
                </div>
                <div class="champ">
                    <label for="message-en-<?= (int) $etab['id'] ?>"><?= e(t('verrou.message_en')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                    <textarea id="message-en-<?= (int) $etab['id'] ?>" name="message_verrou_en" maxlength="255" rows="2" lang="en"><?= e($etab['message_verrou_en'] ?? '') ?></textarea>
                </div>
            </div>
            <p class="champ__aide"><?= e(t('verrou.message_aide')) ?></p>
            <label class="coche">
                <input type="checkbox" name="photo_a_la_caisse" value="1" <?= $etab['photo_a_la_caisse'] ? 'checked' : '' ?>>
                <span><?= e(t('verrou.photo')) ?></span>
            </label>
            <fieldset class="uti-groupe">
                <legend><?= e(t('session.reglage')) ?></legend>
                <label class="coche">
                    <input type="checkbox" name="sessions_caisse" value="1" <?= !empty($etab['sessions_caisse']) ? 'checked' : '' ?>>
                    <span><?= e(t('session.reglage_texte')) ?><br><small class="uti-facultatif"><?= e(t('session.reglage_aide')) ?></small></span>
                </label>
            </fieldset>
            <div class="uti-actions">
                <button class="bouton" type="submit"><?= e(t('utilisateurs.enregistrer')) ?></button>
            </div>
        </form>
    </section>
<?php endforeach; ?>

<h2 class="uti-sous-titre"><?= e(t('appareils.liste')) ?></h2>

<?php if ($appareils === []): ?>
    <p class="bloc aide"><?= e(t('appareils.aucun')) ?></p>
<?php else: ?>
    <ul class="uti-liste">
        <?php foreach ($appareils as $a): ?>
            <li class="uti-carte uti-carte--appareil<?= $a['actif'] ? '' : ' uti-carte--inactive' ?>">
                <span class="uti-carte__texte">
                    <span class="uti-carte__nom"><?= e($a['nom']) ?></span>
                    <span class="uti-carte__info"><?= e($a['etablissement']) ?> · <?= e(t('appareils.autorise', ['nom' => $a['autorise_par'] ?? '—', 'date' => $date($a['cree_le'])])) ?></span>
                    <span class="uti-carte__info"><?= e(t('appareils.dernier_usage', ['date' => $date($a['dernier_usage'])])) ?><?= (int) $a['pin_echecs'] > 0 ? ' · ' . e(t('appareils.echecs', ['n' => (int) $a['pin_echecs']])) : '' ?></span>
                </span>
                <span class="uti-carte__etat">
                    <?php if ($a['actif']): ?>
                        <form method="post" action="/gestion/utilisateurs/appareils/<?= (int) $a['id'] ?>/retirer">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="bouton bouton--petit bouton--secondaire" type="submit"><?= e(t('appareils.retirer')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge--neutre"><?= e(t('appareils.retire_court')) ?></span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<p class="aide"><?= e(t('appareils.comment')) ?></p>
<?php // Mode hors ligne de la caisse : ce que l'appareil fait sans réseau ?>
<h2 class="uti-sous-titre"><?= e(t('appareils.hors_ligne_titre')) ?></h2>
<p class="aide"><?= e(t('appareils.hors_ligne')) ?></p>
