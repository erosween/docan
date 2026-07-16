<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::create('transactions',function(Blueprint $table){$table->id();$table->foreignId('user_id')->constrained()->cascadeOnDelete();$table->string('customer_number',25);$table->string('provider',50);$table->string('product_type',60);$table->unsignedBigInteger('nominal');$table->unsignedBigInteger('price');$table->timestamps();});}public function down():void{Schema::dropIfExists('transactions');}};
