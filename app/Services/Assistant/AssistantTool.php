<?php

namespace App\Services\Assistant;

use App\Models\Masjid;
use App\Models\User;
use Closure;

/**
 * One capability the Masjid Assistant may perform, plus the authorization that
 * governs it. A tool is only ever offered to the model when `isAvailableTo()`
 * passes, and the SAME check is re-run in ToolRegistry::execute() immediately
 * before the handler runs — the model deciding to call a tool is never, on its
 * own, authorization to run it.
 *
 * The handler receives the masjid and user resolved SERVER-SIDE. `masjid_id` is
 * deliberately absent from every input schema: the model cannot name a masjid,
 * so it cannot reach one it shouldn't. That makes cross-tenant action a
 * structural impossibility rather than a rule we remember to enforce.
 */
class AssistantTool
{
    /**
     * @param  string  $name          Tool name exposed to the model.
     * @param  string  $description   When to use it — the model selects on this.
     * @param  array   $inputSchema   JSON Schema for the arguments.
     * @param  Closure $handler       fn(array $input, Masjid $masjid, User $user): array
     * @param  ?string $permission    spatie permission required, if any.
     * @param  ?string $featureKey    masjid mobile-app feature key required, if any.
     * @param  bool    $writes        True if it mutates data (used for the audit trail).
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public Closure $handler,
        public ?string $permission = null,
        public ?string $featureKey = null,
        public bool $writes = false,
    ) {
    }

    /**
     * All three layers, evaluated together:
     *   1. the user is an admin type at all;
     *   2. the masjid has the underlying feature enabled (when the tool needs one);
     *   3. the user holds the spatie permission (when the tool declares one).
     *
     * The per-masjid assistant gate (layer 0) and "may this user act on THIS
     * masjid" are enforced upstream by the `assistant` middleware and
     * ResolveMasjidTenant, so by the time we get here the tenant is already
     * proven — this method covers what varies per tool.
     */
    public function isAvailableTo(User $user, Masjid $masjid): bool
    {
        if (! in_array($user->type, ['SuperAdmin', 'MasjidAdmin'], true)) {
            return false;
        }

        if ($this->featureKey !== null && ! $this->masjidHasFeature($masjid, $this->featureKey)) {
            return false;
        }

        if ($this->permission !== null && ! $user->can($this->permission)) {
            return false;
        }

        return true;
    }

    /** The wire shape the Messages API expects (PHP SDK uses camelCase keys). */
    public function toApiSchema(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }

    private function masjidHasFeature(Masjid $masjid, string $key): bool
    {
        return $masjid->features()
            ->where('key', $key)
            ->exists();
    }
}
