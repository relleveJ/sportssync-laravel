<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchState extends Model
{
    protected $table = 'match_states';
    protected $fillable = [
        'match_id', 'payload', 'last_user_id', 'last_role'
    ];
    protected $casts = [
        'payload' => 'array'
    ];
}
