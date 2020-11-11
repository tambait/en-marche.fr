<?php

namespace App\Entity\OAuth;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * @ORM\Entity(repositoryClass="App\Repository\OAuth\RefreshTokenRepository")
 * @ORM\Table(name="oauth_refresh_tokens", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="oauth_refresh_tokens_uuid_unique", columns="uuid"),
 *     @ORM\UniqueConstraint(name="oauth_refresh_tokens_identifier_unique", columns="identifier")
 * })
 */
class RefreshToken extends AbstractToken
{
    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\OAuth\AccessToken")
     * @ORM\JoinColumn(onDelete="CASCADE")
     */
    private $accessToken;

    public function __construct(
        UuidInterface $uuid,
        AccessToken $accessToken,
        string $identifier,
        \DateTimeImmutable $expiryDateTime
    ) {
        parent::__construct($uuid, $identifier, $expiryDateTime);

        $this->accessToken = $accessToken;
    }

    public function getAccessToken(): AccessToken
    {
        return $this->accessToken;
    }
}
