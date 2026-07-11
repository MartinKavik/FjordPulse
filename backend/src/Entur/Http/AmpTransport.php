<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Http;

use Amp\ByteStream\StreamException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;

final class AmpTransport implements TransportInterface
{
    private ?HttpClient $client;

    /** @var \Closure(): HttpClient */
    private readonly \Closure $clientFactory;

    /**
     * @param (\Closure(): HttpClient)|null $clientFactory
     */
    public function __construct(?HttpClient $client = null, ?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory
            ?? static fn(): HttpClient => (new HttpClientBuilder())->retry(0)->build();
        $this->client = $client ?? ($this->clientFactory)();
    }

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        if ($method === '') {
            throw new \InvalidArgumentException('HTTP method cannot be empty.');
        }
        $request = new Request($url, $method);
        foreach ($headers as $name => $value) {
            if ($name === '') {
                throw new \InvalidArgumentException('HTTP header name cannot be empty.');
            }
            $request->setHeader($name, $value);
        }
        if ($json !== null) {
            $request->setBody(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $client = $this->client ??= ($this->clientFactory)();
        try {
            $response = $client->request($request);

            return new TransportResponse($response->getStatus(), $response->getHeaders(), $response->getBody()->buffer());
        } catch (HttpException | StreamException $error) {
            // A process-lifetime client retains its connection pool. Discard it after a
            // transport failure so the next scheduled request creates a new pool. Do
            // not retry here: the watch scheduler owns pacing and backoff.
            if ($this->client === $client) {
                $this->client = null;
            }

            throw $error;
        }
    }
}
