<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->select('id', 'name')->orderBy('id')->chunkById(500, function ($products): void {
            foreach ($products as $product) {
                $normalized = preg_replace('/\b(\d+)\s*Hari\b/ui', '$1D', $product->name);

                if ($normalized !== $product->name) {
                    DB::table('products')->where('id', $product->id)->update(['name' => $normalized]);
                }
            }
        });
    }

    public function down(): void
    {
        // Label-only normalization is intentionally not reversed.
    }
};
