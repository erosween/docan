<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_uses_the_imported_regency_and_district_list(): void
    {
        $regions = config('outlet_regions');

        $this->assertCount(60, $regions);
        $this->assertContains('AIR BESI', $regions['BENGKULU UTARA']);
        $this->assertContains('TAMAN SARI', $regions['KOTA PANGKAL PINANG']);
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('BENGKULU UTARA')
            ->assertSee('KOTA PANGKAL PINANG')
            ->assertDontSee('Khusus wilayah Sumatera Selatan.');
    }

    public function test_super_admin_can_open_dashboard_manage_denom_and_export(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Share penjualan operator')->assertSee('Penjualan voucher per denom')->assertDontSee('Tambah manual');
        $this->actingAs($admin)->get(route('admin.outlets'))->assertOk()->assertSee('Tambah manual')->assertDontSee('Semua transaksi');
        $this->actingAs($admin)->get(route('admin.transactions'))->assertOk()->assertSee('Semua transaksi')->assertSee('Dari tanggal')->assertSee('Sampai tanggal')->assertDontSee('Tambah manual');
        $this->actingAs($admin)->get(route('admin.denominations'))->assertOk()->assertSee('Master produk outlet')->assertSee('Nominal cepat')->assertSee('Download CSV');
        $this->actingAs($admin)->post(route('admin.denominations.store'), ['operator' => 'TELKOMSEL', 'category' => 'Pulsa Reguler', 'nominal' => '15.000', 'is_active' => 1])->assertRedirect();
        $this->assertDatabaseHas('denominations', ['operator' => 'TELKOMSEL', 'nominal' => 15000]);
        $this->actingAs($admin)->get(route('admin.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($admin)->get(route('admin.products.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_outlet_user_cannot_open_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_super_admin_can_create_outlet_and_default_user(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post(route('admin.outlets.store'), ['name' => 'Outlet Antasari', 'login_id' => 'ats-001', 'password' => 'Docan123!'])->assertRedirect()->assertSessionHas('credentials');
        $outlet = Outlet::where('login_id', 'ATS-001')->firstOrFail();
        $this->assertGreaterThan(0, $outlet->products()->count());
        $this->assertSame(0, $outlet->products()->where('stock', '!=', 0)->count());
        $this->assertSame(1, $outlet->users()->where('role', 'owner')->count());
        $this->assertSame(7, $outlet->products()->where('category', 'Kartu Paket')->where('quota_gb', 3)->where('validity_days', 30)->count());
        $initialProductCount = $outlet->products()->count();
        $this->actingAs($admin)->post(route('admin.outlets.catalog', $outlet))->assertRedirect()->assertSessionHasErrors('catalog');
        $this->assertSame($initialProductCount, $outlet->products()->count());
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'outlet_id' => $outlet->id, 'name' => 'Kasir Antasari', 'password' => 'Docan123!',
        ])->assertRedirect()->assertSessionHas('credentials');
        $this->assertDatabaseHas('users', ['outlet_id' => $outlet->id, 'role' => 'owner']);
        $outlet->update(['regency' => 'Kota Palembang', 'district' => 'Ilir Barat I']);
        $outlet->users()->where('role', 'owner')->update(['phone' => '081234567890']);
        $export = $this->actingAs($admin)->get(route('admin.outlets.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        foreach (['ATS-001', 'Owner Outlet Antasari', 'Nomor RS', 'Kabupaten', 'Kecamatan', '081234567890', 'Kota Palembang', 'Ilir Barat I'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }

        auth()->logout();
        $this->post(route('login.submit'), ['login_id' => 'ATS-001', 'password' => 'Docan123!'])->assertRedirect(route('pos'))->assertSessionHas('prompt_pwa', true);
    }

    public function test_super_admin_can_download_example_and_import_outlets_from_csv(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.outlets.example'))->assertOk()->assertDownload('contoh-import-outlet-docan.csv');
        $csv = "outlet_name,outlet_id,user_name,password\nOutlet Mariso,MRS-001,Kasir Mariso,Docan123!\nOutlet BTP,BTP-001,Kasir BTP,Docan456!\n";
        $file = UploadedFile::fake()->createWithContent('outlets.csv', $csv);
        $this->actingAs($admin)->post(route('admin.outlets.import'), ['csv' => $file])->assertRedirect()->assertSessionHas('success', '2 akun outlet berhasil diimpor.');
        $this->assertDatabaseHas('outlets', ['login_id' => 'MRS-001']);
        $this->assertDatabaseHas('users', ['name' => 'Kasir BTP', 'role' => 'owner']);
        $importedOutlet = Outlet::where('code', 'MRS-001')->firstOrFail();
        $this->assertGreaterThan(0, $importedOutlet->products()->count());
        $this->assertSame(0, $importedOutlet->products()->where('stock', '!=', 0)->count());
    }
}
