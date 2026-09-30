<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\InventoryStockEntry;
use App\Models\InventoryTransaction;
use App\Observers\InventoryStockEntryObserver;
use App\Observers\InventoryTransactionObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        InventoryStockEntry::observe(InventoryStockEntryObserver::class);
        InventoryTransaction::observe(InventoryTransactionObserver::class);
    }
}
