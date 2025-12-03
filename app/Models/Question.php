<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'form_id',
        'type',
        'text',
        'required',
        'placeholder',
        'min_length',
        'max_length',
        'min_selection',
        'max_selection',
        'allowed_file_types',
        'scale_min',
        'scale_max',
        'scale_label_min',
        'scale_label_max',
        'total_score',
        'answer',
    ];

    public function options()
    {
        return $this->hasMany(Option::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class, 'form_id');
    }
}
