<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service as the public booking pages receive it. These are the only fields a visitor gets: no id, no buffer,
 * no switched-on flag. Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * @mixin Service
 */
final class BookableServiceResource extends JsonResource
{
    /** @return array{slug: string, name: string, description: string|null, durationMinutes: int} */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'durationMinutes' => $this->duration_minutes,
        ];
    }
}
