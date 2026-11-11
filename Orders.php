<?php

namespace Omnitrade\WooCommerce;

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Status;
use Omnitrade\Model\Tags;
use Omnitrade\Model\Transaction;

/** A WooCommerce order, as the REST API answers it, read as a Transaction or a PlatformOrder; and a Payment written as one. */
final class Orders
{
    public const REFERENCE_KEY = 'omnitrade_reference';

    /**
     * A map as WooCommerce's meta_data list.
     *
     * @param array<string, string> $values
     *
     * @return list<array{key: string, value: string}>
     */
    public static function meta(array $values): array
    {
        $meta = [];
        foreach ($values as $key => $value) {
            $meta[] = ['key' => $key, 'value' => $value];
        }

        return $meta;
    }

    /**
     * The tags an order or a refund carries: its meta_data, but the
     * merchant's reference and WooCommerce's own hidden fields ("_...").
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, string>
     */
    public static function tags(array $object): array
    {
        $tags = [];
        foreach (\is_array($object['meta_data'] ?? null) ? $object['meta_data'] : [] as $meta) {
            $key = \is_array($meta) ? ($meta['key'] ?? null) : null;
            if (\is_string($key) && '' !== $key && self::REFERENCE_KEY !== $key && !str_starts_with($key, '_') && \is_scalar($meta['value'] ?? null)) {
                $tags[$key] = (string) $meta['value'];
            }
        }

        return $tags;
    }

    /** @param array<mixed> $order */
    public static function status(array $order): Status
    {
        $read = Data::of($order);
        $refunded = 0;
        foreach ($read->each('refunds') as $refund) {
            $refunded += (int) round(abs(Data::decimal($refund->get('total') ?? 0)) * 100);
        }
        $total = (int) round(Data::decimal($order['total'] ?? 0) * 100);

        return match ($read->text('status')) {
            'processing', 'completed' => $refunded > 0 ? ($refunded >= $total ? Status::REFUNDED : Status::PARTIALLY_REFUNDED) : Status::PAID,
            'refunded' => Status::REFUNDED,
            'cancelled' => Status::CANCELLED,
            'failed' => Status::REFUSED,
            default => Status::PENDING, // pending, on-hold, checkout-draft
        };
    }

    /** @param array<string, mixed> $order */
    public static function transaction(array $order): Transaction
    {
        $read = Data::of($order);
        $currency = strtoupper(Data::cast($order['currency'] ?? 'EUR'));
        $status = self::status($order);
        $refunded = 0;
        foreach ($read->each('refunds') as $refund) {
            $refunded += Money::fromDecimal((string) abs(Data::decimal($refund->get('total') ?? 0)), $currency)->amount;
        }

        return new Transaction(
            provider: 'woocommerce',
            reference: $read->text('id'),
            status: $status,
            amount: isset($order['total']) ? Money::fromDecimal($read->text('total'), $currency) : null,
            redirectUrl: Status::PENDING === $status ? $read->textOrNull('payment_url') : null,
            message: $read->textOrNull('status'),
            method: Data::castOrNull(($order['payment_method'] ?? null) ?: null),
            refunded: $refunded > 0 ? Money::of($refunded, $currency) : null,
            metadata: array_filter(['number' => $order['number'] ?? null, 'order_key' => $order['order_key'] ?? null, 'transaction_id' => $order['transaction_id'] ?? null]),
            createdAt: isset($order['date_created_gmt']) ? new \DateTimeImmutable($read->text('date_created_gmt').'Z') : null,
            raw: $order,
        );
    }

    /** @param array<string, mixed> $order */
    public static function platformOrder(array $order): PlatformOrder
    {
        $read = Data::of($order);
        $currency = strtoupper(Data::cast($order['currency'] ?? 'EUR'));
        $billing = $read->data('billing');
        $shipping = $read->data('shipping');
        $address = static fn (string $key): ?string => Data::castOrNull(($shipping->get($key) ?? $billing->get($key)) ?: null);
        $lines = [];
        foreach ($read->each('line_items') as $item) {
            $quantity = max(1, Data::integer($item->get('quantity') ?? 1));
            $lines[] = new Line(
                $item->text('name'),
                Money::fromDecimal(Data::cast($item->get('price') ?? Data::decimal($item->get('total') ?? 0) / $quantity), $currency),
                $quantity,
                Data::castOrNull($item->get('sku') ?: null),
                reference: $item->textOrNull('product_id'),
            );
        }

        return new PlatformOrder(
            provider: 'woocommerce',
            reference: $read->text('id'),
            number: isset($order['number']) ? '#'.$read->text('number') : null,
            status: self::status($order),
            total: Money::fromDecimal(Data::cast($order['total'] ?? '0'), $currency),
            customer: new Customer(
                email: Data::castOrNull($billing->get('email') ?: null),
                name: trim(Data::cast($billing->get('first_name') ?? '').' '.Data::cast($billing->get('last_name') ?? '')) ?: null,
                reference: isset($order['customer_id']) && $order['customer_id'] ? $read->text('customer_id') : null,
                phone: Data::castOrNull($billing->get('phone') ?: null),
                street: array_values(array_map(Data::cast(...), array_filter([$shipping->get('address_1') ?? $billing->get('address_1'), $shipping->get('address_2') ?? $billing->get('address_2')]))),
                postalCode: $address('postcode'),
                city: $address('city'),
                country: $address('country'),
            ),
            lines: $lines,
            financialStatus: $read->textOrNull('status'),
            fulfillmentStatus: 'completed' === ($order['status'] ?? null) ? 'completed' : null,
            createdAt: isset($order['date_created_gmt']) ? new \DateTimeImmutable($read->text('date_created_gmt').'Z') : null,
            raw: $order,
        );
    }

    /**
     * An order to create, unpaid: the buyer pays it on the shop's checkout.
     * Its meta_data: the merchant's reference, then the gateway's tags (the
     * "tags" option) and the payment's metadata over them, "site" the
     * configuration's.
     *
     * @return array<string, mixed>
     */
    public static function create(Payment $payment, ?Tags $tags = null): array
    {
        $currency = $payment->amount->currency;
        $items = [];
        $fees = [];
        if ($payment->linesAddUp()) {
            foreach ($payment->lines as $line) {
                $item = ['name' => $line->label, 'quantity' => $line->quantity, 'total' => $line->total()->decimal()];
                if ($line->reference && ctype_digit($line->reference)) {
                    $item['product_id'] = (int) $line->reference;
                }
                $items[] = $item;
            }
            if ($payment->discount && $payment->discount->amount > 0) {
                $fees[] = ['name' => 'Discount', 'total' => '-'.$payment->discount->decimal()];
            }
        } else {
            $items[] = ['name' => $payment->description ?? $payment->reference, 'quantity' => 1, 'total' => $payment->amount->decimal()];
        }
        $customer = $payment->customer;
        $name = $customer?->name ? explode(' ', $customer->name, 2) : [];
        $meta = [['key' => self::REFERENCE_KEY, 'value' => $payment->reference], ...self::meta(($tags ?? new Tags())->merge($payment->metadata))];
        $order = array_filter([
            'status' => 'pending',
            'currency' => $currency,
            'set_paid' => false,
            'line_items' => $items,
            'fee_lines' => $fees ?: null,
            'shipping_lines' => $payment->linesAddUp() && $payment->shipping && $payment->shipping->amount > 0
                ? [['method_id' => 'flat_rate', 'method_title' => 'Shipping', 'total' => $payment->shipping->decimal()]] : null,
            'customer_note' => $payment->notice,
            'meta_data' => $meta,
            'billing' => $customer ? array_filter([
                'email' => $customer->email,
                'first_name' => $name[0] ?? null,
                'last_name' => $name[1] ?? null,
                'phone' => $customer->phone,
                'address_1' => $customer->street[0] ?? null,
                'address_2' => $customer->street[1] ?? null,
                'postcode' => $customer->postalCode,
                'city' => $customer->city,
                'country' => $customer->country,
            ]) : null,
        ], static fn ($value) => null !== $value);
        $order['set_paid'] = false;

        return $order;
    }
}
