<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['outlet_id', 'name', 'email', 'login_id', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['outlet_id', 'name', 'email', 'login_id', 'password', 'role'];
    protected $hidden = ['password', 'remember_token'];

    public function outlet() { return $this->belongsTo(Outlet::class); }
    public function products() { return $this->hasManyThrough(Product::class, Outlet::class); }
    public function transactions() { return $this->hasMany(Transaction::class); }
    public function isOwner(): bool { return $this->role === 'owner'; }
    public function isFrontliner(): bool { return $this->role === 'frontliner'; }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
