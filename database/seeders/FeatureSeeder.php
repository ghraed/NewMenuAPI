<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const FEATURES = [
        ['key' => 'qr_menu', 'name' => 'QR Menu', 'description' => 'Guest menu access through QR tables and links.', 'category' => 'Menu'],
        ['key' => 'table_ordering', 'name' => 'Table Ordering', 'description' => 'Guests can place orders directly from table sessions.', 'category' => 'Orders'],
        ['key' => 'waiter_call', 'name' => 'Waiter Call', 'description' => 'Guests can request waiter help from their table.', 'category' => 'Service'],
        ['key' => 'request_bill', 'name' => 'Request Bill', 'description' => 'Guests can request bill assistance digitally.', 'category' => 'Service'],
        ['key' => 'inventory', 'name' => 'Inventory', 'description' => 'Ingredient inventory tracking tools.', 'category' => 'Inventory'],
        ['key' => 'ingredient_stock_deduction', 'name' => 'Ingredient Stock Deduction', 'description' => 'Deduct ingredient stock from confirmed orders.', 'category' => 'Inventory'],
        ['key' => 'finance_dashboard', 'name' => 'Finance Dashboard', 'description' => 'Revenue, invoice, and finance overview dashboard.', 'category' => 'Finance'],
        ['key' => 'vat_invoices', 'name' => 'VAT Invoices', 'description' => 'Issue tax-ready VAT invoices.', 'category' => 'Finance'],
        ['key' => 'expense_management', 'name' => 'Expense Management', 'description' => 'Track and classify operating expenses.', 'category' => 'Finance'],
        ['key' => 'payroll_management', 'name' => 'Payroll Management', 'description' => 'Manage payroll periods, entries, and summaries.', 'category' => 'Finance'],
        ['key' => 'dish_profitability', 'name' => 'Dish Profitability', 'description' => 'Show cost and margin analytics per dish.', 'category' => 'Finance'],
        ['key' => 'invoice_splitting', 'name' => 'Invoice Splitting', 'description' => 'Allow guests and staff to split table invoice totals by order or equally.', 'category' => 'Finance'],
        ['key' => 'ai_recommendations', 'name' => 'AI Recommendations', 'description' => 'AI dish recommendations and alternatives.', 'category' => 'AI'],
        ['key' => 'ai_chatbot', 'name' => 'AI Chatbot', 'description' => 'Conversational guest assistant for menu flows.', 'category' => 'AI'],
        ['key' => 'ar_3d_dishes', 'name' => 'AR 3D Dishes', 'description' => 'Augmented reality and 3D dish previews.', 'category' => 'AR/3D'],
        ['key' => 'animated_ingredients', 'name' => 'Animated Ingredients', 'description' => 'Animated ingredient storytelling in dish view.', 'category' => 'AR/3D'],
        ['key' => 'push_notifications', 'name' => 'Push Notifications', 'description' => 'Browser or device push notifications.', 'category' => 'Notifications'],
        ['key' => 'realtime_staff_orders', 'name' => 'Realtime Staff Orders', 'description' => 'Realtime incoming orders for staff dashboards.', 'category' => 'Orders'],
        ['key' => 'room_plan_editor', 'name' => 'Room Plan Editor', 'description' => 'Create and maintain visual room plan layouts.', 'category' => 'Operations'],
        ['key' => 'table_reservations', 'name' => 'Table Reservations', 'description' => 'Allow table reservations with room-plan availability checks.', 'category' => 'Operations'],
        ['key' => 'staff_scheduling', 'name' => 'Staff Scheduling', 'description' => 'Schedule staff shifts and track staffing calendars.', 'category' => 'Operations'],
        ['key' => 'analytics', 'name' => 'Analytics', 'description' => 'Visitor, interaction, and conversion analytics.', 'category' => 'Analytics'],
        ['key' => 'multi_language', 'name' => 'Multi-language', 'description' => 'Enable multi-language guest menu experiences.', 'category' => 'Localization'],
        ['key' => 'custom_domain', 'name' => 'Custom Domain', 'description' => 'Allow tenant custom domains and host mapping.', 'category' => 'Domain'],
    ];

    public function run(): void
    {
        foreach (self::FEATURES as $feature) {
            Feature::query()->updateOrCreate(
                ['key' => $feature['key']],
                [
                    'name' => $feature['name'],
                    'description' => $feature['description'],
                    'category' => $feature['category'],
                    'is_active_by_default' => false,
                ]
            );
        }
    }
}
