<?php

namespace Omnitrade\WooCommerce\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Testing\GatewayContractTests;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Data;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on WooCommerce's recorded answers (REST v3). */
final class WooCommerceContractTest extends TestCase
{
    use GatewayContractTests;

    private const SECRET = 'wc-contract';
    private const API = '/wp-json/wc/v3/';

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new WooCommerceGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['url' => 'https://shop.example/', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'webhook_secret' => self::SECRET];
    }

    protected function transactionReference(): string
    {
        return '1042';
    }

    protected function productReference(): string
    {
        return '501';
    }

    /** @return array<string, array<mixed>|MockResponse|callable> */
    protected function recordedAnswers(): array
    {
        $simple = Data::map(json_decode((string) file_get_contents(__DIR__.'/Fixtures/product.simple.json'), true, 512, \JSON_THROW_ON_ERROR));

        return [
            'POST '.self::API.'orders' => self::order('pending') + ['payment_url' => 'https://shop.example/checkout/order-pay/1042/?pay_for_order=true&key=wc_order_abc'],
            'GET '.self::API.'orders/1042' => self::order('processing'),
            'POST '.self::API.'orders/1042/refunds' => ['id' => 77, 'amount' => '5.00', 'reason' => 'Overcharged'],
            'GET '.self::API.'payment_gateways' => [['id' => 'stripe', 'title' => 'Credit card', 'enabled' => true], ['id' => 'bacs', 'title' => 'Bank transfer', 'enabled' => true]],
            'GET '.self::API.'products' => new MockResponse(json_encode([$simple], \JSON_THROW_ON_ERROR), ['response_headers' => ['X-WP-Total' => '2', 'X-WP-TotalPages' => '2']]),
            'GET '.self::API.'products/501' => $simple,
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    protected function signedNotification(): array
    {
        $body = json_encode(self::order('processing'), \JSON_THROW_ON_ERROR);

        return [$body, ['X-WC-Webhook-Signature' => Api::sign($body, self::SECRET), 'X-WC-Webhook-Topic' => 'order.updated', 'X-WC-Webhook-Delivery-ID' => 'd-1']];
    }

    /** @return array<string, mixed> */
    private static function order(string $status): array
    {
        return ['id' => 1042, 'number' => '1042', 'status' => $status, 'currency' => 'EUR', 'total' => '12.50', 'payment_method' => 'stripe', 'order_key' => 'wc_order_abc', 'date_created_gmt' => '2026-10-01T10:00:00',
            'billing' => ['first_name' => 'Camille', 'last_name' => 'Durand', 'email' => 'camille@example.org', 'country' => 'FR'],
            'shipping' => [], 'line_items' => [['name' => 'Ten hours', 'quantity' => 1, 'price' => 12.5, 'total' => '12.50', 'product_id' => 55, 'sku' => '']], 'refunds' => []];
    }
}
