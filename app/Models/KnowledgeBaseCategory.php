<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeBaseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['group_id', 'title', 'description', 'sort_order'];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function articles()
    {
        return $this->hasMany(KnowledgeBaseArticle::class, 'category_id');
    }
}
