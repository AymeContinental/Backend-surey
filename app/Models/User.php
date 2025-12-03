<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject; // 👈 Importante

class User extends Authenticatable implements JWTSubject // 👈 Implementa la interfaz
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function forms()
    {
        return $this->hasMany(Form::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    //  Métodos requeridos por JWT
    public function getJWTIdentifier()
    {
        return $this->getKey(); // Devuelve el ID del usuario
    }

    public function getJWTCustomClaims()
    {
        return []; // agregar información extra al token
    }
}
