<?php

namespace Tests\Unit;

use Equitab\StripeQa\DestinationChargeProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/stripe-qa/DestinationChargeProof.php';

class DestinationChargeProofTest extends TestCase
{
    private static function objects(string $currency = 'eur', string $settlement = 'cad'): array
    {
        $gross = $currency === $settlement ? 1165 : 1860;
        $money = fn ($id, $amount, $unit) => ['id' => $id, 'amount' => $amount, 'currency' => $unit, 'livemode' => false];
        $balance = fn ($id, $source, $amount, $unit, $fee) => ['id' => $id, 'source' => $source, 'amount' => $amount, 'currency' => $unit, 'fee' => $fee, 'net' => $amount - $fee];

        return [
            'charge' => [...$money('ch_proof', 1165, $currency), 'paid' => true, 'application_fee_amount' => 58,
                'transfer' => 'tr_proof', 'application_fee' => 'fee_proof', 'balance_transaction' => 'txn_charge'],
            'charge_balance' => [...$balance('txn_charge', 'ch_proof', $gross, $settlement, 136), 'exchange_rate' => $currency === $settlement ? null : 1.59656],
            'transfer' => [...$money('tr_proof', $gross, $settlement), 'destination' => 'acct_owner',
                'source_transaction' => 'ch_proof', 'destination_payment' => 'py_proof', 'balance_transaction' => 'txn_transfer'],
            'transfer_balance' => $balance('txn_transfer', 'tr_proof', -$gross, $settlement, 0),
            'destination_payment' => [...$money('py_proof', $gross, $settlement), 'source_transfer' => 'tr_proof', 'balance_transaction' => 'txn_owner'],
            'destination_balance' => [...$balance('txn_owner', 'py_proof', 1165, $currency, 81), 'exchange_rate' => $currency === $settlement ? null : 0.626347],
            'application_fee' => [...$money('fee_proof', 58, $currency), 'account' => 'acct_owner', 'charge' => 'py_proof',
                'originating_transaction' => 'ch_proof', 'balance_transaction' => 'txn_fee'],
            'application_fee_balance' => $balance('txn_fee', 'fee_proof', 58, $currency, 0),
        ];
    }

    public static function currencies(): array
    {
        return [['cad', 'cad'], ['eur', 'cad'], ['eur', 'eur']];
    }

    #[DataProvider('currencies')]
    public function test_proves_the_complete_chain_without_comparing_different_currencies(string $currency, string $settlement): void
    {
        $proof = (new DestinationChargeProof)->verify(self::objects($currency, $settlement), 1165, $currency, 'acct_owner');
        $this->assertSame(['amount' => 1165, 'currency' => $currency], $proof['presentment']);
        $this->assertSame($settlement, $proof['transfer']['currency']);
        $this->assertSame($currency, $proof['commission']['currency']);
        $this->assertSame(58, $proof['commission']['amount']);
        $this->assertNull($proof['net_profit']);
    }

    public static function contradictions(): array
    {
        return [
            ['charge', 'livemode', true], ['charge', 'amount', 1166], ['charge', 'currency', 'cad'],
            ['charge', 'application_fee_amount', 59], ['charge_balance', 'net', 0], ['charge_balance', 'source', 'ch_other'],
            ['charge_balance', 'exchange_rate', null], ['charge_balance', 'exchange_rate', 1.0],
            ['destination_balance', 'exchange_rate', 1.0], ['destination_balance', 'exchange_rate', NAN],
            ['transfer', 'destination', 'acct_other'], ['transfer', 'source_transaction', 'ch_other'],
            ['transfer', 'amount', 1165], ['transfer', 'currency', 'eur'], ['transfer_balance', 'amount', 1860],
            ['transfer', 'destination_payment', 'py_other'], ['destination_payment', 'source_transfer', 'tr_other'],
            ['destination_payment', 'currency', 'eur'], ['destination_balance', 'source', 'py_other'],
            ['destination_balance', 'currency', 'usd'], ['application_fee', 'charge', 'ch_other'],
            ['application_fee', 'originating_transaction', 'ch_other'], ['application_fee', 'account', 'acct_other'],
            ['application_fee', 'currency', 'cad'], ['application_fee', 'amount', 59],
            ['application_fee_balance', 'source', 'fee_other'], ['application_fee_balance', 'currency', 'cad'],
            ['application_fee_balance', 'amount', '58'],
        ];
    }

    #[DataProvider('contradictions')]
    public function test_rejects_wrong_amount_currency_destination_or_object_link(string $object, string $field, mixed $value): void
    {
        $objects = self::objects();
        $objects[$object][$field] = $value;
        $this->expectException(RuntimeException::class);
        (new DestinationChargeProof)->verify($objects, 1165, 'eur', 'acct_owner');
    }

    public function test_accepts_expanded_ids_and_direct_charge_fee_reference(): void
    {
        $objects = self::objects('cad', 'cad');
        $objects['transfer']['destination'] = ['id' => 'acct_owner'];
        $objects['application_fee']['charge'] = 'ch_proof';
        $objects['application_fee']['originating_transaction'] = null;
        $proof = (new DestinationChargeProof)->verify($objects, 1165, 'CAD', 'acct_owner');
        $this->assertSame('cad', $proof['commission']['currency']);
    }
}
