<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Exception;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Thrown when Evolution API rejects or fails to deliver a message.
 *
 * Mautic campaign / event logging can catch this similarly to SMS delivery failures.
 */
class EvolutionDeliveryException extends \RuntimeException
{
    private ?Lead $contact = null;
    private ?int $statusCode = null;
    private ?array $responseBody = null;
    private string $endpoint = '';

    public function __construct(
        string $message = 'Evolution API delivery failed',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function setContact(?Lead $contact): self
    {
        $this->contact = $contact;

        return $this;
    }

    public function getContact(): ?Lead
    {
        return $this->contact;
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

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
