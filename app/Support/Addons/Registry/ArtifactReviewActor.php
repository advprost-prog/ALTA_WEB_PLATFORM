<?php

namespace App\Support\Addons\Registry;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Identity of the person/system that performed a quarantine review action.
 *
 * Must be tied to a real authenticated user (UI) or clearly marked as a
 * non-interactive actor such as the CLI. Never pass an arbitrary name without
 * an actor_type/actor_id linkage.
 */
final class ArtifactReviewActor
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly string $createdAt,
    ) {}

    public static function fromUser(Authenticatable $user): self
    {
        /** @var User $user */
        $id = method_exists($user, 'getAuthIdentifier') ? (string) $user->getAuthIdentifier() : null;

        $name = $user->name ?? ($user->email ?? 'user');

        return new self('user', $id, $name, now()->toIso8601String());
    }

    public static function cli(?string $name = null): self
    {
        $resolved = $name ?? (getenv('USER') ?: (getenv('USERNAME') ?: null));

        return new self(
            'cli',
            'cli',
            $resolved !== null ? 'CLI ('.$resolved.')' : 'CLI',
            now()->toIso8601String(),
        );
    }

    public function toHistoryEntry(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
