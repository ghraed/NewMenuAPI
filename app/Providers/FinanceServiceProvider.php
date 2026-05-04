<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Contracts\FinanceCalculatorContract;
use App\Domain\Finance\FinanceCalculator;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FinanceCalculatorContract::class, FinanceCalculator::class);
    }
}
