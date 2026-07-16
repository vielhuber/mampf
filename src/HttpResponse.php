<?php
declare(strict_types=1);

namespace Mampf;

use JsonException;

final readonly class HttpResponse
{
    public function __construct(public int $status, public string $body, public string $finalUrl = '') {}

    /**
     * Decode the response body.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode(json: $this->body, associative: true, flags: JSON_THROW_ON_ERROR);
        if (!is_array(value: $data)) {
            throw new JsonException(message: 'Die Antwort enthält kein JSON-Objekt.');
        }
        return $data;
    }
}
