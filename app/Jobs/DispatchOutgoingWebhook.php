<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entrega de webhook com retentativas exponenciais e assinatura HMAC-SHA256.
 * Receptores validam: hash_hmac('sha256', "{timestamp}.{body}", secret) === X-Helpdesk-Signature
 */
class DispatchOutgoingWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public string $deliveryId;

    public function __construct(
        public string $url,
        public string $method,
        public array $payload,
        public array $headers = [],
    ) {
        $this->tries = (int) config('helpdesk.webhooks.tries', 5);
        $this->timeout = (int) config('helpdesk.webhooks.timeout', 10) + 5;
        $this->deliveryId = (string) Str::uuid();
        $this->onQueue(config('helpdesk.webhooks.queue'));
        $this->afterCommit();
    }

    /** @return list<int> segundos entre tentativas */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        $headers = $this->headers + [
            'Content-Type' => 'application/json',
            'User-Agent' => config('app.name').'-Webhooks/1.0',
            'X-Helpdesk-Delivery' => $this->deliveryId,
            'X-Helpdesk-Timestamp' => $timestamp,
        ];

        if ($secret = config('helpdesk.webhooks.signing_secret')) {
            $headers['X-Helpdesk-Signature'] = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        $response = Http::withHeaders($headers)
            ->timeout((int) config('helpdesk.webhooks.timeout', 10))
            ->withBody($body, 'application/json')
            ->send(strtoupper($this->method), $this->url);

        // 4xx (exceto 408/429) não adianta repetir
        if ($response->clientError() && ! in_array($response->status(), [408, 429], true)) {
            Log::warning('Webhook rejeitado pelo destino.', [
                'url' => $this->url, 'status' => $response->status(), 'delivery' => $this->deliveryId,
            ]);
            $this->fail(new RequestException($response));

            return;
        }

        $response->throw();
    }

    public function failed(Throwable $e): void
    {
        Log::error('Webhook falhou definitivamente.', [
            'url' => $this->url, 'delivery' => $this->deliveryId, 'error' => $e->getMessage(),
        ]);
    }
}
