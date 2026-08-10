<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Exception;

/**
 * Generic Evolution API client / configuration error.
 */
class EvolutionApiException extends \RuntimeException
{
    private ?int $statusCode = null;
    private ?array $responseBody = null;

    public function __construct(
        string $message = 'Evolution API error',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function setStatusCode(?int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setResponseBody(?array $responseBody): self
    {
        $this->responseBody = $responseBody;

        return $this;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
