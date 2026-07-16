<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Denomination;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $outlet = Outlet::updateOrCreate(['code' => 'SDM-001'], ['login_id'=>'SDM-001','name' => 'Outlet Sudirman']);
        User::updateOrCreate(['email' => 'kasir@outlet.test'], [
            'outlet_id' => $outlet->id,
            'name' => 'Rani — Outlet Sudirman',
            'password' => bcrypt('password'),
        ]);
        User::updateOrCreate(['email' => 'admin@docan.test'], [
            'outlet_id' => null, 'name' => 'Super Admin', 'role' => 'super_admin', 'password' => bcrypt('password'),
        ]);

        foreach (['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS'] as $operator) {
            foreach (['Pulsa Reguler','Pulsa Data'] as $category) {
                foreach ([5000,10000,20000,25000,50000,100000] as $nominal) {
                    Denomination::firstOrCreate(compact('operator','category','nominal'));
                }
            }
        }
        foreach (['DANA','OVO','GOPAY','SHOPEEPAY'] as $operator) {
            $category = 'Saldo E-Wallet';
            foreach ([10000,20000,25000,50000,100000,200000,500000,1000000] as $nominal) {
                Denomination::firstOrCreate(compact('operator','category','nominal'));
            }
        }
        $operator = 'PLN'; $category = 'Token PLN';
        foreach ([20000,50000,100000,200000,500000,1000000] as $nominal) {
            Denomination::firstOrCreate(compact('operator','category','nominal'));
        }
        foreach (['Transfer','Tarik Tunai','Setor Tunai'] as $category) {
            $operator='BRILINK'; foreach ([50000,100000,200000,500000,1000000,2000000] as $nominal) Denomination::firstOrCreate(compact('operator','category','nominal'));
        }
        foreach (['BPJS Kesehatan','PDAM','Internet & TV','Pascabayar','Pajak & PBB'] as $category) {
            $operator='PPOB'; foreach ([50000,100000,150000,200000,300000,500000] as $nominal) Denomination::firstOrCreate(compact('operator','category','nominal'));
        }

        $products = json_decode(file_get_contents(database_path('data/voucher_fisik.json')), true);
        foreach ($products as $index => $product) {
            preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*GB/i', $product['name'], $quota);
            preg_match('/([0-9]+)\s*(?:D|Hari)/i', $product['name'], $days);
            $product['category'] = 'Voucher Internet';
            Product::updateOrCreate([
                'outlet_id' => $outlet->id,
                'operator' => $product['operator'],
                'category' => $product['category'],
                'name' => $product['name'],
            ], [...$product, 'quota_gb' => isset($quota[1]) ? str_replace(',', '.', $quota[1]) : null,
                'validity_days' => $days[1] ?? null, 'sku' => sprintf('VF-%04d', $index + 1), 'is_active' => true]);
        }
        foreach (['TELKOMSEL','BYU','XL','TRI','INDOSAT','SMARTFREN','AXIS'] as $operator) {
            Product::firstOrCreate(['outlet_id'=>$outlet->id,'operator'=>$operator,'category'=>'Kartu Paket','quota_gb'=>3,'validity_days'=>30,'cost_price'=>0],['name'=>'3GB · 30 Hari','selling_price'=>0,'stock'=>0,'is_active'=>true]);
        }
    }
}
