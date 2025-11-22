<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Column extends Model
{
    use HasFactory;
    
    protected $fillable = ['space_id', 'name', 'color', 'position'];

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    // Добавляем виртуальное поле tasks_count
    protected $appends = ['tasks_count'];

    public function getTasksCountAttribute()
    {
        return $this->tasks->count();
    }
}
