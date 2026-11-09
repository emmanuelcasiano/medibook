<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search result in the walk-in dialog: the id to book with, the name and the email to tell two people with the same
 * name apart. Never the birth date or anything about their visits. Call `->resolve()` to get the plain array.
 *
 * @mixin User
 */
final class WalkInPatientResource extends JsonResource
{
    /** @return array{id: int, name: string, email: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
