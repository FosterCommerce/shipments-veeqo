<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Address;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\helpers\AddressFields;
use fostercommerce\shipments\veeqo\Plugin;
use yii\base\Component;

/**
 * Veeqo customer resolver.
 */
class CustomerResolver extends Component
{
	/**
	 * @throws VeeqoApiException
	 * @throws PermanentIntegrationException
	 */
	public function resolveCustomerId(Order $order, VeeqoApi $client): int
	{
		$email = trim((string) $order->getEmail());
		if ($email === '') {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noEmail'));
		}

		// Veeqo requires a customer on every order and dedupes nothing, so look before creating.
		$existingId = $this->findCustomerIdByEmail($client, $email);
		if ($existingId !== null) {
			return $existingId;
		}

		$response = $client->post('/customers', $this->buildCustomerPayload($order, $email));

		$id = isset($response['id']) && is_numeric($response['id']) ? (int) $response['id'] : null;
		if ($id === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noCustomerId', [
				'email' => $email,
			]));
		}

		return $id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildCustomerPayload(Order $order, string $email): array
	{
		$payload = [
			'email' => $email,
			'customer_type' => 'retail',
		];

		$address = $order->getBillingAddress() ?? $order->getShippingAddress();
		if (! $address instanceof Address) {
			return $payload;
		}

		$phone = AddressFields::phone($address, (string) Plugin::instance()->getSettings()->phoneFieldHandle);
		if ($phone !== '') {
			$payload['phone'] = $phone;
		}

		$payload['billing_address_attributes'] = [
			'first_name' => AddressFields::firstName($address),
			'last_name' => (string) $address->lastName,
			'company' => (string) $address->organization,
			'address1' => (string) $address->addressLine1,
			'address2' => (string) $address->addressLine2,
			'city' => (string) $address->locality,
			'country' => $address->countryCode,
			'zip' => (string) $address->postalCode,
		];

		return $payload;
	}

	/**
	 * @throws VeeqoApiException
	 */
	private function findCustomerIdByEmail(VeeqoApi $client, string $email): ?int
	{
		$customers = $client->get('/customers', [
			'query' => $email,
		]);

		foreach ($customers as $customer) {
			if (! is_array($customer)) {
				continue;
			}

			$customerEmail = isset($customer['email']) ? (string) $customer['email'] : '';
			if (strcasecmp($customerEmail, $email) !== 0) {
				continue;
			}

			if (isset($customer['id']) && is_numeric($customer['id'])) {
				return (int) $customer['id'];
			}
		}

		return null;
	}
}
