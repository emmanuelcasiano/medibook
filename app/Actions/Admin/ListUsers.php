<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListUsers
{
    public const int PER_PAGE = 15;

    /**
     * One page of users, by name. Every filter is optional and they combine with AND.
     *
     * - `$term` matches a part of the name or of the email. It is data, not a pattern: the LIKE wildcards in it are
     *   escaped, so "%" finds a literal "%" and not everyone. It is a bound value, and it is never logged.
     * - `$active` is true for active accounts, false for deactivated ones, and null for both.
     *
     * A `LIKE '%term%'` can't use an index. That is fine for a clinic's user count, and the list stays paginated.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function handle(?string $term = null, ?Role $role = null, ?bool $active = null): LengthAwarePaginator
    {
        $query = User::query();

        if ($term !== null) {
            $pattern = '%'.addcslashes($term, '\\%_').'%';

            // Grouped, so the OR can't escape the other filters.
            $query->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern));
        }

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        // `id` breaks ties between users with the same name, so a page never repeats or skips a row.
        return $query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE);
    }
}
