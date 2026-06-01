<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Setting stores platform configuration as key-value pairs grouped by category.
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    /**
     * Retrieves a setting value with optional default.
     *
     * @param string $group  Setting group (e.g., 'general', 'smtp', 'branding')
     * @param string $key    Setting key
     * @param mixed  $default  Default value if not found
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting.{$group}.{$key}", 3600, function () use ($group, $key) {
            return static::where('group', $group)->where('key', $key)->first();
        });

        if (!$setting) return $default;

        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float'   => (float) $setting->value,
            'json'    => json_decode($setting->value, true),
            default   => $setting->value,
        };
    }

    /**
     * Sets or creates a setting value and clears the cache.
     */
    public static function set(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'type' => $type]
        );

        Cache::forget("setting.{$group}.{$key}");
    }
}
