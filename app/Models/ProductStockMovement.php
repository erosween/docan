<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStockMovement extends Model
{
    protected $fillable = [
        'outlet_id', 'product_id', 'user_id', 'transaction_id', 'type',
        'quantity', 'stock_before', 'stock_after', 'product_name',
        'operator', 'category', 'note',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function transaction() { return $this->belongsTo(Transaction::class); }
}
