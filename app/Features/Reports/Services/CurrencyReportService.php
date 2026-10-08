<?php

namespace App\Features\Reports\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CurrencyReportService
{
    /**
     * Native cents, completed payments only, within the caller's authorized
     * scope and date filters. No refund subtraction or fee recalculation:
     * refunded payments remain excluded, as in the original reports.
     *
     * @return Collection<int, array{currency: string, totalPayments: int, totalRevenue: int, equitabEarnings: int}>
     */
    public function completedPayments(Builder $payments): Collection
    {
        return (clone $payments)->where('status', 'completed')
            ->reorder()->select('currency')
            ->selectRaw('COUNT(*) AS payment_count, SUM(amount) AS revenue, SUM(platform_fee_amount) AS earnings')
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'totalPayments' => (int) $row->payment_count,
                'totalRevenue' => (int) $row->revenue,
                // SUM historically ignored missing fees; rows keep their null.
                'equitabEarnings' => (int) $row->earnings,
            ]);
    }

    /**
     * The member payment history has always shown the completed subtotal of
     * the current page, independently of its client-side status filter.
     *
     * @return Collection<int, array{currency: string, amount: int}>
     */
    public function completedPaymentsOnPage(Collection $payments): Collection
    {
        return $payments->where('status', 'completed')->groupBy('currency')
            ->sortKeys()->map(fn ($rows, $currency) => [
                'currency' => $currency,
                'amount' => $rows->sum('amount'),
            ])->values();
    }

    /**
     * Current monthly shares, including the owner's membership as before.
     * Savings are catalogue comparisons, not historical receipts. If any
     * comparison is unavailable, that currency's savings total is null rather
     * than a misleading partial sum. Empty membership returns no currency.
     */
    public function monthlyMemberships(User $user): array
    {
        $memberships = $user->groupMembers()->with('group.subscription')
            ->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->whereNotIn('status', ['closed']))
            ->get();

        $totals = $memberships->groupBy('group.currency')->sortKeys()
            ->map(function ($members, $currency) {
                $spend = 0;
                $savings = 0;
                $missingShares = 0;
                $unavailableSavings = 0;

                foreach ($members as $member) {
                    $share = $member->share_amount;
                    $catalogue = $member->group->subscription;
                    if ($share === null) {
                        $missingShares++;
                    } else {
                        $spend += $share;
                    }
                    if ($share === null || $catalogue?->monthly_price === null
                        || $catalogue->currency !== $currency) {
                        $unavailableSavings++;
                    } else {
                        $savings += $catalogue->monthly_price - $share;
                    }
                }

                return [
                    'currency' => $currency,
                    'monthlySpend' => $missingShares ? null : $spend,
                    'totalSavings' => $unavailableSavings ? null : $savings,
                    'unavailableSavingsCount' => $unavailableSavings,
                ];
            })->values();

        return [
            'activeSubscriptionsCount' => $memberships->count(),
            'monthlyTotalsByCurrency' => $totals,
        ];
    }
}
