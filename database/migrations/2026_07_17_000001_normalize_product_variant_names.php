<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->select('outlet_id', 'operator', 'category', 'quota_gb', 'validity_days')
            ->whereNotNull('quota_gb')
            ->whereNotNull('validity_days')
            ->groupBy('outlet_id', 'operator', 'category', 'quota_gb', 'validity_days')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('outlet_id')
            ->get()
            ->each(function ($group): void {
                $variants = DB::table('products')
                    ->where('outlet_id', $group->outlet_id)
                    ->where('operator', $group->operator)
                    ->where('category', $group->category)
                    ->where('quota_gb', $group->quota_gb)
                    ->where('validity_days', $group->validity_days)
                    ->orderBy('id')
                    ->get(['id', 'name']);

                $sourceName = $variants->first()?->name;
                if ($sourceName) {
                    DB::table('products')->whereIn('id', $variants->pluck('id'))->update(['name'=>$sourceName]);
                }
            });
    }

    public function down(): void
    {
        // Nama lama yang sudah tidak konsisten tidak dapat direkonstruksi dengan aman.
    }
};
