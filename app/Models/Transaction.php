<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Transaction extends Model {
    protected $fillable=['request_token','user_id','product_id','customer_number','provider','product_type','quantity','card_numbers','nominal','admin_fee','price','cost_price','profit'];
    protected function casts():array{return ['card_numbers'=>'array'];}
    public function product() { return $this->belongsTo(Product::class); }
    public function user() { return $this->belongsTo(User::class); }
}
