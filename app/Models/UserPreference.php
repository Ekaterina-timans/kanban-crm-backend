<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'current_group_id',
        'current_space_id',
        'timezone'
    ];

    public function user()  
    { 
        return $this->belongsTo(User::class); 
    }

    public function group() 
    { 
        return $this->belongsTo(Group::class, 'current_group_id'); 
    }

    public function space() 
    { 
        return $this->belongsTo(Space::class, 'current_space_id'); 
    }
}
