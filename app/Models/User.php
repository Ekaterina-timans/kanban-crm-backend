<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    /**
     * Группы, в которых состоит пользователь (многие ко многим).
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'user_groups')
                    ->withPivot('role')
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
}
