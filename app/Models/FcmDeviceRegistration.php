<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'fcm_token_hash',
    'fcm_token',
    'platform',
    'app_version',
    'last_seen_at',
])]
class FcmDeviceRegistration extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = [
        'fcm_token',
        'fcm_token_hash',
    ];
}
