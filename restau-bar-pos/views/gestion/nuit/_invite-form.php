<?php
/**
 * Formulaire d'un invité (gestion et espace du promoteur).
 * @var string $action @var array $evenement @var ?array $invite @var list<array> $promoteurs (vide : promoteur imposé)
 * @var list<array> $tarifs tarifs réduits possibles
 */
$jeton = e(App\Core\Session::jetonCsrf());
$i = $invite ?? [];
?>
<form class="sal-form" method="post" action="<?= e($action) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <input type="hidden" name="evenement" value="<?= (int) $evenement['id'] ?>">
    <label class="champ"><span><?= e(t('gnuit.nom')) ?></span><input class="saisie" name="nom" maxlength="128" required autocomplete="off" value="<?= e($i['nom'] ?? '') ?>"></label>
    <label class="champ"><span><?= e(t('gnuit.telephone')) ?></span><input class="saisie" name="telephone" type="tel" autocomplete="off" value="<?= e(isset($i['telephone']) ? afficherTelephone($i['telephone']) : '') ?>"></label>
    <label class="champ sal-champ--court"><span><?= e(t('gnuit.accompagnants')) ?></span><input class="saisie" name="accompagnants" type="number" min="0" max="20" value="<?= (int) ($i['accompagnants'] ?? 0) ?>"></label>
    <label class="champ"><span><?= e(t('gnuit.entree')) ?></span>
        <select class="saisie" name="entree">
            <option value="gratuite"><?= e(t('nuit.c_invite_gratuite')) ?></option>
            <option value="reduite" <?= ($i['entree'] ?? '') === 'reduite' ? 'selected' : '' ?>><?= e(t('nuit.c_invite_reduite')) ?></option>
        </select></label>
    <label class="champ"><span><?= e(t('gnuit.tarif_reduit')) ?></span>
        <select class="saisie" name="tarif_id">
            <option value="0">—</option>
            <?php foreach ($tarifs as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($i['tarif_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e(champ($t, 'nom')) ?></option><?php endforeach; ?>
        </select></label>
    <?php if ($promoteurs !== []): ?>
        <label class="champ"><span><?= e(t('gnuit.promoteur')) ?></span>
            <select class="saisie" name="promoteur_id">
                <option value="0">—</option>
                <?php foreach ($promoteurs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($i['promoteur_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option><?php endforeach; ?>
            </select></label>
    <?php endif; ?>
    <label class="champ champ--large"><span><?= e(t('gnuit.note')) ?></span><input class="saisie" name="note" maxlength="160" value="<?= e($i['note'] ?? '') ?>"></label>
    <div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t($invite ? 'gsalle.enregistrer' : 'gnuit.invite_ajouter')) ?></button></div>
</form>
