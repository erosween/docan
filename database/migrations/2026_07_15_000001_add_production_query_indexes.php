<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('created_at', 'transactions_created_at_index');
            $table->index(['user_id', 'created_at'], 'transactions_user_created_index');
            $table->index(['provider', 'product_type', 'created_at'], 'transactions_provider_type_created_index');
            $table->index(['product_id', 'created_at'], 'transactions_product_created_index');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->index(['outlet_id', 'is_active', 'selling_price'], 'products_outlet_active_price_index');
            $table->index(['outlet_id', 'operator', 'category', 'is_active'], 'products_catalog_filter_index');
        });
        Schema::table('users', fn (Blueprint $table) => $table->index(['outlet_id', 'role'], 'users_outlet_role_index'));
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_created_at_index');
            $table->dropIndex('transactions_user_created_index');
            $table->dropIndex('transactions_provider_type_created_index');
            $table->dropIndex('transactions_product_created_index');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_outlet_active_price_index');
            $table->dropIndex('products_catalog_filter_index');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_outlet_role_index'));
    }
};
