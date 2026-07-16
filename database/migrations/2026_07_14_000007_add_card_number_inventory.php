<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('product_card_numbers',function(Blueprint $table){$table->id();$table->foreignId('product_id')->constrained()->cascadeOnDelete();$table->string('card_number',30)->unique();$table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();$table->timestamp('sold_at')->nullable();$table->timestamps();$table->index(['product_id','sold_at']);});
        foreach(DB::table('outlets')->pluck('id') as $outletId){DB::table('products')->insertOrIgnore(['outlet_id'=>$outletId,'operator'=>'BYU','category'=>'Kartu Paket','name'=>'3GB · 30 Hari','quota_gb'=>3,'validity_days'=>30,'cost_price'=>0,'selling_price'=>0,'stock'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);}
    }
    public function down():void {Schema::dropIfExists('product_card_numbers');DB::table('products')->where('operator','BYU')->where('category','Kartu Paket')->where('quota_gb',3)->where('validity_days',30)->where('cost_price',0)->where('selling_price',0)->delete();}
};
