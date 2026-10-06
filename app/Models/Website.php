<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Le site vitrine d'une communauté. */
class Website extends Model
{
    use Auditable;

    /** Les mises en page proposées ; les couleurs et le logo restent ceux de la communauté. */
    public const THEMES = [
        'chaleureux' => ['name' => 'Chaleureux', 'hint' => 'Motif wax, couleurs franches'],
        'lumiere' => ['name' => 'Lumière', 'hint' => 'Clair et aéré, la photo en grand'],
        'solennel' => ['name' => 'Solennel', 'hint' => 'Sombre et élégant, titres à empattements'],
    ];

    /** Les pages que la communauté choisit d'ouvrir ; l'accueil est toujours là. */
    public const PAGES = [
        'programme' => 'Programme des cultes',
        'evenements' => 'Événements',
        'annonces' => 'Annonces',
        'predications' => 'Prédications',
        'a-propos' => 'Qui sommes-nous',
        'paroisses' => 'Nos paroisses',
        'don' => 'Faire un don',
        'contact' => 'Nous trouver',
    ];

    /** Les mêmes, en court, pour le menu. */
    public const NAV = [
        'programme' => 'Programme', 'evenements' => 'Événements', 'annonces' => 'Annonces', 'predications' => 'Prédications',
        'a-propos' => 'À propos', 'paroisses' => 'Paroisses', 'don' => 'Dons', 'contact' => 'Contact',
    ];

    public const DEFAULT_PAGES = ['programme', 'evenements', 'annonces', 'predications', 'a-propos', 'don', 'contact'];

    protected $guarded = ['id'];

    protected $attributes = ['is_published' => false, 'theme' => 'chaleureux'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'pages' => 'array', 'giving_accounts' => 'array', 'giving_categories' => 'array', 'published_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasPage(string $page): bool
    {
        return in_array($page, $this->pages ?? self::DEFAULT_PAGES, true);
    }

    public function auditOrganizationId(): ?int
    {
        return $this->organization_id;
    }
}
