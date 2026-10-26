<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;

/** Lecture des saisies des écrans trésorerie et dépenses : textes, montants, dates, période. */
final class TresorerieSaisie
{
    public const PERIODES = ['jour', '7j', 'mois', 'mois_precedent', 'annee', 'tout'];

    /** Texte d'un champ (sans caractères de contrôle, espaces en trop retirés). */
    public static function texte(mixed $valeur, bool $multiligne = false): string
    {
        if (!is_string($valeur)) {
            return '';
        }
        $valeur = $multiligne
            ? (string) preg_replace('/[^\P{Cc}\n\t]/u', '', str_replace("\r\n", "\n", $valeur))
            : (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', $valeur));
        return trim($valeur);
    }

    public static function post(string $nom, bool $multiligne = false): string
    {
        return self::texte($_POST[$nom] ?? null, $multiligne);
    }

    /** Identifiant positif, sinon null. */
    public static function id(mixed $valeur): ?int
    {
        return is_string($valeur) && ctype_digit($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    /** Exemple de montant dans une devise, à la façon de la langue : « 142500 », « 12,50 ». */
    public static function exemple(array $devise): string
    {
        if ((int) $devise['decimales'] === 0) {
            return '142500';
        }
        return I18n::langue() === 'fr' ? '12,50' : '12.50';
    }

    /**
     * Lit un montant saisi dans une devise.
     * @return array{0: ?float, 1: ?string} montant, ou message d'erreur
     */
    public static function montant(mixed $saisie, array $devise, bool $zeroPermis = false): array
    {
        $texte = self::texte($saisie);
        $params = ['code' => $devise['code'], 'exemple' => self::exemple($devise), 'saisie' => $texte, 'n' => (int) $devise['decimales']];
        if ($texte === '') {
            return [null, t('tresorerie.erreur_montant_vide', $params)];
        }
        $nombre = DeviseAffichage::lireNombre($texte);
        if ($nombre === null) {
            return [null, t('tresorerie.erreur_montant_nombre', $params)];
        }
        $decimales = str_contains($nombre, '.') ? strlen(rtrim(substr($nombre, strpos($nombre, '.') + 1), '0')) : 0;
        if ($decimales > (int) $devise['decimales']) {
            return [null, t((int) $devise['decimales'] === 0 ? 'tresorerie.erreur_montant_entier' : 'tresorerie.erreur_montant_decimales', $params)];
        }
        $montant = (float) $nombre;
        if ($montant >= 1e13) {
            return [null, t('tresorerie.erreur_montant_grand', $params)];
        }
        if ($montant <= 0 && !$zeroPermis) {
            return [null, t('tresorerie.erreur_montant_zero', $params)];
        }
        return [$montant, null];
    }

    /** Date et heure d'un champ datetime-local (ou date seule, à minuit). */
    public static function dateHeure(string $texte): ?\DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $texte);
            if ($date !== false && $date->format($format) === $texte) {
                return $date;
            }
        }
        return null;
    }

    /**
     * Date d'une opération : vide = maintenant ; refusée si illisible, avant 2000 ou après aujourd'hui.
     * @return array{0: ?string, 1: ?string} date « Y-m-d H:i:s », ou message d'erreur
     */
    public static function dateOperation(string $texte): array
    {
        if ($texte === '') {
            return [date('Y-m-d H:i:s'), null];
        }
        $date = self::dateHeure($texte);
        if ($date === null || $date->format('Y') < '2000') {
            return [null, t('tresorerie.erreur_date')];
        }
        if ($date > new \DateTimeImmutable('tomorrow')) {
            return [null, t('tresorerie.erreur_date_future')];
        }
        return [$date->format('Y-m-d H:i:s'), null];
    }

    /**
     * Période choisie dans l'adresse : ?periode=mois, ou ?du=2026-10-01&au=2026-10-26.
     * @return array{cle: string, du: ?string, au: ?string, debut: string, fin: string}
     */
    public static function periode(string $defaut = 'mois'): array
    {
        $lire = static function (string $nom): ?\DateTimeImmutable {
            $valeur = $_GET[$nom] ?? '';
            if (!is_string($valeur) || $valeur === '') {
                return null;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
            return $date !== false && $date->format('Y-m-d') === $valeur ? $date : null;
        };
        $du = $lire('du');
        $au = $lire('au');
        $aujourdhui = new \DateTimeImmutable('today');

        if ($du !== null || $au !== null) {
            $cle = 'perso';
            if ($du !== null && $au !== null && $du > $au) {
                [$du, $au] = [$au, $du];
            }
        } else {
            $cle = in_array($_GET['periode'] ?? null, self::PERIODES, true) ? $_GET['periode'] : $defaut;
            [$du, $au] = match ($cle) {
                'jour' => [$aujourdhui, $aujourdhui],
                '7j' => [$aujourdhui->modify('-6 days'), $aujourdhui],
                'mois' => [$aujourdhui->modify('first day of this month'), $aujourdhui->modify('last day of this month')],
                'mois_precedent' => [$aujourdhui->modify('first day of last month'), $aujourdhui->modify('last day of last month')],
                'annee' => [$aujourdhui->setDate((int) $aujourdhui->format('Y'), 1, 1), $aujourdhui->setDate((int) $aujourdhui->format('Y'), 12, 31)],
                default => [null, null],
            };
        }
        return [
            'cle' => $cle,
            'du' => $du?->format('Y-m-d'),
            'au' => $au?->format('Y-m-d'),
            'debut' => $du !== null ? $du->format('Y-m-d 00:00:00') : '1000-01-01 00:00:00',
            'fin' => $au !== null ? $au->format('Y-m-d 23:59:59') : '9999-12-31 23:59:59',
        ];
    }
}
