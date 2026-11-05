<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that hides personal and health data in what is written to the log (security.md section 8).
 *
 * It only sees the structured `context` and `extra` of a record. It can't clean free text inside the message or an
 * exception message, so the rule at the call site stays: log IDs, never content.
 */
final class LogMasker implements ProcessorInterface
{
    public const string HIDDEN = '[hidden]';

    /** @var list<string> Lower case. A key matches whatever its case. */
    private const array SENSITIVE_KEYS = [
        'answers',
        'symptoms',
        'notes',
        'email',
        'phone',
        'password',
        'password_confirmation',
        'current_password',
        'birth_date',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->mask($record->context),
            extra: $this->mask($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function mask(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $masked[$key] = self::HIDDEN;

                continue;
            }

            $masked[$key] = match (true) {
                is_array($value) => $this->mask($value),
                // A model in the context is written out as JSON with its casts applied, so an `encrypted` column would
                // appear in clear text. Keep the class and the key only.
                $value instanceof Model => $value::class.'#'.$value->getKey(),
                default => $value,
            };
        }

        return $masked;
    }
}
