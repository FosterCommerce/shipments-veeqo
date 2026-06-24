<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\errors;

use Throwable;
use yii\base\Exception;

/**
 * Thrown when the Veeqo API returns a non-2xx response or a transport-level error occurs.
 * Inspect `getStatusCode()` to decide whether to retry (429, 5xx) or fail (4xx).
 */
class VeeqoApiException extends Exception
{
	public function __construct(
		private readonly int $statusCode,
		private readonly string $responseBody,
		private readonly string $retryAfter = '',
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

	public function getRetryAfter(): string
	{
		return $this->retryAfter;
	}

	public function isRetryable(): bool
	{
		return $this->statusCode === 429 || ($this->statusCode >= 500 && $this->statusCode < 600) || $this->statusCode === 0;
	}
}
