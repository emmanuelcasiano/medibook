<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ListAuditLogsRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /** Who may open the audit log is decided by the `role:admin` group and the Policy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The filters, all optional and read from the query string. An unknown actor, action or subject is not an error:
     * it just matches no rows. The shape is checked here, so only a plain id, a dotted action name and a class name
     * reach the query, and each one is a bound value there.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:64', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            // An id means nothing without the kind of record it belongs to.
            'subject_type' => ['nullable', 'required_with:subject_id', 'string', 'max:64', 'regex:/^[A-Za-z]+$/'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'string', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject_type' => 'subject type',
            'subject_id' => 'subject id',
        ];
    }

    /** The actor's user id, or null. Call it only after validation passed. */
    public function actorId(): ?int
    {
        return $this->filled('actor') ? $this->integer('actor') : null;
    }

    /** The action name (`intake.viewed`), or null. Call it only after validation passed. */
    public function action(): ?string
    {
        return $this->filled('action') ? $this->string('action')->toString() : null;
    }

    /** The short class name of the subject (`Appointment`), or null. Call it only after validation passed. */
    public function subjectType(): ?string
    {
        return $this->filled('subject_type') ? $this->string('subject_type')->toString() : null;
    }

    /** The subject's id, or null. Call it only after validation passed. */
    public function subjectId(): ?int
    {
        return $this->filled('subject_id') ? $this->integer('subject_id') : null;
    }

    /** The chosen clinic day at 00:00 clinic time, or null for no date filter. Call it only after validation passed. */
    public function day(): ?CarbonImmutable
    {
        if (! $this->filled('date')) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            $this->string('date')->toString(),
            (string) config('app.clinic_timezone'),
        );
    }

    /**
     * What the filter form shows again after a search, as strings (an empty string for "not set"). Call it only
     * after validation passed.
     *
     * @return array{actor: string, action: string, subject_type: string, subject_id: string, date: string}
     */
    public function filters(): array
    {
        return [
            'actor' => (string) $this->actorId(),
            'action' => (string) $this->action(),
            'subject_type' => (string) $this->subjectType(),
            'subject_id' => (string) $this->subjectId(),
            'date' => (string) $this->day()?->format(self::DATE_FORMAT),
        ];
    }
}
