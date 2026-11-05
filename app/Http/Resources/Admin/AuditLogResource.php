<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit log row as the admin screen receives it. The keys are listed one by one, so no other column reaches the
 * browser: the `ip_hash` stays on the server. `details` is the safe metadata as plain text lines. Build it only
 * after `AuditLogPolicy::viewAny` has passed, and with `actor` eager loaded. Call `->resolve()` for the Inertia prop.
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array{id: int, createdAt: string, actorName: string|null, actorRoleLabel: string|null, action: string, subject: string|null, details: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->created_at->toIso8601ZuluString(),
            // Null for a guest or the system (a queue job, a command).
            'actorName' => $this->actor?->name,
            'actorRoleLabel' => $this->actor?->role->label(),
            'action' => $this->action,
            'subject' => $this->subject_type === null ? null : class_basename($this->subject_type).' #'.$this->subject_id,
            'details' => $this->details(),
        ];
    }

    /** @return list<string> */
    private function details(): array
    {
        $lines = [];

        foreach ($this->metadata ?? [] as $key => $value) {
            $lines[] = str_replace('_', ' ', (string) $key).': '.$this->formatValue($value);
        }

        return $lines;
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
