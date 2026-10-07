<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosComplaintAdjustment;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsComplaintRefundFixtures;
use Tests\TestCase;

class PosComplaintRefundSafetyTest extends TestCase
{
    use BuildsComplaintRefundFixtures, RefreshDatabase;

    public function test_stale_drafts_cannot_exceed_sale_and_reports_use_actual_postings(): void
    {
        $first = $this->draft();
        $stale = $this->draft('8.00', ['gifts' => [['dish_id' => $this->dish->id, 'quantity' => 1]]]);
        $this->approve($first)->assertOk();
        $orders = Order::query()->count();
        $this->approve($stale)->assertUnprocessable()->assertJsonValidationErrors('refund_amount');
        $this->assertSame($orders, Order::query()->count());
        $this->assertSame('5.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $stale, 'status' => 'draft', 'approved_by' => null, 'posted_at' => null, 'gift_order_id' => null]);
        $this->assertReport(800, 200);
        $remaining = $this->draft('2.00');
        $this->approve($remaining)->assertOk();
        $this->assertReport(1000, 0);
        $this->assertSame('10.00', $this->invoice->fresh()->total);
        $this->assertSame('10.00', $this->order->fresh()->total);
    }

    public function test_creation_retries_remain_separate_nonfinancial_drafts(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        $this->assertNotSame($a, $b);
        $this->assertReport(0, 1000);
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->approve($a)->assertOk();
        $this->assertReport(800, 200);
    }

    public function test_shared_invoice_discount_caps_refunds_across_orders(): void
    {
        $other = $this->sale();
        $this->invoice->update(['total' => '10.01']); // Two $10 waves settled together with a discount.
        $a = $this->draft();
        $b = $this->draft('8.00', [], $other);
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->approve($this->draft('2.01', [], $other))->assertOk();
        $this->assertReport(1001, 0);
    }

    public function test_cent_boundary_and_per_order_cap(): void
    {
        $this->invoice->update(['total' => '20.00']);
        $a = $this->draft('9.99');
        $b = $this->draft('0.02');
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->approve($this->draft('0.01'))->assertOk();
        $this->assertSame('10.00', PosComplaintAdjustment::query()->where('status', 'posted')->sum('refund_amount'));
    }

    public function test_already_posted_refund_has_no_restock_and_cannot_be_voided(): void
    {
        $id = $this->draft();
        $this->approve($id)->assertOk();
        $before = PosComplaintAdjustment::findOrFail($id)->getAttributes();
        $this->postJson("/api/pos/complaint-adjustments/{$id}/void")->assertUnprocessable();
        $this->approve($id)->assertOk();
        $this->assertSame($before, PosComplaintAdjustment::findOrFail($id)->getAttributes());
        $this->assertSame('5.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_voided_draft_is_not_posted_or_counted(): void
    {
        $id = $this->draft();
        $this->postJson("/api/pos/complaint-adjustments/{$id}/void")->assertOk();
        $this->approve($id)->assertUnprocessable();
        $this->approve($this->draft('10.00'))->assertOk();
        $this->assertReport(1000, 0);
    }

    public function test_staff_cannot_approve_and_forged_identity_is_ignored(): void
    {
        $staff = User::factory()->staff()->create(['name' => $this->prefix.'_staff', 'email' => $this->prefix.'_staff@example.invalid']);
        $this->restaurant->staffUsers()->attach($staff->id);
        Sanctum::actingAs($staff);
        $id = $this->draft('2.00', ['approved_by' => $this->admin->id, 'status' => 'posted']);
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $id, 'status' => 'pending_approval', 'approved_by' => null]);
        $this->approve($id)->assertForbidden();
        $this->postJson("/api/pos/complaint-adjustments/{$id}/void")->assertForbidden();
        $accountant = User::factory()->accountant()->create(['name' => $this->prefix.'_accountant', 'email' => $this->prefix.'_accountant@example.invalid']);
        $this->restaurant->staffUsers()->attach($accountant->id);
        Sanctum::actingAs($accountant);
        // Existing restricted-role middleware denies this POS route to accountants.
        $this->approve($id)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pos/complaint-adjustments/{$id}/post", ['approved_by' => $staff->id])->assertOk();
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $id, 'approved_by' => $this->admin->id]);
    }

    public function test_foreign_sale_item_gift_and_adjustment_are_denied(): void
    {
        $id = $this->draft();
        $foreignAdmin = User::factory()->admin()->create(['name' => $this->prefix.'_foreign', 'email' => $this->prefix.'_foreign@example.invalid']);
        $other = Restaurant::factory()->create(['user_id' => $foreignAdmin->id, 'name' => $this->prefix.'_foreign', 'slug' => strtolower($this->prefix).'_foreign']);
        $foreignDish = Dish::factory()->published()->create(['restaurant_id' => $other->id, 'name' => $this->prefix.'_foreign']);
        $foreignOrder = Order::factory()->accounted()->create(['restaurant_id' => $other->id, 'restaurant_table_id' => null, 'guest_name' => $this->prefix, 'guest_phone' => null, 'guest_email' => null]);
        $foreignItem = OrderItem::factory()->create(['order_id' => $foreignOrder->id, 'dish_id' => $foreignDish->id, 'dish_name' => $this->prefix]);
        $payload = ['complaint_reason' => $this->prefix, 'refund_amount' => 1, 'affected_item_ids' => [$foreignItem->id]];
        $this->postJson("/api/pos/orders/{$foreignOrder->id}/complaint-adjustments", $payload)->assertNotFound();
        $this->postJson("/api/pos/orders/{$this->order->id}/complaint-adjustments", $payload)->assertUnprocessable();
        $payload['affected_item_ids'] = [$this->item->id];
        $payload['gifts'] = [['dish_id' => $foreignDish->id, 'quantity' => 1]];
        $this->postJson("/api/pos/orders/{$this->order->id}/complaint-adjustments", $payload)->assertUnprocessable();
        Sanctum::actingAs($other->user);
        $this->approve($id)->assertNotFound();
        $this->postJson("/api/pos/complaint-adjustments/{$id}/void")->assertNotFound();
    }

    public function test_gift_failure_rolls_back_refund_and_success_deducts_once(): void
    {
        $id = $this->draft('2.00', ['gifts' => [['dish_id' => $this->dish->id, 'quantity' => 6]]]);
        $beforeOrders = Order::query()->count();
        $this->approve($id)->assertUnprocessable();
        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $id, 'status' => 'draft', 'gift_order_id' => null, 'posted_at' => null]);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertReport(0, 1000);
        $this->ingredient->update(['current_stock_quantity' => '10.000']);
        $this->approve($id)->assertOk();
        $this->approve($id)->assertOk();
        $this->assertSame('4.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame($beforeOrders + 1, Order::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, DB::table('order_item_ingredient_usages')->count());
    }

    public function test_disabled_ordering_feature_denies_adjustments(): void
    {
        $id = $this->draft();
        $feature = Feature::where('key', 'table_ordering')->firstOrFail();
        RestaurantFeature::where('restaurant_id', $this->restaurant->id)->where('feature_id', $feature->id)->update(['enabled' => false]);
        $this->approve($id)->assertNotFound();
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $id, 'status' => 'draft']);
    }

    public function test_unsettled_invoice_and_order_are_revalidated_at_posting(): void
    {
        $id = $this->draft();
        $this->invoice->update(['status' => Invoice::STATUS_DRAFT]);
        $this->approve($id)->assertUnprocessable();
        $this->invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->approve($id)->assertUnprocessable();
        $this->invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->order->update(['status' => Order::STATUS_STAFF_CANCELLED]);
        $this->approve($id)->assertUnprocessable();
        $this->assertDatabaseHas('pos_complaint_adjustments', ['id' => $id, 'status' => 'draft']);
    }

    public function test_legacy_sale_without_finance_invoice_still_has_cumulative_cap(): void
    {
        $this->invoice->delete();
        $a = $this->draft();
        $b = $this->draft();
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->approve($this->draft('2.00'))->assertOk();
    }

    public function test_legacy_missing_invoice_link_is_included_in_shared_balance(): void
    {
        $other = $this->sale();
        $a = $this->draft();
        $b = $this->draft('8.00', [], $other);
        PosComplaintAdjustment::whereKey($a)->update(['original_invoice_id' => null]);
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->assertReport(800, 200);
    }

    public function test_real_checkout_refund_does_not_restore_consumed_ingredients(): void
    {
        $sale = $this->postJson('/api/pos/checkout', [
            'items' => [['dish_id' => $this->dish->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'notes' => $this->prefix,
        ])->assertCreated();
        $order = Order::findOrFail($sale->json('order.id'));
        $a = $this->draft('8.00', [], $order);
        $b = $this->draft('8.00', [], $order);
        $beforeStock = $this->ingredient->fresh()->current_stock_quantity;
        $beforeMovements = StockMovement::query()->count();
        $beforeUsage = DB::table('order_item_ingredient_usages')->count();
        $this->assertSame('4.000', $beforeStock);
        $this->assertSame(1, $beforeMovements);
        $this->approve($a)->assertOk();
        $this->approve($b)->assertUnprocessable();
        $this->approve($this->draft('2.00', [], $order))->assertOk();
        $this->assertSame($beforeStock, $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame($beforeMovements, StockMovement::query()->count());
        $this->assertSame($beforeUsage, DB::table('order_item_ingredient_usages')->count());
    }
}
