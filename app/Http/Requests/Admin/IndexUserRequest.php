<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUserRequest extends FormRequest
{
    private const string ACTIVE = 'active';

    private const string INACTIVE = 'inactive';

    /** Who may open the users list is decided by the `role:admin` group and the Policy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The filters, all optional and read from the query string. An unknown role or status is an error, not an empty
     * list: the page only offers the real ones, so a made-up value means a hand-edited address. The search term is
     * capped, so a huge string never reaches the query.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::in([self::ACTIVE, self::INACTIVE])],
        ];
    }

    /** The text to look for in a name or an email, or null. Call it only after validation passed. */
    public function search(): ?string
    {
        return $this->filled('q') ? $this->string('q')->toString() : null;
    }

    /** The role to keep, or null for every role. Call it only after validation passed. */
    public function role(): ?Role
    {
        return $this->filled('role') ? Role::from($this->string('role')->toString()) : null;
    }

    /** True for active accounts, false for deactivated ones, null for both. Call it only after validation passed. */
    public function active(): ?bool
    {
        return $this->filled('status') ? $this->string('status')->toString() === self::ACTIVE : null;
    }

    /**
     * What the filter form shows again after a search, as strings (an empty string for "not set"). Call it only
     * after validation passed.
     *
     * @return array{q: string, role: string, status: string}
     */
    public function filters(): array
    {
        return [
            'q' => (string) $this->search(),
            'role' => (string) $this->role()?->value,
            'status' => $this->string('status')->toString(),
        ];
    }
}
