<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use Override;
use Throwable;

/**
 * Forter implementation of {@see FraudScreeningProvider}. Maps the request to
 * Forter's order payload, calls the API, and translates Forter's `action`
 * (`approve` / `decline` / `not reviewed`) into a {@see FraudVerdict}.
 *
 * Fail-soft per the contract: any transport failure, or a response without a
 * recognizable action, yields a {@see FraudDecision::Errored} verdict rather
 * than throwing, so the decision layer applies its fail-open / fail-closed
 * policy uniformly.
 */
final class ForterFraudScreeningProvider implements FraudScreeningProvider
{
    private ForterRequestMapper $mapper;

    public function __construct(
        private readonly ForterHttpClientInterface $client,
        ?ForterRequestMapper $mapper = null,
    ) {
        $this->mapper = $mapper ?? new ForterRequestMapper;
    }

    #[Override]
    public function screen(FraudScreeningRequest $request): FraudVerdict
    {
        try {
            $response = $this->client->postOrder($request->reference, $this->mapper->toOrderPayload($request));
        } catch (Throwable) {
            return new FraudVerdict(FraudDecision::Errored, reference: $request->reference);
        }

        return $this->mapResponse($request, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function mapResponse(FraudScreeningRequest $request, array $response): FraudVerdict
    {
        $decision = match (strtolower((string) ($response['action'] ?? ''))) {
            'approve' => FraudDecision::Approve,
            'decline' => FraudDecision::Decline,
            'not reviewed', 'not_reviewed' => FraudDecision::NotReviewed,
            default => FraudDecision::Errored,
        };

        return new FraudVerdict(
            $decision,
            reasonCode: isset($response['reasonCode']) ? (string) $response['reasonCode'] : null,
            reference: isset($response['transaction']) ? (string) $response['transaction'] : $request->reference,
        );
    }
}
