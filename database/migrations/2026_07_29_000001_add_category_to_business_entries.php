<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_entries', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('business_categories')
                ->nullOnDelete();
            $table->index(['outlet_id', 'category_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::table('business_entries', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'category_id', 'entry_date']);
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
