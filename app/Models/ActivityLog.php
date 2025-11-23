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

    protected $appends = ['action_group'];

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

    public function getActionGroupAttribute(): string
    {
        $a = $this->action;

        if (in_array($a, [
            'created',
            'checklist_created',
            'checklist_item_created',
            'comment_created',
            'invited',
        ])) {
            return 'created';
        }

        if (
            str_contains($a, 'updated') ||
            in_array($a, [
                'renamed',
                'column_changed',
                'description_updated',
                'priority_updated',
                'due_date_updated',
                'assignee_updated',
                'checklist_updated',
                'checklist_item_updated'
            ])
        ) {
            return 'updated';
        }

        if (in_array($a, [
            'deleted',
            'checklist_deleted',
            'checklist_item_deleted',
            'comment_deleted'
        ])) {
            return 'deleted';
        }

        return 'other';
    }
}
