<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role', 30)->default('outlet')->after('password'));
        Schema::create('denominations', function (Blueprint $table) {
            $table->id();
            $table->string('operator', 40);
            $table->string('category', 60);
            $table->unsignedBigInteger('nominal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['operator','category','nominal']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unique(['outlet_id','operator','category','quota_gb','validity_days'], 'products_outlet_package_unique');
        });
    }
    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropUnique('products_outlet_package_unique'));
        Schema::dropIfExists('denominations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
