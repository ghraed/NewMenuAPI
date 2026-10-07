<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\PosComplaintAdjustment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompensationReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Older browser ICU data uses this IANA name; some PHP tzdata builds
        // include only the replacement. Both names describe the same zone.
        if ($request->input('timezone') === 'Asia/Calcutta') {
            $request->merge(['timezone' => 'Asia/Kolkata']);
        }
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ]);
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user->currentRestaurant() ?? abort(403, 'No restaurant is linked to this account');
        $timezone = $filters['timezone'] ?? 'UTC';
        $from = empty($filters['date_from']) ? null : CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay()->utc();
        $until = empty($filters['date_to']) ? null : CarbonImmutable::parse($filters['date_to'], $timezone)->startOfDay()->addDay()->utc();

        // One read snapshot prevents a concurrent posting appearing without its gifts.
        return DB::transaction(function () use ($restaurant, $timezone, $from, $until): JsonResponse {
            $entries = [];
            $totals = [];
            $add = function (array $entry, int $cents) use (&$entries, &$totals): void {
                $currency = $entry['currency'];
                $totals[$currency] ??= ['waived_revenue' => 0, 'refunded_revenue' => 0, 'gift_catalog_value' => 0];
                $totals[$currency][$entry['financial_kind']] += $cents;
                $entries[] = $entry;
            };
            $inRange = fn ($date) => $date && (! $from || $date->gte($from)) && (! $until || $date->lt($until));
            $base = fn (string $id, $date, string $currency, string $kind) => [
                'id' => $id, 'created_at' => $date->copy()->setTimezone($timezone)->toIso8601String(),
                'currency' => $currency, 'financial_kind' => $kind, 'source' => 'invoice', 'action' => 'checkout',
                'status' => 'compensated', 'compensation_type' => 'none', 'quantity' => 0,
                'original_amount' => 0, 'final_amount' => 0, 'is_complimentary' => false,
            ];

            // Invoice snapshots, rather than mutable orders or browser audit events,
            // are the pre-sale source. Gift delivery orders are represented below.
            $giftItems = OrderItem::query()->whereIn('order_id', PosComplaintAdjustment::query()
                ->where('restaurant_id', $restaurant->id)->whereNotNull('gift_order_id')->select('gift_order_id'))->pluck('id')->flip();
            $invoices = Invoice::query()->where('restaurant_id', $restaurant->id)
                ->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_ISSUED])->with('items')->get();
            foreach ($invoices as $invoice) {
                $date = $invoice->paid_at ?? $invoice->created_at;
                if (! $inRange($date)) {
                    continue;
                }
                foreach ($invoice->items as $item) {
                    if ($item->order_item_id && $giftItems->has($item->order_item_id)) {
                        continue;
                    }
                    $original = $item->original_line_total !== null ? Money::toCents($item->original_line_total)
                        : Money::multiplyToCents($item->quantity, $item->original_unit_price ?? $item->unit_price);
                    $final = Money::toCents($item->line_total);
                    $cents = max(0, $original - $final);
                    if (! $cents && ($item->status ?? 'normal') === 'normal' && ! $item->is_complimentary) {
                        continue;
                    }
                    $entry = array_merge($base("invoice:{$invoice->id}:item:{$item->id}", $date, $invoice->currency, 'waived_revenue'), [
                        'order_reference' => $invoice->invoice_number, 'dish_name' => $item->name,
                        'quantity' => (float) $item->quantity, 'status' => $item->status ?? 'normal',
                        'compensation_type' => $item->compensation_type ?? 'none',
                        'compensation_reason' => $item->compensation_reason, 'complaint_category' => $item->complaint_category,
                        'accounting_bucket' => $item->accounting_bucket, 'is_complimentary' => (bool) $item->is_complimentary,
                        'original_amount' => $original / 100, 'final_amount' => $final / 100, 'loss_amount' => $cents / 100,
                        'approved_by' => $item->approved_by_staff_name ? ['name' => $item->approved_by_staff_name, 'role' => $item->approved_by_staff_role] : null,
                    ]);
                    $add($entry, $cents);
                }
            }
            $adjustments = PosComplaintAdjustment::query()->where('restaurant_id', $restaurant->id)
                ->where('status', 'posted')->whereNotNull('posted_at')->with(['gifts', 'originalOrder', 'originalInvoice'])->get();
            $approvers = User::query()->whereIn('id', $adjustments->pluck('approved_by'))->get()->keyBy('id');
            foreach ($adjustments as $adjustment) {
                if (! $inRange($adjustment->posted_at)) {
                    continue;
                }
                $currency = $adjustment->originalInvoice?->currency ?? $adjustment->originalOrder?->currency;
                if (! $currency) {
                    continue;
                }
                $approver = $approvers->get($adjustment->approved_by);
                $common = ['order_reference' => $adjustment->original_invoice_number,
                    'compensation_reason' => $adjustment->complaint_reason, 'complaint_category' => $adjustment->complaint_category,
                    'accounting_bucket' => $adjustment->accounting_bucket,
                    'approved_by' => $approver ? ['id' => $approver->id, 'name' => $approver->name, 'role' => $approver->role] : null];
                $refund = Money::toCents($adjustment->refund_amount);
                if ($refund > 0) {
                    $add(array_merge($base("adjustment:{$adjustment->id}:refund", $adjustment->posted_at, $currency, 'refunded_revenue'), $common, [
                        'dish_name' => 'Sale refund', 'loss_amount' => $refund / 100,
                    ]), $refund);
                }
                foreach ($adjustment->gifts as $gift) {
                    $value = Money::toCents($gift->line_value);
                    $add(array_merge($base("adjustment:{$adjustment->id}:gift:{$gift->id}", $adjustment->posted_at, $currency, 'gift_catalog_value'), $common, [
                        'dish_name' => $gift->dish_name_snapshot, 'quantity' => (float) $gift->quantity,
                        'is_complimentary' => true, 'compensation_type' => 'complimentary', 'loss_amount' => $value / 100,
                    ]), $value);
                }
            }
            foreach ($totals as &$amounts) {
                $amounts = array_map(fn (int $cents) => Money::formatCents($cents), $amounts);
            }
            unset($amounts);

            return response()->json(['entries' => $entries, 'totals_by_currency' => (object) $totals, 'timezone' => $timezone]);
        });
    }
}
