<?php

namespace App\Http\Controllers;

use App\Services\FinanceReports;
use App\Services\FinanceReportSpreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Rapport financier : version imprimable (et PDF) et classeur Excel. */
class FinanceReportController extends Controller
{
    private function period(Request $request, FinanceReports $reports): array
    {
        abort_unless(Gate::allows('finance.reports'), 403);
        $year = (int) $request->query('annee', now()->year);
        $month = max(0, min(12, (int) $request->query('mois', 0)));
        abort_unless($year >= 2000 && $year <= now()->year + 1, 404);
        $organization = current_organization();
        [$from, $to, $closing, $title] = $reports->selection($organization, $year, $month);

        return [$organization, $year, $month, $reports->period($organization, $from, $to), $closing, $title];
    }

    public function print(Request $request, FinanceReports $reports)
    {
        [$organization, $year, $month, $report, $closing, $title] = $this->period($request, $reports);

        return view('finances.report', [
            'organization' => $organization,
            'identity' => $organization->documentIdentity(),
            'r' => $report,
            'closing' => $closing,
            'title' => __('Rapport financier : :p', ['p' => $title]),
            'query' => ['annee' => $year, 'mois' => $month],
        ]);
    }

    public function excel(Request $request, FinanceReports $reports, FinanceReportSpreadsheet $spreadsheet)
    {
        [$organization, $year, $month, $report, $closing] = $this->period($request, $reports);
        $book = $spreadsheet->build($organization, $report, $closing);
        $period = $month ? $report['from']->format('Y-m') : (string) $year;

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            'rapport-financier-'.Str::slug($organization->displayName()).'-'.$period.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
