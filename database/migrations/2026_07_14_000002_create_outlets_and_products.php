<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('operator', 40);
            $table->string('category', 60)->default('Voucher Fisik');
            $table->string('name');
            $table->string('sku', 80)->nullable();
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['outlet_id', 'operator', 'category']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('cost_price')->default(0)->after('price');
            $table->unsignedBigInteger('profit')->default(0)->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['cost_price', 'profit']);
        });
        Schema::dropIfExists('products');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('outlet_id'));
        Schema::dropIfExists('outlets');
    }
};
