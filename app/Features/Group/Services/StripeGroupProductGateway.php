<?php

namespace App\Features\Group\Services;

use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Group\Exceptions\PublicationUnavailable;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Product;
use Stripe\StripeClient;

class StripeGroupProductGateway implements GroupProductGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function ensureProduct(string $draftId, string $name, int $ownerId, int $version): string
    {
        // A permanent, deterministic ID also prevents duplicates beyond Stripe's
        // 24-hour idempotency window. A SQL rollback cannot undo a Stripe request.
        $id = 'equitab_draft_'.str_replace('-', '', $draftId).'_v'.$version;

        try {
            try {
                $product = $this->stripe->products->retrieve($id);
            } catch (InvalidRequestException $e) {
                if ($e->getHttpStatus() !== 404 || $e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }

                try {
                    $product = $this->stripe->products->create([
                        'id' => $id,
                        'name' => $name.' — EquitAb',
                        'metadata' => ['draft_id' => $draftId, 'owner_id' => (string) $ownerId, 'draft_version' => (string) $version],
                    ], ['idempotency_key' => 'group-draft-product:'.$draftId.':'.$version]);
                } catch (ApiErrorException $creationError) {
                    // A concurrent request or a lost response may already have created it.
                    $product = $this->stripe->products->retrieve($id);
                }
            }

            $this->assertOwned($product, $draftId, $ownerId, $version);

            return $product->id;
        } catch (ApiErrorException $e) {
            throw new PublicationUnavailable;
        }
    }

    private function assertOwned(Product $product, string $draftId, int $ownerId, int $version): void
    {
        if (($product->deleted ?? false) || ! ($product->active ?? false)
            || ($product->metadata->draft_id ?? null) !== $draftId
            || ($product->metadata->owner_id ?? null) !== (string) $ownerId
            || ($product->metadata->draft_version ?? null) !== (string) $version) {
            throw new PublicationUnavailable;
        }
    }
}
