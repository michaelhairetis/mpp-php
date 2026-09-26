<?php

declare(strict_types=1);

namespace Mpp;

/**
 * Stateless challenge binding: the `id` is an HMAC over the parameters it commits to, so a server
 * can verify an echoed challenge without having stored it.
 *
 * Slots are positional and always present, so (expires set, no digest) and (no expires, digest
 * set) cannot collide. `header` is inserted only when the parameter is present, which keeps the
 * input stable for challenges issued before it existed. `description` is excluded because it is
 * display only and never used for verification.
 */
final class ChallengeBinding
{
    public function __construct(private readonly string $secret)
    {
    }

    public function issue(
        string $realm,
        string $method,
        string $intent,
        string $request,
        ?string $digest = null,
        ?string $expires = null,
        ?string $description = null,
        ?string $header = null,
        ?string $opaque = null,
    ): Challenge {
        $id = $this->sign($realm, $method, $intent, $request, $digest, $expires, $header, $opaque);

        return new Challenge(
            id: $id,
            realm: $realm,
            method: $method,
            intent: $intent,
            request: $request,
            digest: $digest,
            expires: $expires,
            description: $description,
            header: $header,
            opaque: $opaque,
        );
    }

    public function verify(Challenge $challenge): bool
    {
        $expected = $this->sign(
            $challenge->realm,
            $challenge->method,
            $challenge->intent,
            $challenge->request,
            $challenge->digest,
            $challenge->expires,
            $challenge->header,
            $challenge->opaque,
        );

        return hash_equals($expected, $challenge->id);
    }

    private function sign(
        string $realm,
        string $method,
        string $intent,
        string $request,
        ?string $digest,
        ?string $expires,
        ?string $header,
        ?string $opaque,
    ): string {
        $slots = [$realm, $method, $intent, $request, $expires ?? '', $digest ?? ''];

        if ($header !== null) {
            $slots[] = $header;
        }

        $slots[] = $opaque ?? '';

        return Base64Url::encode(hash_hmac('sha256', implode('|', $slots), $this->secret, true));
    }
}
