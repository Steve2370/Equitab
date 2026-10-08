<?php

declare(strict_types=1);

namespace Equitab\StripeQa;

use RuntimeException;

/** Read-only acceptance check. Never convert or add amounts across currencies. */
final class DestinationChargeProof
{
    public function verify(array $objects, int $amount, string $currency, string $destination): array
    {
        $currency = strtolower($currency);
        $this->require($amount > 0 && in_array($currency, ['cad', 'eur'], true), 'Unsupported payment.');
        foreach (['charge', 'transfer', 'destination_payment', 'application_fee'] as $name) {
            $object = $objects[$name] ?? [];
            $this->require(($object['livemode'] ?? null) === false, 'TEST object required: '.$name);
            $this->require(is_string($object['id'] ?? null), 'Object identifier missing: '.$name);
            $this->money($object);
        }
        foreach (['charge_balance', 'transfer_balance', 'destination_balance', 'application_fee_balance'] as $name) {
            $balance = $objects[$name] ?? [];
            $this->money($balance);
            $this->require(is_int($balance['fee'] ?? null) && is_int($balance['net'] ?? null)
                && $balance['net'] === $balance['amount'] - $balance['fee'], 'Balance arithmetic mismatch: '.$name);
        }

        $charge = $objects['charge'];
        $balance = $objects['charge_balance'];
        $transfer = $objects['transfer'];
        $transferBalance = $objects['transfer_balance'];
        $received = $objects['destination_payment'];
        $receivedBalance = $objects['destination_balance'];
        $fee = $objects['application_fee'];
        $feeBalance = $objects['application_fee_balance'];
        $expectedFee = (int) round($amount * 0.05);

        $this->require($charge['amount'] === $amount && $charge['currency'] === $currency
            && ($charge['paid'] ?? null) === true, 'Presentment mismatch.');
        $this->require(($charge['application_fee_amount'] ?? null) === $expectedFee, 'Presentment commission mismatch.');
        $this->link($charge, 'balance_transaction', $balance);
        $this->link($balance, 'source', $charge);
        $this->settlementMoney($charge, $balance);
        $this->link($charge, 'transfer', $transfer);
        $this->link($transfer, 'source_transaction', $charge);
        $this->require($this->id($transfer['destination'] ?? null) === $destination, 'Wrong destination.');
        // Without on_behalf_of, transfer uses platform settlement, NOT presentment.
        $this->sameMoney($transfer, $balance, 'Transfer must match settled gross charge.');
        $this->link($transfer, 'balance_transaction', $transferBalance);
        $this->link($transferBalance, 'source', $transfer);
        $this->require($transferBalance['currency'] === $transfer['currency']
            && $transferBalance['amount'] === -$transfer['amount'], 'Transfer debit mismatch.');
        $this->link($transfer, 'destination_payment', $received);
        $this->link($received, 'source_transfer', $transfer);
        $this->sameMoney($received, $transfer, 'Connected payment mismatch.');
        $this->link($received, 'balance_transaction', $receivedBalance);
        $this->link($receivedBalance, 'source', $received);
        $this->settlementMoney($received, $receivedBalance);

        $this->link($charge, 'application_fee', $fee);
        $this->require($this->id($fee['account'] ?? null) === $destination, 'Commission account mismatch.');
        $feeCharge = $this->id($fee['charge'] ?? null);
        $origin = $this->id($fee['originating_transaction'] ?? null);
        $this->require(($feeCharge === $received['id'] && $origin === $charge['id'])
            || ($feeCharge === $charge['id'] && ($origin === null || $origin === $charge['id'])), 'Commission charge lineage mismatch.');
        // CAD->CA and EUR->BE recipes settle the owner in the charged currency.
        // Any other FX commission needs a separate scenario, not a guessed rate.
        $this->require($receivedBalance['currency'] === $currency && $fee['currency'] === $currency,
            'Owner/commission FX needs a separate reconciliation scenario.');
        $this->require($fee['amount'] === $expectedFee, 'Settled commission mismatch.');
        $this->link($fee, 'balance_transaction', $feeBalance);
        $this->link($feeBalance, 'source', $fee);
        $this->sameMoney($feeBalance, $fee, 'Commission balance mismatch.');
        $this->require($receivedBalance['amount'] > 0, 'No connected settlement.');

        return [
            'presentment' => ['amount' => $amount, 'currency' => $currency],
            'platform_settlement' => $this->summary($balance),
            'transfer' => ['id' => $transfer['id'], 'amount' => $transfer['amount'], 'currency' => $transfer['currency'], 'destination' => $destination],
            'owner_settlement' => $this->summary($receivedBalance),
            'commission' => $this->summary($feeBalance),
            'net_profit' => null, // Fees and revenue in different currencies are not a net profit.
        ];
    }

    private function money(array $object): void
    {
        $this->require(is_int($object['amount'] ?? null) && is_string($object['currency'] ?? null)
            && preg_match('/^[a-z]{3}$/D', $object['currency']) === 1, 'Invalid monetary object.');
    }

    private function sameMoney(array $left, array $right, string $message): void
    {
        $this->require($left['currency'] === $right['currency'] && $left['amount'] === $right['amount'], $message);
    }

    private function settlementMoney(array $source, array $settlement): void
    {
        if ($source['currency'] === $settlement['currency']) {
            $this->sameMoney($source, $settlement, 'Same-currency gross settlement mismatch.');

            return;
        }
        $rate = $settlement['exchange_rate'] ?? null;
        $this->require((is_float($rate) || is_int($rate)) && is_finite((float) $rate) && $rate > 0,
            'Stripe exchange rate missing.');
        // Test evidence only; never write a derived FX amount into application data.
        $this->require((int) round($source['amount'] * $rate) === $settlement['amount'], 'FX settlement mismatch.');
    }

    private function id(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) ? ($value['id'] ?? null) : null);
    }

    private function link(array $object, string $field, array $target): void
    {
        $this->require($this->id($object[$field] ?? null) === ($target['id'] ?? false), 'Broken object link: '.$field);
    }

    private function summary(array $balance): array
    {
        return array_intersect_key($balance, array_flip(['amount', 'currency', 'fee', 'net', 'exchange_rate']));
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
