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

use ApiPlatform\Doctrine\Common\Messenger\DispatchTrait;
use ApiPlatform\GraphQl\Resolver\Util\IdentifierTrait;
use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\GraphQl\Operation;
use ApiPlatform\Metadata\GraphQl\Subscription;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Util\ResourceClassInfoTrait;
use ApiPlatform\Metadata\Util\SortTrait;
use ApiPlatform\State\ProcessorInterface;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Manages all the queried subscriptions by creating their ID
 * and saving to a cache the information needed to publish updated data.
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class SubscriptionManager implements OperationAwareSubscriptionManagerInterface
{
    use DispatchTrait;
    use IdentifierTrait;
    use ResourceClassInfoTrait;
    use SortTrait;

    public function __construct(
        private readonly CacheItemPoolInterface                     $subscriptionsCache,
        private readonly SubscriptionIdentifierGeneratorInterface   $subscriptionIdentifierGenerator,
        private readonly ProcessorInterface                         $normalizeProcessor,
        private readonly IriConverterInterface                      $iriConverter,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        private readonly MercureSubscriptionPayloadsBag             $mercureSubscriptionPayloadsBag,
        private readonly ?MercureSubscriptionIriGeneratorInterface  $graphQlMercureSubscriptionIriGenerator = null,
        private readonly ?HubRegistry                               $hubRegistry = null,
                         ?MessageBusInterface                       $messageBus = null,
    ) {
        if (null === $messageBus && null === $hubRegistry) {
            throw new InvalidArgumentException('A message bus or a hub registry must be provided.');
        }
        $this->messageBus = $messageBus;
    }

    public function retrieveSubscriptionId(array $context, ?array $result, ?Operation $operation = null): ?string
    {
        /** @var ResolveInfo $info */
        $info = $context['info'];
        $options = $operation->getMercure() ?? false;
        $private = $options['private'] ?? false;
        $privateFields = $options['private_fields'] ?? [];
        $subjectObject = $context['graphql_context']['previous_object'] ?? null;
        if ($subjectObject === null) {
            foreach ($this->mercureSubscriptionPayloadsBag->getPayloads() as $payload) {
                if (get_class($payload->payload) === $operation->getClass()) {
                    $subjectObject = $payload->payload;
                    break;
                }
            }
        }

        $fields = $info->getFieldSelection(\PHP_INT_MAX);
        $this->arrayRecursiveSort($fields, 'ksort');
        $iri = $operation ? $this->getIdentifierFromOperation($operation, $context['args'] ?? []) : $this->getIdentifierFromContext($context);
        if ($iri === null && $private && is_object($subjectObject) && $operation instanceof Mutation) {
            $iri = $this->iriConverter->getIriFromResource($subjectObject);
        }
        if (null === $iri || empty($iri)) {
            return null;
        }

        if ($private && $privateFields && $subjectObject) {
            foreach ($options['private_fields'] as $privateField) {
                $fields['__private_field_'.$privateField] = $subjectObject->{'get'.ucfirst($privateField)}()->getId();
            }
        }

        $subscriptionsCacheItem = $this->subscriptionsCache->getItem($this->encodeIriToCacheKey($iri));
        $subscriptions = [];
        if ($subscriptionsCacheItem->isHit()) {
            $subscriptions = $subscriptionsCacheItem->get();
            foreach ($subscriptions as [$subscriptionId, $subscriptionFields, $subscriptionResult]) {
                if ($subscriptionFields === $fields) {
                    return $subscriptionId;
                }
            }
        }

        
        $subscriptionId = $this->subscriptionIdentifierGenerator->generateSubscriptionIdentifier($fields);
        unset($result['clientSubscriptionId']);
        if ($private && $privateFields && $subjectObject) {
            foreach ($options['private_fields'] as $privateField) {
                unset($result['__private_field_'.$privateField]);
            }
        }
        $subscriptions[] = [$subscriptionId, $fields, $result];
        $subscriptionsCacheItem->set($subscriptions);
        $this->subscriptionsCache->save($subscriptionsCacheItem);

        return $subscriptionId;
    }

    public function pushPayloadsToMercure(): void
    {
        foreach ($this->mercureSubscriptionPayloadsBag->getPayloads() as $payload) {
            $this->pushPayloadToMercure($payload);
        }
    }

    private function pushPayloadToMercure(SubscriptionPayloadBag $payload): void
    {
        $iri = $this->iriConverter->getIriFromResource($payload->payload);
        $subscriptions = $this->getSubscriptionsFromIri($iri);

        $resourceClass = $this->getObjectClass($payload->payload);
        $resourceMetadata = $this->resourceMetadataCollectionFactory->create($resourceClass);
        $shortName = $resourceMetadata->getOperation()->getShortName();

        $payloads = [];
        foreach ($subscriptions as [$subscriptionId, $subscriptionFields, $subscriptionResult]) {
            $resolverContext = ['fields' => $subscriptionFields, 'is_collection' => false, 'is_mutation' => false, 'is_subscription' => true];
            /** @var Operation */
            $operation = (new Subscription())->withName('update_subscription')->withShortName($shortName);
            $data = $this->normalizeProcessor->process($payload->payload, $operation, [], $resolverContext);

            unset($data['clientSubscriptionId']);

            if ($data !== $subscriptionResult) {
                $payloads[] = [$subscriptionId, $data];
            }
        }

        foreach ($payloads as [$subscriptionId, $data]) {
            $update = $this->buildUpdate(
                $this->graphQlMercureSubscriptionIriGenerator->generateTopicIri($subscriptionId),
                (string) (new JsonResponse($data))->getContent(),
                $payload->options
            );
            if ($payload->options['enable_async_update'] && $this->messageBus) {
                $this->dispatch($update);
                continue;
            }
            $this->hubRegistry->getHub($payload->options['hub'] ?? null)->publish($update);
        }
    }

    private function buildUpdate(string|array $iri, string $data, array $options): Update
    {
        return new Update($iri, $data, $options['private'] ?? false, $options['id'] ?? null, $options['type'] ?? null, $options['retry'] ?? null);
    }

    /**
     * @return array<array>
     */
    private function getSubscriptionsFromIri(string $iri): array
    {
        $subscriptionsCacheItem = $this->subscriptionsCache->getItem($this->encodeIriToCacheKey($iri));

        if ($subscriptionsCacheItem->isHit()) {
            return $subscriptionsCacheItem->get();
        }

        return [];
    }

    private function encodeIriToCacheKey(string $iri): string
    {
        return str_replace('/', '_', $iri);
    }
}
