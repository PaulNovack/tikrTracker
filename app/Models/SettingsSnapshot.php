<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property array $data
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SettingsSnapshot extends Model
{
    protected $fillable = ['name', 'data'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /**
     * Create a snapshot from all current settings.
     */
    public static function createFromCurrent(string $name): self
    {
        $settings = Setting::orderBy('name')->pluck('value', 'name')->all();

        // Normalize boolean values back from '0'/'1' strings for clean storage
        $normalized = [];
        foreach ($settings as $key => $value) {
            $normalized[$key] = $value;
        }

        return self::create([
            'name' => $name,
            'data' => $normalized,
        ]);
    }

    /**
     * Restore this snapshot, overwriting all current settings.
     */
    public function restoreAll(): void
    {
        foreach ($this->data as $name => $value) {
            Setting::set($name, $value);
        }
    }
}
