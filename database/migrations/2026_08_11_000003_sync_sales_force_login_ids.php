<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'sf')
            ->whereNotNull('sf_code')
            ->orderBy('id')
            ->each(function ($salesForce): void {
                $loginIsUsed = DB::table('users')
                    ->where('login_id', $salesForce->sf_code)
                    ->where('id', '!=', $salesForce->id)
                    ->exists();

                if (! $loginIsUsed) {
                    DB::table('users')->where('id', $salesForce->id)->update([
                        'login_id' => $salesForce->sf_code,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // SF Code tetap dipertahankan sebagai User Login agar akun tidak terkunci.
    }
};
