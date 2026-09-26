<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Catalog\Http\Controllers\CatalogSettingsController;
use App\Modules\Catalog\Http\Controllers\ItemController;
use App\Modules\Catalog\Http\Controllers\MenuCategoryController;
use App\Modules\Catalog\Http\Controllers\MenuToolsController;
use App\Modules\Catalog\Http\Controllers\ModifierGroupController;
use App\Modules\Catalog\Http\Controllers\PromotionController;
use App\Modules\Catalog\Http\Controllers\QuoteController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\Identity\Http\Controllers\PosAuthController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\StaffController;
use App\Modules\Inventory\Http\Controllers\FoodCostController;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\RecipeController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockDocumentController;
use App\Modules\Inventory\Http\Controllers\StockLocationController;
use App\Modules\Payment\Http\Controllers\PaymentWebhookController;
use App\Modules\Payment\Http\Controllers\PosPaymentController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Reporting\Http\Controllers\ReportScheduleController;
use App\Modules\Sales\Http\Controllers\BackofficeSalesController;
use App\Modules\Sales\Http\Controllers\OpenBillController;
use App\Modules\Sales\Http\Controllers\PosSalesController;
use App\Modules\Sync\Http\Controllers\SyncController;
use App\Modules\Tenancy\Http\Controllers\BrandController;
use App\Modules\Tenancy\Http\Controllers\CompanyProfileController;
use App\Modules\Tenancy\Http\Controllers\DeviceController;
use App\Modules\Tenancy\Http\Controllers\DeviceSessionController;
use App\Modules\Tenancy\Http\Controllers\OutletController;
use App\Modules\Tenancy\Http\Controllers\PlatformCompanyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Publik
    Route::middleware('throttle:login')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('devices/pair', [DeviceSessionController::class, 'pair']);
    });

    // Notifikasi payment gateway (tanda tangan diverifikasi di controller)
    Route::post('webhooks/payment/{provider}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:webhook')
        ->where('provider', '[a-z0-9_-]{1,30}');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        // Akun back-office / owner app (belum memilih company)
        Route::middleware('actor:user')->group(function (): void {
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::get('auth/invitations', [InvitationController::class, 'index']);
            Route::post('auth/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
            Route::post('auth/invitations/{invitation}/decline', [InvitationController::class, 'decline']);
            Route::post('auth/logout', [AuthController::class, 'logout']);

            Route::prefix('platform')->group(function (): void {
                Route::get('companies', [PlatformCompanyController::class, 'index']);
                Route::post('companies', [PlatformCompanyController::class, 'store']);
                Route::post('companies/{company}/suspend', [PlatformCompanyController::class, 'suspend']);
                Route::post('companies/{company}/activate', [PlatformCompanyController::class, 'activate']);
                Route::delete('companies/{company}', [PlatformCompanyController::class, 'destroy']);
            });
        });

        // Back-office dalam konteks company (header X-Company-Id)
        Route::middleware(['actor:user', 'tenant', 'writable'])->group(function (): void {
            Route::get('company', [CompanyProfileController::class, 'show']);
            Route::patch('company', [CompanyProfileController::class, 'update']);

            Route::apiResource('brands', BrandController::class);
            Route::apiResource('outlets', OutletController::class);

            Route::apiResource('devices', DeviceController::class)->except('destroy');
            Route::post('devices/{device}/pairing-code', [DeviceController::class, 'pairingCode']);
            Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke']);

            Route::apiResource('staff', StaffController::class)->parameters(['staff' => 'member'])->except('destroy');
            Route::put('staff/{member}/pin', [StaffController::class, 'setPin']);
            Route::post('staff/{member}/unlock-pin', [StaffController::class, 'unlockPin']);
            Route::post('staff/{member}/revoke-sessions', [StaffController::class, 'revokeSessions']);

            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::apiResource('roles', RoleController::class);

            Route::get('audit-logs', [AuditLogController::class, 'index']);

            // Menu, harga & promo (Tahap 2)
            Route::get('kitchen-stations', [CatalogSettingsController::class, 'stations']);
            Route::post('kitchen-stations', [CatalogSettingsController::class, 'storeStation']);
            Route::patch('kitchen-stations/{station}', [CatalogSettingsController::class, 'updateStation']);
            Route::delete('kitchen-stations/{station}', [CatalogSettingsController::class, 'destroyStation']);
            Route::get('sales-channels', [CatalogSettingsController::class, 'channels']);
            Route::post('sales-channels', [CatalogSettingsController::class, 'storeChannel']);
            Route::patch('sales-channels/{channel}', [CatalogSettingsController::class, 'updateChannel']);

            Route::apiResource('menu-categories', MenuCategoryController::class)->parameters(['menu-categories' => 'category']);
            Route::apiResource('modifier-groups', ModifierGroupController::class)->parameters(['modifier-groups' => 'modifierGroup']);

            Route::get('items/export', [MenuToolsController::class, 'export']);
            Route::post('items/import', [MenuToolsController::class, 'import']);
            Route::post('menu/copy-brand', [MenuToolsController::class, 'copyBrand']);
            Route::post('menu/copy-outlet', [MenuToolsController::class, 'copyOutlet']);
            Route::apiResource('items', ItemController::class);
            Route::get('items/{item}/prices', [ItemController::class, 'prices']);
            Route::put('items/{item}/prices', [ItemController::class, 'replacePrices']);
            Route::get('items/{item}/price-history', [ItemController::class, 'priceHistory']);
            Route::get('items/{item}/availability', [ItemController::class, 'availability']);
            Route::put('items/{item}/availability', [ItemController::class, 'setAvailability']);

            Route::apiResource('promotions', PromotionController::class);
            Route::post('quotes', [QuoteController::class, 'backoffice'])->name('api.backoffice.quote');

            // Transaksi & tutup hari (Tahap 3)
            Route::get('shifts', [BackofficeSalesController::class, 'shifts']);
            Route::get('shifts/{shift}', [BackofficeSalesController::class, 'shift']);
            Route::get('orders', [BackofficeSalesController::class, 'orders']);
            Route::get('orders/{order}', [BackofficeSalesController::class, 'order']);
            Route::get('outlets/{outlet}/end-of-day', [BackofficeSalesController::class, 'endOfDay']);
            Route::post('outlets/{outlet}/end-of-day', [BackofficeSalesController::class, 'closeDay']);
            Route::get('outlets/{outlet}/payment-methods', [BackofficeSalesController::class, 'paymentMethods']);
            Route::put('outlets/{outlet}/payment-methods', [BackofficeSalesController::class, 'updatePaymentMethods']);

            // Inventory & resep (Tahap 4)
            Route::apiResource('ingredients', IngredientController::class);
            Route::get('stock-locations', [StockLocationController::class, 'index']);
            Route::post('stock-locations', [StockLocationController::class, 'store']);
            Route::patch('stock-locations/{location}', [StockLocationController::class, 'update']);
            Route::get('recipes/{type}/{id}', [RecipeController::class, 'show'])->whereIn('type', ['item', 'variant', 'modifier', 'ingredient']);
            Route::put('recipes/{type}/{id}', [RecipeController::class, 'update'])->whereIn('type', ['item', 'variant', 'modifier', 'ingredient']);
            Route::get('stock/balances', [StockController::class, 'balances']);
            Route::get('stock/movements', [StockController::class, 'movements']);
            Route::get('stock-adjustments', [StockDocumentController::class, 'adjustments']);
            Route::post('stock-adjustments', [StockDocumentController::class, 'storeAdjustment']);
            Route::get('stock-adjustments/{adjustment}', [StockDocumentController::class, 'adjustment']);
            Route::get('stock-transfers', [StockDocumentController::class, 'transfers']);
            Route::post('stock-transfers', [StockDocumentController::class, 'storeTransfer']);
            Route::get('stock-transfers/{transfer}', [StockDocumentController::class, 'transfer']);
            Route::post('stock-transfers/{transfer}/receive', [StockDocumentController::class, 'receiveTransfer']);
            Route::post('stock-transfers/{transfer}/cancel', [StockDocumentController::class, 'cancelTransfer']);
            Route::get('stock-counts', [StockCountController::class, 'index']);
            Route::post('stock-counts', [StockCountController::class, 'store']);
            Route::get('stock-counts/{count}', [StockCountController::class, 'show']);
            Route::put('stock-counts/{count}/lines', [StockCountController::class, 'record']);
            Route::post('stock-counts/{count}/submit', [StockCountController::class, 'submit']);
            Route::post('stock-counts/{count}/approve', [StockCountController::class, 'approve']);
            Route::post('stock-counts/{count}/recount', [StockCountController::class, 'recount']);
            Route::post('stock-counts/{count}/cancel', [StockCountController::class, 'cancel']);
            Route::get('reports/food-cost', [FoodCostController::class, 'actual']);
            Route::get('reports/food-cost/menu', [FoodCostController::class, 'menu']);

            // Laporan & dashboard (Tahap 5)
            Route::get('dashboard', [ReportController::class, 'dashboard']);
            Route::get('reports', [ReportController::class, 'catalog']);
            Route::get('reports/fraud/events', [ReportController::class, 'events'])->middleware('throttle:reports');
            Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('throttle:reports')
                ->where('report', '(sales\\.[a-z_]+|tax|fraud|fraud\\.events|menu_engineering|gross_profit|inventory\\.[a-z_]+)');
            Route::get('reports/{report}/export', [ReportController::class, 'export'])->middleware('throttle:reports')
                ->where('report', '(sales\\.[a-z_]+|tax|fraud|fraud\\.events|menu_engineering|gross_profit|inventory\\.[a-z_]+)');
            Route::get('report-schedules', [ReportScheduleController::class, 'index']);
            Route::post('report-schedules', [ReportScheduleController::class, 'store']);
            Route::get('report-schedules/{schedule}', [ReportScheduleController::class, 'show']);
            Route::patch('report-schedules/{schedule}', [ReportScheduleController::class, 'update']);
            Route::post('report-schedules/{schedule}/activate', [ReportScheduleController::class, 'activate']);
            Route::post('report-schedules/{schedule}/deactivate', [ReportScheduleController::class, 'deactivate']);

            // Pembelian (Tahap 4)
            Route::apiResource('suppliers', SupplierController::class);
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
            Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
            Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);
            Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close']);
            Route::post('purchase-orders/{purchaseOrder}/receipts', [PurchaseOrderController::class, 'receive']);
            Route::get('goods-receipts', [PurchaseOrderController::class, 'receipts']);
            Route::post('goods-receipts', [PurchaseOrderController::class, 'storeReceipt']);
            Route::get('goods-receipts/{receipt}', [PurchaseOrderController::class, 'receipt']);
        });

        // Aplikasi POS/KDS (token device)
        Route::middleware(['actor:device', 'tenant'])->group(function (): void {
            Route::get('devices/me', [DeviceSessionController::class, 'me'])->withoutMiddleware('throttle:api');
            Route::post('devices/heartbeat', [DeviceSessionController::class, 'heartbeat'])->withoutMiddleware('throttle:api');
            Route::get('pos/staff', [PosAuthController::class, 'staff']);
            Route::post('pos/auth/pin', [PosAuthController::class, 'login'])->middleware('throttle:pin');
        });

        Route::middleware(['actor:device_or_pos', 'tenant'])->group(function (): void {
            Route::get('pos/supervisors', [PosAuthController::class, 'supervisors']);
            Route::post('pos/authorize', [PosAuthController::class, 'authorizeAction'])->middleware('throttle:pin');
            Route::get('pos/catalog', [QuoteController::class, 'catalog'])->name('api.pos.catalog');
            Route::post('pos/quotes', [QuoteController::class, 'pos'])->name('api.pos.quote');

            // Sinkronisasi offline (Tahap 3)
            Route::post('sync/push', [SyncController::class, 'push'])->middleware('throttle:sync');
            Route::get('sync/pull', [SyncController::class, 'pull'])->middleware('throttle:sync');
            Route::get('pos/shifts/current', [PosSalesController::class, 'currentShift']);
            Route::get('pos/shifts/{shift}/report', [PosSalesController::class, 'shiftReport']);
            Route::get('pos/orders', [PosSalesController::class, 'findOrders']);
            Route::get('pos/orders/{order}', [PosSalesController::class, 'showOrder']);
        });

        Route::middleware(['actor:pos', 'tenant'])->group(function (): void {
            Route::post('pos/auth/logout', [PosAuthController::class, 'logout']);
            Route::post('pos/items/{item}/sold-out', [QuoteController::class, 'soldOut'])->name('api.pos.sold-out');

            // Transaksi online (Tahap 3)
            Route::post('pos/shifts', [PosSalesController::class, 'openShift']);
            Route::post('pos/shifts/{shift}/cash-movements', [PosSalesController::class, 'cashMovement']);
            Route::post('pos/shifts/{shift}/close', [PosSalesController::class, 'closeShift']);
            Route::post('pos/kitchen-tickets', [PosSalesController::class, 'sendToKitchen']);
            Route::post('pos/orders', [PosSalesController::class, 'storeOrder']);

            // Parkir bill: tagihan yang belum dibayar, milik outlet (FR-POS-12)
            Route::get('pos/open-bills', [OpenBillController::class, 'index']);
            Route::post('pos/open-bills', [OpenBillController::class, 'store']);
            Route::get('pos/open-bills/{bill}', [OpenBillController::class, 'show']);
            Route::delete('pos/open-bills/{bill}', [OpenBillController::class, 'destroy']);
            Route::post('pos/orders/{order}/void', [PosSalesController::class, 'voidOrder']);
            Route::post('pos/orders/{order}/refunds', [PosSalesController::class, 'refundOrder']);

            Route::post('payments/qris', [PosPaymentController::class, 'store']);
            Route::get('payments/{intent}', [PosPaymentController::class, 'show']);
            Route::post('payments/{intent}/cancel', [PosPaymentController::class, 'cancel']);
            Route::post('payments/{intent}/simulate', [PosPaymentController::class, 'simulate']);
        });
    });
});
