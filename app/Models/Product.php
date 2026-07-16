<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['outlet_id','operator','category','name','quota_gb','validity_days','sku','cost_price','selling_price','stock','is_active'];
    protected function casts(): array { return ['is_active' => 'boolean', 'quota_gb' => 'float']; }
    public function outlet(): BelongsTo { return $this->belongsTo(Outlet::class); }
    public function cardNumbers(): HasMany { return $this->hasMany(ProductCardNumber::class); }
    public function getProfitAttribute(): int { return $this->selling_price - $this->cost_price; }
}
