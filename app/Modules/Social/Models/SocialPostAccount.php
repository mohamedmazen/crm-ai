<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPostAccount extends Model
{
    protected $table = 'social_media_post_accounts';

    protected $fillable = ['workspace_id', 'post_id', 'social_account_id', 'status', 'platform_post_id', 'error', 'published_at'];

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            if ($link->workspace_id !== null) {
                return;
            }

            $link->workspace_id = SocialPost::whereKey($link->post_id)->value('workspace_id');
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function post()
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function account()
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
