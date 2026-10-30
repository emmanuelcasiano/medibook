<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateWorkingHoursRequest extends FormRequest
{
    /** Seven days with a few ranges each is plenty. The cap stops a huge payload from becoming a huge insert. */
    private const int MAX_RANGES = 70;

    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user()?->can('update', $doctor) ?? false);
    }

    /**
     * `ranges` must be present (an empty list is allowed: no hours on any day), so a request that leaves it out
     * can't wipe the doctor's schedule by accident. The rules that compare rows with each other
     * (end after start, no overlap) are in `after()`.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'ranges' => ['present', 'array', 'max:'.self::MAX_RANGES],
            'ranges.*' => ['array'],
            // 0 = Sunday ... 6 = Saturday, the same numbering as Carbon's dayOfWeek and the slot finder.
            'ranges.*.weekday' => ['required', 'integer', 'between:0,6'],
            // `H:i` is zero-padded 24-hour time, so two valid times compare correctly as plain strings.
            'ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.ends_at' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ranges.*.weekday' => 'weekday',
            'ranges.*.starts_at' => 'start time',
            'ranges.*.ends_at' => 'end time',
        ];
    }

    /**
     * Each range must end after it starts, and two ranges on the same weekday must not overlap. Ranges that
     * only touch (12:00 end, 12:00 start) are fine. An overlap would make the slot finder count the same time
     * twice, so it is stopped here, with the error on the row that is wrong.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ranges = $this->input('ranges');

                if (! is_array($ranges)) {
                    return;
                }

                /** @var list<array{weekday: int, starts_at: string, ends_at: string}> $accepted */
                $accepted = [];

                foreach ($ranges as $key => $range) {
                    $path = "ranges.{$key}";

                    // A row that already failed the rules above has no usable times to compare.
                    if (! is_array($range) || $validator->errors()->hasAny([$path, "{$path}.weekday", "{$path}.starts_at", "{$path}.ends_at"])) {
                        continue;
                    }

                    $weekday = (int) $range['weekday'];
                    $startsAt = (string) $range['starts_at'];
                    $endsAt = (string) $range['ends_at'];

                    if ($endsAt <= $startsAt) {
                        $validator->errors()->add("{$path}.ends_at", 'The end time must be after the start time.');

                        continue;
                    }

                    foreach ($accepted as $other) {
                        if ($other['weekday'] === $weekday && $startsAt < $other['ends_at'] && $other['starts_at'] < $endsAt) {
                            $validator->errors()->add(
                                "{$path}.starts_at",
                                "This overlaps another range on the same day ({$other['starts_at']} to {$other['ends_at']}).",
                            );

                            continue 2;
                        }
                    }

                    $accepted[] = ['weekday' => $weekday, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
                }
            },
        ];
    }
}
