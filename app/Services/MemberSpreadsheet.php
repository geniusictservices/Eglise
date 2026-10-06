<?php

namespace App\Services;

use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberImport;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Support\Phone;
use App\Support\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Le registre dans Excel : modèle à remplir, analyse ligne par ligne d'un
 * fichier rempli, import, et export dans le même format (aller-retour).
 */
class MemberSpreadsheet
{
    public function __construct(private MemberRegistry $registry) {}

    /**
     * Colonnes du modèle, selon les réglages et les champs de la communauté.
     *
     * @return list<array{key: string, label: string, type: string, required?: bool, hint?: string, options?: list<string>, field?: MemberField}>
     */
    public function columns(Organization $organization, bool $withSensitive = true): array
    {
        $hidden = $this->registry->settings($organization)['hidden_fields'];
        $show = fn (string $f) => ! in_array($f, $hidden, true);
        $statuses = $this->registry->statuses($organization)->pluck('name')->all();

        $columns = array_filter([
            ['key' => 'number', 'label' => __('Numéro de membre'), 'type' => 'text', 'hint' => __('Vide : Waumini attribue le numéro. Rempli : le numéro de l’ancien registre est gardé.')],
            ['key' => 'last_name', 'label' => __('Nom'), 'type' => 'text', 'required' => true],
            ['key' => 'middle_name', 'label' => __('Post-nom'), 'type' => 'text'],
            ['key' => 'first_name', 'label' => __('Prénom'), 'type' => 'text'],
            ['key' => 'gender', 'label' => __('Sexe'), 'type' => 'gender', 'options' => ['F', 'M'], 'hint' => __('F ou M')],
            ['key' => 'birth_date', 'label' => __('Date de naissance'), 'type' => 'date', 'hint' => __('JJ/MM/AAAA')],
            $show('birth_place') ? ['key' => 'birth_place', 'label' => __('Lieu de naissance'), 'type' => 'text'] : null,
            ['key' => 'phone', 'label' => __('Téléphone'), 'type' => 'phone'],
            $show('phone2') ? ['key' => 'phone2', 'label' => __('Second téléphone'), 'type' => 'phone'] : null,
            $show('email') ? ['key' => 'email', 'label' => __('E-mail'), 'type' => 'email'] : null,
            ['key' => 'district', 'label' => __('Quartier'), 'type' => 'text'],
            ['key' => 'street', 'label' => __('Avenue'), 'type' => 'text'],
            ['key' => 'house_number', 'label' => __('N° de parcelle'), 'type' => 'text'],
            ['key' => 'city', 'label' => __('Ville'), 'type' => 'text'],
            $show('profession') ? ['key' => 'profession', 'label' => __('Profession'), 'type' => 'text'] : null,
            $show('marital_status') ? ['key' => 'marital_status', 'label' => __('État civil'), 'type' => 'marital', 'options' => array_map('__', array_values(Member::MARITAL_STATUSES))] : null,
            $show('education_level') ? ['key' => 'education_level', 'label' => __('Niveau d’études'), 'type' => 'text'] : null,
            $show('origin_church') ? ['key' => 'origin_church', 'label' => __('Église d’origine'), 'type' => 'text'] : null,
            $show('emergency_contact') ? ['key' => 'emergency_contact_name', 'label' => __('Personne à prévenir'), 'type' => 'text'] : null,
            $show('emergency_contact') ? ['key' => 'emergency_contact_phone', 'label' => __('Téléphone de la personne à prévenir'), 'type' => 'phone'] : null,
            $show('preferred_language') ? ['key' => 'preferred_language', 'label' => __('Langue'), 'type' => 'language', 'options' => array_values(config('waumini.locales'))] : null,
            $show('joined_on') ? ['key' => 'joined_on', 'label' => __('Date d’adhésion'), 'type' => 'date', 'hint' => __('JJ/MM/AAAA')] : null,
            ['key' => 'status', 'label' => __('Statut'), 'type' => 'status', 'options' => $statuses, 'hint' => __('Vide : :status', ['status' => $this->registry->defaultStatus($organization)?->name])],
            ['key' => 'household', 'label' => __('Ménage'), 'type' => 'text', 'hint' => __('Même nom sur plusieurs lignes = même ménage. Exemple : Famille KAMBALE')],
            ['key' => 'household_role', 'label' => __('Place dans le ménage'), 'type' => 'household_role', 'options' => array_map('__', array_values(Household::ROLES))],
            ['key' => 'baptism_date', 'label' => __('Date de baptême'), 'type' => 'date', 'hint' => __('JJ/MM/AAAA')],
            ['key' => 'baptism_register', 'label' => __('N° registre de baptême'), 'type' => 'text'],
        ]);

        foreach ($this->registry->fields($organization) as $field) {
            if ($field->sensitive && ! $withSensitive) {
                continue;
            }
            $columns[] = ['key' => 'custom.'.$field->key, 'label' => $field->label, 'type' => 'custom', 'field' => $field,
                'required' => $field->required, 'options' => $field->type === 'select' ? $field->options : ($field->type === 'boolean' ? [__('Oui'), __('Non')] : null)];
        }

        return array_values($columns);
    }

    // ------------------------------------------------------------------
    // Modèle et export
    // ------------------------------------------------------------------

    public function template(Organization $organization): Spreadsheet
    {
        $columns = $this->columns($organization);
        $book = $this->book($organization, $columns, __('Membres'));
        $sheet = $book->getSheet(0);

        // Une ligne d'exemple, en italique gris, que l'église remplace.
        $example = [
            'last_name' => 'KAMBALE', 'middle_name' => 'Musavuli', 'first_name' => 'Jean-Paul', 'gender' => 'M', 'birth_date' => '07/12/1973',
            'phone' => '0812 345 678', 'district' => 'Himbi II', 'street' => 'Mapendo', 'house_number' => '77', 'city' => $organization->city ?: 'Goma',
            'marital_status' => __('Marié(e)'), 'status' => $this->registry->defaultStatus($organization)?->name, 'household' => 'Famille KAMBALE',
            'household_role' => __('Chef de ménage'), 'joined_on' => '25/05/2019', 'baptism_date' => '12/06/1993', 'baptism_register' => 'B-301',
        ];
        foreach ($columns as $i => $column) {
            $sheet->setCellValueExplicit([$i + 1, 2], (string) ($example[$column['key']] ?? ''), DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, 2, count($columns), 2])->getFont()->setItalic(true)->getColor()->setRGB('8F8577');
        $this->validations($book, $sheet, $columns, 500);
        $this->instructions($book, $organization, $columns);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function export(Organization $organization, Builder $members, bool $withSensitive): Spreadsheet
    {
        $columns = $this->columns($organization, $withSensitive);
        $book = $this->book($organization, $columns, __('Membres'));
        $sheet = $book->getSheet(0);
        $row = 2;

        $members->with(['status', 'household', 'lifeEvents' => fn ($q) => $q->where('type', 'baptism')])
            ->orderBy('last_name')->orderBy('first_name')
            ->chunk(500, function ($chunk) use ($sheet, $columns, &$row) {
                foreach ($chunk as $member) {
                    foreach ($columns as $i => $column) {
                        $value = $this->exportValue($member, $column);
                        if ($value !== null && $value !== '') {
                            $sheet->setCellValueExplicit([$i + 1, $row], (string) $value, DataType::TYPE_STRING);
                        }
                    }
                    $row++;
                }
            });

        return $book;
    }

    private function exportValue(Member $member, array $column): ?string
    {
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : null;
        $baptism = $member->lifeEvents->first();

        return match ($column['key']) {
            'gender' => $member->gender,
            'birth_date', 'joined_on' => $date($member->{$column['key']}),
            'phone', 'phone2', 'emergency_contact_phone' => Phone::format($member->{$column['key']}),
            'marital_status' => $member->marital_status ? __(Member::MARITAL_STATUSES[$member->marital_status] ?? '') : null,
            'preferred_language' => config('waumini.locales')[$member->preferred_language] ?? null,
            'status' => $member->status?->name,
            'household' => $member->household?->name,
            'household_role' => $member->household_role ? __(Household::ROLES[$member->household_role] ?? '') : null,
            'baptism_date' => $date($baptism?->occurred_on),
            'baptism_register' => $baptism?->register_number,
            default => str_starts_with($column['key'], 'custom.')
                ? $this->customExportValue($column['field'], $member->custom[$column['field']->key] ?? null)
                : $member->{$column['key']},
        };
    }

    private function customExportValue(MemberField $field, mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $field->type === 'boolean' => $value ? __('Oui') : __('Non'),
            $field->type === 'date' => Carbon::parse($value)->format('d/m/Y'),
            default => (string) $value,
        };
    }

    private function book(Organization $organization, array $columns, string $title): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Waumini')->setTitle(__('Registre des membres de :name', ['name' => $organization->name]));
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $book->getActiveSheet()->setTitle($title);

        foreach ($columns as $i => $column) {
            $cell = Coordinate::stringFromColumnIndex($i + 1).'1';
            $sheet->setCellValue($cell, $column['label'].(($column['required'] ?? false) ? ' *' : ''));
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth(max(14, min(32, mb_strlen($column['label']) + 4)));
            if (! empty($column['hint'])) {
                $sheet->getComment($cell)->getText()->createTextRun($column['hint']);
            }
            // Les colonnes de texte restent du texte : un téléphone ne devient pas un nombre.
            $sheet->getStyle([$i + 1, 2, $i + 1, 5000])->getNumberFormat()->setFormatCode('@');
        }

        $sheet->getStyle([1, 1, count($columns), 1])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C2F6B']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle([1, 1, count($columns), 1])->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->freezePane('C2');

        return $book;
    }

    /** Listes déroulantes (sexe, statut…) tirées d'une feuille cachée. */
    private function validations(Spreadsheet $book, Worksheet $sheet, array $columns, int $rows): void
    {
        $lists = $book->createSheet()->setTitle(__('Listes'));
        $listColumn = 0;

        foreach ($columns as $i => $column) {
            if (empty($column['options'])) {
                continue;
            }
            $listColumn++;
            $letter = Coordinate::stringFromColumnIndex($listColumn);
            foreach (array_values($column['options']) as $r => $option) {
                $lists->setCellValueExplicit($letter.($r + 1), (string) $option, DataType::TYPE_STRING);
            }

            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_WARNING)
                ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
                ->setErrorTitle(__('Valeur inconnue'))->setError(__('Choisissez une valeur de la liste.'))
                ->setFormula1("'".__('Listes')."'!\$".$letter.'$1:$'.$letter.'$'.count($column['options']));
            $target = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setDataValidation("{$target}2:{$target}{$rows}", $validation);
        }

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    }

    private function instructions(Spreadsheet $book, Organization $organization, array $columns): void
    {
        $sheet = $book->createSheet()->setTitle(__('Mode d’emploi'));
        $lines = [
            [__('Modèle Waumini : registre des membres de :name', ['name' => $organization->name]), true],
            [''],
            [__('1. Remplissez une ligne par personne dans la feuille « Membres ». Remplacez la ligne d’exemple en gris.')],
            [__('2. Seul le nom est obligatoire (colonnes marquées *). Laissez vide ce que vous ne connaissez pas.')],
            [__('3. Les dates s’écrivent JJ/MM/AAAA, par exemple 07/12/1973. Une année seule (1973) est acceptée.')],
            [__('4. Les téléphones s’écrivent comme d’habitude : 0812 345 678 ou +243 812 345 678.')],
            [__('5. Pour regrouper une famille, écrivez le même nom de ménage sur chaque ligne, et la place de chacun.')],
            [__('6. Le numéro de membre : laissez vide pour que Waumini l’attribue, ou recopiez celui de votre ancien registre.')],
            [__('7. Ne changez pas les titres de la première ligne. Vous pouvez supprimer les colonnes inutiles.')],
            [__('8. Dans Waumini : Membres › Importer depuis Excel. Waumini vérifie chaque ligne avant d’importer, et l’import peut être annulé.')],
            [''],
            [__('Besoin d’aide ? Genius ICT vous accompagne : :email', ['email' => Platform::get('contact.email')])],
        ];
        foreach ($lines as $r => $line) {
            $sheet->setCellValue('A'.($r + 1), $line[0]);
            if ($line[1] ?? false) {
                $sheet->getStyle('A'.($r + 1))->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('2C2F6B');
            }
        }
        $sheet->getColumnDimension('A')->setWidth(120);
    }

    // ------------------------------------------------------------------
    // Lecture et analyse
    // ------------------------------------------------------------------

    /**
     * Lit le fichier : en-têtes reconnus et lignes non vides.
     *
     * @return array{columns: array<int, string>, unknown: list<string>, rows: list<array{line: int, values: array<string, string>}>}
     */
    public function read(string $path, Organization $organization): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getSheetByName(__('Membres')) ?? $book->getSheet(0);

        $known = collect($this->columns($organization))->mapWithKeys(fn ($c) => [self::normalize($c['label']) => $c['key']]);
        $aliases = collect([
            'numero' => 'number', 'n' => 'number', 'no' => 'number', 'matricule' => 'number', 'numero de membre' => 'number', 'n de membre' => 'number',
            'noms' => 'last_name', 'nom de famille' => 'last_name', 'postnom' => 'middle_name', 'prenoms' => 'first_name',
            'genre' => 'gender', 'telephone' => 'phone', 'tel' => 'phone', 'contact' => 'phone', 'adresse e mail' => 'email', 'email' => 'email', 'mail' => 'email',
            'commune quartier' => 'district', 'rue' => 'street', 'avenue rue' => 'street', 'numero de parcelle' => 'house_number', 'parcelle' => 'house_number',
            'date de naissance' => 'birth_date', 'ne le' => 'birth_date', 'ne e le' => 'birth_date', 'lieu de naissance' => 'birth_place',
            'date d adhesion' => 'joined_on', 'adhesion' => 'joined_on', 'menage' => 'household', 'famille' => 'household',
            'bapteme' => 'baptism_date', 'date du bapteme' => 'baptism_date', 'etat civil' => 'marital_status',
        ]);

        $grid = $sheet->toArray(null, true, false, false);
        $headerIndex = null;
        $map = [];
        $unknown = [];

        // La ligne d'en-têtes est l'une des 5 premières : celle qui contient « Nom ».
        foreach (array_slice($grid, 0, 5, true) as $index => $cells) {
            $normalized = array_map(fn ($c) => self::normalize((string) $c), $cells);
            if (in_array('nom', $normalized, true) || in_array('noms', $normalized, true)) {
                $headerIndex = $index;
                foreach ($normalized as $col => $label) {
                    if ($label === '') {
                        continue;
                    }
                    $key = $known->get($label) ?? $aliases->get($label);
                    if ($key && ! in_array($key, $map, true)) {
                        $map[$col] = $key;
                    } else {
                        $unknown[] = (string) $cells[$col];
                    }
                }
                break;
            }
        }

        if ($headerIndex === null) {
            return ['columns' => [], 'unknown' => [], 'rows' => []];
        }

        $rows = [];
        foreach (array_slice($grid, $headerIndex + 1, null, true) as $index => $cells) {
            $values = [];
            foreach ($map as $col => $key) {
                $value = $cells[$col] ?? null;
                if (is_float($value) && in_array($key, ['birth_date', 'joined_on', 'baptism_date'], true) && $value > 1000 && $value < 80000) {
                    $value = ExcelDate::excelToDateTimeObject($value)->format('d/m/Y'); // date saisie comme date Excel
                } elseif (is_float($value) && floor($value) === $value) {
                    $value = (string) (int) $value; // 812345678.0 → « 812345678 »
                }
                $values[$key] = trim((string) $value);
            }
            if (implode('', $values) !== '') {
                $rows[] = ['line' => $index + 1, 'values' => $values];
            }
        }

        return ['columns' => array_values($map), 'unknown' => $unknown, 'rows' => $rows];
    }

    public static function normalize(string $label): string
    {
        return Str::of($label)->replace('*', '')->replaceMatches('/\(.*?\)/', '')->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }

    /**
     * Vérifie chaque ligne et prépare les valeurs à enregistrer.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return list<array{line: int, name: string, data: array, custom: array, household: ?string, household_role: ?string, baptism: ?array, errors: list<string>, warnings: list<string>, duplicate: ?string}>
     */
    public function analyse(Organization $organization, array $rows): array
    {
        $columns = collect($this->columns($organization))->keyBy('key');
        $statuses = $this->registry->statuses($organization)->keyBy(fn ($s) => self::normalize($s->name));
        $default = $this->registry->defaultStatus($organization);
        $marital = collect(Member::MARITAL_STATUSES)->mapWithKeys(fn ($l, $k) => [self::normalize(__($l)) => $k])
            ->merge(['marie' => 'married', 'mariee' => 'married', 'celibataire' => 'single', 'veuf' => 'widowed', 'veuve' => 'widowed', 'divorce' => 'divorced', 'divorcee' => 'divorced', 'separe' => 'separated', 'separee' => 'separated']);
        $roles = collect(Household::ROLES)->mapWithKeys(fn ($l, $k) => [self::normalize(__($l)) => $k])
            ->merge(['chef' => 'head', 'pere' => 'head', 'epoux' => 'spouse', 'epouse' => 'spouse', 'conjoint' => 'spouse', 'conjointe' => 'spouse', 'mere' => 'spouse', 'fils' => 'child', 'fille' => 'child', 'enfant' => 'child', 'dependant' => 'dependent']);
        $languages = collect(config('waumini.locales'))->mapWithKeys(fn ($l, $k) => [self::normalize($l) => $k, $k => $k])
            ->merge(['francais' => 'fr', 'swahili' => 'sw', 'lingala' => 'ln', 'kikongo' => 'kg', 'tshiluba' => 'lua', 'ciluba' => 'lua']);

        // Registre existant : doublons possibles dans toute la dénomination.
        $existing = Member::withoutOrganizationScope()->withTrashed()
            ->whereIn('organization_id', Organization::query()->subtreeOf($organization->root())->pluck('id'))
            ->get(['id', 'organization_id', 'number', 'last_name', 'first_name', 'phone', 'deleted_at']);
        $byName = $existing->whereNull('deleted_at')->groupBy(fn ($m) => self::normalize($m->last_name.' '.$m->first_name));
        $byPhone = $existing->whereNull('deleted_at')->whereNotNull('phone')->groupBy('phone');
        $numbers = $existing->where('organization_id', $organization->id)->pluck('number')->filter()->map(fn ($n) => mb_strtolower($n))->flip();
        $existingHouseholds = Household::withoutOrganizationScope()->where('organization_id', $organization->id)->pluck('name')->map(fn ($n) => self::normalize($n))->flip();

        $seenNumbers = [];
        $seenPeople = [];
        $results = [];

        foreach ($rows as $row) {
            $v = $row['values'];
            $errors = [];
            $warnings = [];
            $data = [];
            $custom = [];

            $text = function (string $key, int $max) use ($v, &$data, &$errors, $columns) {
                $value = Str::squish($v[$key] ?? '');
                if ($value === '') {
                    return;
                }
                if (mb_strlen($value) > $max) {
                    $errors[] = __(':column : :max caractères au plus.', ['column' => $columns[$key]['label'] ?? $key, 'max' => $max]);
                } else {
                    $data[$key] = $value;
                }
            };

            // Identité
            $text('last_name', 80);
            $text('middle_name', 80);
            $text('first_name', 80);
            if (empty($data['last_name'])) {
                $errors[] = __('Le nom est obligatoire.');
            } else {
                $data['last_name'] = mb_strtoupper($data['last_name']);
            }

            if (($gender = self::normalize($v['gender'] ?? '')) !== '') {
                $data['gender'] = match (true) {
                    in_array($gender, ['f', 'femme', 'feminin', 'fille'], true) => 'F',
                    in_array($gender, ['m', 'h', 'homme', 'masculin', 'garcon'], true) => 'M',
                    default => null,
                };
                if (! $data['gender']) {
                    unset($data['gender']);
                    $errors[] = __('Sexe « :value » : écrivez F ou M.', ['value' => $v['gender']]);
                }
            }

            foreach (['birth_date' => __('Date de naissance'), 'joined_on' => __('Date d’adhésion'), 'baptism_date' => __('Date de baptême')] as $key => $label) {
                if (($raw = $v[$key] ?? '') === '' || ! $columns->has($key)) {
                    continue;
                }
                [$date, $yearOnly] = self::parseDate($raw);
                if (! $date || $date->isFuture() || $date->year < 1900) {
                    $errors[] = __(':column « :value » : date non reconnue (JJ/MM/AAAA).', ['column' => $label, 'value' => $raw]);
                } else {
                    $data[$key] = $date->format('Y-m-d');
                    if ($yearOnly) {
                        $warnings[] = __(':column : année seule, enregistrée au 1er janvier :year.', ['column' => $label, 'year' => $date->year]);
                    }
                }
            }

            // Contacts
            foreach (['phone', 'phone2', 'emergency_contact_phone'] as $key) {
                if (($raw = $v[$key] ?? '') === '') {
                    continue;
                }
                $phone = Phone::normalize($raw);
                $phone ? $data[$key] = $phone : $errors[] = __('Téléphone « :value » non valide.', ['value' => $raw]);
            }
            if (($email = $v['email'] ?? '') !== '') {
                filter_var($email, FILTER_VALIDATE_EMAIL) ? $data['email'] = mb_strtolower($email) : $errors[] = __('E-mail « :value » non valide.', ['value' => $email]);
            }

            foreach (['district' => 100, 'street' => 120, 'house_number' => 30, 'city' => 100, 'birth_place' => 100, 'profession' => 100, 'education_level' => 60, 'origin_church' => 150, 'emergency_contact_name' => 120] as $key => $max) {
                if ($columns->has($key)) {
                    $text($key, $max);
                }
            }

            if (($raw = $v['marital_status'] ?? '') !== '') {
                ($key = $marital->get(self::normalize($raw))) ? $data['marital_status'] = $key
                    : $errors[] = __('État civil « :value » inconnu.', ['value' => $raw]);
            }
            if (($raw = $v['preferred_language'] ?? '') !== '') {
                ($key = $languages->get(self::normalize($raw))) ? $data['preferred_language'] = $key
                    : $warnings[] = __('Langue « :value » inconnue : ignorée.', ['value' => $raw]);
            }

            // Statut
            if (($raw = $v['status'] ?? '') !== '') {
                ($status = $statuses->get(self::normalize($raw))) ? $data['status_id'] = $status->id
                    : $errors[] = __('Statut « :value » inconnu. Statuts possibles : :list.', ['value' => $raw, 'list' => $statuses->pluck('name')->implode(', ')]);
            } else {
                $data['status_id'] = $default?->id;
            }

            // Numéro
            if (($number = Str::squish($v['number'] ?? '')) !== '') {
                if (mb_strlen($number) > 60) {
                    $errors[] = __('Numéro trop long.');
                } elseif ($numbers->has(mb_strtolower($number))) {
                    $errors[] = __('Le numéro :number est déjà attribué dans le registre.', ['number' => $number]);
                } elseif (isset($seenNumbers[mb_strtolower($number)])) {
                    $errors[] = __('Le numéro :number apparaît aussi à la ligne :line.', ['number' => $number, 'line' => $seenNumbers[mb_strtolower($number)]]);
                } else {
                    $data['number'] = $number;
                    $seenNumbers[mb_strtolower($number)] = $row['line'];
                }
            }

            // Ménage
            $household = Str::squish($v['household'] ?? '') ?: null;
            $householdRole = null;
            if (($raw = $v['household_role'] ?? '') !== '') {
                $householdRole = $roles->get(self::normalize($raw));
                if (! $householdRole) {
                    $errors[] = __('Place dans le ménage « :value » inconnue.', ['value' => $raw]);
                }
            }
            if ($household && ! $householdRole) {
                $householdRole = 'dependent';
            }
            if ($household && $existingHouseholds->has(self::normalize($household))) {
                $warnings[] = __('Sera ajouté(e) au ménage existant « :name ».', ['name' => $household]);
            }

            // Champs propres à la communauté
            foreach ($columns->filter(fn ($c) => $c['type'] === 'custom') as $key => $column) {
                /** @var MemberField $field */
                $field = $column['field'];
                $raw = $v[$key] ?? '';
                if ($raw === '') {
                    if ($field->required && array_key_exists($key, $v)) {
                        $errors[] = __(':column est obligatoire.', ['column' => $field->label]);
                    }

                    continue;
                }
                $value = match ($field->type) {
                    'number' => is_numeric(str_replace(',', '.', $raw)) ? (string) (float) str_replace(',', '.', $raw) : null,
                    'date' => ($d = self::parseDate($raw)[0]) ? $d->format('Y-m-d') : null,
                    'boolean' => match (self::normalize($raw)) {
                        'oui', 'o', 'yes', '1', 'x', 'vrai' => true, 'non', 'n', 'no', '0', 'faux' => false, default => null
                    },
                    'select' => collect($field->options)->first(fn ($o) => self::normalize($o) === self::normalize($raw)),
                    'phone' => Phone::normalize($raw),
                    default => mb_substr($raw, 0, 255),
                };
                $value === null
                    ? $errors[] = __(':column « :value » non reconnu.', ['column' => $field->label, 'value' => $raw])
                    : $custom[$field->key] = $value;
            }

            // Doublons : dans le registre, puis dans le fichier.
            $duplicate = null;
            if (! empty($data['last_name'])) {
                $nameKey = self::normalize($data['last_name'].' '.($data['first_name'] ?? ''));
                $match = (! empty($data['phone']) ? $byPhone->get($data['phone'])?->first() : null)
                    ?? (($data['first_name'] ?? '') !== '' ? $byName->get($nameKey)?->first() : null);
                if ($match) {
                    $duplicate = __('Déjà inscrit(e) : :number', ['number' => $match->number ?? '#'.$match->id]);
                }
                $personKey = $nameKey.'|'.($data['birth_date'] ?? $data['phone'] ?? '');
                if (isset($seenPeople[$personKey]) && ($data['first_name'] ?? '') !== '') {
                    $duplicate ??= __('Même personne qu’à la ligne :line ?', ['line' => $seenPeople[$personKey]]);
                }
                $seenPeople[$personKey] ??= $row['line'];
            }

            $results[] = [
                'line' => $row['line'],
                'name' => trim(($data['last_name'] ?? '').' '.($data['middle_name'] ?? '').' '.($data['first_name'] ?? '')) ?: '—',
                'data' => $data,
                'custom' => $custom,
                'household' => $household,
                'household_role' => $household ? $householdRole : null,
                'errors' => $errors,
                'warnings' => $warnings,
                'duplicate' => $duplicate,
            ];
        }

        return $results;
    }

    /** @return array{0: ?Carbon, 1: bool} la date, et vrai si seule l'année était donnée */
    public static function parseDate(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(19|20)\d{2}$/', $raw)) {
            return [Carbon::create((int) $raw, 1, 1), true];
        }
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $raw);
            $problems = \DateTime::getLastErrors();
            if ($date && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return [Carbon::instance($date), false];
            }
        }

        return [null, false];
    }

    // ------------------------------------------------------------------
    // Import et annulation
    // ------------------------------------------------------------------

    /** Enregistre les lignes valides. Retourne le nombre de membres créés. */
    public function import(MemberImport $import, array $results, bool $includeDuplicates): int
    {
        $organization = $import->organization;
        $default = $this->registry->defaultStatus($organization);
        $created = 0;
        $householdsCreated = 0;

        AuditLogger::quietly(function () use ($import, $results, $includeDuplicates, $organization, $default, &$created, &$householdsCreated) {
            DB::transaction(function () use ($import, $results, $includeDuplicates, $organization, $default, &$created, &$householdsCreated) {
                $households = Household::withoutOrganizationScope()->where('organization_id', $organization->id)->get()->keyBy(fn ($h) => self::normalize($h->name));

                foreach ($results as $row) {
                    if ($row['errors'] || ($row['duplicate'] && ! $includeDuplicates)) {
                        continue;
                    }

                    $data = $row['data'];
                    $number = $data['number'] ?? null;
                    $baptism = array_filter(['occurred_on' => $data['baptism_date'] ?? null, 'register_number' => $data['baptism_register'] ?? null]);
                    unset($data['number'], $data['baptism_date'], $data['baptism_register']);

                    $member = new Member($data + ['status_id' => $default?->id]);
                    $member->forceFill([
                        'organization_id' => $organization->id,
                        'custom' => $row['custom'] ?: null,
                        'created_by' => $import->user_id,
                        'import_id' => $import->id,
                    ])->save();

                    $number ? $member->forceFill(['number' => $number])->save() : $this->registry->assignNumber($member);

                    if ($member->status_id) {
                        MemberStatusChange::create(['member_id' => $member->id, 'to_status_id' => $member->status_id,
                            'changed_on' => $member->joined_on ?? now(), 'reason' => __('Import Excel'), 'user_id' => $import->user_id]);
                    }
                    if ($baptism) {
                        LifeEvent::create(['member_id' => $member->id, 'type' => 'baptism'] + $baptism);
                    }

                    if ($row['household']) {
                        $key = self::normalize($row['household']);
                        $household = $households->get($key);
                        if (! $household) {
                            $household = new Household(['name' => $row['household']] + $member->only(['district', 'street', 'house_number', 'city', 'phone']));
                            $household->forceFill(['organization_id' => $organization->id, 'import_id' => $import->id])->save();
                            $households->put($key, $household);
                            $householdsCreated++;
                        }
                        $member->update(['household_id' => $household->id, 'household_role' => $row['household_role']]);
                        if ($row['household_role'] === 'head' && ! $household->head_member_id) {
                            $household->update(['head_member_id' => $member->id]);
                        }
                    }

                    $created++;
                }

                $import->update(['status' => 'imported', 'imported_rows' => $created, 'households_created' => $householdsCreated,
                    'imported_at' => now(), 'options' => ['include_duplicates' => $includeDuplicates]]);
            });
        });

        app(AuditLogger::class)->record('imported', $import, [], ['imported_rows' => $created, 'households_created' => $householdsCreated],
            __('a importé :count membres depuis :file', ['count' => $created, 'file' => $import->file_name]));

        return $created;
    }

    /** Retire les membres et ménages créés par un import. */
    public function cancel(MemberImport $import): int
    {
        $count = 0;

        AuditLogger::quietly(function () use ($import, &$count) {
            DB::transaction(function () use ($import, &$count) {
                $ids = Member::withoutOrganizationScope()->withTrashed()->where('import_id', $import->id)->pluck('id');
                Household::withoutOrganizationScope()->whereIn('head_member_id', $ids)->update(['head_member_id' => null]);
                $count = Member::withoutOrganizationScope()->withTrashed()->whereIn('id', $ids)->forceDelete();
                // Les ménages créés par l'import et désormais vides disparaissent aussi.
                Household::withoutOrganizationScope()->where('import_id', $import->id)
                    ->whereDoesntHave('members')->forceDelete();
                $import->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            });
        });

        app(AuditLogger::class)->record('cancelled', $import, [], ['removed_rows' => $count],
            __('a annulé l’import :file (:count membres retirés)', ['file' => $import->file_name, 'count' => $count]));

        return $count;
    }

    /** Résumé de l'analyse. */
    public static function summary(array $results): array
    {
        $results = collect($results);

        return [
            'total' => $results->count(),
            'errors' => $results->filter(fn ($r) => $r['errors'])->count(),
            'duplicates' => $results->filter(fn ($r) => ! $r['errors'] && $r['duplicate'])->count(),
            'valid' => $results->filter(fn ($r) => ! $r['errors'] && ! $r['duplicate'])->count(),
            'warnings' => $results->filter(fn ($r) => ! $r['errors'] && $r['warnings'])->count(),
            'households' => $results->filter(fn ($r) => ! $r['errors'])->pluck('household')->filter()->map(fn ($h) => self::normalize($h))->unique()->count(),
        ];
    }

    /** Lignes réduites pour l'affichage. */
    public static function preview(array $results, string $filter): Collection
    {
        return collect($results)->filter(fn ($r) => match ($filter) {
            'erreurs' => (bool) $r['errors'],
            'doublons' => ! $r['errors'] && $r['duplicate'],
            'valides' => ! $r['errors'] && ! $r['duplicate'],
            default => true,
        })->values();
    }
}
