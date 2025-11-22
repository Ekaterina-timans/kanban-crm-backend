<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'group_id',
        'user_id',
        'entity_type',
        'entity_id',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    /** Кто совершил действие */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** К какой группе относится действие */
    public function group()
    {
        return $this->belongsTo(Group::class);
    }
}
