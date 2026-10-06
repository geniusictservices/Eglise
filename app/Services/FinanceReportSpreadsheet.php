<?php

namespace App\Services;

use App\Models\FinanceClosing;
use App\Models\Organization;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Le rapport financier en classeur Excel : une feuille par tableau. */
class FinanceReportSpreadsheet
{
    public function build(Organization $organization, array $r, ?FinanceClosing $closing): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Waumini')->setTitle(__('Rapport financier'));

        $sheet = $book->getActiveSheet()->setTitle(__('Synthèse'));
        $rows = [
            [$organization->name],
            [__('Rapport financier du :a au :b', ['a' => $r['from']->format('d/m/Y'), 'b' => $r['to']->format('d/m/Y')])],
            [$closing?->isClosed() ? __('Période clôturée le :d', ['d' => $closing->closed_at->format('d/m/Y')]) : __('Provisoire : période non clôturée')],
            [],
            [__('En dollars (équivalent au taux du jour de chaque opération)')],
            [__('Recettes'), $r['totals']['income']],
            [__('Dépenses'), $r['totals']['expense']],
            [__('Résultat'), $r['totals']['result']],
        ];
        if ($r['months']) {
            $rows[] = [];
            $rows[] = [__('Mois'), __('Recettes'), __('Dépenses'), __('Résultat')];
            foreach ($r['months'] as $m) {
                $rows[] = [ucfirst($m['month']->translatedFormat('F Y')), $m['income'], $m['expense'], round($m['income'] - $m['expense'], 2)];
            }
        }
        $this->fill($sheet, $rows, [1, 2]);

        $rows = [[__('Compte'), __('Devise'), __('Solde de début'), __('Recettes'), __('Dépenses'), __('Virements et change'), __('Solde de fin')]];
        foreach ($r['accounts'] as $a) {
            $rows[] = [$a['account']->name, $a['currency'], ...array_map(fn ($k) => (float) (string) $a[$k], ['opening', 'income', 'expense', 'transfers', 'closing'])];
        }
        foreach ($r['currencies'] as $code => $t) {
            $rows[] = [__('Total'), $code, ...array_map(fn ($k) => (float) (string) $t[$k], ['opening', 'income', 'expense', 'transfers', 'closing'])];
        }
        $this->fill($book->createSheet()->setTitle(__('Comptes')), $rows, [1]);

        foreach (['income' => __('Recettes'), 'expense' => __('Dépenses')] as $type => $title) {
            $currencies = collect($r[$type])->flatMap(fn ($row) => array_keys($row['amounts']))->unique()->sort()->values()->all();
            $rows = [[__('Catégorie'), ...$currencies, __('Équivalent USD')]];
            foreach ($r[$type] as $row) {
                $rows[] = [$row['name'], ...array_map(fn ($c) => $row['amounts'][$c] ?? null, $currencies), $row['usd']];
            }
            $this->fill($book->createSheet()->setTitle($title), $rows, [1]);
        }

        if ($r['departments']) {
            $rows = [[__('Département'), __('Dépenses (équivalent USD)')]];
            foreach ($r['departments'] as $d) {
                $rows[] = [$d['name'], $d['usd']];
            }
            $this->fill($book->createSheet()->setTitle(__('Départements')), $rows, [1]);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function fill(Worksheet $sheet, array $rows, array $bold): void
    {
        $sheet->fromArray($rows, null, 'A1', true);
        foreach ($bold as $line) {
            $sheet->getStyle('A'.$line.':'.$sheet->getHighestColumn().$line)->getFont()->setBold(true);
        }
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('B1:'.$sheet->getHighestColumn().$sheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0.00');
    }
}
