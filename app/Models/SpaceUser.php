<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpaceUser extends Model
{
    use HasFactory;
    protected $table = 'space_user';
    protected $fillable = ['space_id', 'user_id', 'role'];

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'space_user_permissions', 'space_user_id', 'permission_id')
            ->withTimestamps();
    }

    /**
     * Проверка права для данного пользователя в пространстве
     */
    public function can(string $permissionName): bool
    {
        // Владельцу разрешено всё
        if ($this->role === 'owner') {
            return true;
        }

        // Проверяем наличие конкретного права
        return $this->permissions->contains('name', $permissionName);
    }
}
