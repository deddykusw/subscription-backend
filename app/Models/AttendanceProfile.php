<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'attendance_username',
    'attendance_password',
    'attendance_token',
    'token_obtained_at',
    'attendance_user_id',
    'id_peg',
    'is_active',
    'tipe',
    'jabatan',
    'kode_unit_kerja',
    'kode_uptd',
    'avatar_url',
    'last_integrity_check_at',
])]
class AttendanceProfile extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'is_active'                => 'boolean',
            'attendance_user_id'       => 'integer',
            'id_peg'                   => 'integer',
            'kode_unit_kerja'          => 'integer',
            'kode_uptd'                => 'integer',
            'token_obtained_at'        => 'datetime',
            'last_integrity_check_at'  => 'datetime',
            'attendance_password'      => 'string',
        ];
    }
}
