<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Http;

use GuzzleHttp\Client;

final readonly class GuzzleTransport implements TransportInterface
{
    public function __construct(private Client $client = new Client(['timeout' => 10.0, 'connect_timeout' => 5.0]))
    {
    }

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        $options = ['headers' => $headers, 'http_errors' => false];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = $this->client->request($method, $url, $options);
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (!is_string($name)) {
                continue;
            }
            $headers[$name] = array_values($values);
        }

        return new TransportResponse($response->getStatusCode(), $headers, (string)$response->getBody());
    }
}
