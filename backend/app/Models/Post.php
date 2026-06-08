<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'repost_id', 'title', 'body', 'image_url', 'share_count'])]
class Post extends Model
{
    use HasFactory;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function repost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'repost_id');
    }

    public function reposts(): HasMany
    {
        return $this->hasMany(Post::class, 'repost_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(PostInteraction::class);
    }
}
