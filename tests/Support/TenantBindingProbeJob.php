<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A queued job that records the tenant binding it INHERITED, then binds one of
 * its own — which is precisely the shape of job that leaked before S1
 * (`ImpactMetrics::withTenant()`, `DemoSchoolSeeder`, and anything that resolves
 * a masjid inside `handle()`).
 *
 * Observations are collected in a static so they survive serialization: the
 * worker unserializes a fresh job instance, so instance state would be lost, and
 * a cache/DB round-trip would drag the tenant scope into the assertion itself.
 * Everything runs in one PHP process, so a static is the honest place for it.
 *
 * Used by tests/Feature/QueueTenantContextTest.php.
 */
class TenantBindingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retries: a leak must surface as a failed assertion, not a re-run. */
    public int $tries = 1;

    /**
     * One entry per execution, in order.
     *
     * @var list<array{job: int, inherited: int|null}>
     */
    public static array $observations = [];

    public function __construct(
        private int $label,
        private int $bindsMasjidId,
    ) {
    }

    public function handle(TenantContext $tenant): void
    {
        static::$observations[] = [
            'job' => $this->label,
            'inherited' => $tenant->get(),
        ];

        // Bind, and deliberately never unbind — the point is that the NEXT job
        // must not be able to see this.
        $tenant->set($this->bindsMasjidId);
    }

    public static function forget(): void
    {
        static::$observations = [];
    }

    /** @return list<int|null> what each execution inherited, in order */
    public static function inherited(): array
    {
        return array_map(fn (array $o) => $o['inherited'], static::$observations);
    }
}
