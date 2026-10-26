<?php
/**
 * Nouveau client (ou rattacher une instance existante) : contact, sous-domaine, formule, essai.
 * @var array $saisie
 * @var array<string, string> $erreurs
 * @var bool $rattacher
 * @var list<array> $formules
 * @var string $domaine
 */

use App\Console\Abonnement;
use App\Console\Affichage as A;
use App\Console\SessionConsole;

$err = static fn (string $cle): string => isset($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$inv = static fn (string $cle): string => isset($erreurs[$cle]) ? ' aria-invalid="true" aria-describedby="err-' . e($cle) . '"' : '';
?>
<p><a class="lien" href="/clients">← <?= e(t('console.clients.titre')) ?></a></p>
<h1><?= e(t($rattacher ? 'console.client.rattacher_titre' : 'console.client.nouveau_titre')) ?></h1>
<p class="aide"><?= e(t($rattacher ? 'console.client.rattacher_aide' : 'console.client.nouveau_aide')) ?></p>
<?php if ($erreurs !== []): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e(t('console.corriger')) ?></p>
<?php endif; ?>

<form method="post" action="/clients/nouveau" class="con-form" novalidate>
    <input type="hidden" name="csrf" value="<?= e(SessionConsole::jetonCsrf()) ?>">
    <section class="bloc">
        <h2><?= e(t('console.client.identite')) ?></h2>
        <div class="con-grille">
            <div class="champ"><label for="nom"><?= e(t('console.client.nom')) ?></label><input id="nom" name="nom" required value="<?= e($saisie['nom']) ?>"<?= $inv('nom') ?>><?= $err('nom') ?></div>
            <div class="champ">
                <label for="sous_domaine"><?= e(t('console.client.sous_domaine')) ?></label>
                <input id="sous_domaine" name="sous_domaine" required value="<?= e($saisie['sous_domaine']) ?>" pattern="[a-z0-9-]+" autocapitalize="none" spellcheck="false"<?= $rattacher ? ' readonly' : '' ?><?= $inv('sous_domaine') ?> aria-describedby="aide-sd">
                <p class="champ__aide" id="aide-sd"><?= e(t('console.client.sous_domaine_aide', ['domaine' => $domaine])) ?></p><?= $err('sous_domaine') ?>
            </div>
            <div class="champ"><label for="contact_nom"><?= e(t('console.client.contact_nom')) ?></label><input id="contact_nom" name="contact_nom" value="<?= e($saisie['contact_nom']) ?>" autocomplete="off"><?= $err('contact_nom') ?></div>
            <div class="champ"><label for="contact_email"><?= e(t('console.client.contact_email')) ?></label><input id="contact_email" name="contact_email" type="email" required value="<?= e($saisie['contact_email']) ?>"<?= $inv('contact_email') ?>><p class="champ__aide"><?= e(t('console.client.contact_email_aide')) ?></p><?= $err('contact_email') ?></div>
            <div class="champ"><label for="contact_telephone"><?= e(t('console.client.contact_telephone')) ?></label><input id="contact_telephone" name="contact_telephone" type="tel" value="<?= e($saisie['contact_telephone']) ?>" placeholder="+243 81 000 0000"<?= $inv('contact_telephone') ?>><p class="champ__aide"><?= e(t('console.client.contact_telephone_aide')) ?></p><?= $err('contact_telephone') ?></div>
            <div class="champ"><label for="langue"><?= e(t('console.client.langue')) ?></label>
                <select id="langue" name="langue"><option value="fr"<?= $saisie['langue'] === 'fr' ? ' selected' : '' ?>>Français</option><option value="en"<?= $saisie['langue'] === 'en' ? ' selected' : '' ?>>English</option></select>
            </div>
        </div>
        <div class="champ"><label for="notes"><?= e(t('console.client.notes')) ?></label><textarea id="notes" name="notes" rows="2"><?= e($saisie['notes']) ?></textarea></div>
    </section>

    <section class="bloc">
        <h2><?= e(t('console.client.abonnement')) ?></h2>
        <div class="con-grille">
            <div class="champ"><label for="formule_id"><?= e(t('console.client.formule')) ?></label>
                <select id="formule_id" name="formule_id" required<?= $inv('formule_id') ?>>
                    <option value=""><?= e(t('console.client.choisir')) ?></option>
                    <?php foreach ($formules as $f): ?>
                        <option value="<?= (int) $f['id'] ?>"<?= (string) $saisie['formule_id'] === (string) $f['id'] ? ' selected' : '' ?>><?= e($f['nom']) ?> — <?= e(A::montant(Abonnement::prix($f, 'mois') ?? Abonnement::mensuel($f, 'annee'))) ?>/<?= e(t('console.periode.mois')) ?></option>
                    <?php endforeach; ?>
                </select><?= $err('formule_id') ?>
            </div>
            <div class="champ"><label for="periode"><?= e(t('console.client.periode')) ?></label>
                <select id="periode" name="periode"<?= $inv('periode') ?>>
                    <?php foreach (array_keys(Abonnement::PERIODES) as $p): ?>
                        <option value="<?= e($p) ?>"<?= $saisie['periode'] === $p ? ' selected' : '' ?>><?= e(t('console.periode.' . $p)) ?></option>
                    <?php endforeach; ?>
                </select><?= $err('periode') ?>
            </div>
            <div class="champ"><label for="debut"><?= e(t('console.client.debut')) ?></label><input id="debut" name="debut" type="date" value="<?= e((string) $saisie['debut']) ?>"<?= $inv('debut') ?>><?= $err('debut') ?></div>
            <div class="champ"><label for="essai_jours"><?= e(t('console.client.essai_jours')) ?></label><input id="essai_jours" name="essai_jours" inputmode="numeric" value="<?= e((string) $saisie['essai_jours']) ?>"<?= $inv('essai_jours') ?>><p class="champ__aide"><?= e(t('console.client.essai_aide')) ?></p><?= $err('essai_jours') ?></div>
        </div>
    </section>
    <p class="con-actions"><button class="bouton" type="submit"><?= e(t($rattacher ? 'console.clients.rattacher' : 'console.client.creer')) ?></button></p>
</form>
