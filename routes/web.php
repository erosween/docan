<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\OperationalExpenseController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesForceController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/ready', function () {
    try {
        DB::select('select 1');
        Cache::store('redis')->put('health:ready', 1, 10);

        return response()->json(['status' => 'ready']);
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }
});
Route::get('/syarat-ketentuan', [AuthController::class, 'terms'])->name('legal.terms');
Route::get('/kebijakan-privasi', [AuthController::class, 'privacy'])->name('legal.privacy');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
    Route::get('/lupa-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/lupa-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::get('/daftar', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/daftar/cek-email', [AuthController::class, 'checkRegistrationEmail'])->middleware('throttle:20,1')->name('register.email.check');
    Route::post('/daftar/cek-sf-code', [AuthController::class, 'checkRegistrationSalesForceCode'])->middleware('throttle:30,1')->name('register.sf-code.check');
    Route::post('/daftar', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.submit');
});
Route::middleware(['auth', 'outlet.active'])->group(function () {
    Route::get('/', [PosController::class, 'index'])->name('pos');
    Route::post('/transactions', [PosController::class, 'store'])->middleware('throttle:120,1')->name('transactions.store');
    Route::get('/transactions/receipt', [PosController::class, 'receipt'])->name('transactions.receipt');
    Route::post('/transactions/{transaction}/refund', [PosController::class, 'refund'])->middleware('throttle:10,1')->name('transactions.refund');
    Route::post('/transactions/{transaction}/edit', [PosController::class, 'edit'])->middleware('throttle:10,1')->name('transactions.edit');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
Route::middleware(['auth', 'outlet.active', 'owner'])->group(function () {
    Route::post('/products/{product}/stock', [ProductController::class, 'addStock'])->name('products.stock');
    Route::post('/products/{product}/price', [ProductController::class, 'updatePrice'])->name('products.price');
    Route::delete('/products/bulk', [ProductController::class, 'bulkDestroy'])->name('products.bulk.destroy');
    Route::resource('products', ProductController::class)->except(['show', 'index']);
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export/sales', [ReportController::class, 'exportSales'])->name('reports.sales.export');
    Route::post('/reports/stock-movements/{movement}/edit', [ReportController::class, 'updateStockMovement'])->name('reports.stock-movements.edit');
    Route::get('/reports/detail/{metric}', [ReportController::class, 'detail'])->name('reports.detail');
    Route::get('/operational-expenses', [OperationalExpenseController::class, 'index'])->name('operational-expenses.index');
    Route::post('/operational-expenses', [OperationalExpenseController::class, 'store'])->name('operational-expenses.store');
    Route::put('/operational-expenses/{expense}', [OperationalExpenseController::class, 'update'])->name('operational-expenses.update');
    Route::delete('/operational-expenses/{expense}', [OperationalExpenseController::class, 'destroy'])->name('operational-expenses.destroy');
    Route::post('/operational-expense-categories', [OperationalExpenseController::class, 'storeCategory'])->name('operational-expenses.categories.store');
});
Route::middleware(['auth', 'outlet.active', 'owner'])->prefix('business')->name('business.')->group(function () {
    Route::get('/', [BusinessController::class, 'dashboard'])->name('dashboard');
    Route::get('/relations', [BusinessController::class, 'relations'])->name('relations');
    Route::get('/contacts/{contact}', [BusinessController::class, 'contact'])->name('contact');
    Route::get('/{module}', [BusinessController::class, 'module'])->name('module');
    Route::post('/{module}', [BusinessController::class, 'store'])->name('store');
});
Route::middleware(['auth', 'outlet.active'])->group(function () {
    Route::get('/settings', [ReportController::class, 'settings'])->name('settings.index');
    Route::put('/settings/email', [ReportController::class, 'updateEmail'])->name('settings.email');
    Route::put('/settings/password', [ReportController::class, 'updatePassword'])->name('settings.password');
    Route::post('/settings/frontliners', [ReportController::class, 'storeFrontliner'])->middleware('owner')->name('settings.frontliners.store');
    Route::delete('/settings/frontliners/{frontliner}', [ReportController::class, 'destroyFrontliner'])->middleware('owner')->name('settings.frontliners.destroy');
});
Route::middleware('auth')->prefix('sf')->name('sf.')->group(function () {
    Route::get('/', [SalesForceController::class, 'dashboard'])->name('dashboard');
    Route::put('/outlets/{outlet}/status', [SalesForceController::class, 'updateStatus'])->name('outlets.status');
});
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->defaults('page', 'dashboard')->name('dashboard');
    Route::get('/outlets', [AdminController::class, 'dashboard'])->defaults('page', 'outlets')->name('outlets');
    Route::get('/outlets/export', [AdminController::class, 'exportOutlets'])->name('outlets.export');
    Route::get('/sales-forces/export', [AdminController::class, 'exportSalesForces'])->name('sales-forces.export');
    Route::get('/transactions', [AdminController::class, 'dashboard'])->defaults('page', 'transactions')->name('transactions');
    Route::get('/master-products', [AdminController::class, 'dashboard'])->defaults('page', 'denominations')->name('denominations');
    Route::get('/master-products/export', [AdminController::class, 'exportProducts'])->name('products.export');
    Route::post('/outlets', [AdminController::class, 'storeOutlet'])->middleware('throttle:10,1')->name('outlets.store');
    Route::put('/outlets/{outlet}', [AdminController::class, 'updateOutlet'])->name('outlets.update');
    Route::post('/mail/test', [AdminController::class, 'sendTestEmail'])->middleware('throttle:3,1')->name('mail.test');
    Route::post('/outlets/import', [AdminController::class, 'importOutlets'])->middleware('throttle:10,1')->name('outlets.import');
    Route::get('/outlets/example', [AdminController::class, 'outletImportExample'])->name('outlets.example');
    Route::post('/outlets/{outlet}/catalog', [AdminController::class, 'syncOutletCatalog'])->name('outlets.catalog');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
    Route::post('/sales-forces', [AdminController::class, 'storeSalesForce'])->name('sales-forces.store');
    Route::put('/sales-forces/{salesForce}', [AdminController::class, 'updateSalesForce'])->name('sales-forces.update');
    Route::get('/transactions/export', [AdminController::class, 'export'])->name('export');
    Route::post('/denominations', [AdminController::class, 'storeDenomination'])->name('denominations.store');
    Route::put('/denominations/{denomination}', [AdminController::class, 'updateDenomination'])->name('denominations.update');
    Route::delete('/denominations/{denomination}', [AdminController::class, 'destroyDenomination'])->name('denominations.destroy');
});
