<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Survey extends Model
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
        static::addGlobalScope('survey', function ($builder) {
            $builder->where('type', 'survey');
        });

        static::creating(function ($survey) {
            $survey->type = 'survey';
        });
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'form_id');
    }
}
