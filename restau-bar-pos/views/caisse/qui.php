<?php
/**
 * Caisse partagée verrouillée, à la manière de l'écran de connexion de Windows :
 * fond de l'établissement, grande heure, message choisi par l'administrateur, personnes en bas à gauche ;
 * une personne touchée → sa grande photo au centre et le pavé du PIN.
 *
 * @var array $etab
 * @var array $appareil
 * @var list<array> $personnes
 * @var ?string $erreur
 * @var ?int $choisi
 * @var string $zone
 */
use App\Core\Avatars;
use App\Core\CaissePartagee;
use App\Core\I18n;
use App\Core\View;

$personneChoisie = null;
foreach ($personnes as $p) {
    if ((int) $p['id'] === $choisi) {
        $personneChoisie = $p;
    }
}
$fond = urlImageSure($etab['couverture'] ?? null);
$logo = urlImageSure($etab['logo'] ?? null);
$message = trim((string) champ($etab, 'message_verrou'));
$regle = App\Core\CaissePartagee::regle($etab);
$langue = I18n::langue();
$date = class_exists(IntlDateFormatter::class)
    ? (new IntlDateFormatter($langue === 'en' ? 'en_GB' : 'fr_FR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'EEEE d MMMM'))->format(time())
    : date('d/m/Y');
?>
<div class="verrou<?= $personneChoisie ? ' verrou--pin' : '' ?>" id="qui" data-langue="<?= e($langue) ?>">
    <div class="verrou__fond" aria-hidden="true" <?= $fond ? 'style="background-image: url(\'' . e($fond) . '\')"' : '' ?>></div>

    <header class="verrou__haut">
        <div class="verrou__lieu">
            <?php if ($logo): ?><img class="verrou__logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
            <div>
                <p class="verrou__etab"><?= e($etab['nom']) ?></p>
                <p class="verrou__appareil"><?= e($appareil['nom']) ?></p>
            </div>
        </div>
    </header>

    <section class="verrou__accueil" aria-labelledby="verrou-heure">
        <p class="verrou__heure" id="verrou-heure" data-heure><?= e(date('H:i')) ?></p>
        <p class="verrou__date" data-date><?= e(mb_convert_case($date, MB_CASE_TITLE)) ?></p>
        <?php if ($message !== ''): ?>
            <p class="verrou__message"><?= e($message) ?></p>
        <?php endif; ?>
        <?php if ($erreur && $personneChoisie === null): ?>
            <p class="verrou__erreur" role="alert"><?= e($erreur) ?></p>
        <?php endif; ?>
    </section>

    <nav class="verrou__personnes" aria-labelledby="verrou-qui">
        <h1 class="verrou__titre" id="verrou-qui"><?= e(t('caisse_pin.titre')) ?></h1>
        <?php if ($personnes === []): ?>
            <p class="verrou__vide"><?= e(t('caisse_pin.personne')) ?></p>
        <?php else: ?>
            <ul class="verrou__liste">
                <?php foreach ($personnes as $p): $bloque = $p['pin_bloque_le'] !== null; ?>
                    <li>
                        <a class="verrou__personne<?= $bloque ? ' verrou__personne--bloquee' : '' ?>"
                           href="/caisse?personne=<?= (int) $p['id'] ?><?= $zone !== '' ? '&amp;zone=' . e($zone) : '' ?>"
                           data-personne="<?= (int) $p['id'] ?>" data-nom="<?= e($p['nom']) ?>"
                           <?= (int) $p['id'] === $choisi ? 'aria-current="true"' : '' ?> <?= $bloque ? 'data-bloque="1"' : '' ?>>
                            <?= View::rendre('partials/_avatar', ['nom' => $p['nom'], 'photo' => $p['avatar'], 'classe' => '']) ?>
                            <span class="verrou__nom"><?= e($p['nom']) ?></span>
                            <span class="verrou__poste"><?= e($bloque ? t('caisse_pin.bloque_court') : ($p['poste'] ?? '')) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </nav>

    <section class="verrou__pin" id="pave" aria-labelledby="pave-nom" <?= $personneChoisie ? '' : 'hidden' ?>>
        <form method="post" action="/caisse/pin" class="pave__form" id="pave-form">
            <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
            <input type="hidden" name="utilisateur_id" id="pave-utilisateur" value="<?= (int) ($personneChoisie['id'] ?? 0) ?>">
            <input type="hidden" name="zone" value="<?= e($zone) ?>">
            <div class="pave__avatar" id="pave-avatar">
                <?php if ($personneChoisie): ?><?= View::rendre('partials/_avatar', ['nom' => $personneChoisie['nom'], 'photo' => $personneChoisie['avatar'], 'classe' => 'avatar--geant']) ?><?php endif; ?>
            </div>
            <h2 class="pave__nom" id="pave-nom"><?= e($personneChoisie['nom'] ?? '') ?></h2>
            <p class="pave__aide" id="pave-aide"><?= e(t('caisse_pin.taper', ['n' => CaissePartagee::LONGUEUR_PIN])) ?></p>
            <?php if ($erreur && $personneChoisie !== null): ?>
                <p class="verrou__erreur" role="alert" id="pave-erreur"><?= e($erreur) ?></p>
            <?php endif; ?>
            <label class="visuellement-cache" for="pave-pin"><?= e(t('caisse_pin.pin')) ?></label>
            <input class="pave__champ" id="pave-pin" name="pin" type="password" inputmode="numeric" autocomplete="off"
                   pattern="\d{<?= CaissePartagee::LONGUEUR_PIN ?>}" maxlength="<?= CaissePartagee::LONGUEUR_PIN ?>" required>
            <div class="pave__points" aria-hidden="true" id="pave-points">
                <?php for ($i = 0; $i < CaissePartagee::LONGUEUR_PIN; $i++): ?><span></span><?php endfor; ?>
            </div>
            <div class="pave__touches" id="pave-touches" hidden>
                <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $chiffre): ?>
                    <button type="button" class="pave__touche" data-chiffre="<?= $chiffre ?>"><?= $chiffre ?></button>
                <?php endforeach; ?>
                <button type="button" class="pave__touche pave__touche--action" data-effacer aria-label="<?= e(t('caisse_pin.effacer')) ?>">⌫</button>
                <button type="button" class="pave__touche" data-chiffre="0">0</button>
                <button type="button" class="pave__touche pave__touche--action" data-fermer aria-label="<?= e(t('caisse_pin.annuler')) ?>">✕</button>
            </div>
            <div class="pave__actions" id="pave-actions">
                <button class="bouton bouton--large" type="submit"><?= e(t('caisse_pin.valider')) ?></button>
                <a class="lien" href="/caisse"><?= e(t('caisse_pin.annuler')) ?></a>
            </div>
        </form>
    </section>

    <?php if ($regle !== null): ?>
        <p class="verrou__regles"><?= e($regle) ?></p>
    <?php endif; ?>
    <template id="modeles-avatars">
        <?php foreach ($personnes as $p): ?>
            <div data-avatar-de="<?= (int) $p['id'] ?>"><?= View::rendre('partials/_avatar', ['nom' => $p['nom'], 'photo' => $p['avatar'], 'classe' => 'avatar--geant']) ?></div>
        <?php endforeach; ?>
    </template>
</div>
