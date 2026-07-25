<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('regency', 80)->nullable()->after('name');
            $table->string('district', 80)->nullable()->after('regency');
            $table->index(['regency', 'district']);
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropIndex(['regency', 'district']);
            $table->dropColumn(['regency', 'district']);
        });
    }
};
