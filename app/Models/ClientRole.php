<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-defined team role.
 *
 * Built-in roles live in config/client_permissions.php and never appear here;
 * this model only covers the roles a client creates for itself, so every row is
 * scoped by `client_id`.
 */
class ClientRole extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'slug',
        'description',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
