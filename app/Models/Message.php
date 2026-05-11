<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'user_id',
        'sender_is_admin',
        'admin_user_id',
        'body',
        'read_by_user_at',
        'read_by_admin_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    protected function casts(): array
    {
        return [
            'sender_is_admin'  => 'boolean',
            'read_by_user_at'  => 'datetime',
            'read_by_admin_at' => 'datetime',
        ];
    }
}
