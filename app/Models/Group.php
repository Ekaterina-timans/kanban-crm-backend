<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'creator_id',
        'invite_policy'
    ];

    /**
     * Пользователь — создатель группы (один к одному).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Пользователи, состоящие в группе (многие ко многим).
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_groups')
                    ->withPivot('role', 'status')
                    ->withTimestamps();
    }

    public function canInvite($userId)
    {
        $member = $this->users()->where('user_id', $userId)->first();

        if (!$member) return false;

        // Заблокированные не могут приглашать
        if ($member->pivot->status !== 'active') {
            return false;
        }

        // Только админ
        if ($this->invite_policy === 'admin_only') {
            return $member->pivot->role === 'admin';
        }

        // Все участники
        if ($this->invite_policy === 'all') {
            return true;
        }

        return false;
    }
}