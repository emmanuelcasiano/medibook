<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Models\VisitNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The doctor's own note as the appointment page receives it. The text is health data: build this only after
 * `VisitNotePolicy::view` has passed. The keys are listed one by one, so no other column reaches the browser. The
 * page shows the text as plain text only. Call `->resolve()` for the Inertia prop.
 *
 * @mixin VisitNote
 */
final class VisitNoteResource extends JsonResource
{
    /**
     * @return array{body: string, updatedAt: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'body' => $this->body,
            'updatedAt' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
