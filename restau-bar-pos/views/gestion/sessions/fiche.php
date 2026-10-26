<?php
/**
 * Ventes > Sessions de caisse > une session : rapport de clôture (ou état en cours), et pour une session
 * ouverte, la clôture par le gérant avec le comptage.
 * @var ?string $flash
 * @var array $session
 * @var array $rapport
 * @var bool $peutCloturer
 */
$jeton = e(App\Core\Session::jetonCsrf());
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">

<p><a class="lien" href="/gestion/sessions-caisse">← <?= e(t('gsession.retour')) ?></a></p>
<div class="tre-entete">
    <div>
        <h1><?= e(t('gsession.une', ['n' => (int) $session['id']])) ?> · <?= e($session['utilisateur_nom']) ?></h1>
        <p class="aide"><?= e($session['etablissement_nom']) ?> · <?= e(t($session['statut'] === 'ouverte' ? 'gsession.ouverte' : 'gsession.fermee')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<div class="ven-detail">
    <div class="ven-detail__recu"><?= App\Core\View::rendre('caisse/_rapport-session', $rapport) ?></div>

    <?php if ($peutCloturer): ?>
        <section class="bloc ven-detail__infos" aria-labelledby="cloturer-titre">
            <h2 id="cloturer-titre"><?= e(t('gsession.cloturer_pour')) ?></h2>
            <p class="aide"><?= e(t('gsession.cloturer_pour_aide')) ?></p>
            <form method="post" action="/gestion/sessions-caisse/<?= (int) $session['id'] ?>/cloturer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <?php foreach ($rapport['lignes'] as $l): ?>
                    <div class="champ">
                        <label for="compte-<?= $l['compte_id'] ?>-<?= $l['devise_id'] ?>"><?= e($l['nom']) ?> · <?= e($rapport['devises'][$l['devise_id']]['code'] ?? '') ?> — <?= e(t('session.compte')) ?></label>
                        <input id="compte-<?= $l['compte_id'] ?>-<?= $l['devise_id'] ?>" name="compte[<?= $l['compte_id'] ?>][<?= $l['devise_id'] ?>]" inputmode="decimal" autocomplete="off" required>
                    </div>
                <?php endforeach; ?>
                <div class="champ">
                    <label for="note"><?= e(t('session.note')) ?></label>
                    <input id="note" name="note" maxlength="255">
                </div>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('session.valider_cloture')) ?></button></div>
            </form>
        </section>
    <?php endif; ?>
</div>
