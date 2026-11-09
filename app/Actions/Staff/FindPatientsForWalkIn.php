<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Collection;

final class FindPatientsForWalkIn
{
    /** Enough to find a person by name at a glance; a longer list would only mean the term is too short. */
    public const int LIMIT = 8;

    /**
     * Active patient accounts whose name or email contains $term, for the walk-in dialog. Only `id`, `name` and `email`
     * are read: the birth date and every health record stay unloaded, so they can't leak into the answer.
     *
     * The term is data, not a pattern: the LIKE wildcards in it are escaped, so "%" finds a literal "%" and not everyone.
     * The caller has already authorized this and checked the minimum length (ShowWalkInPatientsRequest).
     *
     * @return Collection<int, User>
     */
    public function handle(string $term): Collection
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        return User::query()
            ->select(['id', 'name', 'email'])
            ->where('role', Role::Patient)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
