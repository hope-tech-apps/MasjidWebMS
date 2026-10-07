<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnauthenticatedRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['app.debug' => false]);
    }

    public static function realms(): array
    {
        $cases = [];
        foreach ([
            'admin' => ['GET', '/api/admin/user', false],
            'teacher' => ['GET', '/api/teacher/user', false],
            'family' => ['GET', '/api/family/masjids/1/me', false],
            'member' => ['GET', '/api/mobile/masjids/1/interests', false],
            'member account' => ['DELETE', '/api/mobile/masjids/1/me', true],
            'lunch staff' => ['GET', '/api/lunch/user', false],
        ] as $realm => [$method, $uri, $hasData]) {
            foreach (['application/json', 'text/html'] as $accept) {
                $cases[$realm.' '.$accept] = [$method, $uri, $hasData, $accept];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('realms')]
    public function unauthenticated_api_requests_return_the_existing_401_without_logging_an_exception(
        string $method, string $uri, bool $hasData, string $accept,
    ): void {
        Log::spy();
        $body = '{"status":"error","message":"Unauthenticated."'.($hasData ? ',"data":{}' : '').'}';

        $response = $this->call($method, $uri, server: ['HTTP_ACCEPT' => $accept]);

        $response->assertStatus(401)->assertContent($body);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertFalse($response->headers->has('Location'));
        $this->assertFalse(Route::has('login'));
        Log::shouldNotHaveReceived('error');
    }
}
