<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\errors;

use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use Throwable;
use yii\base\Exception;

/**
 * Thrown when the Veeqo API returns a non-2xx response or a transport-level error occurs.
 *
 * A status code of 0 means no response was received, so the request's outcome is unknown.
 */
class VeeqoApiException extends Exception
{
	public function __construct(
		private readonly int $statusCode,
		private readonly string $responseBody,
		?Throwable $previous = null,
	) {
		$message = $this->responseBody !== ''
			? 'Veeqo API error (' . $this->statusCode . '): ' . $this->responseBody
			: 'Veeqo API error (' . $this->statusCode . ')';
		parent::__construct($message, $this->statusCode, $previous);
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getResponseBody(): string
	{
		return $this->responseBody;
	}

	public function isRetryable(): bool
	{
		return $this->statusCode === 429 || ($this->statusCode >= 500 && $this->statusCode < 600) || $this->statusCode === 0;
	}

	/**
	 * The Shipments-plugin exception for this failure. The subclass is what decides whether the
	 * queue retries the job or fails it outright.
	 */
	public function toIntegrationException(): IntegrationException
	{
		if ($this->isRetryable()) {
			return new IntegrationException($this->getMessage(), 0, $this);
		}

		return new PermanentIntegrationException($this->getMessage(), 0, $this);
	}
}
