<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\FinanceCategory;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les chiffres de chaque niveau, additionnés de ses niveaux inférieurs :
 * effectifs, recettes et dépenses en dollars, présence au culte, et les
 * paroisses qui tardent à saisir.
 */
class Consolidation
{
    /** Au-delà, une paroisse sans nouvelle opération est signalée. */
    public const LATE_DAYS = 14;

    /** Les catégories des quotes-parts : un mouvement interne au réseau, retiré des totaux consolidés. */
    public const QUOTA_CATEGORIES = ['Quotes-parts reçues', 'Quote-part versée au niveau supérieur'];

    /**
     * Une ligne par niveau directement en dessous (ou le niveau lui-même s'il n'en a pas),
     * chacune avec les chiffres de tout ce qui est sous elle.
     *
     * @return array{units: Collection, totals: array, late: Collection}
     */
    public function report(Organization $organization, Carbon $from, Carbon $to): array
    {
        $children = $organization->children()->orderBy('name')->get();
        $units = ($children->isEmpty() ? collect([$organization]) : $children->prepend($organization))
            ->map(fn (Organization $unit) => $this->figures($unit, $from, $to, own: $unit->is($organization) && $children->isNotEmpty()));

        $totals = $this->figures($organization, $from, $to);
        $late = Organization::query()->subtreeOf($organization)->get()
            ->filter(fn (Organization $o) => $o->children()->doesntExist())
            ->map(fn (Organization $o) => ['organization' => $o, 'last' => $this->lastEntry([$o->id])])
            ->filter(fn ($r) => $this->isLate($r['organization'], $r['last']))->sortBy('organization.name')->values();

        return ['units' => $units->filter(fn ($u) => ! $u['own'] || $u['income'] || $u['expense'] || $u['members'])->values(), 'totals' => $totals, 'late' => $late];
    }

    /** Les chiffres d'un niveau : le sien seul ($own), ou avec tous ses niveaux inférieurs. */
    public function figures(Organization $unit, Carbon $from, Carbon $to, bool $own = false): array
    {
        $ids = $own ? [$unit->id] : Organization::query()->subtreeOf($unit)->pluck('id')->all();
        $quota = FinanceCategory::withoutOrganizationScope()->whereIn('organization_id', $ids)->whereIn('name', self::QUOTA_CATEGORIES)->pluck('id');
        $moves = FinanceTransaction::withoutOrganizationScope()->valid()->whereIn('organization_id', $ids)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])->whereIn('type', ['income', 'expense'])
            ->when($quota->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhereNotIn('category_id', $quota)))
            ->selectRaw('type, sum(usd_amount) as usd')->groupBy('type')->pluck('usd', 'type');
        $members = Member::withoutOrganizationScope()->whereIn('organization_id', $ids)
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereHas('status', fn ($q) => $q->where('counts_as_member', true)));
        $last = $this->lastEntry($ids);
        $closed = FinanceClosing::withoutOrganizationScope()->whereIn('organization_id', $ids)->where('status', 'closed')
            ->where('year', $from->year)->where('month', $from->month)->count();

        return [
            'organization' => $unit, 'own' => $own, 'count' => count($ids),
            'members' => (clone $members)->count(),
            'new_members' => (clone $members)->whereBetween('joined_on', [$from->toDateString(), $to->toDateString()])->count(),
            'income' => round((float) ($moves['income'] ?? 0), 2),
            'expense' => round((float) ($moves['expense'] ?? 0), 2),
            'result' => round((float) ($moves['income'] ?? 0) - (float) ($moves['expense'] ?? 0), 2),
            'attendance' => $this->attendance($ids, $from, $to),
            'last_entry' => $last,
            'late' => ! $own && count($ids) === 1 && $this->isLate($unit, $last),
            'closed' => $closed,
        ];
    }

    /** La présence moyenne au culte principal de chaque niveau (le plus fréquenté), additionnée. */
    private function attendance(array $ids, Carbon $from, Carbon $to): ?int
    {
        $records = AttendanceRecord::withoutOrganizationScope()->whereIn('organization_id', $ids)->whereNotNull('total')
            ->whereBetween('occurs_on', [$from->toDateString(), $to->toDateString()])->get(['organization_id', 'event_id', 'total']);
        if ($records->isEmpty()) {
            return null;
        }

        return (int) round($records->groupBy('organization_id')->sum(fn ($rs) => $rs->groupBy('event_id')->map(fn ($e) => $e->avg('total'))->max()));
    }

    private function lastEntry(array $ids): ?Carbon
    {
        $last = FinanceTransaction::withoutOrganizationScope()->whereIn('organization_id', $ids)->max('created_at');

        return $last ? Carbon::parse($last) : null;
    }

    private function isLate(Organization $organization, ?Carbon $last): bool
    {
        $since = $last ?? $organization->created_at;

        return $since !== null && $since->lt(now()->subDays(self::LATE_DAYS));
    }
}
