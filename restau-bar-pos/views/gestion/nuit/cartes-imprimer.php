<?php
/**
 * Planche de cartes cashless à imprimer (format carte bancaire, 85 × 54 mm), toujours sur fond blanc.
 * @var list<array> $cartes @var ?string $flash
 */
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="jour">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(t('gnuit.cartes_imprimer')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/gestion-nuit.css">
</head>
<body class="planche-cartes">
    <header class="planche-cartes__tete">
        <?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
        <a class="bouton bouton--secondaire" href="/gestion/cashless"><?= e(t('gnuit.retour_cartes')) ?></a>
        <button class="bouton" type="button" onclick="window.print()"><?= e(t('nuit.c_imprimer')) ?></button>
        <p class="aide"><?= e(t('gnuit.cartes_imprimer_aide', ['n' => count($cartes)])) ?></p>
    </header>
    <div class="planche-cartes__grille">
        <?php foreach ($cartes as $c): ?>
            <section class="carte-imprimee">
                <div class="carte-imprimee__qr" data-qr="<?= e($c['code']) ?>"></div>
                <div class="carte-imprimee__texte">
                    <p class="carte-imprimee__titre"><?= e($c['support'] === 'bracelet' ? t('nuit.bracelet_titre') : t('nuit.carte_titre')) ?></p>
                    <p class="carte-imprimee__numero">N° <?= e(str_pad((string) $c['numero'], 4, '0', STR_PAD_LEFT)) ?></p>
                    <p class="carte-imprimee__code"><?= e($c['code']) ?></p>
                    <p class="carte-imprimee__aide"><?= e(t('nuit.carte_aide')) ?></p>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <script src="/assets/vendor/qrcode.js"></script>
    <script>
        document.querySelectorAll('[data-qr]').forEach(function (z) {
            var qr = qrcode(0, 'M'); qr.addData(z.getAttribute('data-qr')); qr.make();
            z.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
        });
    </script>
</body>
</html>
