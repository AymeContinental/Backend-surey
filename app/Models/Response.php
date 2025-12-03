<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Response extends Model
{
    use HasFactory;

    protected $fillable = ['form_id', 'question_id', 'user_id', 'submission_id', 'answer_text'];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function options()
    {
        return $this->belongsToMany(Option::class, 'response_options');
    }
}
