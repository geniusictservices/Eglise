<?php

declare(strict_types=1);

namespace App\Core;

/** Icônes au trait (24 × 24, couleur du texte) des modules et de leurs écrans. */
final class Icones
{
    private const TRACES = [
        'modules' => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
        'caisse' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V5h10v6"/><path d="M10 8h4"/><path d="M7 15h2M11 15h2M15 15h2M7 18h10"/>',
        'ventes' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'dettes' => '<path d="M4 6h16v12H4z"/><path d="M4 10h16"/><path d="M8 15h4"/>',
        'achats' => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6"/><circle cx="10" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
        'stock' => '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        'inventaire' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v2h6V3"/><path d="M9 11l2 2 4-4M9 17h6"/>',
        'finance' => '<path d="M4 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 13.5h2"/>',
        'transferts' => '<path d="M4 8h14l-3-3"/><path d="M20 16H6l3 3"/>',
        'depenses' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 11h6"/>',
        'devises' => '<circle cx="9" cy="9" r="5.5"/><path d="M14.5 9.6A5.5 5.5 0 1 1 9.6 14.5"/>',
        'rapports' => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
        'carte' => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h13v4H6"/><path d="M9 8h6M9 11h4"/>',
        'categories' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'prix' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'medias' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3M14 21h1M21 14v1"/>',
        'vitrine' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'parametres' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'etablissements' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 11v9h14v-9"/><path d="M10 20v-5h4v5"/>',
        'compte' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'aide' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14"/><path d="M12 17.5v.01"/>',
        'design' => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4A4.6 4.6 0 0 0 21 9.8C21 5.9 17 3 12 3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'personnel' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2a5 5 0 0 1 5.5 5.8"/>',
        'planning' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M7 14h3M7 17h3M14 14h3"/>',
        'paie' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
        'sortie' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'retour' => '<path d="M15 18l-6-6 6-6"/>',
        // Clients : une carte de visite avec une personne
        'clients' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><circle cx="8.5" cy="10.5" r="2.3"/><path d="M5 16.5a3.6 3.6 0 0 1 7 0M14.5 9.5h4M14.5 13h4"/>',
        // Remboursement : une pièce et une flèche qui revient
        'remboursement' => '<circle cx="9" cy="12" r="6"/><path d="M9 9.2v5.6M7.3 10.4h2.6a1.1 1.1 0 0 1 0 2.2H8.1a1.1 1.1 0 0 0 0 2.2h2.6"/><path d="M17 7.5a6 6 0 0 1 0 9"/><path d="M19.5 15l-2.5 1.6-.2-2.9"/>',
        // Garantie : un bouclier coché
        'garantie' => '<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8-7.5 9.5-4.3-1.5-7.5-4.9-7.5-9.5V6z"/><path d="M8.8 12.2l2.2 2.2 4.3-4.4"/>',
        // Messagerie : une bulle de conversation
        'messagerie' => '<path d="M4 5.5h16a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5H10l-4.5 3.5V17.5H4A1.5 1.5 0 0 1 2.5 16V7A1.5 1.5 0 0 1 4 5.5z"/><path d="M7 10h10M7 13.5h6"/>',
        // Stock : journal (liste), perte (verre brisé), fiche technique (toque), emballage (casier), lieux (entrepôt)
        'journal' => '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'perte' => '<path d="M7 3h10l-1.5 9a3.5 3.5 0 0 1-7 0z"/><path d="M12 15.5V21M8.5 21h7"/><path d="M11 6l1.5 2-2 1.5 1.5 2"/>',
        'recette' => '<path d="M7 14a4 4 0 0 1-.9-7.9 5 5 0 0 1 9.8 0A4 4 0 0 1 17 14"/><path d="M7 14h10v6H7z"/><path d="M10 17h4"/>',
        'emballage' => '<rect x="3" y="8" width="18" height="12" rx="1.5"/><path d="M3 12h18M7.5 8v12M12 8v12M16.5 8v12"/><path d="M8 8V5h8v3"/>',
        'lieux' => '<path d="M3 21V9l9-5 9 5v12"/><path d="M7 21v-8h10v8"/><path d="M7 17h10"/>',
        // Personnel : congés et absences justifiées (calendrier coché)
        'conge' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8.5 15.5l2.2 2.2 4.8-4.8"/>',
        // Événements : soirées (note de musique)
        // Salle et cuisine : table et chaises (plan), marmite (cuisine), calendrier et horloge (réservations, services)
        'salle' => '<rect x="5" y="9" width="14" height="6" rx="1.5"/><path d="M8 15v5M16 15v5M3 6h4M17 6h4M5 6v3M19 6v3"/>',
        'cuisine' => '<path d="M4 11h16v3a6 6 0 0 1-6 6h-4a6 6 0 0 1-6-6z"/><path d="M2 11h20"/><path d="M9 7c0-1.5 1-1.5 1-3M14 7c0-1.5 1-1.5 1-3"/>',
        'reservation' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15.5" r="2.5"/>',
        'horloge' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'evenement' => '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/><path d="M9 9l11-2"/>',
    ];

    public static function svg(string $nom, int $taille = 22): string
    {
        $traces = self::TRACES[$nom] ?? self::TRACES['modules'];
        return '<svg class="icone-trait" viewBox="0 0 24 24" width="' . $taille . '" height="' . $taille . '" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $traces . '</svg>';
    }
}
