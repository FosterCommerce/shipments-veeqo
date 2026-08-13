<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\tests\unit\jobs;

use fostercommerce\shipments\veeqo\jobs\ClassifyAdoptedMappingsJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the read of `active_channels` that decides whether a Veeqo product was built for a sales
 * channel, which cannot be exercised against an account with no marketplace connected.
 */
final class ClassifyAdoptedMappingsTest extends TestCase
{
	public function testListedWhenAChannelIsPresent(): void
	{
		self::assertTrue($this->isListed([
			'active_channels' => [
				[
					'id' => 1,
					'name' => 'Amazon',
				],
			],
		]));
	}

	public function testNotListedWhenChannelsAreEmpty(): void
	{
		self::assertFalse($this->isListed([
			'active_channels' => [],
		]));
	}

	public function testNotListedWhenTheKeyIsAbsent(): void
	{
		self::assertFalse($this->isListed([
			'id' => 123,
		]));
	}

	public function testNotListedWhenChannelsAreNotAList(): void
	{
		self::assertFalse($this->isListed([
			'active_channels' => null,
		]));
	}

	/**
	 * @param array<array-key, mixed> $veeqoProduct
	 */
	private function isListed(array $veeqoProduct): bool
	{
		$method = new ReflectionMethod(ClassifyAdoptedMappingsJob::class, 'isListedOnAChannel');
		$method->setAccessible(true);

		return $method->invoke(new ClassifyAdoptedMappingsJob(), $veeqoProduct);
	}
}
