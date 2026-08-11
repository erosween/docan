<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sf_code', 40)->nullable()->unique()->after('role');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->foreignId('sf_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('district')->index();
            $table->timestamp('approved_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sf_user_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'approved_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sf_code'));
    }
};
