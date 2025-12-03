<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'code',
        'type', // quiz o survey
    ];

    public function questions()
    {
        return $this->hasMany(Question::class, 'form_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'form_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
