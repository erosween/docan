<?php

namespace Tests\Feature;

use App\Models\BusinessCategory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCardNumber;
use App\Models\ProductStockMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

class ProductFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_record_operational_expenses_and_report_includes_them(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Biaya', 'code' => 'BIAYA']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->get(route('operational-expenses.index'))
            ->assertOk()
            ->assertSee('Biaya Operasional')
            ->assertSee('Bensin')
            ->assertSee('Biaya Admin')
            ->assertSee('Stok Produk');

        $category = BusinessCategory::where('outlet_id', $outlet->id)
            ->where('name', 'Bensin')
            ->firstOrFail();
        $this->actingAs($owner)->post(route('operational-expenses.store'), [
            'category_id' => $category->id,
            'description' => 'Bensin antar barang',
            'amount' => '75.000',
            'entry_date' => now()->format('Y-m-d'),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('business_entries', [
            'outlet_id' => $outlet->id,
            'category_id' => $category->id,
            'type' => 'operational-expense',
            'amount' => 75000,
        ]);
        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Modal Operasional')
            ->assertSee('Biaya Operasional')
            ->assertSee('75.000');
    }

    public function test_sales_time_filter_and_excel_export_are_valid(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Export', 'code' => 'EXPORT']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        foreach ([['09:00', 10000], ['15:00', 25000]] as [$time, $price]) {
            $sale = Transaction::create([
                'user_id' => $owner->id,
                'provider' => 'TELKOMSEL',
                'product_type' => 'Pulsa',
                'quantity' => 1,
                'nominal' => $price,
                'price' => $price,
                'cost_price' => $price - 1000,
                'profit' => 1000,
                'customer_number' => '081234567890',
            ]);
            $sale->timestamps = false;
            $sale->forceFill([
                'created_at' => now()->setTimeFromTimeString($time),
                'updated_at' => now()->setTimeFromTimeString($time),
            ])->save();
        }

        $date = now()->format('Y-m-d');
        $this->actingAs($owner)->get(route('reports.index', [
            'sales_from' => $date,
            'sales_to' => $date,
            'sales_start_time' => '08:00',
            'sales_end_time' => '10:00',
        ]))->assertOk()
            ->assertSee('Rp 10.000')
            ->assertSee('Jam awal')
            ->assertSee('Daily · Weekly · Monthly');

        $response = $this->actingAs($owner)->get(route('reports.sales.export', [
            'sales_from' => $date,
            'sales_to' => $date,
            'sales_start_time' => '08:00',
            'sales_end_time' => '16:00',
        ]));
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'docan-test-xlsx-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString('Daily', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('Weekly', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('Monthly', $zip->getFromName('xl/workbook.xml'));
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $sheetNames = [];
        $dailyRows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetNames[] = $sheet->getName();
            if ($sheet->getName() === 'Daily') {
                foreach ($sheet->getRowIterator() as $row) {
                    $dailyRows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                }
            }
        }
        $reader->close();
        $this->assertSame(['Daily', 'Weekly', 'Monthly'], $sheetNames);
        $this->assertContains('Group Produk', $dailyRows[3]);
        $this->assertContains('Produk / Denom', $dailyRows[3]);
        $this->assertContains('Pulsa & Paket Tembak', $dailyRows[4]);
        $this->assertStringContainsString('TELKOMSEL · Pulsa', $dailyRows[4][2]);
        unlink($path);
    }

    public function test_outlet_can_open_thermal_receipt_for_its_transaction(): void
    {
        $outlet = Outlet::create(['name' => 'Abdul Cell', 'code' => 'RECEIPT']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $transaction = Transaction::create([
            'user_id' => $owner->id,
            'provider' => 'TELKOMSEL',
            'product_type' => 'Paket Tembak',
            'quantity' => 2,
            'nominal' => 10000,
            'price' => 20000,
            'cost_price' => 18000,
            'profit' => 2000,
            'customer_number' => '081234567890',
        ]);

        $this->actingAs($owner)->get(route('transactions.receipt', ['ids' => $transaction->id]))
            ->assertOk()
            ->assertSee('Abdul Cell')
            ->assertSee('Cetak struk')
            ->assertSee('Bluetooth ESC/POS')
            ->assertSee('navigator.bluetooth', false)
            ->assertSee('58 mm')
            ->assertSee('80 mm')
            ->assertSee('Rp 20.000');
    }

    public function test_report_summary_cards_open_grouped_metric_details(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Laporan', 'code' => 'REPORT']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $voucher = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1,
            'cost_price' => 8000, 'selling_price' => 10000, 'stock' => 10,
        ]);
        Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Kartu Paket',
            'name' => '3GB · 30D', 'quota_gb' => 3, 'validity_days' => 30,
            'cost_price' => 12000, 'selling_price' => 15000, 'stock' => 2,
        ]);
        Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA · 081234567890', 'account_number' => '081234567890',
            'cost_price' => 0, 'selling_price' => 0, 'stock' => 450000,
        ]);
        $currentSale = Transaction::create([
            'user_id' => $owner->id, 'product_id' => $voucher->id, 'provider' => 'TELKOMSEL',
            'product_type' => 'Voucher Internet', 'quantity' => 2, 'nominal' => 10000, 'price' => 20000,
            'cost_price' => 16000, 'profit' => 4000, 'customer_number' => '-',
        ]);
        $yesterdaySale = Transaction::create([
            'user_id' => $owner->id, 'product_id' => $voucher->id, 'provider' => 'TELKOMSEL',
            'product_type' => 'Voucher Internet', 'quantity' => 3, 'nominal' => 10000, 'price' => 30000,
            'cost_price' => 24000, 'profit' => 6000, 'customer_number' => '-',
        ]);
        $yesterdaySale->timestamps = false;
        $yesterdaySale->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('12 item')
            ->assertDontSee('450.012 item')
            ->assertSee(route('reports.detail', ['metric' => 'turnover', 'month' => now()->format('Y-m')]), false)
            ->assertSee(route('transactions.receipt', ['ids' => $currentSale->id]), false)
            ->assertSee('Cetak struk');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'turnover']))
            ->assertOk()->assertSee('Produk Provider')->assertSee('Pulsa &amp; Paket Tembak', false)
            ->assertSee('E-Wallet')->assertSee('Aksesoris');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'turnover', 'group' => 'provider']))
            ->assertOk()->assertSee('Semua Provider')->assertSee('Telkomsel')
            ->assertSee('Omset Voucher Fisik')->assertSee('Rp 50.000')->assertSee('Omset Kartu Paket');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'stock', 'group' => 'provider']))
            ->assertOk()->assertSee('Stok Voucher Fisik')->assertSee('10 item')
            ->assertSee('Stok Kartu Paket')->assertSee('2 item');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'stock']))
            ->assertOk()->assertSee('Rp 450.000')->assertDontSee('450.000 item');
        $this->actingAs($owner)->get(route('reports.index', [
            'sales_from' => now()->subDay()->format('Y-m-d'),
            'sales_to' => now()->format('Y-m-d'),
        ]))->assertOk()
            ->assertSee('Filter ringkasan')
            ->assertSee('Rp 50.000')
            ->assertSee('data-sales-range-form', false)
            ->assertSee('data-report-range-picker', false);
    }

    public function test_report_daily_activity_includes_stock_and_editing_it_updates_product_history(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Jurnal', 'code' => 'JURNAL']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => 'Jurnal 5GB', 'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 10, 'is_active' => true]);

        $this->actingAs($owner)->post(route('products.stock', $product), [
            'quantity' => 1, 'direction' => 'increase',
        ])->assertRedirect();
        $movement = ProductStockMovement::where('product_id', $product->id)->where('type', 'increase')->latest('id')->firstOrFail();

        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()->assertSee('Aktivitas harian')->assertSee('Rentang aktivitas')
            ->assertSee('Jurnal 5GB')->assertSee('Penambahan manual')
            ->assertSee('Penambahan Stok')->assertDontSee('#activity-journal', false)
            ->assertSee('data-activity-filter="sale"', false)
            ->assertSee('data-activity-filter="stock-in"', false)
            ->assertSee('data-activity-groups="stock-in"', false)
            ->assertSee('name="activity_from"', false)
            ->assertSee('name="activity_to"', false)
            ->assertSee('data-report-range-picker', false)
            ->assertSee('/vendor/flatpickr/flatpickr.min.js?v=4.6.13', false);

        $this->actingAs($owner)->post(route('reports.stock-movements.edit', $movement), [
            'quantity' => 5,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(15, $product->fresh()->stock);
        $this->assertSame(5, $movement->fresh()->quantity);
        $this->assertSame(15, $movement->fresh()->stock_after);
        $this->actingAs($owner)->get(route('products.index'))
            ->assertOk()->assertSee('+5')->assertSee('15 tersisa');
    }

    public function test_opening_capital_is_recorded_separately_from_sales_cash_in(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Modal', 'code' => 'MODAL']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('business.store', 'capital'), [
            'description' => 'Modal pembukaan outlet',
            'amount' => 5000000,
            'entry_date' => now()->format('Y-m-d'),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('business_entries', [
            'outlet_id' => $outlet->id,
            'user_id' => $owner->id,
            'type' => 'capital',
            'amount' => 5000000,
        ]);
        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Modal awal')
            ->assertSee('5.000.000')
            ->assertSee('Saldo kas periode');
        $this->actingAs($owner)->get(route('business.module', 'capital'))
            ->assertOk()
            ->assertSee('Catat Modal Awal')
            ->assertSee('Modal pembukaan outlet');
    }

    public function test_cashier_sales_reduce_product_capital_and_stock_or_expenses_reduce_operational_capital(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Jurnal Modal', 'code' => 'JURNAL-MODAL']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $physical = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1,
            'cost_price' => 10000, 'selling_price' => 15000, 'stock' => 0, 'is_active' => true,
        ]);
        $wallet = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA · 081234567890', 'account_number' => '081234567890',
            'cost_price' => 0, 'selling_price' => 0, 'stock' => 0, 'is_active' => true,
        ]);
        $category = BusinessCategory::create([
            'outlet_id' => $outlet->id, 'kind' => 'operational-expense', 'name' => 'Bensin',
        ]);

        $this->actingAs($owner)->post(route('business.store', 'capital'), [
            'description' => 'Modal awal',
            'amount' => 1000000,
            'entry_date' => now()->format('Y-m-d'),
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('products.stock', $physical), ['quantity' => 10])->assertRedirect();
        $this->actingAs($owner)->post(route('products.stock', $wallet), ['quantity' => 200000])->assertRedirect();
        $this->actingAs($owner)->post(route('operational-expenses.store'), [
            'category_id' => $category->id,
            'description' => 'Bensin operasional',
            'amount' => 50000,
            'entry_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('transactions.store'), [
            'cart_items' => json_encode([['product_id' => $physical->id, 'quantity' => 2]]),
            'customer_number' => '081234567890',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('transactions.store'), [
            'provider' => 'DANA',
            'product_type' => 'Saldo E-Wallet',
            'nominal' => 50000,
            'admin_fee' => 2000,
            'balance_product_id' => $wallet->id,
            'transaction_action' => 'customer_topup',
            'customer_number' => '081234567890',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('transactions.store'), [
            'provider' => 'PPOB',
            'product_type' => 'PPOB',
            'nominal' => 30000,
            'customer_number' => '1234567890',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_entries', ['outlet_id' => $outlet->id, 'type' => 'purchase', 'amount' => 100000]);
        $this->assertDatabaseHas('business_entries', ['outlet_id' => $outlet->id, 'type' => 'purchase', 'amount' => 200000]);
        $this->assertSame(100000, (int) Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outlet->id))->sum('cost_price'));
        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('<b>Modal Produk</b> Rp 200.000', false)
            ->assertSee('<b>Modal Operasional</b> Rp 650.000', false);
    }

    public function test_balance_groups_show_only_their_services_and_correct_totals(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Saldo', 'code' => 'SALDO']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 10]);
        Product::create(['outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 125000]);

        $this->actingAs($owner)->get(route('products.index', ['group' => 'wallet']))
            ->assertOk()->assertSee('Jumlah akun saldo')->assertSee('125.000')
            ->assertSee('Pilih E-Wallet')->assertDontSee('Stok terendah');
        $this->actingAs($owner)->get(route('products.index', ['group' => 'wallet', 'operator' => 'DANA']))
            ->assertOk()->assertSee('Tambah saldo')->assertSee('Saldo DANA')
            ->assertDontSee('Kembali ke provider')->assertDontSee('Stok terlaris');
    }

    public function test_owner_can_create_frontliner_and_frontliner_access_is_limited(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Role', 'code' => 'ROLE', 'login_id' => 'ROLE-001']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('settings.frontliners.store'), [
            'name' => 'FL Pagi', 'login_id' => 'ROLE-001-FL01', 'password' => 'Front123!', 'password_confirmation' => 'Front123!',
        ])->assertRedirect();
        $frontliner = User::where('outlet_id', $outlet->id)->where('role', 'frontliner')->firstOrFail();
        $this->assertSame('ROLE-001-FL01', $frontliner->login_id);

        $this->actingAs($frontliner)->get(route('pos'))->assertOk()->assertSee('Pilih Provider');
        $this->actingAs($frontliner)->get(route('products.index'))->assertRedirect(route('products.index', ['stock' => 1]));
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 10]);
        $this->actingAs($frontliner)->get(route('products.index', ['stock' => 1, 'group' => 'provider', 'operator' => 'TELKOMSEL']))
            ->assertOk()->assertSee('5GB · 1D')->assertDontSee('+ Stok')->assertDontSee('Edit harga');
        $this->actingAs($frontliner)->post(route('products.stock', $product), ['quantity' => 10])->assertForbidden();
        $this->assertSame(10, $product->fresh()->stock);
        $this->actingAs($frontliner)->get(route('products.create'))->assertForbidden();
        $this->actingAs($frontliner)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($frontliner)->get(route('settings.index'))->assertOk()->assertSee('Frontliner')->assertDontSee('Tambah Frontliner');
    }

    public function test_owner_and_frontliner_use_distinct_login_ids_but_share_outlet(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Login', 'code' => 'LOGIN', 'login_id' => 'LOGIN-001']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner', 'login_id' => 'LOGIN-001', 'password' => 'Owner123!']);
        $frontliner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'frontliner', 'login_id' => 'LOGIN-001-FL01', 'password' => 'Front123!']);

        $this->post(route('login.submit'), ['login_id' => 'LOGIN-001-FL01', 'password' => 'Front123!'])->assertRedirect(route('pos'));
        $this->assertAuthenticatedAs($frontliner);
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['login_id' => 'LOGIN-001', 'password' => 'Owner123!'])->assertRedirect(route('pos'));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame($owner->outlet_id, $frontliner->outlet_id);
    }

    public function test_aggregator_sale_requires_customer_number(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Aggregator', 'code' => 'AGG']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $balance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'DIGIPOS', 'category' => 'Saldo Provider',
            'name' => 'Saldo DigiPOS', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => true]);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'provider' => 'DIGIPOS', 'product_type' => 'Pulsa', 'nominal' => 10000, 'balance_product_id' => $balance->id,
        ])->assertSessionHasErrors('customer_number');
        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081234567890', 'provider' => 'DIGIPOS', 'product_type' => 'Paket Tembak', 'nominal' => 25000,
            'admin_fee' => 3000, 'balance_product_id' => $balance->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('transactions', [
            'provider' => 'DIGIPOS', 'product_type' => 'Paket Tembak', 'nominal' => 25000,
            'admin_fee' => 3000, 'price' => 28000, 'cost_price' => 25000, 'profit' => 3000,
        ]);
        $this->assertSame(75000, $balance->fresh()->stock);
    }

    public function test_recharge_transaction_is_blocked_until_provider_balance_is_set(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Saldo Channel', 'code' => 'CHANNEL']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081734567890', 'provider' => 'SIDIVA', 'product_type' => 'Paket Tembak', 'nominal' => 10000,
        ])->assertSessionHasErrors('balance_product_id');
        $this->assertDatabaseCount('transactions', 0);

        $balance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'SIDIVA', 'category' => 'Saldo Provider',
            'name' => 'Saldo SIDIVA', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 5000, 'is_active' => true]);
        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081734567890', 'provider' => 'SIDIVA', 'product_type' => 'Paket Tembak',
            'nominal' => 10000, 'balance_product_id' => $balance->id,
        ])->assertSessionHasErrors('balance_product_id');
        $this->assertSame(5000, $balance->fresh()->stock);

        $this->actingAs($owner)->get(route('pos'))->assertOk()
            ->assertSee('SIDIVA')->assertDontSee('SIDIVA · XL/Axis/Smartfren');
    }

    public function test_recharge_channels_enforce_their_provider_prefixes(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Prefix', 'code' => 'PREFIX', 'login_id' => 'PREFIX-001']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $validNumbers = ['DIGIPOS' => '081234567890', 'SIDIVA' => '081734567890', 'ISIMPEL' => '081534567890', 'RITA' => '089534567890'];
        $invalidNumbers = ['DIGIPOS' => '088123456789', 'SIDIVA' => '089534567890', 'ISIMPEL' => '088123456789', 'RITA' => '081234567890'];
        $balances = collect(array_keys($validNumbers))->mapWithKeys(fn ($provider) => [$provider => Product::create([
            'outlet_id' => $outlet->id, 'operator' => $provider, 'category' => 'Saldo Provider', 'name' => 'Saldo '.$provider,
            'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => true,
        ])]);

        foreach ($validNumbers as $provider => $number) {
            $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => $invalidNumbers[$provider], 'provider' => $provider, 'product_type' => 'Paket Tembak', 'nominal' => 10000, 'balance_product_id' => $balances[$provider]->id])->assertSessionHasErrors('customer_number');
            $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => $number, 'provider' => $provider, 'product_type' => 'Paket Tembak', 'nominal' => 10000, 'balance_product_id' => $balances[$provider]->id])->assertRedirect()->assertSessionHas('success');
        }

        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '088234567890', 'provider' => 'SIDIVA', 'product_type' => 'Paket Tembak', 'nominal' => 10000, 'balance_product_id' => $balances['SIDIVA']->id])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '081234567890', 'provider' => 'PROVIDER-PALSU', 'product_type' => 'Paket Tembak', 'nominal' => 10000])->assertSessionHasErrors('provider');
        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '081234567890', 'provider' => 'DIGIPOS', 'product_type' => 'KATEGORI-PALSU', 'nominal' => 10000])->assertSessionHasErrors('product_type');
    }

    public function test_ppob_services_accept_customer_ids_and_are_recorded_separately(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet PPOB', 'code' => 'PPOB', 'login_id' => 'PPOB-001']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $balance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'DIGIPOS', 'category' => 'Saldo Provider',
            'name' => 'Saldo DigiPOS', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 500000, 'is_active' => true]);
        $services = ['Listrik PLN Pascabayar', 'PDAM', 'BPJS Kesehatan', 'Telepon & Telkom/IndiHome', 'TV Berlangganan', 'Cicilan/Multifinance', 'Pulsa Elektrik', 'Paket Data/Internet', 'Token Listrik', 'Voucher Game'];

        foreach ($services as $service) {
            $this->actingAs($user)->post(route('transactions.store'), [
                'customer_number' => 'ID-123456', 'provider' => 'DIGIPOS', 'product_type' => $service, 'nominal' => 10000,
                'balance_product_id' => $balance->id,
            ])->assertRedirect()->assertSessionHas('success');
            $this->assertDatabaseHas('transactions', ['provider' => 'DIGIPOS', 'product_type' => $service, 'customer_number' => 'ID-123456']);
        }
    }

    public function test_cashier_uses_one_hidden_customer_field_and_direct_identity_box(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Input', 'code' => 'INPUT', 'login_id' => 'INPUT-001']);
        $user = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($user)->get(route('pos'))->assertOk()
            ->assertSee('name="customer_number" id="customer_number"', false)
            ->assertSee('id="direct-identity-input"', false)
            ->assertSee('id="close-customer-warning"', false)
            ->assertSee('Kembali ke pilihan produk')
            ->assertSee('/img/dana.webp', false)
            ->assertSee('/img/gopay.webp', false)
            ->assertSee('/img/shopeepay.webp', false)
            ->assertSee('id="ppob-service-grid"', false)
            ->assertDontSee('id="card-sale-modal"', false)
            ->assertDontSee('Masukkan qty penjualan')
            ->assertDontSee('class="number-section"', false);
    }

    public function test_outlet_can_manage_its_own_product_and_sale_reduces_stock(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Test', 'code' => 'TEST']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);

        $this->actingAs($user)->get(route('products.create'))
            ->assertOk()
            ->assertSee('id="cost_price" name="cost_price" type="text" inputmode="numeric" data-money-input value=""', false)
            ->assertSee('id="selling_price" name="selling_price" type="text" inputmode="numeric" data-money-input value=""', false)
            ->assertSee('id="stock" name="stock" type="text" inputmode="numeric" data-money-input value=""', false);

        $this->actingAs($user)->post(route('products.store'), [
            'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet', 'quota_gb' => 8, 'validity_days' => 28,
            'sku' => 'TSEL-8-28', 'cost_price' => '30.000', 'selling_price' => '35.000', 'stock' => 5, 'is_active' => 1,
        ])->assertRedirect(route('products.index'));

        $product = Product::firstOrFail();
        $this->assertSame('8GB · 28D', $product->name);
        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number' => '81234567890', 'product_id' => $product->id, 'nominal' => 0,
        ])->assertRedirect();

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['product_id' => $product->id, 'cost_price' => 30000, 'price' => 35000, 'profit' => 5000]);
        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('Sering kamu jual')->assertSee('8GB · 28D');

        foreach (range(1, 12) as $number) {
            Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
                'name' => $number.'GB · 7 Hari', 'quota_gb' => $number, 'validity_days' => 7,
                'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);
        }
        $this->actingAs($user)->get(route('products.index', ['view' => 'all']))->assertOk()
            ->assertSee('Halaman 1 dari 2')->assertSee('Berikutnya');
    }

    public function test_user_cannot_edit_another_outlets_product(): void
    {
        $first = Outlet::create(['name' => 'Satu', 'code' => 'ONE']);
        $second = Outlet::create(['name' => 'Dua', 'code' => 'TWO']);
        $user = User::factory()->create(['outlet_id' => $first->id]);
        $product = Product::create(['outlet_id' => $second->id, 'operator' => 'XL', 'category' => 'Voucher Fisik', 'name' => '5GB', 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);

        $this->actingAs($user)->get(route('products.edit', $product))->assertNotFound();
    }

    public function test_owner_cannot_edit_or_refund_another_outlets_transaction(): void
    {
        $first = Outlet::create(['name' => 'Outlet Transaksi Satu', 'code' => 'TRX-ONE']);
        $second = Outlet::create(['name' => 'Outlet Transaksi Dua', 'code' => 'TRX-TWO']);
        $firstOwner = User::factory()->create(['outlet_id' => $first->id, 'role' => 'owner']);
        $secondOwner = User::factory()->create(['outlet_id' => $second->id, 'role' => 'owner']);
        $transaction = Transaction::create([
            'user_id' => $secondOwner->id, 'customer_number' => '081234567890',
            'provider' => 'TELKOMSEL', 'product_type' => 'Pulsa Reguler',
            'quantity' => 1, 'nominal' => 10000, 'price' => 10000, 'cost_price' => 10000, 'profit' => 0,
        ]);

        $this->actingAs($firstOwner)->post(route('transactions.edit', $transaction), ['nominal' => 20000])
            ->assertNotFound();
        $this->actingAs($firstOwner)->post(route('transactions.refund', $transaction))
            ->assertNotFound();
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'nominal' => 10000]);
    }

    public function test_duplicate_product_is_rejected_and_direct_nominal_sale_works(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Test', 'code' => 'DUP']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 7 Hari', 'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);

        $this->actingAs($user)->post(route('products.store'), ['operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2, 'is_active' => 1])
            ->assertSessionHasErrors('quota_gb');

        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '08123456789',
            'provider' => 'TELKOMSEL', 'product_type' => 'Pulsa Reguler', 'nominal' => 25000])->assertRedirect();
        $this->assertDatabaseHas('transactions', ['product_id' => null, 'provider' => 'TELKOMSEL', 'price' => 25000, 'profit' => 0]);
    }

    public function test_same_package_with_different_cost_and_accessory_are_allowed(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Test', 'code' => 'COST']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 7 Hari', 'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);

        $this->actingAs($user)->post(route('products.store'), ['operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5500, 'selling_price' => 7500, 'stock' => 2, 'is_active' => 1])
            ->assertRedirect(route('products.index'));
        $this->actingAs($user)->post(route('products.store'), ['operator' => 'AKSESORIS', 'category' => 'Aksesoris HP',
            'name' => 'Kabel Data Type-C', 'brand' => 'Vivan', 'cost_price' => 10000, 'selling_price' => 15000, 'stock' => 4, 'is_active' => 1])
            ->assertRedirect(route('products.index'));
        $this->actingAs($user)->post(route('products.store'), ['operator' => 'HANDPHONE', 'category' => 'Handphone',
            'name' => 'Galaxy A55 5G', 'brand' => 'Samsung', 'cost_price' => 5000000, 'selling_price' => 5500000, 'stock' => 2, 'is_active' => 1,
            'return_group' => 'phone', 'return_operator' => 'HANDPHONE'])
            ->assertRedirect(route('products.index', ['group' => 'phone', 'operator' => 'HANDPHONE']));

        $this->assertDatabaseCount('products', 4);
        $this->assertDatabaseHas('products', ['operator' => 'AKSESORIS', 'name' => 'Kabel Data Type-C', 'brand' => 'Vivan']);
        $this->assertDatabaseHas('products', ['operator' => 'HANDPHONE', 'category' => 'Handphone', 'name' => 'Galaxy A55 5G', 'brand' => 'Samsung']);
        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSee('Handphone')->assertSee('1 perangkat berdasarkan merek dan model');
        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('data-service="phone"', false)->assertSee('/img/handphone.svg', false);
        $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertSee('OMSET '.mb_strtoupper(now()->translatedFormat('F Y')));
    }

    public function test_owner_can_type_a_custom_package_quota(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Kuota Manual', 'code' => 'MANUAL-QUOTA']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);

        $this->actingAs($user)->post(route('products.store'), [
            'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'quota_gb' => '125,5', 'validity_days' => 30,
            'cost_price' => 100000, 'selling_price' => 110000,
            'stock' => 1, 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'outlet_id' => $outlet->id, 'name' => '125.5GB · 30D', 'quota_gb' => 125.5,
        ]);
    }

    public function test_payment_services_require_the_correct_customer_identifier(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Payment', 'code' => 'PAY']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $danaBalance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA · 089812345678', 'account_number' => '089812345678', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 200000]);
        $briBalance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'BRILINK', 'category' => 'Saldo Provider',
            'name' => 'Saldo BRILINK · 880012345678', 'account_number' => '880012345678', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 200000]);

        $this->actingAs($user)->post(route('transactions.store'), [
            'provider' => 'DANA', 'product_type' => 'Saldo E-Wallet', 'nominal' => 20000,
        ])->assertSessionHasErrors('customer_number');

        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number' => '089812345678', 'provider' => 'DANA', 'product_type' => 'Saldo E-Wallet', 'nominal' => 20000,
            'balance_product_id' => $danaBalance->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number' => 'ID-PLN-7788', 'provider' => 'PPOB', 'product_type' => 'Pascabayar', 'nominal' => 50000,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number' => '880012345678', 'provider' => 'BRILINK', 'product_type' => 'Transfer', 'nominal' => 100000,
            'balance_product_id' => $briBalance->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', ['provider' => 'DANA', 'customer_number' => '089812345678']);
        $this->assertDatabaseHas('transactions', ['provider' => 'PPOB', 'customer_number' => 'ID-PLN-7788']);
        $this->assertDatabaseHas('transactions', ['provider' => 'BRILINK', 'customer_number' => '880012345678']);
    }

    public function test_transaction_rejects_customer_number_from_another_provider(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Prefix', 'code' => 'PREFIX']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 7 Hari', 'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);

        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number' => '081712345678', 'product_id' => $product->id,
        ])->assertSessionHasErrors('customer_number');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_duplicate_transaction_token_only_reduces_stock_once(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Idempotent', 'code' => 'IDEMP']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet', 'name' => '5GB · 7 Hari', 'quota_gb' => 5, 'validity_days' => 7, 'cost_price' => 5000, 'selling_price' => 7000, 'stock' => 2]);
        $payload = ['request_token' => '16cf55ca-7eb2-4b1c-a440-5478c9039469', 'customer_number' => '081234567890', 'product_id' => $product->id];

        $this->actingAs($user)->post(route('transactions.store'), $payload)->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'), $payload)->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $product->fresh()->stock);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_zero_stock_product_is_still_sent_to_cashier_catalog(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Stok', 'code' => 'STOCK']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => 'Produk Baru Stok Kosong', 'quota_gb' => 1, 'validity_days' => 1,
            'cost_price' => 5000, 'selling_price' => 8000, 'stock' => 0, 'is_active' => true]);

        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('Produk Baru Stok Kosong');
    }

    public function test_outlet_user_can_change_own_password(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Password', 'code' => 'PASS']);
        $user = User::factory()->create(['outlet_id' => $outlet->id, 'password' => 'PasswordLama!']);

        $this->actingAs($user)->put(route('settings.password'), [
            'current_password' => 'PasswordLama!', 'password' => 'PasswordBaru!', 'password_confirmation' => 'PasswordBaru!',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue(Hash::check('PasswordBaru!', $user->fresh()->password));
    }

    public function test_outlet_can_add_stock_and_sell_package_cards_by_quantity(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Kartu', 'code' => 'CARD']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'BYU', 'category' => 'Kartu Paket', 'name' => '3GB · 30 Hari', 'quota_gb' => 3, 'validity_days' => 30, 'cost_price' => 10000, 'selling_price' => 12000, 'stock' => 0, 'is_active' => true]);
        $this->actingAs($user)->postJson(route('products.stock', $product), ['quantity' => 10])->assertOk()->assertJson(['stock' => 10]);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, ProductCardNumber::where('product_id', $product->id)->count());

        $this->actingAs($user)->post(route('transactions.store'), ['product_id' => $product->id, 'quantity' => 5])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(0, ProductCardNumber::where('product_id', $product->id)->count());
        $this->assertDatabaseHas('transactions', ['product_id' => $product->id, 'quantity' => 5, 'price' => 60000, 'cost_price' => 50000, 'profit' => 10000]);
        $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertSee('Qty 5')->assertSee('1 transaksi');
    }

    public function test_package_card_sale_does_not_require_numbers_and_outlet_can_update_price_inline(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Inline', 'code' => 'INLINE']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'XL', 'category' => 'Kartu Paket', 'name' => '3GB · 30 Hari', 'quota_gb' => 3, 'validity_days' => 30, 'cost_price' => 10000, 'selling_price' => 12000, 'stock' => 3, 'is_active' => true]);

        $this->actingAs($user)->post(route('transactions.store'), [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('transactions', 1);

        $this->actingAs($user)->postJson(route('products.price', $product), [
            'cost_price' => '11.000', 'selling_price' => '14.000',
        ])->assertOk()->assertJson(['cost_price' => 11000, 'selling_price' => 14000]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'cost_price' => 11000, 'selling_price' => 14000]);
    }

    public function test_refund_package_card_restores_quantity_to_stock(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Refund', 'code' => 'REFUND']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $product = Product::create(['outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Kartu Paket',
            'name' => '8GB · 30 Hari', 'quota_gb' => 8, 'validity_days' => 30, 'cost_price' => 20000, 'selling_price' => 25000, 'stock' => 10, 'is_active' => true]);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertRedirect();
        $transaction = Transaction::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(6, $product->fresh()->stock);

        $this->actingAs($owner)->post(route('transactions.refund', $transaction))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $product->id, 'type' => 'refund', 'quantity' => 4, 'stock_after' => 10]);
    }

    public function test_owner_edits_digital_transaction_nominal_instead_of_customer_number(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Edit Nominal', 'code' => 'NOMINAL']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $balance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA', 'account_number' => '081234567890', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => true]);
        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081298765432', 'provider' => 'DANA', 'product_type' => 'Saldo E-Wallet',
            'transaction_action' => 'customer_topup', 'balance_product_id' => $balance->id, 'nominal' => 20000, 'admin_fee' => 2000,
        ])->assertRedirect();
        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertSame(80000, $balance->fresh()->stock);

        $this->actingAs($owner)->post(route('transactions.edit', $transaction), ['nominal' => 30000])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(70000, $balance->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'customer_number' => '081298765432',
            'nominal' => 30000, 'price' => 32000, 'cost_price' => 30000, 'profit' => 2000]);
        $this->actingAs($owner)->get(route('reports.index'))->assertOk()
            ->assertSee('name="nominal"', false)->assertDontSee('name="customer_number"', false);
    }

    public function test_edit_sale_rejects_insufficient_stock_and_only_updates_original_provider_product(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Edit Stok', 'code' => 'EDIT-STOCK']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $telkomsel = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'cost_price' => 7000, 'selling_price' => 9000, 'stock' => 1,
        ]);
        $indosat = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'INDOSAT', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'cost_price' => 7500, 'selling_price' => 9500, 'stock' => 20,
        ]);
        $transaction = Transaction::create([
            'user_id' => $owner->id, 'product_id' => $telkomsel->id, 'provider' => 'TELKOMSEL',
            'product_type' => 'Voucher Internet', 'quantity' => 1, 'nominal' => 9000,
            'price' => 9000, 'cost_price' => 7000, 'profit' => 2000, 'customer_number' => '-',
        ]);

        $this->actingAs($owner)->post(route('transactions.edit', $transaction), ['quantity' => 3])
            ->assertRedirect()->assertSessionHasErrors('quantity');
        $this->assertSame(1, $telkomsel->fresh()->stock);
        $this->assertSame(20, $indosat->fresh()->stock);
        $this->assertSame(1, $transaction->fresh()->quantity);
        $this->assertDatabaseMissing('product_stock_movements', [
            'transaction_id' => $transaction->id, 'type' => 'adjust',
        ]);

        $this->actingAs($owner)->post(route('transactions.edit', $transaction), ['quantity' => 2])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, $telkomsel->fresh()->stock);
        $this->assertSame(20, $indosat->fresh()->stock);
        $this->assertSame(2, $transaction->fresh()->quantity);
        $this->assertDatabaseHas('product_stock_movements', [
            'transaction_id' => $transaction->id,
            'product_id' => $telkomsel->id,
            'type' => 'adjust',
            'quantity' => -1,
            'stock_after' => 0,
        ]);
    }

    public function test_refund_digital_transaction_restores_balance_and_reports_unreturnable_credit(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Refund Saldo', 'code' => 'REFSALDO']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $balance = Product::create(['outlet_id' => $outlet->id, 'operator' => 'DANA', 'category' => 'Saldo Provider',
            'name' => 'Saldo DANA', 'account_number' => '081234567890', 'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => true]);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081298765432', 'provider' => 'DANA', 'product_type' => 'Saldo E-Wallet',
            'transaction_action' => 'customer_topup', 'balance_product_id' => $balance->id, 'nominal' => 20000,
        ])->assertRedirect();
        $debit = Transaction::latest('id')->firstOrFail();
        $this->assertSame(80000, $balance->fresh()->stock);
        $this->actingAs($owner)->post(route('transactions.refund', $debit))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(100000, $balance->fresh()->stock);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'customer_number' => '081298765432', 'provider' => 'DANA', 'product_type' => 'Saldo E-Wallet',
            'transaction_action' => 'receive_payment', 'balance_product_id' => $balance->id, 'nominal' => 30000,
        ])->assertRedirect();
        $credit = Transaction::latest('id')->firstOrFail();
        $balance->update(['stock' => 10000]);
        $this->actingAs($owner)->post(route('transactions.refund', $credit))
            ->assertRedirect()->assertSessionHasErrors('refund');
        $this->assertDatabaseHas('transactions', ['id' => $credit->id]);
        $this->actingAs($owner)->withSession(['success' => 'Transaksi berhasil dibatalkan.'])
            ->get(route('reports.index'))->assertOk()
            ->assertSee('data-report-popup', false)->assertSee('Berhasil')
            ->assertSee('Transaksi berhasil dibatalkan.');
    }

    public function test_edit_keeps_package_identity_and_new_cost_creates_price_variant(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Varian', 'code' => 'VAR']);
        $user = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $product = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '4GB SERU · 1D', 'quota_gb' => 4, 'validity_days' => 1,
            'cost_price' => 7000, 'selling_price' => 8000, 'stock' => 10, 'is_active' => true,
        ]);

        $this->actingAs($user)->put(route('products.update', $product), [
            'operator' => 'XL', 'category' => 'Kartu Paket', 'quota_gb' => 20, 'validity_days' => 30,
            'cost_price' => 7200, 'selling_price' => 9000, 'stock' => 10, 'is_active' => 1,
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('TELKOMSEL', $product->operator);
        $this->assertSame('Voucher Internet', $product->category);
        $this->assertSame(4.0, $product->quota_gb);
        $this->assertSame(1, $product->validity_days);
        $this->assertSame('4GB SERU · 1D', $product->name);

        $this->actingAs($user)->get(route('products.create', ['variant' => 1, 'source' => $product->id]))
            ->assertOk()
            ->assertSee('Harga baru')
            ->assertSee('id="cost_price" name="cost_price" type="text" inputmode="numeric" data-money-input value=""', false)
            ->assertSee('id="selling_price" name="selling_price" type="text" inputmode="numeric" data-money-input value=""', false)
            ->assertSee('id="stock" name="stock" type="text" inputmode="numeric" data-money-input value=""', false);

        $this->actingAs($user)->post(route('products.store'), [
            'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet', 'quota_gb' => 4, 'validity_days' => 1,
            'cost_price' => 7200, 'selling_price' => 9000, 'stock' => 0, 'is_active' => 1, 'variant' => 1, 'source_id' => $product->id,
        ])->assertSessionHasErrors();

        $this->actingAs($user)->post(route('products.store'), [
            'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet', 'quota_gb' => 4, 'validity_days' => 1,
            'cost_price' => 7500, 'selling_price' => 9500, 'stock' => 0, 'is_active' => 1, 'variant' => 1, 'source_id' => $product->id,
        ])->assertRedirect(route('products.index', ['operator' => 'TELKOMSEL']));

        $this->assertSame(2, Product::where('outlet_id', $outlet->id)
            ->where('operator', 'TELKOMSEL')->where('quota_gb', 4)->where('validity_days', 1)->count());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('products', [
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '4GB SERU · 1D', 'cost_price' => 7500, 'selling_price' => 9500, 'stock' => 0,
        ]);
    }

    public function test_owner_can_create_and_top_up_provider_balance(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Saldo', 'code' => 'SALDO']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('products.store'), [
            'operator' => 'SMARTFREN', 'category' => 'Saldo Provider', 'cost_price' => 999,
            'selling_price' => 2000, 'stock' => '1.500.000', 'is_active' => 1,
        ])->assertRedirect(route('products.index'));

        $balance = Product::where('outlet_id', $outlet->id)->where('category', 'Saldo Provider')->firstOrFail();
        $this->assertSame('Saldo SIDIVA', $balance->name);
        $this->assertSame(1500000, $balance->stock);
        $this->assertSame(0, $balance->cost_price);
        $this->assertNull($balance->quota_gb);

        $this->actingAs($owner)->post(route('products.stock', $balance), ['quantity' => '250.000'])->assertRedirect();
        $this->assertSame(1750000, $balance->fresh()->stock);
        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertSee('Rp 1.750.000');

        $this->actingAs($owner)->post(route('products.store'), [
            'operator' => 'SMARTFREN', 'category' => 'Saldo Provider', 'cost_price' => 0,
            'selling_price' => 0, 'stock' => 1000, 'is_active' => 1,
        ])->assertSessionHasErrors();
    }

    public function test_wallet_balances_are_separated_by_account_number(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Wallet', 'code' => 'WALLET']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $payload = [
            'operator' => 'DANA', 'category' => 'Saldo Provider', 'cost_price' => 0,
            'selling_price' => 0, 'stock' => 100000, 'is_active' => 1,
        ];

        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number' => '6281234567890',
        ])->assertRedirect(route('products.index'));
        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number' => '081298765432',
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'outlet_id' => $outlet->id, 'operator' => 'DANA', 'account_number' => '081234567890',
            'name' => 'Saldo DANA · 081234567890', 'stock' => 100000,
        ]);
        $this->assertDatabaseHas('products', [
            'outlet_id' => $outlet->id, 'operator' => 'DANA', 'account_number' => '081298765432',
        ]);

        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number' => '081234567890',
        ])->assertSessionHasErrors();
    }

    public function test_bank_balance_uses_the_same_flow_as_e_wallet(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Bank', 'code' => 'BANK']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);

        $this->actingAs($owner)->get(route('products.index'))
            ->assertOk()->assertSee('Perbankan');
        $this->actingAs($owner)->get(route('products.index', ['group' => 'bank']))
            ->assertOk()->assertSee('Bank Mandiri')->assertSee('SeaBank')->assertSee('Bank Jago')->assertSee('Bank ICBC Indonesia');
        foreach ([['SEABANK', '1234567890', '9876543210'], ['BANK_JAGO', '2234567890', '8876543210']] as [$operator, $account, $destination]) {
            $this->actingAs($owner)->post(route('products.store'), [
                'operator' => $operator, 'category' => 'Saldo Provider', 'account_number' => $account,
                'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => 1,
                'return_group' => 'bank', 'return_operator' => $operator,
            ])->assertRedirect(route('products.index', ['group' => 'bank', 'operator' => $operator]));

            $balance = Product::where('outlet_id', $outlet->id)->where('operator', $operator)->firstOrFail();
            $this->actingAs($owner)->post(route('transactions.store'), [
                'provider' => $operator, 'product_type' => 'Transfer', 'customer_number' => $destination,
                'transaction_action' => 'customer_topup', 'nominal' => 25000, 'admin_fee' => 2500,
                'balance_product_id' => $balance->id,
            ])->assertRedirect()->assertSessionHas('success');

            $this->assertSame(75000, $balance->fresh()->stock);
            $this->assertDatabaseHas('transactions', ['provider' => $operator, 'nominal' => 25000, 'admin_fee' => 2500]);
        }
    }

    public function test_maxim_wallet_sale_adds_selected_admin_fee(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Maxim', 'code' => 'MAX']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $balance = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'MAXIM', 'category' => 'Saldo Provider',
            'name' => 'Saldo MAXIM · 081234567890', 'account_number' => '081234567890',
            'cost_price' => 0, 'selling_price' => 0, 'stock' => 100000, 'is_active' => true,
        ]);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'provider' => 'MAXIM', 'product_type' => 'Saldo E-Wallet', 'customer_number' => '081234567890',
            'nominal' => 20000, 'admin_fee' => 3000, 'balance_product_id' => $balance->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'provider' => 'MAXIM', 'nominal' => 20000, 'admin_fee' => 3000,
            'cost_price' => 20000, 'price' => 23000, 'profit' => 3000,
        ]);
        $this->assertSame(80000, $balance->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $balance->id, 'type' => 'wallet_debit', 'quantity' => -20000, 'stock_after' => 80000,
        ]);
    }

    public function test_owner_can_increase_and_decrease_stock_and_history_is_recorded(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Mutasi', 'code' => 'MUTASI']);
        $owner = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $product = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1,
            'cost_price' => 8000, 'selling_price' => 10000, 'stock' => 10, 'is_active' => true,
        ]);

        $this->actingAs($owner)->post(route('products.stock', $product), [
            'quantity' => '2.000', 'direction' => 'increase',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('products.stock', $product), [
            'quantity' => '500', 'direction' => 'decrease',
        ])->assertRedirect();

        $this->assertSame(1510, $product->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $product->id, 'type' => 'increase', 'quantity' => 2000]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $product->id, 'type' => 'decrease', 'quantity' => -500]);
        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertSee('RIWAYAT STOK', false);
    }

    public function test_cashier_can_sell_multiple_provider_products_in_one_atomic_cart(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Grosir', 'code' => 'GROSIR']);
        $cashier = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $first = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1,
            'cost_price' => 8000, 'selling_price' => 10000, 'stock' => 150, 'is_active' => true,
        ]);
        $second = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '2GB · 1D', 'quota_gb' => 2, 'validity_days' => 1,
            'cost_price' => 3500, 'selling_price' => 5000, 'stock' => 600, 'is_active' => true,
        ]);

        $cart = json_encode([
            ['product_id' => $first->id, 'quantity' => 100, 'card_numbers' => []],
            ['product_id' => $second->id, 'quantity' => 500, 'card_numbers' => []],
        ]);
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_number' => '081234567890', 'cart_items' => $cart,
            'request_token' => '43c3dd64-ef03-4855-a882-ac732b32fe00',
        ])->assertRedirect()->assertSessionHas('success', '2 jenis produk berhasil dijual dalam satu pesanan.');

        $this->assertSame(50, $first->fresh()->stock);
        $this->assertSame(100, $second->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['product_id' => $first->id, 'quantity' => 100, 'price' => 1000000]);
        $this->assertDatabaseHas('transactions', ['product_id' => $second->id, 'quantity' => 500, 'price' => 2500000]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $first->id, 'quantity' => -100, 'stock_after' => 50]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $second->id, 'quantity' => -500, 'stock_after' => 100]);
    }

    public function test_cashier_price_override_only_applies_to_voucher_and_accessory_transaction(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Harga Kasir', 'code' => 'HARGA-KASIR']);
        $cashier = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $voucher = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'cost_price' => 8000, 'selling_price' => 10000, 'stock' => 5, 'is_active' => true,
        ]);
        $accessory = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'AKSESORIS', 'category' => 'Aksesoris HP',
            'name' => 'Kabel Data', 'cost_price' => 15000, 'selling_price' => 20000, 'stock' => 3, 'is_active' => true,
        ]);
        $phone = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'HANDPHONE', 'category' => 'Handphone',
            'name' => 'Galaxy A55 5G', 'brand' => 'Samsung', 'cost_price' => 5000000, 'selling_price' => 5500000, 'stock' => 2, 'is_active' => true,
        ]);
        $cardPackage = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'BYU', 'category' => 'Kartu Paket',
            'name' => '3GB · 30D', 'cost_price' => 10000, 'selling_price' => 12000, 'stock' => 2, 'is_active' => true,
        ]);

        $cart = json_encode([
            ['product_id' => $voucher->id, 'quantity' => 2, 'selling_price' => 12500],
            ['product_id' => $accessory->id, 'quantity' => 1, 'selling_price' => 23000],
            ['product_id' => $phone->id, 'quantity' => 1, 'selling_price' => 5600000],
            ['product_id' => $cardPackage->id, 'quantity' => 1, 'selling_price' => 99000],
        ]);

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_number' => '081234567890', 'cart_items' => $cart,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'product_id' => $voucher->id, 'quantity' => 2, 'nominal' => 12500,
            'price' => 25000, 'cost_price' => 16000, 'profit' => 9000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $accessory->id, 'quantity' => 1, 'nominal' => 23000,
            'price' => 23000, 'cost_price' => 15000, 'profit' => 8000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $phone->id, 'quantity' => 1, 'nominal' => 5600000,
            'price' => 5600000, 'cost_price' => 5000000, 'profit' => 600000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $cardPackage->id, 'quantity' => 1, 'nominal' => 12000,
            'price' => 12000, 'cost_price' => 10000, 'profit' => 2000,
        ]);
        $this->assertSame(10000, $voucher->fresh()->selling_price);
        $this->assertSame(20000, $accessory->fresh()->selling_price);
        $this->assertSame(5500000, $phone->fresh()->selling_price);
        $this->assertSame(12000, $cardPackage->fresh()->selling_price);
        $this->actingAs($cashier)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Harga jual Rp 12.500')
            ->assertSee('Laba Rp 9.000');
    }

    public function test_multi_product_cart_rolls_back_every_item_when_one_stock_is_insufficient(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Atomic', 'code' => 'ATOMIC']);
        $cashier = User::factory()->create(['outlet_id' => $outlet->id, 'role' => 'owner']);
        $available = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '5GB · 1D', 'quota_gb' => 5, 'validity_days' => 1,
            'cost_price' => 8000, 'selling_price' => 10000, 'stock' => 10, 'is_active' => true,
        ]);
        $insufficient = Product::create([
            'outlet_id' => $outlet->id, 'operator' => 'TELKOMSEL', 'category' => 'Voucher Internet',
            'name' => '2GB · 1D', 'quota_gb' => 2, 'validity_days' => 1,
            'cost_price' => 3500, 'selling_price' => 5000, 'stock' => 1, 'is_active' => true,
        ]);

        $cart = json_encode([
            ['product_id' => $available->id, 'quantity' => 2, 'card_numbers' => []],
            ['product_id' => $insufficient->id, 'quantity' => 2, 'card_numbers' => []],
        ]);
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_number' => '081234567890', 'cart_items' => $cart,
        ])->assertSessionHasErrors('cart_items');

        $this->assertSame(10, $available->fresh()->stock);
        $this->assertSame(1, $insufficient->fresh()->stock);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }
}
