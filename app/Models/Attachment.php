<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'question_id',
        'file_path',
        'file_type',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
