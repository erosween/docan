<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('transactions',function(Blueprint $table){$table->unsignedInteger('quantity')->default(1)->after('product_type');$table->text('card_numbers')->nullable()->after('quantity');});}
    public function down():void {Schema::table('transactions',fn(Blueprint $table)=>$table->dropColumn(['quantity','card_numbers']));}
};
