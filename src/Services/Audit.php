<?php

declare(strict_types=1);

namespace ServerCreator\Services;

use Pterodactyl\Models\User;
use ServerCreator\Models\AuditEntry;

final class Audit
{
    /** @param array<string, mixed> $details */
    public function record(User $actor, string $action, string $target, array $details = []): void
    {
        AuditEntry::query()->create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->username,
            'action' => $action,
            'target' => mb_substr($target, 0, 191),
            'details' => $details,
        ]);
    }

    /** @return array{entries: array<int, array<string, mixed>>, next_before: int|null} */
    public function page(?int $before = null): array
    {
        $entries = AuditEntry::query()->when($before !== null, static fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(51)->get();
        $page = $entries->take(50)->values();

        return [
            'entries' => $page->map(static fn (AuditEntry $entry): array => [
                'id' => $entry->id, 'actor_id' => $entry->actor_id, 'actor_name' => $entry->actor_name,
                'action' => $entry->action, 'target' => $entry->target, 'details' => $entry->details,
                'created_at' => $entry->created_at->toAtomString(),
            ])->all(),
            'next_before' => $entries->count() > 50 ? $page->last()->id : null,
        ];
    }
}
