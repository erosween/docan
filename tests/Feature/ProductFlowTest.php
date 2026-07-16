<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCardNumber;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlet_can_manage_its_own_product_and_sale_reduces_stock(): void
    {
        $outlet = Outlet::create(['name'=>'Outlet Test','code'=>'TEST']);
        $user = User::factory()->create(['outlet_id'=>$outlet->id]);

        $this->actingAs($user)->post(route('products.store'), [
            'operator'=>'TELKOMSEL','category'=>'Voucher Internet','quota_gb'=>8,'validity_days'=>28,
            'sku'=>'TSEL-8-28','cost_price'=>'30.000','selling_price'=>'35.000','stock'=>5,'is_active'=>1,
        ])->assertRedirect(route('products.index'));

        $product = Product::firstOrFail();
        $this->assertSame('8GB · 28 Hari', $product->name);
        $this->actingAs($user)->post(route('transactions.store'), [
            'customer_number'=>'81234567890','product_id'=>$product->id,'nominal'=>0,
        ])->assertRedirect();

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('transactions', ['product_id'=>$product->id,'cost_price'=>30000,'price'=>35000,'profit'=>5000]);
        $this->actingAs($user)->get(route('pos'))->assertOk()->assertSee('Sering kamu jual')->assertSee('8GB · 28 Hari');

        foreach (range(1, 12) as $number) {
            Product::create(['outlet_id'=>$outlet->id,'operator'=>'TELKOMSEL','category'=>'Voucher Internet',
                'name'=>$number.'GB · 7 Hari','quota_gb'=>$number,'validity_days'=>7,
                'cost_price'=>5000,'selling_price'=>7000,'stock'=>2]);
        }
        $this->actingAs($user)->get(route('products.index'))->assertOk()
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
            'name'=>'Kabel Data Type-C','cost_price'=>10000,'selling_price'=>15000,'stock'=>4,'is_active'=>1])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseCount('products',3);
        $this->assertDatabaseHas('products',['operator'=>'AKSESORIS','name'=>'Kabel Data Type-C']);
        $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertSee('Tren 7 hari');
    }

    public function test_payment_services_require_the_correct_customer_identifier(): void
    {
        $outlet=Outlet::create(['name'=>'Outlet Payment','code'=>'PAY']);
        $user=User::factory()->create(['outlet_id'=>$outlet->id]);

        $this->actingAs($user)->post(route('transactions.store'),[
            'provider'=>'DANA','product_type'=>'Saldo E-Wallet','nominal'=>20000,
        ])->assertSessionHasErrors('customer_number');

        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'089812345678','provider'=>'DANA','product_type'=>'Saldo E-Wallet','nominal'=>20000,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'ID-PLN-7788','provider'=>'PPOB','product_type'=>'Pascabayar','nominal'=>50000,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('transactions.store'),[
            'customer_number'=>'880012345678','provider'=>'BRILINK','product_type'=>'Transfer','nominal'=>100000,
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
}
