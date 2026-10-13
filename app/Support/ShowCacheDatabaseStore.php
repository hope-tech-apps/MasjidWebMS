<?php

namespace App\Support;

use Illuminate\Cache\DatabaseStore;

/** Reuse Laravel's driver-specific serialization instead of guessing its binary encoding. */
final class ShowCacheDatabaseStore extends DatabaseStore
{
    public function publish(string $key, mixed $expected, mixed $value, int $seconds): bool
    {
        $key=$this->prefix.$key;
        $value=$this->serialize($value);
        $expiration=$this->getTime()+$seconds;
        if ($expected === null) return $this->table()->insertOrIgnore(compact('key','value','expiration')) > 0;
        return $this->table()->where('key',$key)->where('value',$this->serialize($expected))
            ->update(compact('value','expiration')) > 0;
    }
}
