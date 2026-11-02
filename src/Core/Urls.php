<?php

namespace facilo\portero\Core;

/** The addresses a provider is told about. These paths are copied into providers, so they never change. */
final class Urls
{
    public function __construct(private readonly string $base, private readonly string $handle, private readonly ?string $legacyAcsPath = null)
    {
    }

    public function login(): string
    {
        return $this->url('login');
    }

    public function acs(): string
    {
        // A connection brought across from the old plugin keeps the address the provider already has.
        return $this->legacyAcsPath !== null ? rtrim($this->base, '/') . '/' . ltrim($this->legacyAcsPath, '/') : $this->url('saml/acs');
    }

    public function metadata(): string
    {
        return $this->url('saml/metadata');
    }

    public function slo(): string
    {
        return $this->url('saml/slo');
    }

    public function redirect(): string
    {
        return $this->url('oidc/callback');
    }

    public function logout(): string
    {
        return $this->url('oidc/logout');
    }

    public function backchannel(): string
    {
        return $this->url('oidc/backchannel');
    }

    private function url(string $path): string
    {
        return rtrim($this->base, '/') . "/portero/$path/{$this->handle}";
    }
}
