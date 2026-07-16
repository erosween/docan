<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('transactions', fn (Blueprint $table) => $table->uuid('request_token')->nullable()->unique()->after('id')); }
    public function down(): void { Schema::table('transactions', function (Blueprint $table) { $table->dropUnique(['request_token']); $table->dropColumn('request_token'); }); }
};
