<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quota_gb', 6, 1)->nullable()->after('name');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('quota_gb');
        });
        DB::table('products')->where('category', 'Voucher Fisik')->update(['category' => 'Voucher Internet']);

        foreach (DB::table('products')->select('id','name')->cursor() as $product) {
            preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*GB/i', $product->name, $quota);
            preg_match('/(?:·\s*)?([0-9]+)\s*(?:D|Hari)/i', $product->name, $days);
            DB::table('products')->where('id', $product->id)->update([
                'quota_gb' => isset($quota[1]) ? str_replace(',', '.', $quota[1]) : null,
                'validity_days' => $days[1] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('products')->where('category', 'Voucher Internet')->update(['category' => 'Voucher Fisik']);
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['quota_gb','validity_days']));
    }
};
