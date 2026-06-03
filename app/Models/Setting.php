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
        // Cache a plain array (value + type), never the Eloquent model — caching
        // the model can deserialize into a __PHP_Incomplete_Class and break with
        // "access a property on an incomplete object".
        $cached = Cache::remember("setting.{$group}.{$key}", 3600, function () use ($group, $key) {
            $setting = static::where('group', $group)->where('key', $key)->first();
            return $setting ? ['value' => $setting->value, 'type' => $setting->type] : null;
        });

        if (!$cached) return $default;

        return match ($cached['type']) {
            'boolean' => (bool) $cached['value'],
            'integer' => (int) $cached['value'],
            'float'   => (float) $cached['value'],
            'json'    => json_decode($cached['value'], true),
            default   => $cached['value'],
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
