<?php

namespace Omnitrade\WooCommerce\Tests;

use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * WooCommerce's usage policy (no limit of its own: the shop's host may set
 * one) and a 429 from that host to an order's creation: sent once, surfaced,
 * the next calls held unsent for the wait its Retry-After asks.
 */
final class WooCommercePolicyTest extends TestCase
{
    private int $requests = 0;

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnitrade/woocommerce', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('woocommerce', $policy->provider);
        self::assertSame([], $policy->rateLimits, 'none of its own');
        self::assertTrue($policy->knowsLimits());
    }

    public function testA429FromTheShopsHostIsSurfacedAndHoldsTheNextCalls(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->requests;

            return new MockResponse('{"code":"too_many","message":"Slow down"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '10']]);
        });
        $gateway = (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example/', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x']);

        try {
            $gateway->purchase(new Payment(Money::of(1250, 'EUR'), 'B-42', 'Two nights'));
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(10, $e->retryAfter);
        }
        try {
            $gateway->fetch('1042');
            self::fail('held');
        } catch (UsageLimitReachedException) {
        }
        self::assertSame(1, $this->requests);
    }
}
