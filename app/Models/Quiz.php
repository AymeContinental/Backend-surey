<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    protected $table = 'forms';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'code',
        'type',
    ];

    protected static function booted()
    {
        static::addGlobalScope('quiz', function ($builder) {
            $builder->where('type', 'quiz');
        });

        static::creating(function ($quiz) {
            $quiz->type = 'quiz';
        });
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'form_id');
    }
}
