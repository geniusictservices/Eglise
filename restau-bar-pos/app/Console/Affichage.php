<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\I18n;

/** Formats d'affichage de la console (dates, montants, badges de statut), en français ou en anglais. */
final class Affichage
{
    public const TONS = [
        'essai' => 'glace', 'actif' => 'succes', 'a_relancer' => 'braise', 'grace' => 'braise',
        'lecture_seule' => 'erreur', 'suspendu' => 'plein', 'a_installer' => 'neutre',
    ];

    public static function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        return I18n::langue() === 'fr' ? date('d/m/Y', strtotime($date)) : date('M j, Y', strtotime($date));
    }

    public static function dateHeure(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        return I18n::langue() === 'fr' ? date('d/m/Y H:i', strtotime($date)) : date('M j, Y g:i A', strtotime($date));
    }

    public static function montant(float|string|null $montant, string $devise = 'USD'): string
    {
        if ($montant === null || $montant === '') {
            return '—';
        }
        $montant = (float) $montant;
        $decimales = $devise === 'CDF' || abs($montant - round($montant)) < 0.005 ? 0 : 2;
        $nombre = I18n::langue() === 'fr' ? number_format($montant, $decimales, ',', "\u{202F}") : number_format($montant, $decimales);
        if ($devise === 'USD') {
            return I18n::langue() === 'fr' ? $nombre . "\u{00A0}\$" : '$' . $nombre;
        }
        return $nombre . "\u{00A0}" . ($devise === 'CDF' ? 'FC' : $devise);
    }

    /** Code du badge d'un client : « a_installer » tant que l'instance n'est pas installée, sinon son statut. */
    public static function code(array $client): string
    {
        return $client['installe_le'] === null ? 'a_installer' : $client['statut']['code'];
    }

    public static function badge(array $client): string
    {
        $code = self::code($client);
        $html = '<span class="badge badge--' . self::TONS[$code] . '" data-statut="' . e($code) . '">' . e(t('console.statut.' . $code)) . '</span>';
        if ($code !== 'a_installer' && $client['statut']['force']) {
            $html .= ' <span class="badge badge--contour" title="' . e((string) $client['statut_force_motif']) . '">' . e(t('console.statut.force')) . '</span>';
        }
        return $html;
    }

    /** Nom d'un module (lang « module.<code>.nom »), ou son code si sa traduction n'existe pas encore. */
    public static function module(string $code): string
    {
        $nom = t('module.' . $code . '.nom');
        return $nom === 'module.' . $code . '.nom' ? ucfirst(str_replace('_', ' ', $code)) : $nom;
    }

    /** Numéro au format international (+243…), indicatif de la RDC par défaut ; null s'il n'est pas valable. */
    public static function telephone(?string $saisie, string $indicatif = '243'): ?string
    {
        $chiffres = preg_replace('/\D+/', '', (string) $saisie) ?? '';
        if ($chiffres === '') {
            return null;
        }
        if (str_starts_with(trim((string) $saisie), '+') || str_starts_with($chiffres, '00')) {
            $chiffres = ltrim(str_starts_with($chiffres, '00') ? substr($chiffres, 2) : $chiffres, '0');
        } elseif (!(str_starts_with($chiffres, $indicatif) && strlen($chiffres) > 10)) {
            $chiffres = $indicatif . ltrim($chiffres, '0');
        }
        return strlen($chiffres) >= 8 && strlen($chiffres) <= 15 ? '+' . $chiffres : null;
    }

    /** « dans 5 j », « aujourd'hui », « il y a 3 j » pour l'échéance. */
    public static function relatif(?int $jours): string
    {
        if ($jours === null) {
            return '';
        }
        return match (true) {
            $jours === 0 => t('console.jours.aujourdhui'),
            $jours > 0 => t('console.jours.dans', ['n' => $jours]),
            default => t('console.jours.depuis', ['n' => -$jours]),
        };
    }
}
