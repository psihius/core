<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\GraphQl\Subscription;

/**
 * Holds current requests payload and options for a Mercure subscription for SubscriptionProcessor
 */
class MercureSubscriptionPayloadsBag
{
    private array $payloads = [];

    public function addPayload(SubscriptionPayloadBag $payload): void
    {
        $this->payloads[] = $payload;
    }

    /**
     * @return array<SubscriptionPayloadBag>
     */
    public function getPayloads(): array
    {
        return $this->payloads;
    }
}
