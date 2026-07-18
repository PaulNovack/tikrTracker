<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareerApplication extends Model
{
    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'position_applied',
        'cover_letter',
        'resume_path',
        'linkedin_url',
        'portfolio_url',
        'status',
        'admin_notes',
    ];
}
