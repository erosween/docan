<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $password = Hash::make('Docan123!');

        foreach (config('sf_codes', []) as $sfCode) {
            if (DB::table('users')->where('sf_code', $sfCode)->exists()) {
                continue;
            }

            $loginId = $sfCode;
            if (DB::table('users')->where('login_id', $loginId)->exists()) {
                $loginId = 'SF-'.strtoupper(substr(sha1($sfCode), 0, 12));
            }

            DB::table('users')->insert([
                'outlet_id' => null,
                'name' => 'SF '.$sfCode,
                'email' => 'sf.'.sha1($sfCode).'@sf.docan.local',
                'login_id' => $loginId,
                'password' => $password,
                'role' => 'sf',
                'sf_code' => $sfCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'sf')
            ->where('email', 'like', 'sf.%@sf.docan.local')
            ->whereIn('sf_code', config('sf_codes', []))
            ->delete();
    }
};
