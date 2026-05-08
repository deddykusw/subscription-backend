<?php

namespace App\Models;

use App\Enums\ReferralSettingType;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores key-value configuration for the referral system.
 *
 * @property int    $id
 * @property string $key
 * @property string $value
 * @property string|null $description
 * @property ReferralSettingType $type
 */
class ReferralSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReferralSettingType::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns the setting value cast to its native PHP type based on the `type` column.
     *
     * @param  mixed $default  Returned when the setting does not exist.
     */
    public function getValue(mixed $default = null): mixed
    {
        return match($this->type) {
            ReferralSettingType::Boolean    => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            ReferralSettingType::Percentage,
            ReferralSettingType::Amount     => (float) $this->value,
            default                         => $this->value ?? $default,
        };
    }

    /**
     * Persists a new value for this setting record.
     */
    public function setValue(mixed $value): void
    {
        $this->value = (string) $value;
        $this->save();
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves a setting value by key, cast to its native type.
     *
     * @param  string $key
     * @param  mixed  $default  Returned when the key is not found.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->getValue($default) : $default;
    }

    /**
     * Creates or updates a setting value by key.
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set(string $key, mixed $value): void
    {
        $setting = static::firstOrNew(['key' => $key]);
        $setting->value = (string) $value;
        $setting->save();
    }
}
