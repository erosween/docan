<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outlet extends Model
{
    protected $fillable = ['name', 'code', 'login_id'];
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
}
