<?php

namespace SocialiteProviders\Ctrader\Exceptions;

use RuntimeException;

/**
 * The cTrader token endpoint did not return a usable access token.
 *
 * Carries the provider's own OAuth error fields and the HTTP status so the
 * application can log what happened and show an actionable sentence, instead of
 * passing an empty string into the protobuf client and surfacing
 * "Cannot convert '' to string" to the user.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $oauthError = null,
        private readonly ?string $oauthErrorDescription = null,
        private readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The OAuth `error` code (for example `invalid_grant`), when one was returned.
     */
    public function getOauthError(): ?string
    {
        return $this->oauthError;
    }

    /**
     * The provider's human-readable description, when one was returned.
     */
    public function getOauthErrorDescription(): ?string
    {
        return $this->oauthErrorDescription;
    }

    /**
     * The HTTP status of the token response.
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }
}
