<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('login_id', 80)->nullable()->unique()->after('email');
        });
        DB::table('users')->whereIn('role', ['owner', 'outlet'])->orderBy('outlet_id')->orderBy('id')->get()->groupBy('outlet_id')->each(function ($users, $outletId): void {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            if (! $outlet) return;
            foreach ($users->values() as $index => $user) {
                $loginId = $index === 0 ? $outlet->login_id : sprintf('%s-OWN%02d', $outlet->login_id, $index + 1);
                DB::table('users')->where('id', $user->id)->update(['login_id' => $loginId]);
            }
        });
        DB::table('users')->where('role', 'frontliner')->orderBy('outlet_id')->orderBy('id')->get()->groupBy('outlet_id')->each(function ($users, $outletId): void {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            if (! $outlet) return;
            foreach ($users->values() as $index => $user) DB::table('users')->where('id', $user->id)->update(['login_id'=>sprintf('%s-FL%02d', $outlet->login_id, $index + 1)]);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('login_id'));
    }
};
