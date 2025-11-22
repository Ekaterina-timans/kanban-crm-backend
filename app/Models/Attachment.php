<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use HasFactory;
    protected $fillable = [
        'uploaded_by','disk','path','original_name','mime','size','sha256','meta'
    ];
    protected $casts = ['meta' => 'array'];
    protected $appends = ['url'];

    public function attachable(){ return $this->morphTo(); }
    public function uploader()  { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) return null;
        return asset('storage/'.$this->path);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('attachments.download', ['attachment' => $this->id], false); 
    }
}
