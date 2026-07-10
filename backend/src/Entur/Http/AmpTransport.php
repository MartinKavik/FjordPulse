<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

final readonly class AmpTransport implements TransportInterface
{
    private HttpClient $client;

    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? (new HttpClientBuilder())->build();
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
        $response = $this->client->request($request);

        return new TransportResponse($response->getStatus(), $response->getHeaders(), $response->getBody()->buffer());
    }
}
