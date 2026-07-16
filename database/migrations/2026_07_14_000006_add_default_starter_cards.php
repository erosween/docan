<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up():void {foreach(DB::table('outlets')->pluck('id') as $outletId){foreach(['TELKOMSEL','XL','TRI','INDOSAT','SMARTFREN','AXIS'] as $operator){DB::table('products')->insertOrIgnore(['outlet_id'=>$outletId,'operator'=>$operator,'category'=>'Kartu Paket','name'=>'3GB · 30 Hari','quota_gb'=>3,'validity_days'=>30,'cost_price'=>0,'selling_price'=>0,'stock'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);}}}
    public function down():void {DB::table('products')->where('category','Kartu Paket')->where('quota_gb',3)->where('validity_days',30)->where('cost_price',0)->where('selling_price',0)->delete();}
};
