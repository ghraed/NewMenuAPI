<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class InvoicePdfService
{
    public function __construct(
        private readonly InvoiceSplitService $invoiceSplitService,
    ) {}

    /**
     * @return array{disk:string,path:string}
     */
    public function generateAndStore(Invoice $invoice): array
    {
        if ((bool) config('services.invoice_pdf.force_failure', false)) {
            throw new RuntimeException('Forced invoice PDF failure.');
        }

        $invoice->loadMissing(['items', 'restaurant']);
        $linkedOrders = $this->loadLinkedOrders($invoice);

        $htmlPath = tempnam(sys_get_temp_dir(), 'invoice-html-');
        $pdfPath = tempnam(sys_get_temp_dir(), 'invoice-pdf-');

        if (! is_string($htmlPath) || ! is_string($pdfPath)) {
            throw new RuntimeException('Unable to prepare temporary files for invoice PDF generation.');
        }

        @unlink($htmlPath);
        $htmlPath .= '.html';
        @unlink($pdfPath);
        $pdfPath .= '.pdf';

        try {
            file_put_contents($htmlPath, $this->renderHtml($invoice, $linkedOrders));
            $this->renderPdf($htmlPath, $pdfPath);

            $disk = 'local';
            $relativePath = sprintf(
                'invoices/%d/%s.pdf',
                (int) $invoice->restaurant_id,
                Str::slug((string) $invoice->invoice_number ?: 'invoice-'.$invoice->id)
            );

            Storage::disk($disk)->put($relativePath, (string) file_get_contents($pdfPath));

            $invoice->update([
                'pdf_disk' => $disk,
                'pdf_path' => $relativePath,
                'pdf_generated_at' => now(),
            ]);

            return [
                'disk' => $disk,
                'path' => $relativePath,
            ];
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    private function loadLinkedOrders(Invoice $invoice)
    {
        if (! is_string($invoice->invoice_number) || trim($invoice->invoice_number) === '') {
            return collect();
        }

        return Order::query()
            ->where('restaurant_id', $invoice->restaurant_id)
            ->where('invoice_number', trim($invoice->invoice_number))
            ->where('status', Order::STATUS_ACCOUNTED)
            ->with(['items', 'confirmedBy', 'tableSession.restaurant'])
            ->orderBy('accounted_at')
            ->orderBy('id')
            ->get();
    }

    private function renderPdf(string $htmlPath, string $pdfPath): void
    {
        $browser = (new ExecutableFinder)->find('google-chrome')
            ?? (new ExecutableFinder)->find('google-chrome-stable')
            ?? (new ExecutableFinder)->find('chromium-browser')
            ?? (new ExecutableFinder)->find('chromium');

        if (! is_string($browser) || $browser === '') {
            throw new RuntimeException('No headless Chrome binary is available for invoice PDF generation.');
        }

        $process = new Process([
            $browser,
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--run-all-compositor-stages-before-draw',
            '--print-to-pdf='.$pdfPath,
            '--print-to-pdf-no-header',
            'file://'.$htmlPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if (! is_file($pdfPath) || filesize($pdfPath) === 0) {
            throw new RuntimeException('Invoice PDF was not generated.');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $linkedOrders
     */
    private function renderHtml(Invoice $invoice, $linkedOrders): string
    {
        $restaurant = $invoice->restaurant;
        $tableReference = $linkedOrders
            ->map(fn (Order $order): ?string => $order->table_reference ?: $order->guest_name)
            ->first(fn (?string $value): bool => is_string($value) && trim($value) !== '');
        $waiterName = $linkedOrders
            ->map(fn (Order $order): ?string => $order->confirmedBy?->name)
            ->first(fn (?string $value): bool => is_string($value) && trim($value) !== '');
        $includedOrders = $linkedOrders
            ->map(fn (Order $order): string => $order->order_number ?: 'ORD-'.$order->id)
            ->values()
            ->all();
        $splitMarkup = $this->renderSplitSection($linkedOrders);
        $logoMarkup = $this->renderLogo($restaurant?->logo_path);
        $itemRows = $invoice->items
            ->sortBy('order_index')
            ->map(function ($item): string {
                return sprintf(
                    '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                    $this->escape((string) $item->name),
                    $this->escape((string) $item->quantity),
                    $this->escape((string) $item->unit_price),
                    $this->escape((string) $item->line_total)
                );
            })
            ->implode('');

        $notes = is_string($invoice->notes) && trim($invoice->notes) !== ''
            ? '<section class="notes"><h3>Notes / ملاحظات</h3><p>'.$this->escape($invoice->notes).'</p></section>'
            : '';

        $includedOrdersMarkup = $includedOrders !== []
            ? '<p class="muted">Orders: '.$this->escape(implode(', ', $includedOrders)).'</p>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice {$this->escape((string) $invoice->invoice_number)}</title>
  <style>
    @page { size: A4; margin: 16mm; }
    body {
      font-family: "Noto Naskh Arabic", "Noto Sans Arabic", "Noto Sans", "DejaVu Sans", sans-serif;
      color: #141414;
      font-size: 12px;
      line-height: 1.45;
      margin: 0;
    }
    h1, h2, h3, p { margin: 0; }
    .header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; margin-bottom: 18px; }
    .brand { max-width: 70%; }
    .brand h1 { font-size: 24px; margin-bottom: 6px; }
    .brand .meta { color: #5b5b5b; }
    .logo img { max-width: 120px; max-height: 120px; object-fit: contain; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .card { border: 1px solid #d8d8d8; border-radius: 10px; padding: 12px; }
    .card h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #6a6a6a; margin-bottom: 8px; }
    .muted { color: #666; }
    .items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .items th, .items td { border-bottom: 1px solid #e4e4e4; padding: 8px 6px; text-align: left; vertical-align: top; }
    .items th { font-size: 11px; text-transform: uppercase; color: #666; }
    .num { text-align: right; white-space: nowrap; }
    .summary { margin-left: auto; width: 320px; border-collapse: collapse; }
    .summary td { padding: 6px 0; }
    .summary .total td { font-size: 16px; font-weight: 700; border-top: 1px solid #1c1c1c; padding-top: 10px; }
    .notes { margin-top: 16px; }
    .notes h3, .split h3 { margin-bottom: 6px; }
    .split table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .split td, .split th { border-bottom: 1px solid #e4e4e4; padding: 6px; text-align: left; }
  </style>
</head>
<body>
  <header class="header">
    <div class="brand">
      <h1>{$this->escape((string) ($restaurant?->name ?? 'Restaurant'))}</h1>
      <p class="meta">Invoice {$this->escape((string) $invoice->invoice_number)}</p>
      <p class="meta">Date: {$this->escape((string) $invoice->invoice_date?->toDateString())}</p>
      <p class="meta">Currency: {$this->escape((string) $invoice->currency)} | Exchange rate: {$this->escape((string) $invoice->exchange_rate)}</p>
      {$includedOrdersMarkup}
    </div>
    <div class="logo">{$logoMarkup}</div>
  </header>

  <section class="grid">
    <div class="card">
      <h3>Invoice</h3>
      <p>Status: {$this->escape((string) $invoice->status)}</p>
      <p>Table: {$this->escape((string) ($tableReference ?: '-'))}</p>
      <p>Waiter: {$this->escape((string) ($waiterName ?: '-'))}</p>
    </div>
    <div class="card">
      <h3>Payment</h3>
      <p>Method: {$this->escape((string) ($invoice->payment_method ?: '-'))}</p>
      <p>Reference: {$this->escape((string) ($invoice->payment_reference ?: '-'))}</p>
      <p>Paid at: {$this->escape((string) ($invoice->paid_at?->toIso8601String() ?: '-'))}</p>
    </div>
  </section>

  <table class="items">
    <thead>
      <tr>
        <th>Item / الصنف</th>
        <th class="num">Qty</th>
        <th class="num">Unit</th>
        <th class="num">Line</th>
      </tr>
    </thead>
    <tbody>
      {$itemRows}
    </tbody>
  </table>

  <table class="summary">
    <tr><td>Subtotal</td><td class="num">{$this->escape((string) $invoice->subtotal)}</td></tr>
    <tr><td>Discount</td><td class="num">{$this->escape((string) $invoice->discount_amount)}</td></tr>
    <tr><td>Taxable subtotal</td><td class="num">{$this->escape((string) $invoice->taxable_subtotal)}</td></tr>
    <tr><td>Service charge ({$this->escape((string) ($invoice->service_charge_rate ?? '0.00'))}%)</td><td class="num">{$this->escape((string) $invoice->service_charge_amount)}</td></tr>
    <tr><td>VAT ({$this->escape((string) ($invoice->vat_rate ?? '0.00'))}%)</td><td class="num">{$this->escape((string) $invoice->vat_amount)}</td></tr>
    <tr class="total"><td>Total</td><td class="num">{$this->escape((string) $invoice->total)}</td></tr>
  </table>

  {$notes}
  {$splitMarkup}
</body>
</html>
HTML;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $linkedOrders
     */
    private function renderSplitSection($linkedOrders): string
    {
        $sessionIds = $linkedOrders->pluck('table_session_id')
            ->filter()
            ->unique()
            ->values();

        if ($sessionIds->count() !== 1) {
            return '';
        }

        $sessionId = $sessionIds->first();
        $session = TableSession::query()->find($sessionId);
        if (! $session) {
            return '';
        }

        $split = $this->invoiceSplitService->buildPayload(
            $session,
            $linkedOrders,
            feature_enabled('invoice_splitting', $session->restaurant)
        );

        if (($split['breakdown'] ?? []) === []) {
            return '';
        }

        $rows = collect($split['breakdown'])
            ->map(fn (array $row): string => sprintf(
                '<tr><td>%s</td><td class="num">%s</td></tr>',
                $this->escape((string) $row['label']),
                $this->escape((string) $row['amount'])
            ))
            ->implode('');

        return '<section class="split"><h3>Split Summary / تقسيم الفاتورة</h3><table><thead><tr><th>Share</th><th class="num">Amount</th></tr></thead><tbody>'.$rows.'</tbody></table></section>';
    }

    private function renderLogo(?string $logoPath): string
    {
        if (! is_string($logoPath) || trim($logoPath) === '') {
            return '';
        }

        if (! Storage::disk('public')->exists($logoPath)) {
            return '';
        }

        $contents = Storage::disk('public')->get($logoPath);
        $mime = Storage::disk('public')->mimeType($logoPath) ?: 'image/png';

        return '<img alt="Restaurant logo" src="data:'.$mime.';base64,'.base64_encode($contents).'">';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
