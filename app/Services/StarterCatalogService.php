<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;

class StarterCatalogService
{
    public function apply(Outlet $outlet): int
    {
        $products = json_decode(file_get_contents(database_path('data/voucher_fisik.json')), true);
        $added = 0;

        foreach ($products as $index => $product) {
            preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*GB/i', $product['name'], $quota);
            preg_match('/([0-9]+)\s*(?:D|Hari)/i', $product['name'], $days);
            $attributes = [
                'outlet_id' => $outlet->id,
                'operator' => $product['operator'],
                'category' => 'Voucher Internet',
                'name' => $product['name'],
                'cost_price' => $product['cost_price'],
            ];
            $values = [...$product,
                'category' => 'Voucher Internet',
                'quota_gb' => isset($quota[1]) ? str_replace(',', '.', $quota[1]) : null,
                'validity_days' => $days[1] ?? null,
                'sku' => sprintf('VF-%04d', $index + 1),
                'is_active' => true,
            ];

            if (Product::firstOrCreate($attributes, $values)->wasRecentlyCreated) {
                $added++;
            }
        }

        foreach (['TELKOMSEL', 'BYU', 'INDOSAT', 'XL', 'TRI', 'SMARTFREN', 'AXIS'] as $operator) {
            if (Product::firstOrCreate([
                'outlet_id' => $outlet->id,
                'operator' => $operator,
                'category' => 'Kartu Paket',
                'name' => '3GB · 30D',
                'cost_price' => 0,
            ], [
                'selling_price' => 0,
                'stock' => 0,
                'quota_gb' => 3,
                'validity_days' => 30,
                'sku' => 'KP-'.$operator.'-3-30',
                'is_active' => true,
            ])->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }
}
