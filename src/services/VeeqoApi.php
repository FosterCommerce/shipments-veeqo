<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;

/**
 * HTTP client for the Veeqo REST API. Built per integration with that integration's API key.
 *
 * @see https://developers.veeqo.com
 */
class VeeqoApi extends Component
{
	public const BASE_URI = 'https://api.veeqo.com';

	public const DEFAULT_TIMEOUT = 30.0;

	/**
	 * Header carrying the total page count on paginated list responses.
	 */
	public const TOTAL_PAGES_HEADER = 'X-Total-Pages-Count';

	/**
	 * API key or `$ENV_VAR` reference; resolved through `App::parseEnv` before each request.
	 */
	public string $apiKey = '';

	private ?GuzzleClient $guzzleClient = null;

	/**
	 * @param array<string, mixed> $query
	 * @return array<array-key, mixed>
	 * @throws VeeqoApiException
	 */
	public function get(string $path, array $query = []): array
	{
		return $this->decode($this->send('GET', $path, [
			'query' => $query,
		]));
	}

	/**
	 * GET a paginated collection, returning the decoded items plus the total page count.
	 *
	 * @param array<string, mixed> $query
	 * @return array{items: array<array-key, mixed>, totalPages: int}
	 * @throws VeeqoApiException
	 */
	public function getPage(string $path, array $query = []): array
	{
		$response = $this->send('GET', $path, [
			'query' => $query,
		]);

		$totalPages = (int) $response->getHeaderLine(self::TOTAL_PAGES_HEADER);

		return [
			'items' => $this->decode($response),
			'totalPages' => max($totalPages, 1),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<array-key, mixed>
	 * @throws VeeqoApiException
	 */
	public function post(string $path, array $body): array
	{
		return $this->decode($this->send('POST', $path, [
			'json' => $body,
		]));
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<array-key, mixed>
	 * @throws VeeqoApiException
	 */
	public function put(string $path, array $body): array
	{
		return $this->decode($this->send('PUT', $path, [
			'json' => $body,
		]));
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws VeeqoApiException
	 */
	public function delete(string $path): array
	{
		return $this->decode($this->send('DELETE', $path, []));
	}

	/**
	 * Pings `GET /current_company` as a lightweight API-key validation check.
	 *
	 * @return array<array-key, mixed>
	 * @throws VeeqoApiException
	 */
	public function testConnection(): array
	{
		return $this->get('/current_company');
	}

	/**
	 * @param array<string, mixed> $options
	 * @throws VeeqoApiException
	 */
	private function send(string $method, string $path, array $options): ResponseInterface
	{
		try {
			return $this->getGuzzleClient()->request($method, $path, $options);
		} catch (BadResponseException $badResponseException) {
			$response = $badResponseException->getResponse();
			$status = $response->getStatusCode();
			$body = (string) $response->getBody();

			// Veeqo sends no Retry-After on 429; capture it anyway in case that changes.
			$retryAfter = $response->getHeaderLine('Retry-After');
			if ($retryAfter === '') {
				$retryAfter = $response->getHeaderLine('X-Retry-After');
			}

			Craft::warning('Veeqo API ' . $method . ' ' . $path . ' returned ' . $status . ': ' . $body, Plugin::HANDLE);
			throw new VeeqoApiException($status, $body, $retryAfter, $badResponseException);
		} catch (GuzzleException $guzzleException) {
			Craft::error('Veeqo API ' . $method . ' ' . $path . ' transport error: ' . $guzzleException->getMessage(), Plugin::HANDLE);
			throw new VeeqoApiException(0, $guzzleException->getMessage(), '', $guzzleException);
		}
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function decode(ResponseInterface $response): array
	{
		$body = (string) $response->getBody();
		if ($body === '') {
			return [];
		}

		$decoded = Json::decode($body);
		if (! is_array($decoded)) {
			return [];
		}

		return $decoded;
	}

	private function getGuzzleClient(): GuzzleClient
	{
		if ($this->guzzleClient instanceof GuzzleClient) {
			return $this->guzzleClient;
		}

		$this->guzzleClient = Craft::createGuzzleClient([
			'base_uri' => self::BASE_URI,
			'timeout' => self::DEFAULT_TIMEOUT,
			'headers' => [
				'x-api-key' => App::parseEnv($this->apiKey),
				'x-api-request' => 'true',
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		return $this->guzzleClient;
	}
}
