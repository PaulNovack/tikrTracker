<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['name', 'value'];

    /**
     * Get a setting value by name
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $setting = self::where('name', $name)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by name
     */
    public static function set(string $name, mixed $value): void
    {
        self::updateOrCreate(
            ['name' => $name],
            ['value' => $value]
        );
    }
}
