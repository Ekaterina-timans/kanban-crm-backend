<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'access_level',
        'account_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function isSystemAdmin(): bool
    {
        return $this->access_level === 'admin';
    }

    public function isAccountBlocked(): bool
    {
        return $this->account_status === 'blocked';
    }

    /**
     * Группы, в которых состоит пользователь (многие ко многим).
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'user_groups')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Группы, которые создал пользователь (один ко многим).
     */
    public function createdGroups()
    {
        return $this->hasMany(Group::class, 'creator_id');
    }

    public function preference()
    {
        return $this->hasOne(UserPreference::class);
    }

    public function spaces()
    {
        return $this->belongsToMany(Space::class, 'space_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function spaceUsers()
    {
        return $this->hasMany(SpaceUser::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }
}
