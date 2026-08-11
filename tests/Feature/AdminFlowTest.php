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
        $this->assertCount(597, config('sf_codes'));
        $this->assertContains('CVS-BENGKULU-8', config('sf_codes'));
        $this->assertContains('AIR BESI', $regions['BENGKULU UTARA']);
        $this->assertContains('TAMAN SARI', $regions['KOTA PANGKAL PINANG']);
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('BENGKULU UTARA')
            ->assertSee('KOTA PANGKAL PINANG')
            ->assertSee('SF Code')
            ->assertDontSee('Khusus wilayah Sumatera Selatan.');
    }

    public function test_registration_accepts_lowercase_region_and_explains_duplicate_email(): void
    {
        $existing = User::factory()->create(['email' => 'owner@docan.test']);
        $salesForce = User::where('sf_code', 'CVS-BENGKULU-8')->firstOrFail();

        $payload = [
            'outlet_name' => 'Outlet Huruf Kecil',
            'owner_name' => 'Pemilik Outlet',
            'email' => $existing->email,
            'regency' => 'bengkulu utara',
            'district' => 'air besi',
            'login_id' => 'OUTLET-LOWERCASE',
            'sf_code' => 'cvs-bengkulu-8',
            'rs_number' => '081234567890',
            'password' => 'PasswordBaru!2',
            'password_confirmation' => 'PasswordBaru!2',
            'terms' => '1',
        ];

        $this->postJson(route('register.email.check'), ['email' => 'OWNER@DOCAN.TEST'])
            ->assertOk()
            ->assertJson([
                'available' => false,
                'message' => 'Email sudah terdaftar. Silakan masuk atau gunakan lupa password.',
            ]);
        $this->postJson(route('register.email.check'), ['email' => 'tersedia@docan.test'])
            ->assertOk()
            ->assertJson(['available' => true]);

        $this->post(route('register.submit'), $payload)
            ->assertSessionHasErrors([
                'email' => 'Email sudah terdaftar. Silakan masuk atau gunakan lupa password.',
            ]);

        $invalidSfPayload = $payload;
        $invalidSfPayload['email'] = 'invalid.sf@docan.test';
        $invalidSfPayload['sf_code'] = 'SF-TIDAK-TERDAFTAR';
        $this->post(route('register.submit'), $invalidSfPayload)
            ->assertSessionHasErrors(['sf_code' => 'SF Code tidak terdaftar. Periksa kembali atau hubungi petugas SF.']);

        $payload['email'] = 'baru@docan.test';
        $this->post(route('register.submit'), $payload)->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertDatabaseHas('outlets', [
            'login_id' => 'OUTLET-LOWERCASE',
            'regency' => 'BENGKULU UTARA',
            'district' => 'AIR BESI',
            'sf_user_id' => $salesForce->id,
            'status' => 'pending',
        ]);
    }

    public function test_sales_force_can_approve_and_control_registered_outlets(): void
    {
        $salesForce = User::where('sf_code', 'CVS-BENGKULU-8')->firstOrFail();
        $outlet = Outlet::create([
            'name' => 'Outlet Binaan', 'code' => 'BINAAN-01', 'login_id' => 'BINAAN-01',
            'regency' => 'KOTA BENGKULU', 'district' => 'RATU AGUNG',
            'sf_user_id' => $salesForce->id, 'status' => 'pending',
        ]);
        $owner = User::factory()->create([
            'outlet_id' => $outlet->id, 'role' => 'owner', 'login_id' => 'BINAAN-01', 'password' => 'Docan123!',
        ]);
        Transaction::create([
            'user_id' => $owner->id, 'customer_number' => '-', 'provider' => 'TELKOMSEL',
            'product_type' => 'Voucher Internet', 'quantity' => 2, 'nominal' => 10000,
            'price' => 20000, 'cost_price' => 16000, 'profit' => 4000,
        ]);

        $this->post(route('login.submit'), ['login_id' => 'BINAAN-01', 'password' => 'Docan123!'])
            ->assertSessionHasErrors('login_id');
        $this->post(route('login.submit'), ['login_id' => 'CVS-BENGKULU-8', 'password' => 'Docan123!'])
            ->assertRedirect(route('sf.dashboard'));
        $this->get(route('sf.dashboard'))->assertOk()
            ->assertSee('Outlet Binaan')->assertSee('Menunggu')
            ->assertSee('Sudah mencatat')->assertSee('Rp 20.000')->assertSee('Item terjual');
        $this->put(route('sf.outlets.status', $outlet), ['status' => 'active'])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'status' => 'active']);

        auth()->logout();
        $this->post(route('login.submit'), ['login_id' => 'BINAAN-01', 'password' => 'Docan123!'])
            ->assertRedirect(route('pos'));
        $this->actingAs($salesForce)->put(route('sf.outlets.status', $outlet), ['status' => 'inactive'])->assertRedirect();
        $this->actingAs($owner)->get(route('pos'))->assertRedirect(route('login'));
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
            ->assertSee('List SF')
            ->assertSee('Download outlet & user', false)
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

    public function test_all_official_sales_force_codes_are_provisioned_as_accounts(): void
    {
        $this->assertDatabaseHas('users', [
            'role' => 'sf', 'outlet_id' => null, 'login_id' => 'SF-LAMPUNG1-36', 'sf_code' => 'SF-LAMPUNG1-36',
        ]);
        $this->assertSame(count(config('sf_codes')), User::where('role', 'sf')->whereNotNull('sf_code')->count());
    }

    public function test_super_admin_can_browse_search_and_export_sales_force_directory(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->get(route('admin.outlets'))
            ->assertOk()
            ->assertSee('List Outlet')
            ->assertSee('List SF')
            ->assertDontSee('Buat akun SF');

        $this->actingAs($admin)->get(route('admin.outlets', ['directory' => 'sf', 'sf_search' => 'SF-LAMPUNG1-36']))
            ->assertOk()
            ->assertSee('SF-LAMPUNG1-36')
            ->assertSee('Buat akun SF baru')
            ->assertSee('sf_sort=active', false)
            ->assertSee('Download list SF');

        $this->actingAs($admin)->post(route('admin.sales-forces.store'), [
            'name' => 'SF Area Baru',
            'sf_code' => 'SF-AREA-BARU-01',
            'email' => '',
            'password' => 'Docan123!',
            'password_confirmation' => 'Docan123!',
        ])->assertRedirect()->assertSessionHas('credentials');
        $this->assertDatabaseHas('users', [
            'role' => 'sf', 'name' => 'SF Area Baru', 'login_id' => 'SF-AREA-BARU-01', 'sf_code' => 'SF-AREA-BARU-01',
        ]);
        $newSalesForce = User::where('sf_code', 'SF-AREA-BARU-01')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.sales-forces.update', $newSalesForce), [
            'sf_code' => 'KODE-TIDAK-BOLEH-BERUBAH',
            'name' => 'SF Area Baru Diperbarui',
            'login_id' => 'PERCOBAAN-UBAH-LOGIN',
            'email' => 'sf.area.baru@docan.test',
            'password' => 'Password456!',
            'password_confirmation' => 'Password456!',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('users', [
            'id' => $newSalesForce->id,
            'sf_code' => 'SF-AREA-BARU-01',
            'name' => 'SF Area Baru Diperbarui',
            'login_id' => 'SF-AREA-BARU-01',
            'email' => 'sf.area.baru@docan.test',
        ]);
        $this->assertTrue(Hash::check('Password456!', $newSalesForce->fresh()->password));

        $export = $this->actingAs($admin)->get(route('admin.sales-forces.export', ['sf_search' => 'SF-LAMPUNG1-36']));
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('"SF Code / User Login","Nama SF",Email', $csv);
        $this->assertStringContainsString('SF-LAMPUNG1-36', $csv);
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
        $salesForce = User::where('sf_code', 'SF-LAMPUNG1-36')->firstOrFail();
        $outlet->update(['sf_user_id' => $salesForce->id]);
        $outlet->users()->where('role', 'owner')->update(['phone' => '081234567890']);
        $export = $this->actingAs($admin)->get(route('admin.outlets.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        foreach (['ID Outlet', 'Nama Outlet', 'Nomor RS', 'Kabupaten', 'Kecamatan', 'SF Code', 'Nama SF', 'Akun Owner', 'Akun Frontliner', 'Email', 'Tanggal Dibuat', 'Aksi', 'ATS-001', 'Owner Antasari Baru', 'owner.baru@example.test', '081234567890', 'Kota Palembang', 'Ilir Barat I', 'SF-LAMPUNG1-36', 'SF SF-LAMPUNG1-36'] as $value) {
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
