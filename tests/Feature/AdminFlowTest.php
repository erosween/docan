<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    public function test_owner_can_request_and_complete_password_reset_by_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@docan.test', 'password' => 'PasswordLama!']);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->assertStringStartsWith(
                'https://docan.suhail.my.id/reset-password/',
                $notification->toMail($user)->actionUrl
            );
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'PasswordBaru!2',
                'password_confirmation' => 'PasswordBaru!2',
            ])->assertRedirect(route('login'));

            return true;
        });
        $this->assertTrue(Hash::check('PasswordBaru!2', $user->fresh()->password));
    }

    public function test_super_admin_can_open_dashboard_manage_denom_and_export(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Share penjualan operator')->assertSee('Penjualan voucher per denom')->assertDontSee('Tambah manual');
        $this->actingAs($admin)->get(route('admin.outlets'))->assertOk()
            ->assertSee('Tambah manual')
            ->assertSee('list="admin-regencies"', false)
            ->assertSee('data-regency', false)
            ->assertDontSee('Semua transaksi');
        $this->actingAs($admin)->get(route('admin.transactions'))->assertOk()->assertSee('Semua transaksi')->assertSee('Pilih outlet')->assertSee('Dari tanggal')->assertSee('Sampai tanggal')->assertDontSee('Tambah manual');
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
        $this->actingAs($admin)->post(route('admin.outlets.store'), [
            'name' => 'Outlet Antasari',
            'owner_name' => 'Owner Antasari',
            'login_id' => 'ats-001',
            'phone' => '081234567890',
            'regency' => 'KOTA PALEMBANG',
            'district' => 'ILIR BARAT I',
            'email' => 'owner.antasari@example.test',
            'password' => 'Docan123!',
            'password_confirmation' => 'Docan123!',
        ])->assertRedirect()->assertSessionHas('credentials');
        $outlet = Outlet::where('login_id', 'ATS-001')->firstOrFail();
        $this->assertSame('KOTA PALEMBANG', $outlet->regency);
        $this->assertSame('ILIR BARAT I', $outlet->district);
        $this->assertGreaterThan(0, $outlet->products()->count());
        $this->assertSame(0, $outlet->products()->where('stock', '!=', 0)->count());
        $this->assertSame(1, $outlet->users()->where('role', 'owner')->count());
        $this->assertSame('owner.antasari@example.test', $outlet->users()->where('role', 'owner')->value('email'));
        $this->assertSame(7, $outlet->products()->where('category', 'Kartu Paket')->where('quota_gb', 3)->where('validity_days', 30)->count());
        $this->actingAs($admin)->get(route('admin.denominations'))
            ->assertOk()
            ->assertSee('Daftar outlet Master Produk')
            ->assertSee('1 outlet unik')
            ->assertSee('Outlet Antasari')
            ->assertSee('ATS-001');
        $initialProductCount = $outlet->products()->count();
        $this->actingAs($admin)->post(route('admin.outlets.catalog', $outlet))->assertRedirect()->assertSessionHasErrors('catalog');
        $this->assertSame($initialProductCount, $outlet->products()->count());
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'outlet_id' => $outlet->id, 'name' => 'Kasir Antasari', 'password' => 'Docan123!',
        ])->assertRedirect()->assertSessionHas('credentials');
        $this->assertDatabaseHas('users', ['outlet_id' => $outlet->id, 'role' => 'owner']);
        $this->actingAs($admin)->put(route('admin.outlets.update', $outlet), [
            'name' => 'Outlet Antasari Baru',
            'owner_name' => 'Owner Antasari Baru',
            'email' => 'owner.baru@example.test',
            'phone' => '081234567890',
            'regency' => 'KOTA PALEMBANG',
            'district' => 'ILIR BARAT I',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'name' => 'Outlet Antasari Baru', 'regency' => 'KOTA PALEMBANG', 'district' => 'ILIR BARAT I']);
        $this->assertDatabaseHas('users', ['outlet_id' => $outlet->id, 'name' => 'Owner Antasari Baru', 'email' => 'owner.baru@example.test']);
        $frontliner = User::factory()->create([
            'outlet_id' => $outlet->id,
            'name' => 'Frontliner Antasari',
            'login_id' => 'ATS-001-FL01',
            'role' => 'frontliner',
        ]);
        Transaction::create([
            'user_id' => $frontliner->id,
            'customer_number' => '081234567890',
            'provider' => 'DIGIPOS',
            'product_type' => 'Pulsa',
            'quantity' => 1,
            'nominal' => 50000,
            'price' => 51000,
            'cost_price' => 50000,
            'profit' => 1000,
        ]);
        $outlet->update(['regency' => 'Kota Palembang', 'district' => 'Ilir Barat I']);
        $outlet->users()->where('role', 'owner')->update(['phone' => '081234567890']);
        $export = $this->actingAs($admin)->get(route('admin.outlets.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        foreach (['ID Outlet', 'Nama Outlet', 'Nomor RS', 'Kabupaten', 'Kecamatan', 'Akun Owner', 'Akun Frontliner', 'Email', 'Tanggal Dibuat', 'Aksi', 'ATS-001', 'Owner Antasari Baru', 'owner.baru@example.test', '081234567890', 'Kota Palembang', 'Ilir Barat I'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
        $otherOutlet = Outlet::create(['name' => 'Outlet Tidak Dicari', 'login_id' => 'ZZZ-999', 'code' => 'ZZZ-999']);
        User::factory()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Owner Lain', 'login_id' => 'ZZZ-999', 'role' => 'owner']);
        $this->actingAs($admin)->get(route('admin.outlets', ['outlet_search' => 'owner.baru@example.test']))
            ->assertOk()
            ->assertSee('Outlet Antasari Baru')
            ->assertDontSee('Outlet Tidak Dicari');
        $filteredCsv = $this->actingAs($admin)->get(route('admin.outlets.export', ['outlet_search' => 'ats-001']))->streamedContent();
        $this->assertStringContainsString('Outlet Antasari Baru', $filteredCsv);
        $this->assertStringNotContainsString('Outlet Tidak Dicari', $filteredCsv);
        $transactionCsv = $this->actingAs($admin)->get(route('admin.export', ['outlet' => $outlet->id]))->streamedContent();
        foreach (['ID Outlet', 'Nama Outlet', 'Nomor RS', 'Kabupaten', 'Kecamatan', 'Akun Owner', 'Akun Frontliner', 'Email', 'Tanggal Dibuat', 'Kasir', 'Operator', 'Produk', 'Nomor', 'Qty', 'Modal', 'Harga Jual', 'Laba', 'Petugas', 'Jenis Akun', 'ATS-001', 'Frontliner Antasari', 'DIGIPOS', 'Pulsa'] as $value) {
            $this->assertStringContainsString($value, $transactionCsv);
        }
        $transactionRows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($transactionCsv)));
        $this->assertSame('="081234567890"', $transactionRows[1][12]);
        $this->assertSame('Frontliner Antasari', $transactionRows[1][17]);
        $this->assertSame('FL', $transactionRows[1][18]);

        auth()->logout();
        $this->post(route('login.submit'), ['login_id' => 'ATS-001', 'password' => 'Docan123!'])->assertRedirect(route('pos'))->assertSessionHas('prompt_pwa', true);
    }

    public function test_super_admin_can_download_example_and_import_outlets_from_csv(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.outlets.example'))->assertOk()->assertDownload('contoh-import-outlet-docan.csv');
        $csv = "outlet_name,outlet_id,owner_name,phone,regency,district,email,password\nOutlet Mariso,MRS-001,Owner Mariso,081234567891,KOTA PALEMBANG,ILIR BARAT I,mariso@example.test,Docan123!\nOutlet BTP,BTP-001,Owner BTP,081234567892,KOTA PALEMBANG,ILIR TIMUR I,btp@example.test,Docan456!\n";
        $file = UploadedFile::fake()->createWithContent('outlets.csv', $csv);
        $this->actingAs($admin)->post(route('admin.outlets.import'), ['csv' => $file])->assertRedirect()->assertSessionHas('success', '2 akun outlet berhasil diimpor.');
        $this->assertDatabaseHas('outlets', ['login_id' => 'MRS-001']);
        $this->assertDatabaseHas('users', ['name' => 'Owner BTP', 'email' => 'btp@example.test', 'role' => 'owner']);
        $importedOutlet = Outlet::where('code', 'MRS-001')->firstOrFail();
        $this->assertGreaterThan(0, $importedOutlet->products()->count());
        $this->assertSame(0, $importedOutlet->products()->where('stock', '!=', 0)->count());
    }
}
