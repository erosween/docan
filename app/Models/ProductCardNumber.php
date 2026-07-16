<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCardNumber extends Model {
    protected $fillable=['product_id','card_number','transaction_id','sold_at'];
    protected function casts():array{return ['sold_at'=>'datetime'];}
    public function product(){return $this->belongsTo(Product::class);}
    public function transaction(){return $this->belongsTo(Transaction::class);}
}
