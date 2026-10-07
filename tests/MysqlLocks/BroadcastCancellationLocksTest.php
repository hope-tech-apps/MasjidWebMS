<?php

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\BroadcastCancellation;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\ChannelResult;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Committed fixtures: RefreshDatabase would hide them from the competing connection.
// tests/Pest.php pins mysql group, mysql engine and a throwaway *_test database.
beforeEach(function () {
    Carbon::setTestNow('2026-10-06 12:00:00');
    $this->actor = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Test Admin', 'phone' => '+15555550102']);
    $suffix = Str::uuid()->toString();
    $this->organisation = Masjid::create([
        'name' => 'Test Organisation '.$suffix, 'email' => $suffix.'@example.invalid',
        'phone' => $suffix, 'country_id' => '1', 'city_id' => '1',
        'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0,
        'user_id' => $this->actor->id,
    ]);
    app(TenantContext::class)->set($this->organisation->id);
    $this->broadcast = app(BroadcastComposer::class)->compose($this->organisation, [
        'title' => 'Test notice', 'body' => 'Test body', 'scheduled_at' => now()->addHour(),
    ], [BroadcastChannel::SIGNAGE]);
    $this->primary = DB::getDefaultConnection();
    config(['database.connections.broadcast_competitor' => config('database.connections.'.$this->primary)]);
    $this->other = DB::connection('broadcast_competitor');
    $this->other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $this->seen = (object) ['drivers' => 0, 'blocked' => false, 'refusal' => null];
    $seen = $this->seen;
    $actorId = $this->actor->id;
    $this->app->bind(BroadcastDispatcher::DRIVERS['signage'], fn () => new class($seen, $actorId) implements BroadcastChannelDriver {
        public function __construct(private object $seen, private int $actorId) {}
        public function channel(): BroadcastChannel { return BroadcastChannel::SIGNAGE; }
        public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
        {
            $this->seen->drivers++;
            $this->seen->transactionLevel = DB::transactionLevel();
            $this->seen->refusal = broadcastOnCompetitor(fn () => app(BroadcastCancellation::class)->cancel($broadcast->id, $this->actorId));
            return ChannelResult::sent(1);
        }
    });
});

afterEach(function () {
    Carbon::setTestNow();
    if (! isset($this->organisation)) return;
    DB::setDefaultConnection($this->primary);
    while ($this->other->transactionLevel() > 0) $this->other->rollBack();
    while (DB::transactionLevel() > 0) DB::rollBack();
    DB::purge('broadcast_competitor');
    DB::table('broadcast_deliveries')->where('broadcast_id', $this->broadcast->id)->delete();
    DB::table('broadcasts')->where('id', $this->broadcast->id)->delete();
    DB::table('masjids')->where('id', $this->organisation->id)->delete();
    DB::table('users')->where('id', $this->actor->id)->delete();
    app(TenantContext::class)->forgetTenant();
});

function broadcastOnCompetitor(Closure $operation): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('broadcast_competitor');
    try {
        return $operation();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function broadcastLockBlocks(Closure $operation): bool
{
    try {
        broadcastOnCompetitor($operation);
    } catch (QueryException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1205) return true;
        throw $exception;
    }
    return false;
}

it('makes the competing send wait for cancellation and then refuses the stale job row', function () {
    $stale = $this->broadcast;
    $seen = $this->seen;
    Broadcast::saving(function (Broadcast $row) use ($stale, $seen) {
        if ($row->id === $stale->id && $row->status === 'cancelled' && DB::getDefaultConnection() !== 'broadcast_competitor') {
            $seen->blocked = broadcastLockBlocks(fn () => app(BroadcastDispatcher::class)->dispatch($stale));
        }
    });
    $result = app(BroadcastCancellation::class)->cancel($stale->id, $this->actor->id);
    broadcastOnCompetitor(fn () => app(BroadcastDispatcher::class)->dispatch($stale));
    expect($result['cancelled'])->toBeTrue()
        ->and($seen->blocked)->toBeTrue()->and($seen->drivers)->toBe(0)
        ->and($stale->fresh()->status)->toBe('cancelled');
});

it('makes cancellation wait for the send claim and refuses it before the first channel runs', function () {
    $seen = $this->seen;
    $actorId = $this->actor->id;
    $broadcastId = $this->broadcast->id;
    Broadcast::saving(function (Broadcast $row) use ($seen, $actorId, $broadcastId) {
        if ($row->id === $broadcastId && $row->status === 'sending' && DB::getDefaultConnection() !== 'broadcast_competitor') {
            $seen->blocked = broadcastLockBlocks(fn () => app(BroadcastCancellation::class)->cancel($broadcastId, $actorId));
        }
    });
    app(BroadcastDispatcher::class)->dispatch($this->broadcast);
    expect($seen->blocked)->toBeTrue()->and($seen->drivers)->toBe(1)
        ->and($seen->transactionLevel)->toBe(0)
        ->and($seen->refusal['cancelled'])->toBeFalse()
        ->and($seen->refusal['broadcast']->status)->toBe('sending')
        ->and($this->broadcast->fresh()->status)->toBe('sent')
        ->and($this->broadcast->fresh()->cancelled_at)->toBeNull();
});

it('decides from the locking read even when an older repeatable-read snapshot says scheduled', function () {
    $this->other->beginTransaction();
    $snapshot = $this->other->table('broadcasts')->where('id', $this->broadcast->id)->first();
    expect($snapshot->status)->toBe('scheduled');
    app(BroadcastCancellation::class)->cancel($this->broadcast->id, $this->actor->id);
    // Ordinary SELECT still sees the stale snapshot. FOR UPDATE must see the committed cancel.
    expect($this->other->table('broadcasts')->where('id', $this->broadcast->id)->value('status'))->toBe('scheduled');
    $result = broadcastOnCompetitor(fn () => app(BroadcastCancellation::class)->cancel($this->broadcast->id, $this->actor->id));
    broadcastOnCompetitor(fn () => app(BroadcastDispatcher::class)->dispatch($this->broadcast));
    expect($result['cancelled'])->toBeFalse()->and($result['broadcast']->status)->toBe('cancelled')
        ->and($this->seen->drivers)->toBe(0);
    $this->other->rollBack();
});
