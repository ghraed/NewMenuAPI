<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PosComplaintAdjustment;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsComplaintRefundFixtures;
use Tests\TestCase;

class CompensationReportTest extends TestCase
{
    use BuildsComplaintRefundFixtures, RefreshDatabase;

    private function report(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/pos/compensation-report'.$query);
    }

    private function waived(Invoice $invoice, string $original = '10.00', string $final = '0.00'): int
    {
        return $invoice->items()->create([
            'name' => $this->prefix, 'quantity' => 1, 'unit_price' => $final,
            'line_total' => $final, 'original_line_total' => $original,
            'status' => 'compensated', 'compensation_type' => 'full_waiver',
            'approved_by_staff_name' => $this->admin->name, 'approved_by_staff_role' => 'admin',
        ])->id;
    }

    public function test_real_compensated_checkout_retry_reports_one_persisted_effect_and_rejection_reports_none(): void
    {
        $intent = ['dish_id' => $this->dish->id, 'quantity' => 1, 'status' => 'compensated',
            'compensation_type' => 'complimentary', 'compensation_reason' => 'quality_issue'];
        $payload = ['payment_method' => 'cash', 'items' => [$intent]];
        $headers = ['X-Idempotency-Key' => $this->prefix];
        $this->report()->assertOk()->assertJsonCount(0, 'entries');
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertCreated();
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertOk();
        $currency = $this->restaurant->currency;
        $this->report()->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonPath("totals_by_currency.{$currency}.waived_revenue", '10.00');
        $this->ingredient->update(['current_stock_quantity' => 0]);
        $this->postJson('/api/pos/checkout', $payload, ['X-Idempotency-Key' => $this->prefix.'_failed'])->assertUnprocessable();
        $this->report()->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonPath("totals_by_currency.{$currency}.waived_revenue", '10.00');
    }

    public function test_final_invoice_snapshots_count_once_and_drafts_never_become_losses(): void
    {
        $this->invoice->update(['currency' => 'USD']);
        $id = $this->waived($this->invoice);
        // Mutable order data and abandoned invoice drafts are not financial facts.
        $this->item->update(['status' => 'compensated', 'original_unit_price' => 10, 'final_unit_price' => 0]);
        $draft = Invoice::factory()->create(['restaurant_id' => $this->restaurant->id, 'status' => 'draft', 'currency' => 'USD']);
        $this->waived($draft, '99.00');
        $this->draft('8.00');
        foreach (range(1, 3) as $attempt) {
            $this->report()->assertOk()->assertJsonCount(1, 'entries')
                ->assertJsonPath('entries.0.id', "invoice:{$this->invoice->id}:item:{$id}")
                ->assertJsonPath('totals_by_currency.USD.waived_revenue', '10.00')
                ->assertJsonPath('totals_by_currency.USD.refunded_revenue', '0.00');
        }
        $this->invoice->update(['status' => 'cancelled']);
        $this->report()->assertOk()->assertJsonCount(0, 'entries');
    }

    public function test_posted_refunds_and_gifts_have_distinct_identities_and_metrics(): void
    {
        $this->invoice->update(['currency' => 'USD']);
        $this->order->update(['currency' => 'USD']);
        $this->waived($this->invoice, '10.00', '7.50');
        $id = $this->draft('8.00', ['gifts' => [['dish_id' => $this->dish->id, 'quantity' => 1]]]);
        $this->report()->assertOk()->assertJsonCount(1, 'entries');
        $this->approve($id)->assertOk();
        $this->approve($id)->assertOk();
        $stale = $this->draft('8.00');
        $this->approve($stale)->assertUnprocessable();
        $void = $this->draft('1.00');
        $this->postJson("/api/pos/complaint-adjustments/{$void}/void")->assertOk();
        // A gift's delivery order must not be counted again if it gets an invoice.
        $giftOrder = PosComplaintAdjustment::findOrFail($id)->gift_order_id;
        $giftInvoice = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id]);
        $giftItem = $this->waived($giftInvoice);
        $giftInvoice->items()->whereKey($giftItem)->update(['order_item_id' => \App\Models\Order::findOrFail($giftOrder)->items()->firstOrFail()->id]);
        $report = $this->report()->assertOk()->assertJsonCount(3, 'entries')
            ->assertJsonPath('totals_by_currency.USD.waived_revenue', '2.50')
            ->assertJsonPath('totals_by_currency.USD.refunded_revenue', '8.00')
            ->assertJsonPath('totals_by_currency.USD.gift_catalog_value', '10.00');
        $this->assertCount(3, array_unique(array_column($report->json('entries'), 'id')));
        $this->assertSame('4.000', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_date_filter_uses_local_day_half_open_utc_boundaries_and_posting_time(): void
    {
        $this->invoice->update(['currency' => 'USD', 'paid_at' => '2026-10-06 21:00:00']);
        $this->waived($this->invoice);
        $before = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'paid_at' => '2026-10-06 20:59:59', 'currency' => 'USD']);
        $after = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'paid_at' => '2026-10-07 21:00:00', 'currency' => 'USD']);
        $this->waived($before, '20.00');
        $this->waived($after, '30.00');
        $this->order->update(['currency' => 'USD']);
        $id = $this->draft('2.00');
        $this->approve($id)->assertOk();
        PosComplaintAdjustment::whereKey($id)->update(['posted_at' => '2026-10-07 20:59:59', 'created_at' => '2026-10-01 00:00:00']);
        $this->report('?date_from=2026-10-07&date_to=2026-10-07&timezone=Asia%2FBeirut')->assertOk()
            ->assertJsonCount(2, 'entries')->assertJsonPath('totals_by_currency.USD.waived_revenue', '10.00')
            ->assertJsonPath('totals_by_currency.USD.refunded_revenue', '2.00');
        $this->report('?date_from=2026-10-08&date_to=2026-10-07')->assertUnprocessable();
        $this->report('?timezone=invalid')->assertUnprocessable();
        // Browsers can return IANA aliases, such as Asia/Calcutta.
        $this->report('?timezone=Asia%2FCalcutta')->assertOk()->assertJsonPath('timezone', 'Asia/Kolkata');
    }

    public function test_daylight_saving_boundary_and_single_sided_filters(): void
    {
        $this->invoice->update(['currency' => 'USD', 'paid_at' => '2026-10-23 21:00:00']);
        $this->waived($this->invoice);
        $last = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'currency' => 'USD', 'paid_at' => '2026-10-24 21:59:59']);
        $next = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'currency' => 'USD', 'paid_at' => '2026-10-24 22:00:00']);
        $this->waived($last, '2.00');
        $this->waived($next, '3.00');
        // Beirut's clock moves back: this local day has 25 hours.
        $this->report('?date_from=2026-10-24&date_to=2026-10-24&timezone=Asia%2FBeirut')->assertOk()
            ->assertJsonCount(2, 'entries')->assertJsonPath('totals_by_currency.USD.waived_revenue', '12.00');
        $this->report('?date_to=2026-10-24&timezone=Asia%2FBeirut')->assertOk()->assertJsonCount(2, 'entries');
        $this->report('?date_from=2026-10-25&timezone=Asia%2FBeirut')->assertOk()->assertJsonCount(1, 'entries');
    }

    public function test_supported_readers_missing_tenant_and_unauthenticated_access(): void
    {
        foreach (['staff', 'accountant'] as $role) {
            $reader = User::factory()->attachedToRestaurant($this->restaurant)->create(['role' => $role, 'name' => $this->prefix.'_'.$role]);
            Sanctum::actingAs($reader);
            if ($role === 'accountant') {
                // Preserve the existing dedicated finance surface restriction.
                $this->report()->assertForbidden();
            } else {
                $this->report()->assertOk();
            }
        }
        Sanctum::actingAs(User::factory()->admin()->create(['name' => $this->prefix.'_unlinked']));
        $this->report()->assertForbidden();
        $this->refreshApplication();
        $this->report()->assertUnauthorized();
    }

    public function test_issued_invoice_uses_finalized_snapshot_date_not_draft_or_catalog_state(): void
    {
        $this->invoice->forceFill(['status' => 'issued', 'currency' => 'USD', 'paid_at' => null, 'created_at' => '2026-10-07 12:00:00'])->save();
        $this->waived($this->invoice, '10.00', '7.50');
        $this->dish->update(['price' => '100.00']);
        $this->report('?date_from=2026-10-07&date_to=2026-10-07')->assertOk()
            ->assertJsonPath('totals_by_currency.USD.waived_revenue', '2.50');
    }

    public function test_currencies_are_never_added_or_converted_using_current_rates(): void
    {
        $this->invoice->update(['currency' => 'USD']);
        $this->waived($this->invoice, '10.00', '6.67');
        $eur = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'currency' => 'EUR']);
        $this->waived($eur, '10.00', '7.45');
        $this->restaurant->update(['currency' => 'LBP', 'dollar_rate' => 100000]);
        $this->report()->assertOk()->assertJsonPath('totals_by_currency.USD.waived_revenue', '3.33')
            ->assertJsonPath('totals_by_currency.EUR.waived_revenue', '2.55');
    }

    public function test_tenant_roles_authentication_and_disabled_feature_fail_closed(): void
    {
        $this->waived($this->invoice);
        $other = User::factory()->admin()->create(['name' => $this->prefix.'_other']);
        $tenant = \App\Models\Restaurant::factory()->create(['user_id' => $other->id, 'name' => $this->prefix.'_other']);
        foreach ($this->restaurant->features as $feature) {
            RestaurantFeature::create(['restaurant_id' => $tenant->id, 'feature_id' => $feature->id, 'enabled' => true]);
        }
        Sanctum::actingAs($other);
        $this->report()->assertOk()->assertJsonCount(0, 'entries');
        Sanctum::actingAs(User::factory()->create(['role' => 'chef', 'name' => $this->prefix.'_chef']));
        $this->report()->assertForbidden();
        Sanctum::actingAs($this->admin);
        RestaurantFeature::where('restaurant_id', $this->restaurant->id)->update(['enabled' => false]);
        $this->report()->assertNotFound();
    }
}
