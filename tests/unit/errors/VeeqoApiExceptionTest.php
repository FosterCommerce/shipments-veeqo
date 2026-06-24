<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\tests\unit\errors;

use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use PHPUnit\Framework\TestCase;

final class VeeqoApiExceptionTest extends TestCase
{
	public function testRetryableForRateLimitServerErrorsAndTransport(): void
	{
		foreach ([429, 500, 502, 503, 0] as $status) {
			self::assertTrue((new VeeqoApiException($status, 'x'))->isRetryable(), "Expected {$status} retryable");
		}
	}

	public function testNotRetryableForClientErrors(): void
	{
		foreach ([400, 401, 404, 422] as $status) {
			self::assertFalse((new VeeqoApiException($status, 'x'))->isRetryable(), "Expected {$status} not retryable");
		}
	}

	public function testExposesStatusBodyAndRetryAfter(): void
	{
		$exception = new VeeqoApiException(429, '{"error":"slow down"}', '6');
		self::assertSame(429, $exception->getStatusCode());
		self::assertSame('{"error":"slow down"}', $exception->getResponseBody());
		self::assertSame('6', $exception->getRetryAfter());
	}
}
