<?php

declare(strict_types=1);

namespace Mpp\Client;

use Mpp\AuthParams;
use Mpp\Challenge;
use Mpp\Credential;
use Mpp\Exception\ClientException;
use Mpp\Exception\ParseException;
use Mpp\Receipt;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 decorator that answers 402s automatically.
 */
final class PaymentClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Payer $payer,
        private readonly int $maxPayments = 3,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        // A server may re-challenge for a fresh nonce, a changed price or a retryable failure,
        // so keep paying until it stops asking or we hit the ceiling.
        for ($attempt = 0; $attempt < $this->maxPayments; ++$attempt) {
            if ($response->getStatusCode() !== 402) {
                return $response;
            }

            $challenge = $this->select($response);
            $credential = new Credential($challenge, $this->payer->pay($challenge), $this->payer->source());

            $request = $request->withHeader($credential->headerName(), $credential->toHeader());
            $response = $this->inner->sendRequest($request);
        }

        if ($response->getStatusCode() === 402) {
            throw new ClientException("server still requires payment after {$this->maxPayments} attempts");
        }

        return $response;
    }

    /**
     * Last receipt seen, if the caller wants it.
     */
    public function receiptFrom(ResponseInterface $response): ?Receipt
    {
        $header = $response->getHeaderLine(Receipt::HEADER);

        return $header === '' ? null : Receipt::fromHeader($header);
    }

    /**
     * Pick the best challenge the server offered. Preference order comes from the payer, not from
     * the order the server happened to list them in.
     */
    private function select(ResponseInterface $response): Challenge
    {
        $offered = [];
        foreach (AuthParams::parseChallenges($response->getHeader('WWW-Authenticate')) as $parsed) {
            if (strcasecmp($parsed['scheme'], Challenge::SCHEME) !== 0) {
                continue;
            }

            try {
                $challenge = Challenge::fromParams($parsed['params']);
            } catch (ParseException) {
                continue;
            }

            if (!$challenge->isExpired()) {
                $offered[] = $challenge;
            }
        }

        if ($offered === []) {
            throw new ClientException('402 carried no Payment challenge this client can use');
        }

        foreach ($this->payer->supports() as $method) {
            foreach ($offered as $challenge) {
                if ($challenge->method === $method) {
                    return $challenge;
                }
            }
        }

        throw new ClientException('no offered payment method is supported by this payer');
    }
}
