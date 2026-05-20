<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = ['title', 'content', 'user_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->latest();
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    public function isAccessibleBy($user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->shares()->where('user_id', $user->id)->exists();
    }

    public function canEdit($user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->shares()
            ->where('user_id', $user->id)
            ->where('permission', 'editor')
            ->exists();
    }
}