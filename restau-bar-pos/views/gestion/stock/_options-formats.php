<?php
/**
 * Options « article · format » d'une liste, groupées par catégorie : la valeur est l'identifiant du format
 * (conditionnement), qui donne le produit et son contenu en unités de base (data-base, data-unite).
 * @var array<int, array> $articles GestionStockRepository::articles()
 * @var ?int $choisi
 * @var bool $achat seulement les formats d'achat quand le produit en a (réceptions, commandes)
 */

use App\Controllers\Gestion\StockAffichage as S;

$groupes = [];
foreach ($articles as $article) {
    $groupes[$article['categorie']][] = $article;
}
?>
<?php foreach ($groupes as $categorie => $liste): ?>
    <optgroup label="<?= e($categorie) ?>">
        <?php foreach ($liste as $article):
            $formats = $article['conditionnements'];
            if (!empty($achat)) {
                $achetables = array_filter($formats, static fn (array $c): bool => (bool) $c['achetable']);
                // Le format d'unité reste proposé (achat au détail, au marché)
                $formats = $achetables ? array_merge($achetables, array_filter($formats, static fn (array $c): bool => !$c['achetable'] && abs((float) $c['quantite_base'] - 1) < 0.0005)) : $formats;
            }
            foreach ($formats as $format):
                $base = (float) $format['quantite_base'];
                $detail = abs($base - 1) < 0.0005 && in_array($article['unite_base'], ['bouteille', 'canette', 'piece', 'verre', 'portion'], true)
                    ? '' : ' (' . S::base($base, $article) . ')';
                ?>
                <option value="<?= (int) $format['id'] ?>" data-base="<?= e((string) $base) ?>" data-produit="<?= (int) $article['id'] ?>"
                        data-unite="<?= e(S::unite((string) $article['unite_base'], 2)) ?>"
                        <?= (int) ($choisi ?? 0) === (int) $format['id'] ? 'selected' : '' ?>><?= e(champ($article, 'nom') . ' · ' . champ($format, 'nom') . $detail) ?></option>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </optgroup>
<?php endforeach; ?>
