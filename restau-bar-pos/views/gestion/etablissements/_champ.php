<?php
/**
 * Un champ texte de la fiche : libellé, saisie, aide et message d'erreur sous le champ.
 * @var string $nom       nom du champ (et de la colonne)
 * @var string $libelle
 * @var array $valeurs
 * @var array $erreurs    indexées par nom de champ
 * @var ?string $type     text, email, tel, url, textarea
 * @var ?string $aide
 * @var ?string $exemple
 * @var ?int $max
 * @var ?bool $requis
 * @var ?bool $facultatif
 * @var ?string $langue   attribut lang du champ
 * @var ?string $attributs attributs supplémentaires, déjà sûrs
 */
$type ??= 'text';
$id = 'etb-' . str_replace('_', '-', $nom);
$erreur = $erreurs[$nom] ?? null;
$decrit = trim(($erreur !== null ? $id . '-erreur ' : '') . (!empty($aide) ? $id . '-aide' : ''));
$commun = 'id="' . e($id) . '" name="' . e($nom) . '"'
    . (!empty($max) ? ' maxlength="' . (int) $max . '"' : '')
    . (!empty($requis) ? ' required' : '')
    . (!empty($langue) ? ' lang="' . e($langue) . '"' : '')
    . (!empty($exemple) ? ' placeholder="' . e($exemple) . '"' : '')
    . ($erreur !== null ? ' aria-invalid="true"' : '')
    . ($decrit !== '' ? ' aria-describedby="' . e($decrit) . '"' : '')
    . (!empty($attributs) ? ' ' . $attributs : '');
?>
<div class="champ<?= $type === 'textarea' ? ' etb-champ--texte' : '' ?>">
    <label for="<?= e($id) ?>"><?= e($libelle) ?><?php if (!empty($facultatif)): ?> <span class="etb-facultatif"><?= e(t('etab.facultatif')) ?></span><?php endif; ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea <?= $commun ?> rows="4"><?= e($valeurs[$nom] ?? '') ?></textarea>
    <?php else: ?>
        <input <?= $commun ?> type="<?= e($type) ?>" value="<?= e($valeurs[$nom] ?? '') ?>">
    <?php endif; ?>
    <?php if ($erreur !== null): ?><p class="etb-erreur-champ" id="<?= e($id) ?>-erreur"><?= e($erreur) ?></p><?php endif; ?>
    <?php if (!empty($aide)): ?><p class="aide" id="<?= e($id) ?>-aide"><?= e($aide) ?></p><?php endif; ?>
</div>
