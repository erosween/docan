<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCardNumber;
use App\Models\ProductStockMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductFlowTest extends TestCase
{
    use RefreshDatabase;

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
        Transaction::create([
            'user_id' => $owner->id, 'product_id' => $voucher->id, 'provider' => 'TELKOMSEL',
            'product_type' => 'Voucher Internet', 'quantity' => 2, 'nominal' => 10000, 'price' => 20000,
            'cost_price' => 16000, 'profit' => 4000, 'customer_number' => '-',
        ]);

        $this->actingAs($owner)->get(route('reports.index'))
            ->assertOk()->assertSee(route('reports.detail', ['metric' => 'turnover', 'month' => now()->format('Y-m')]), false);
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'turnover']))
            ->assertOk()->assertSee('Produk Provider')->assertSee('Pulsa &amp; Paket Tembak', false)
            ->assertSee('E-Wallet')->assertSee('Aksesoris');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'turnover', 'group' => 'provider']))
            ->assertOk()->assertSee('Semua Provider')->assertSee('Telkomsel')
            ->assertSee('Omset Voucher Fisik')->assertSee('Rp 20.000')->assertSee('Omset Kartu Paket');
        $this->actingAs($owner)->get(route('reports.detail', ['metric' => 'stock', 'group' => 'provider']))
            ->assertOk()->assertSee('Stok Voucher Fisik')->assertSee('10 item')
            ->assertSee('Stok Kartu Paket')->assertSee('2 item');
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
        $outlet=Outlet::create(['name'=>'Outlet Role','code'=>'ROLE','login_id'=>'ROLE-001']);
        $owner=User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);

        $this->actingAs($owner)->post(route('settings.frontliners.store'),[
            'name'=>'FL Pagi','login_id'=>'ROLE-001-FL01','password'=>'Front123!','password_confirmation'=>'Front123!',
        ])->assertRedirect();
        $frontliner=User::where('outlet_id',$outlet->id)->where('role','frontliner')->firstOrFail();
        $this->assertSame('ROLE-001-FL01', $frontliner->login_id);

        $this->actingAs($frontliner)->get(route('pos'))->assertOk()->assertSee('Pilih Provider');
        $this->actingAs($frontliner)->get(route('products.index'))->assertRedirect(route('products.index',['stock'=>1]));
        $product=Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 1D','quota_gb'=>5,'validity_days'=>1,'cost_price'=>5000,'selling_price'=>7000,'stock'=>10]);
        $this->actingAs($frontliner)->get(route('products.index',['stock'=>1,'group'=>'provider','operator'=>'TELKOMSEL']))
            ->assertOk()->assertSee('5GB · 1D')->assertDontSee('+ Stok')->assertDontSee('Edit harga');
        $this->actingAs($frontliner)->post(route('products.stock',$product),['quantity'=>10])->assertForbidden();
        $this->assertSame(10,$product->fresh()->stock);
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
        $outlet=Outlet::create(['name'=>'Outlet Aggregator','code'=>'AGG']);
        $owner=User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);

        $this->actingAs($owner)->post(route('transactions.store'),[
            'provider'=>'DIGIPOS','product_type'=>'Pulsa','nominal'=>10000,
        ])->assertSessionHasErrors('customer_number');
        $this->actingAs($owner)->post(route('transactions.store'),[
            'customer_number'=>'081234567890','provider'=>'DIGIPOS','product_type'=>'Paket Tembak','nominal'=>25000,
            'admin_fee'=>3000,
        ])->assertRedirect();
        $this->assertDatabaseHas('transactions',[
            'provider'=>'DIGIPOS','product_type'=>'Paket Tembak','nominal'=>25000,
            'admin_fee'=>3000,'price'=>28000,'cost_price'=>25000,'profit'=>3000,
        ]);
    }

    public function test_recharge_channels_enforce_their_provider_prefixes(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Prefix', 'code' => 'PREFIX', 'login_id' => 'PREFIX-001']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $validNumbers = ['DIGIPOS' => '081234567890', 'SIDIVA' => '081734567890', 'ISIMPEL' => '081534567890', 'RITA' => '089534567890'];
        $invalidNumbers = ['DIGIPOS' => '088123456789', 'SIDIVA' => '089534567890', 'ISIMPEL' => '088123456789', 'RITA' => '081234567890'];

        foreach ($validNumbers as $provider => $number) {
            $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => $invalidNumbers[$provider], 'provider' => $provider, 'product_type' => 'Paket Tembak', 'nominal' => 10000])->assertSessionHasErrors('customer_number');
            $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => $number, 'provider' => $provider, 'product_type' => 'Paket Tembak', 'nominal' => 10000])->assertRedirect()->assertSessionHas('success');
        }

        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '088234567890', 'provider' => 'SIDIVA', 'product_type' => 'Paket Tembak', 'nominal' => 10000])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '081234567890', 'provider' => 'PROVIDER-PALSU', 'product_type' => 'Paket Tembak', 'nominal' => 10000])->assertSessionHasErrors('provider');
        $this->actingAs($user)->post(route('transactions.store'), ['customer_number' => '081234567890', 'provider' => 'DIGIPOS', 'product_type' => 'KATEGORI-PALSU', 'nominal' => 10000])->assertSessionHasErrors('product_type');
    }

    public function test_ppob_services_accept_customer_ids_and_are_recorded_separately(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet PPOB', 'code' => 'PPOB', 'login_id' => 'PPOB-001']);
        $user = User::factory()->create(['outlet_id' => $outlet->id]);
        $services = ['Listrik PLN Pascabayar','PDAM','BPJS Kesehatan','Telepon & Telkom/IndiHome','TV Berlangganan','Cicilan/Multifinance','Pulsa Elektrik','Paket Data/Internet','Token Listrik','Voucher Game'];

        foreach ($services as $service) {
            $this->actingAs($user)->post(route('transactions.store'), [
                'customer_number' => 'ID-123456', 'provider' => 'DIGIPOS', 'product_type' => $service, 'nominal' => 10000,
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
            ->assertDontSee('class="number-section"', false);
    }

    public function test_outlet_can_manage_its_own_product_and_sale_reduces_stock(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Test','code'=>'TEST']);
        $user = User::factory()->create(['outlet_id'=>$outlet->id]);

        $this->actingAs($user)->post(route('products.store'), [
            'operator'=>'TELKOMSEL','category'=>'Voucher Internet','quota_gb'=>8,'validity_days'=>28,
            'sku'=>'TSEL-8-28','cost_price'=>'30.000','selling_price'=>'35.000','stock'=>5,'is_active'=>1,
        ])->assertRedirect(route('products.index'));

        $product = Product::firstOrFail();
        $this->assertSame('8GB · 28D', $product->name);
        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number'=>'81234567890','product_id'=>$product->id,'nominal'=>0,
        ])->assertRedirect();

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['product_id'=>$product->id,'cost_price'=>30000,'price'=>35000,'profit'=>5000]);
        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('Sering kamu jual')->assertSee('8GB · 28D');

        foreach (range(1, 12) as $number) {
            Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
                'name'=>$number.'GB · 7 Hari','quota_gb'=>$number,'validity_days'=>7,
                'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);
        }
        $this->actingAs($user)->get(route('products.index', ['view' => 'all']))->assertOk()
            ->assertSee('Halaman 1 dari 2')->assertSee('Berikutnya');
    }

    public function test_user_cannot_edit_another_outlets_product(): void
    {
        $first = Outlet::create(['name'=>'Satu','code'=>'ONE']);
        $second = Outlet::create(['name'=>'Dua','code'=>'TWO']);
        $user = User::factory()->create(['outlet_id'=>$first->id]);
        $product = Product::create(['outlet_id'=>$second->id,'operator'=>'XL','category'=>'Voucher Fisik','name'=>'5GB','cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);

        $this->actingAs($user)->get(route('products.edit', $product))->assertNotFound();
    }

    public function test_duplicate_product_is_rejected_and_direct_nominal_sale_works(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Test','code'=>'DUP']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 7 Hari','quota_gb'=>5,'validity_days'=>7,'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);

        $this->actingAs($user)->post(route('products.store'),['operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'quota_gb'=>5,'validity_days'=>7,'cost_price'=>5000,'selling_price'=>7000,'stock'=>2,'is_active'=>1])
            ->assertSessionHasErrors('quota_gb');

        $this->actingAs($user)->post(route('transactions.store'),['customer_number'=>'08123456789',
            'provider'=>'TELKOMSEL','product_type'=>'Pulsa Reguler','nominal'=>25000])->assertRedirect();
        $this->assertDatabaseHas('transactions',['product_id'=>null,'provider'=>'TELKOMSEL','price'=>25000,'profit'=>0]);
    }

    public function test_same_package_with_different_cost_and_accessory_are_allowed(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Test','code'=>'COST']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 7 Hari','quota_gb'=>5,'validity_days'=>7,'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);

        $this->actingAs($user)->post(route('products.store'),['operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'quota_gb'=>5,'validity_days'=>7,'cost_price'=>5500,'selling_price'=>7500,'stock'=>2,'is_active'=>1])
            ->assertRedirect(route('products.index'));
        $this->actingAs($user)->post(route('products.store'),['operator'=>'AKSESORIS','category'=>'Aksesoris HP',
            'name'=>'Kabel Data Type-C','brand'=>'Vivan','cost_price'=>10000,'selling_price'=>15000,'stock'=>4,'is_active'=>1])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseCount('products',3);
        $this->assertDatabaseHas('products',['operator'=>'AKSESORIS','name'=>'Kabel Data Type-C','brand'=>'Vivan']);
        $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertSee('OMSET BULAN INI');
    }

    public function test_payment_services_require_the_correct_customer_identifier(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Payment','code'=>'PAY']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        $danaBalance=Product::create(['outlet_id'=>$outlet->id,'operator'=>'DANA','category'=>'Saldo Provider',
            'name'=>'Saldo DANA · 089812345678','account_number'=>'089812345678','cost_price'=>0,'selling_price'=>0,'stock'=>200000]);
        $briBalance=Product::create(['outlet_id'=>$outlet->id,'operator'=>'BRILINK','category'=>'Saldo Provider',
            'name'=>'Saldo BRILINK · 880012345678','account_number'=>'880012345678','cost_price'=>0,'selling_price'=>0,'stock'=>200000]);

        $this->actingAs($user)->post(route('transactions.store'),[
            'provider'=>'DANA','product_type'=>'Saldo E-Wallet','nominal'=>20000,
        ])->assertSessionHasErrors('customer_number');

        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'089812345678','provider'=>'DANA','product_type'=>'Saldo E-Wallet','nominal'=>20000,
            'balance_product_id'=>$danaBalance->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'ID-PLN-7788','provider'=>'PPOB','product_type'=>'Pascabayar','nominal'=>50000,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'880012345678','provider'=>'BRILINK','product_type'=>'Transfer','nominal'=>100000,
            'balance_product_id'=>$briBalance->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('transactions',['provider'=>'DANA','customer_number'=>'089812345678']);
        $this->assertDatabaseHas('transactions',['provider'=>'PPOB','customer_number'=>'ID-PLN-7788']);
        $this->assertDatabaseHas('transactions',['provider'=>'BRILINK','customer_number'=>'880012345678']);
    }

    public function test_transaction_rejects_customer_number_from_another_provider(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Prefix','code'=>'PREFIX']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        $product=Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 7 Hari','quota_gb'=>5,'validity_days'=>7,'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);

        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'081712345678','product_id'=>$product->id,
        ])->assertSessionHasErrors('customer_number');

        $this->assertSame(2,$product->fresh()->stock);
        $this->assertDatabaseCount('transactions',0);
    }

    public function test_duplicate_transaction_token_only_reduces_stock_once(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Idempotent','code'=>'IDEMP']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        $product=Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet','name'=>'5GB · 7 Hari','quota_gb'=>5,'validity_days'=>7,'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);
        $payload=['request_token'=>'16cf55ca-7eb2-4b1c-a440-5478c9039469','customer_number'=>'081234567890','product_id'=>$product->id];

        $this->actingAs($user)->post(route('transactions.store'),$payload)->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'),$payload)->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1,$product->fresh()->stock);
        $this->assertDatabaseCount('transactions',1);
    }

    public function test_zero_stock_product_is_still_sent_to_cashier_catalog(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Stok','code'=>'STOCK']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'Produk Baru Stok Kosong','quota_gb'=>1,'validity_days'=>1,
            'cost_price'=>5000,'selling_price'=>8000,'stock'=>0,'is_active'=>true]);

        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('Produk Baru Stok Kosong');
    }

    public function test_outlet_user_can_change_own_password(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Password','code'=>'PASS']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id,'password'=>'PasswordLama!']);

        $this->actingAs($user)->put(route('settings.password'),[
            'current_password'=>'PasswordLama!','password'=>'PasswordBaru!','password_confirmation'=>'PasswordBaru!',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue(Hash::check('PasswordBaru!',$user->fresh()->password));
    }

    public function test_outlet_can_add_stock_and_bulk_sell_card_numbers(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Kartu','code'=>'CARD']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        $product=Product::create(['outlet_id'=>$outlet->id,'operator'=>'BYU','category'=>'Kartu Paket','name'=>'3GB · 30 Hari','quota_gb'=>3,'validity_days'=>30,'cost_price'=>10000,'selling_price'=>12000,'stock'=>0,'is_active'=>true]);
        $numbers=collect(range(10,14))->map(fn($suffix)=>'0851123456'.$suffix)->implode("\n");

        $this->actingAs($user)->postJson(route('products.stock',$product),['quantity'=>10])->assertOk()->assertJson(['stock'=>10]);
        $this->assertSame(10,$product->fresh()->stock);
        $this->assertSame(0,ProductCardNumber::where('product_id',$product->id)->count());

        $this->actingAs($user)->post(route('transactions.store'),['product_id'=>$product->id,'quantity'=>5,'card_numbers'=>$numbers])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(5,$product->fresh()->stock);
        $this->assertSame(5,ProductCardNumber::where('product_id',$product->id)->whereNotNull('sold_at')->count());
        $this->assertDatabaseHas('transactions',['product_id'=>$product->id,'quantity'=>5,'price'=>60000,'cost_price'=>50000,'profit'=>10000]);
        $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertSee('Qty 5')->assertSee('1 transaksi');
    }

    public function test_card_numbers_must_match_operator_and_outlet_can_update_price_inline(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Inline','code'=>'INLINE']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);
        $product=Product::create(['outlet_id'=>$outlet->id,'operator'=>'XL','category'=>'Kartu Paket','name'=>'3GB · 30 Hari','quota_gb'=>3,'validity_days'=>30,'cost_price'=>10000,'selling_price'=>12000,'stock'=>3,'is_active'=>true]);

        $this->actingAs($user)->post(route('transactions.store'),[
            'product_id'=>$product->id,'quantity'=>1,'card_numbers'=>'081212345678',
        ])->assertSessionHasErrors('card_numbers');
        $this->assertSame(3,$product->fresh()->stock);
        $this->assertDatabaseCount('transactions',0);

        $this->actingAs($user)->postJson(route('products.price',$product),[
            'cost_price'=>'11.000','selling_price'=>'14.000',
        ])->assertOk()->assertJson(['cost_price'=>11000,'selling_price'=>14000]);
        $this->assertDatabaseHas('products',['id'=>$product->id,'cost_price'=>11000,'selling_price'=>14000]);
    }

    public function test_edit_keeps_package_identity_and_new_cost_creates_price_variant(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Varian','code'=>'VAR']);
        $user = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);
        $product = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'4GB SERU · 1D','quota_gb'=>4,'validity_days'=>1,
            'cost_price'=>7000,'selling_price'=>8000,'stock'=>10,'is_active'=>true,
        ]);

        $this->actingAs($user)->put(route('products.update', $product), [
            'operator'=>'XL','category'=>'Kartu Paket','quota_gb'=>20,'validity_days'=>30,
            'cost_price'=>7200,'selling_price'=>9000,'stock'=>10,'is_active'=>1,
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('TELKOMSEL', $product->operator);
        $this->assertSame('Voucher Internet', $product->category);
        $this->assertSame(4.0, $product->quota_gb);
        $this->assertSame(1, $product->validity_days);
        $this->assertSame('4GB SERU · 1D', $product->name);

        $this->actingAs($user)->get(route('products.create', ['variant'=>1,'source'=>$product->id]))
            ->assertOk()
            ->assertSee('Harga baru')
            ->assertSee('value="0"', false);

        $this->actingAs($user)->post(route('products.store'), [
            'operator'=>'TELKOMSEL','category'=>'Voucher Internet','quota_gb'=>4,'validity_days'=>1,
            'cost_price'=>7200,'selling_price'=>9000,'stock'=>0,'is_active'=>1,'variant'=>1,'source_id'=>$product->id,
        ])->assertSessionHasErrors();

        $this->actingAs($user)->post(route('products.store'), [
            'operator'=>'TELKOMSEL','category'=>'Voucher Internet','quota_gb'=>4,'validity_days'=>1,
            'cost_price'=>7500,'selling_price'=>9500,'stock'=>0,'is_active'=>1,'variant'=>1,'source_id'=>$product->id,
        ])->assertRedirect(route('products.index', ['operator'=>'TELKOMSEL']));

        $this->assertSame(2, Product::where('outlet_id',$outlet->id)
            ->where('operator','TELKOMSEL')->where('quota_gb',4)->where('validity_days',1)->count());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('products',[
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'4GB SERU · 1D','cost_price'=>7500,'selling_price'=>9500,'stock'=>0,
        ]);
    }

    public function test_owner_can_create_and_top_up_provider_balance(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Saldo','code'=>'SALDO']);
        $owner = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);

        $this->actingAs($owner)->post(route('products.store'), [
            'operator'=>'SMARTFREN','category'=>'Saldo Provider','cost_price'=>999,
            'selling_price'=>2000,'stock'=>'1.500.000','is_active'=>1,
        ])->assertRedirect(route('products.index'));

        $balance = Product::where('outlet_id',$outlet->id)->where('category','Saldo Provider')->firstOrFail();
        $this->assertSame('Saldo SIDIVA', $balance->name);
        $this->assertSame(1500000, $balance->stock);
        $this->assertSame(0, $balance->cost_price);
        $this->assertNull($balance->quota_gb);

        $this->actingAs($owner)->post(route('products.stock',$balance), ['quantity'=>'250.000'])->assertRedirect();
        $this->assertSame(1750000, $balance->fresh()->stock);
        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertSee('Rp 1.750.000');

        $this->actingAs($owner)->post(route('products.store'), [
            'operator'=>'SMARTFREN','category'=>'Saldo Provider','cost_price'=>0,
            'selling_price'=>0,'stock'=>1000,'is_active'=>1,
        ])->assertSessionHasErrors();
    }

    public function test_wallet_balances_are_separated_by_account_number(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Wallet','code'=>'WALLET']);
        $owner = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);

        $payload = [
            'operator'=>'DANA','category'=>'Saldo Provider','cost_price'=>0,
            'selling_price'=>0,'stock'=>100000,'is_active'=>1,
        ];

        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number'=>'6281234567890',
        ])->assertRedirect(route('products.index'));
        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number'=>'081298765432',
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'outlet_id'=>$outlet->id,'operator'=>'DANA','account_number'=>'081234567890',
            'name'=>'Saldo DANA · 081234567890','stock'=>100000,
        ]);
        $this->assertDatabaseHas('products', [
            'outlet_id'=>$outlet->id,'operator'=>'DANA','account_number'=>'081298765432',
        ]);

        $this->actingAs($owner)->post(route('products.store'), $payload + [
            'account_number'=>'081234567890',
        ])->assertSessionHasErrors();
    }

    public function test_maxim_wallet_sale_adds_selected_admin_fee(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Maxim','code'=>'MAX']);
        $owner = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);
        $balance = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'MAXIM','category'=>'Saldo Provider',
            'name'=>'Saldo MAXIM · 081234567890','account_number'=>'081234567890',
            'cost_price'=>0,'selling_price'=>0,'stock'=>100000,'is_active'=>true,
        ]);

        $this->actingAs($owner)->post(route('transactions.store'), [
            'provider'=>'MAXIM','product_type'=>'Saldo E-Wallet','customer_number'=>'081234567890',
            'nominal'=>20000,'admin_fee'=>3000,'balance_product_id'=>$balance->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'provider'=>'MAXIM','nominal'=>20000,'admin_fee'=>3000,
            'cost_price'=>20000,'price'=>23000,'profit'=>3000,
        ]);
        $this->assertSame(80000, $balance->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id'=>$balance->id,'type'=>'wallet_debit','quantity'=>-20000,'stock_after'=>80000,
        ]);
    }

    public function test_owner_can_increase_and_decrease_stock_and_history_is_recorded(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Mutasi','code'=>'MUTASI']);
        $owner = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);
        $product = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 1D','quota_gb'=>5,'validity_days'=>1,
            'cost_price'=>8000,'selling_price'=>10000,'stock'=>10,'is_active'=>true,
        ]);

        $this->actingAs($owner)->post(route('products.stock',$product), [
            'quantity'=>'2.000','direction'=>'increase',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('products.stock',$product), [
            'quantity'=>'500','direction'=>'decrease',
        ])->assertRedirect();

        $this->assertSame(1510, $product->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', ['product_id'=>$product->id,'type'=>'increase','quantity'=>2000]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id'=>$product->id,'type'=>'decrease','quantity'=>-500]);
        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertSee('RIWAYAT STOK', false);
    }

    public function test_cashier_can_sell_multiple_provider_products_in_one_atomic_cart(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Grosir','code'=>'GROSIR']);
        $cashier = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);
        $first = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 1D','quota_gb'=>5,'validity_days'=>1,
            'cost_price'=>8000,'selling_price'=>10000,'stock'=>150,'is_active'=>true,
        ]);
        $second = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'2GB · 1D','quota_gb'=>2,'validity_days'=>1,
            'cost_price'=>3500,'selling_price'=>5000,'stock'=>600,'is_active'=>true,
        ]);

        $cart = json_encode([
            ['product_id'=>$first->id,'quantity'=>100,'card_numbers'=>[]],
            ['product_id'=>$second->id,'quantity'=>500,'card_numbers'=>[]],
        ]);
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_number'=>'081234567890','cart_items'=>$cart,
            'request_token'=>'43c3dd64-ef03-4855-a882-ac732b32fe00',
        ])->assertRedirect()->assertSessionHas('success','2 jenis produk berhasil dijual dalam satu pesanan.');

        $this->assertSame(50, $first->fresh()->stock);
        $this->assertSame(100, $second->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['product_id'=>$first->id,'quantity'=>100,'price'=>1000000]);
        $this->assertDatabaseHas('transactions', ['product_id'=>$second->id,'quantity'=>500,'price'=>2500000]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id'=>$first->id,'quantity'=>-100,'stock_after'=>50]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id'=>$second->id,'quantity'=>-500,'stock_after'=>100]);
    }

    public function test_multi_product_cart_rolls_back_every_item_when_one_stock_is_insufficient(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Atomic','code'=>'ATOMIC']);
        $cashier = User::factory()->create(['outlet_id'=>$outlet->id,'role'=>'owner']);
        $available = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'5GB · 1D','quota_gb'=>5,'validity_days'=>1,
            'cost_price'=>8000,'selling_price'=>10000,'stock'=>10,'is_active'=>true,
        ]);
        $insufficient = Product::create([
            'outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
            'name'=>'2GB · 1D','quota_gb'=>2,'validity_days'=>1,
            'cost_price'=>3500,'selling_price'=>5000,'stock'=>1,'is_active'=>true,
        ]);

        $cart = json_encode([
            ['product_id'=>$available->id,'quantity'=>2,'card_numbers'=>[]],
            ['product_id'=>$insufficient->id,'quantity'=>2,'card_numbers'=>[]],
        ]);
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_number'=>'081234567890','cart_items'=>$cart,
        ])->assertSessionHasErrors('cart_items');

        $this->assertSame(10, $available->fresh()->stock);
        $this->assertSame(1, $insufficient->fresh()->stock);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }
}
