<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime-editable chatbot settings, keyed by name.
 *
 * Reads fall back to config/services.php so an unconfigured install still
 * honours whatever is in .env during development.
 */
class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const ALLOWED_NUMBERS = 'allowed_numbers';

    public static function get(string $key, $default = null)
    {
        $row = static::find($key);

        return $row && $row->value !== null ? $row->value : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Digits only, local 0-prefix promoted to Indonesia's 62 country code,
     * because Evolution reports senders as bare msisdn (e.g. 6285842041644).
     */
    public static function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/[^0-9]/', '', $number);

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }

    /** Normalized whitelist, or ['*'] when every sender is allowed. */
    public static function allowedNumbers(): array
    {
        $raw = static::get(self::ALLOWED_NUMBERS, config('services.whatsapp.allowed_numbers'));

        if ($raw === null || trim($raw) === '') {
            return ['*'];
        }

        if (trim($raw) === '*') {
            return ['*'];
        }

        return array_values(array_filter(array_map(
            fn ($n) => static::normalizeNumber($n),
            explode(',', $raw)
        )));
    }

    public static function allows(string $number): bool
    {
        $allowed = static::allowedNumbers();

        return in_array('*', $allowed, true)
            || in_array(static::normalizeNumber($number), $allowed, true);
    }
}
