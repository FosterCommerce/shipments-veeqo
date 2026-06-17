<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use craft\commerce\elements\Order;
use craft\elements\Address;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\helpers\AddressFields;
use yii\base\Component;

/**
 * Resolves a Veeqo customer id for a Commerce order, deduping by email.
 *
 * Veeqo requires a customer on every order and has no idempotency keys, so we look the customer
 * up by email before creating one. No local persistence; the lookup runs on every push.
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
			throw new PermanentIntegrationException("Order {$order->id} has no email; Veeqo requires a customer.");
		}

		$existingId = $this->findCustomerIdByEmail($client, $email);
		if ($existingId !== null) {
			return $existingId;
		}

		$response = $client->post('/customers', $this->buildCustomerPayload($order, $email));

		$id = isset($response['id']) && is_numeric($response['id']) ? (int) $response['id'] : null;
		if ($id === null) {
			throw new PermanentIntegrationException("Veeqo customer create for {$email} returned no id.");
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

		$phone = AddressFields::phone($address);
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
