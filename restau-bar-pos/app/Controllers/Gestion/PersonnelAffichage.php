<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;
use App\Repositories\GestionTresorerieRepository;

/** Affichage des écrans Personnel et paie : montants par devise (locale d'abord, dollar ensuite), durées, périodes. */
final class PersonnelAffichage
{
    private static ?array $devises = null;

    /** @return array<int, array> devises dans l'ordre d'affichage (locale, dollar, autres) */
    public static function devises(): array
    {
        return self::$devises ??= (new GestionTresorerieRepository())->devises();
    }

    public static function montant(float|string|null $montant, int $deviseId, bool $signe = false): string
    {
        $devise = self::devises()[$deviseId] ?? null;
        return $devise ? TresorerieAffichage::montant($montant, $devise, $signe) : (string) $montant;
    }

    /**
     * Montants de plusieurs devises, locale d'abord puis dollar : « 100 000 FC · 150 $ ».
     * @param array<int, float> $parDevise
     */
    public static function montants(array $parDevise, string $vide = '—', bool $garderZeros = false): string
    {
        $ordre = array_flip(array_keys(self::devises()));
        $parDevise = array_filter($parDevise, static fn ($m) => $garderZeros || abs((float) $m) >= 0.0005);
        uksort($parDevise, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        if ($parDevise === []) {
            return $vide;
        }
        return implode(' · ', array_map(static fn ($d, $m) => self::montant($m, (int) $d), array_keys($parDevise), $parDevise));
    }

    /** « 7 h 30 », « 45 min » */
    public static function duree(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return t('pers.duree_min', ['m' => $minutes]);
        }
        $m = $minutes % 60;
        return $m === 0 ? t('pers.duree_h', ['h' => intdiv($minutes, 60)]) : t('pers.duree_hm', ['h' => intdiv($minutes, 60), 'm' => sprintf('%02d', $m)]);
    }

    public static function heure(?string $date): string
    {
        return $date ? date('H:i', strtotime($date)) : '';
    }

    /** « lun. 26/10 » */
    public static function jourCourt(string $date): string
    {
        $t = strtotime($date);
        return t('jour.' . date('N', $t)) . ' ' . date(I18n::langue() === 'fr' ? 'd/m' : 'm/d', $t);
    }

    public static function jour(?string $date): string
    {
        return TresorerieAffichage::jour($date);
    }

    public static function nomMois(int $mois): string
    {
        return t('pers.mois_' . $mois);
    }

    /** Période d'un bulletin : « octobre 2026 », « semaine du 19/10 au 25/10/2026 », « du 01/10 au 15/10/2026 » */
    public static function periode(string $debut, string $fin): string
    {
        if (substr($debut, 8) === '01' && $fin === date('Y-m-t', strtotime($debut))) {
            return self::nomMois((int) substr($debut, 5, 2)) . ' ' . substr($debut, 0, 4);
        }
        return t('pers.periode_du_au', ['du' => self::jour($debut), 'au' => self::jour($fin)]);
    }

    /** Nombre lisible (« 2,5 », « 12 ») */
    public static function nombre(float|string|null $valeur, int $decimales = 2): string
    {
        $valeur = (float) $valeur;
        $texte = number_format($valeur, $decimales, I18n::langue() === 'fr' ? ',' : '.', I18n::langue() === 'fr' ? "\u{202F}" : ',');
        return str_contains($texte, I18n::langue() === 'fr' ? ',' : '.') ? rtrim(rtrim($texte, '0'), ',.') : $texte;
    }

    /** Libellé traduit d'une ligne de bulletin (les lignes calculées sont décrites par leur type). */
    public static function libelleLigne(array $l): string
    {
        if (!$l['auto'] || !in_array($l['type'], ['base', 'heures_sup', 'ferie', 'commission', 'absence', 'sans_solde', 'avance', 'dette', 'manquant'], true)) {
            return (string) $l['libelle'];
        }
        $devise = (int) $l['devise_id'];
        return match ($l['type']) {
            'base' => $l['quantite'] !== null && $l['taux'] !== null
                ? t($l['libelle'] === 'Soirées travaillées' ? 'pers.ligne_soirees' : 'pers.ligne_base_prorata', ['n' => self::nombre($l['quantite']), 'taux' => self::montant($l['taux'], $devise)])
                : t('pers.ligne_base'),
            'heures_sup' => t('pers.ligne_heures_sup', ['n' => self::nombre($l['quantite']), 'taux' => self::montant($l['taux'], $devise)]),
            'ferie' => t('pers.ligne_ferie', ['n' => self::nombre($l['quantite']), 'taux' => self::montant($l['taux'], $devise)]),
            'commission' => t('pers.ligne_commission', ['pct' => self::nombre($l['quantite']), 'ventes' => self::montant($l['taux'], $devise)]),
            'absence' => t('pers.ligne_absence', ['n' => self::nombre($l['quantite']), 'taux' => self::montant($l['taux'], $devise)]),
            'sans_solde' => t('pers.ligne_sans_solde', ['n' => self::nombre($l['quantite']), 'taux' => self::montant($l['taux'], $devise)]),
            'avance' => t('pers.ligne_avance', ['date' => self::jour($l['date_avance'] ?? null)]),
            'dette' => t('pers.ligne_dette', ['code' => self::devises()[(int) $l['source_devise_id']]['code'] ?? '']),
            'manquant' => t('pers.ligne_manquant', ['n' => (int) $l['session_id'], 'date' => self::jour($l['session_fermee_le'] ?? null)]),
        };
    }
}
