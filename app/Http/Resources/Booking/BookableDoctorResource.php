<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor as the public booking pages receive it. The name lives on the user, so the caller must load the `user`
 * relation first (strict mode throws on a lazy load). No email, no user id. Call `->resolve()` to get the plain
 * array for an Inertia prop.
 *
 * @mixin Doctor
 */
final class BookableDoctorResource extends JsonResource
{
    /** @return array{id: int, name: string, specialty: string, bio: string|null} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'specialty' => $this->specialty,
            'bio' => $this->bio,
        ];
    }
}
