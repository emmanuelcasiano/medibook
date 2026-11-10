<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\Appointment;
use App\Models\VitalsRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The vitals form on the nurse's appointment page. The appointment comes from the URL and the nurse comes from the
 * server. The six fields are the five vital signs (blood pressure is two numbers).
 *
 * These are plausibility checks, not medical judgement: a number outside its range is almost surely a typo, so it is
 * refused with "Check the reading". The app never says that a value is high, low or dangerous. The ranges are
 * constants here and not clinic settings, because they guard against slips of the finger and do not change by clinic.
 */
final class SaveVitalsRequest extends FormRequest
{
    /** Lowest and highest accepted value of each reading, both included. */
    private const array LIMITS = [
        'systolic' => [50, 260],
        'diastolic' => [30, 160],
        'pulse' => [20, 250],
        'temperature' => [30, 45],
        'oxygen_saturation' => [50, 100],
        'weight' => [1, 400],
    ];

    /**
     * Checked here as well as in the controller and the Action, so a user who may not record gets a 403 and not a
     * validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('create', [VitalsRecord::class, $appointment]);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'systolic' => ['required', 'integer', $this->between('systolic')],
            // Lower than the systolic pressure. If the systolic field is empty or wrong, its own error is shown instead.
            'diastolic' => ['required', 'integer', $this->between('diastolic'), 'lt:systolic'],
            'pulse' => ['required', 'integer', $this->between('pulse')],
            'temperature' => ['required', 'numeric', $this->between('temperature'), 'decimal:0,1'],
            'oxygen_saturation' => ['required', 'integer', $this->between('oxygen_saturation')],
            'weight' => ['required', 'numeric', $this->between('weight'), 'decimal:0,1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            // Positional words only: the app never calls a value high or low (those are a clinical judgement).
            'diastolic.lt' => 'Check the reading. The diastolic pressure must be less than the systolic pressure.',
            'temperature.decimal' => 'Use at most one decimal place.',
            'weight.decimal' => 'Use at most one decimal place.',
        ];

        foreach (array_keys(self::LIMITS) as $field) {
            $messages["{$field}.required"] = 'Enter the :attribute.';
            $messages["{$field}.between"] = 'Check the reading.';
            $messages["{$field}.numeric"] = 'Enter a number.';
            $messages["{$field}.integer"] = 'Use a whole number.';
        }

        return $messages;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'systolic' => 'systolic pressure',
            'diastolic' => 'diastolic pressure',
            'pulse' => 'pulse',
            'temperature' => 'temperature',
            'oxygen_saturation' => 'oxygen saturation',
            'weight' => 'weight',
        ];
    }

    /**
     * The validated readings with their real types: the form posts text, and the stored record holds numbers.
     *
     * @return array{systolic: int, diastolic: int, pulse: int, temperature: float, oxygen_saturation: int, weight: float}
     */
    public function readings(): array
    {
        /** @var array<string, int|float|string> $data */
        $data = $this->validated();

        return [
            'systolic' => (int) $data['systolic'],
            'diastolic' => (int) $data['diastolic'],
            'pulse' => (int) $data['pulse'],
            'temperature' => round((float) $data['temperature'], 1),
            'oxygen_saturation' => (int) $data['oxygen_saturation'],
            'weight' => round((float) $data['weight'], 1),
        ];
    }

    private function between(string $field): string
    {
        [$min, $max] = self::LIMITS[$field];

        return "between:{$min},{$max}";
    }
}
