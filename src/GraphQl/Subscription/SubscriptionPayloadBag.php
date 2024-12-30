<?php

declare(strict_types=1);

namespace ApiPlatform\GraphQl\Subscription;

class SubscriptionPayloadBag
{
    public function __construct(
        public readonly object $payload,
        public readonly array $options,
    ) {}
}
