<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'name',
        'description',
        'background_image',
        'background_color'
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function spaceUsers()
    {
        return $this->hasMany(SpaceUser::class);
    }

    public function columns()
    {
        return $this->hasMany(Column::class);
    }
}
