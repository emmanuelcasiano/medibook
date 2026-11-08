<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use Carbon\CarbonImmutable;

/**
 * What the doctor typed when they asked a patient to come back. Plain values only: the patient, the doctor and the
 * status are not here, because RequestFollowUp takes them from the finished visit.
 */
final readonly class FollowUpData
{
    /**
     * @param  CarbonImmutable  $startsOn  Clinic-local day (Asia/Manila) at 00:00.
     * @param  CarbonImmutable  $endsOn  Clinic-local day (Asia/Manila) at 00:00.
     * @param  string|null  $note  Health text. Never log it.
     */
    public function __construct(
        public int $serviceId,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public bool $sameDoctor,
        public ?string $note,
    ) {}
}
