<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GuideAskTransport implements ClientInterface
{
    public array $requests = [];
    public string $answer = 'Open Admin Sprout task.';
    public array $answers = [];
    public string $mode = 'ok';
    public ?\Closure $onRequest = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = json_decode((string) $request->getBody(), true);
        $callback = $this->onRequest; $this->onRequest = null; if ($callback) $callback();
        if ($this->mode === 'timeout') throw new \GuzzleHttp\Exception\ConnectException('Sensitive question in timeout', $request);
        if ($this->mode === 'error') return new Response(500, [], json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Sensitive question']]));
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_fixture', 'type' => 'message', 'role' => 'assistant', 'model' => 'fixture-model',
            'stop_reason' => $this->mode === 'truncated' ? 'max_tokens' : 'end_turn', 'stop_sequence' => null,
            'content' => [['type' => 'text', 'text' => $this->mode === 'empty' ? '' : (array_shift($this->answers) ?? $this->answer)]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10, 'cache_creation_input_tokens' => 25, 'cache_read_input_tokens' => 50],
        ]));
    }
}
