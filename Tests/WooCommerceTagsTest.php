<?php

namespace Omnitrade\WooCommerce\Tests;

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Data;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway's tags (the "tags" option) as WooCommerce's meta_data, on the
 * order and on the refund, as the requests go on the wire; an order's
 * webhook gives them back (the merchant's reference and the "_" fields left out).
 */
final class WooCommerceTagsTest extends TestCase
{
    private const SECRET = 'wc_secret';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    public function testAnOrderAndARefundCarryTheSite(): void
    {
        $gateway = $this->gateway(['site' => 'solstay', 'env' => 'demo']);
        $gateway->purchase(new Payment(Money::of(1250, 'EUR'), 'B-42', 'Two nights', metadata: ['env' => 'mine', 'site' => 'wineseller']));
        $gateway->refund('1042', Money::of(500, 'EUR'));

        self::assertSame([
            ['key' => 'omnitrade_reference', 'value' => 'B-42'],
            ['key' => 'site', 'value' => 'solstay'],
            ['key' => 'env', 'value' => 'mine'],
        ], $this->sent('orders')['meta_data']);
        self::assertSame([['key' => 'site', 'value' => 'solstay'], ['key' => 'env', 'value' => 'demo']], $this->sent('orders/1042/refunds')['meta_data']);
    }

    public function testWithoutTagsTheRefundIsAsBefore(): void
    {
        $this->gateway([])->refund('1042', Money::of(500, 'EUR'));

        self::assertArrayNotHasKey('meta_data', $this->sent('orders/1042/refunds'));
    }

    public function testAnOrdersWebhookGivesTheSiteBack(): void
    {
        $body = (string) json_encode(['id' => 1042, 'status' => 'processing', 'currency' => 'EUR', 'total' => '12.50', 'meta_data' => [
            ['id' => 1, 'key' => 'omnitrade_reference', 'value' => 'W-1'],
            ['id' => 2, 'key' => 'site', 'value' => 'wineseller'],
            ['id' => 3, 'key' => '_stripe_intent_id', 'value' => 'pi_1'],
        ]], \JSON_THROW_ON_ERROR);
        $notification = $this->gateway(['site' => 'solstay'])->notify($body, ['X-WC-Webhook-Signature' => Api::sign($body, self::SECRET), 'X-WC-Webhook-Topic' => 'order.updated', 'X-WC-Webhook-Delivery-ID' => 'd-1']);

        self::assertSame(['site' => 'wineseller'], $notification->tags);
        self::assertTrue($notification->isFromAnotherSite('solstay'));
    }

    /** @param array<string, mixed> $tags */
    private function gateway(array $tags): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/wp-json/wc/v3/'));
            $sent = \is_string($options['body'] ?? null) && '' !== $options['body'] ? json_decode($options['body'], true) : [];
            $this->calls[] = [$method, $path, Data::map($sent)];

            return new MockResponse((string) json_encode(match (true) {
                'orders' === $path => ['id' => 1042, 'status' => 'pending', 'currency' => 'EUR', 'total' => '12.50', 'payment_url' => 'https://shop.example/checkout/order-pay/1042/'],
                'orders/1042' === $path => ['id' => 1042, 'status' => 'processing', 'currency' => 'EUR', 'total' => '12.50'],
                default => ['id' => 77, 'amount' => '5.00'],
            }, \JSON_THROW_ON_ERROR));
        });

        return (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example/', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'webhook_secret' => self::SECRET] + ([] === $tags ? [] : ['tags' => $tags]));
    }

    /** @return array<string, mixed> */
    private function sent(string $path): array
    {
        $calls = array_values(array_filter($this->calls, static fn (array $call) => 'POST' === $call[0] && $call[1] === $path));
        self::assertCount(1, $calls, $path);

        return $calls[0][2];
    }
}
