<?php /** @var string $actif semaine | feries */ ?>
<?= App\Core\View::rendre('gestion/personnel/_onglets', ['actif' => $actif, 'libelle' => t('ecran.plannings'), 'onglets' => [
    'semaine' => ['/gestion/personnel/plannings', 'pers.onglet_semaine'],
    'feries' => ['/gestion/personnel/plannings/feries', 'pers.onglet_feries'],
]]) ?>
