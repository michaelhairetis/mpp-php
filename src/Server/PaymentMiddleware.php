<?php

declare(strict_types=1);

namespace Mpp\Server;

use Mpp\Challenge;
use Mpp\Exception\VerificationException;
use Mpp\Receipt;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates a route behind payment.
 *
 * The priced callable returns the method-specific request for this call, or null to let it
 * through free. On success the verified Credential and Receipt are attached as request attributes
 * so the handler can read the payer.
 */
final class PaymentMiddleware implements MiddlewareInterface
{
    public const ATTR_CREDENTIAL = 'mpp.credential';
    public const ATTR_RECEIPT = 'mpp.receipt';

    /**
     * @param callable(ServerRequestInterface): ?array<string, mixed> $priced
     */
    public function __construct(
        private readonly PaymentGate $gate,
        private readonly ResponseFactoryInterface $responses,
        private $priced,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $priced = ($this->priced)($request);
        if ($priced === null) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();
        $offered = $request->getHeaderLine($this->gate->credentialHeaderName());

        if ($offered === '' || stripos($offered, Challenge::SCHEME . ' ') !== 0) {
            return $this->challenge($priced, $body);
        }

        try {
            $receipt = $this->gate->verify($offered, $body);
        } catch (VerificationException $e) {
            return $this->challenge($priced, $body, $e);
        }

        $response = $handler->handle(
            $request
                ->withAttribute(self::ATTR_CREDENTIAL, \Mpp\Credential::fromHeader($offered))
                ->withAttribute(self::ATTR_RECEIPT, $receipt)
        );

        return $this->withReceipt($response, $receipt);
    }

    /**
     * @param array<string, mixed> $priced
     */
    private function challenge(array $priced, string $body, ?VerificationException $failure = null): ResponseInterface
    {
        $challenge = $this->gate->challenge($priced, $body === '' ? null : $body);

        $response = $this->responses->createResponse(402)
            ->withHeader('WWW-Authenticate', $challenge->toHeader())
            ->withHeader('Cache-Control', 'no-store');

        if ($failure === null) {
            return $response;
        }

        // RFC 9457 — say why the previous attempt failed without leaking verifier internals.
        $problem = json_encode([
            'type' => $failure->problemType,
            'title' => 'Payment verification failed',
            'status' => 402,
            'detail' => $failure->getMessage(),
        ], JSON_THROW_ON_ERROR);

        $response = $response->withHeader('Content-Type', 'application/problem+json');
        $response->getBody()->write($problem);

        return $response;
    }

    private function withReceipt(ResponseInterface $response, Receipt $receipt): ResponseInterface
    {
        $status = $response->getStatusCode();

        // Receipts are only valid on 2xx; an error response must not carry one.
        return $status >= 200 && $status < 300
            ? $response->withHeader(Receipt::HEADER, $receipt->toHeader())
            : $response;
    }
}
