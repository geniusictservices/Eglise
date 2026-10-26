<?php
/**
 * Caisse partagée verrouillée : « Qui êtes-vous ? », puis le PIN de 6 chiffres.
 *
 * @var array $etab
 * @var array $appareil
 * @var list<array> $personnes
 * @var ?string $erreur
 * @var ?int $choisi
 * @var string $zone
 */
use App\Core\CaissePartagee;

$initiales = static fn (string $nom): string => mb_strtoupper(implode('', array_map(
    static fn (string $m) => mb_substr($m, 0, 1),
    array_slice(preg_split('/\s+/', trim($nom)) ?: [], 0, 2)
)));
$personneChoisie = null;
foreach ($personnes as $p) {
    if ((int) $p['id'] === $choisi) {
        $personneChoisie = $p;
    }
}
?>
<div class="qui" id="qui">
    <header class="qui__haut">
        <div>
            <p class="qui__lieu"><?= e($etab['nom']) ?></p>
            <p class="qui__appareil"><?= e($appareil['nom']) ?></p>
        </div>
        <button type="button" class="icone" data-bascule-theme aria-pressed="false" title="<?= e(t('theme.nuit')) ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>
            <span class="visuellement-cache"><?= e(t('theme.nuit')) ?></span>
        </button>
    </header>

    <main class="qui__corps">
        <h1 class="qui__titre"><?= e(t('caisse_pin.titre')) ?></h1>
        <?php if ($erreur && $personneChoisie === null): ?>
            <p class="qui__erreur" role="alert"><?= e($erreur) ?></p>
        <?php endif; ?>

        <?php if ($personnes === []): ?>
            <p class="qui__vide"><?= e(t('caisse_pin.personne')) ?></p>
        <?php else: ?>
            <ul class="qui__liste">
                <?php foreach ($personnes as $p): $bloque = $p['pin_bloque_le'] !== null; ?>
                    <li>
                        <a class="qui__personne<?= $bloque ? ' qui__personne--bloquee' : '' ?>"
                           href="/caisse?personne=<?= (int) $p['id'] ?><?= $zone !== '' ? '&amp;zone=' . e($zone) : '' ?>"
                           data-personne="<?= (int) $p['id'] ?>" data-nom="<?= e($p['nom']) ?>" data-initiales="<?= e($initiales($p['nom'])) ?>"
                           <?= $bloque ? 'data-bloque="1"' : '' ?>>
                            <span class="qui__initiales" aria-hidden="true"><?= e($initiales($p['nom'])) ?></span>
                            <span class="qui__nom"><?= e($p['nom']) ?></span>
                            <span class="qui__poste"><?= e($bloque ? t('caisse_pin.bloque_court') : ($p['poste'] ?? '')) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>

    <dialog class="pave" id="pave" aria-labelledby="pave-nom" <?= $personneChoisie ? 'open data-ouvrir="1"' : '' ?>>
        <form method="post" action="/caisse/pin" class="pave__form" id="pave-form">
            <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
            <input type="hidden" name="utilisateur_id" id="pave-utilisateur" value="<?= (int) ($personneChoisie['id'] ?? 0) ?>">
            <input type="hidden" name="zone" value="<?= e($zone) ?>">
            <span class="qui__initiales qui__initiales--grand" id="pave-initiales" aria-hidden="true"><?= e($personneChoisie ? $initiales($personneChoisie['nom']) : '') ?></span>
            <h2 class="pave__nom" id="pave-nom"><?= e($personneChoisie['nom'] ?? '') ?></h2>
            <p class="pave__aide" id="pave-aide"><?= e(t('caisse_pin.taper', ['n' => CaissePartagee::LONGUEUR_PIN])) ?></p>
            <?php if ($erreur && $personneChoisie !== null): ?>
                <p class="qui__erreur" role="alert" id="pave-erreur"><?= e($erreur) ?></p>
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
    </dialog>
</div>
