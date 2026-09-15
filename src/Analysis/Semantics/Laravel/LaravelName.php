<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

final class LaravelName
{
    public const FACADE_DB = 'Illuminate\\Support\\Facades\\DB';
    public const FACADE_AUTH = 'Illuminate\\Support\\Facades\\Auth';
    public const FACADE_HTTP = 'Illuminate\\Support\\Facades\\Http';
    public const FACADE_EVENT = 'Illuminate\\Support\\Facades\\Event';
    public const FACADE_ROUTE = 'Illuminate\\Support\\Facades\\Route';
    public const FACADE_BUS = 'Illuminate\\Support\\Facades\\Bus';
    public const FACADE_QUEUE = 'Illuminate\\Support\\Facades\\Queue';
    public const CONTROLLER = 'Illuminate\\Routing\\Controller';
    public const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    public const BUILDER = 'Illuminate\\Database\\Query\\Builder';
    public const ELOQUENT_BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    public const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';
    public const BUS_DISPATCHABLE = 'Illuminate\\Foundation\\Bus\\Dispatchable';
    public const EVENT_DISPATCHABLE = 'Illuminate\\Foundation\\Events\\Dispatchable';
    public const HTTP_REQUEST = 'Illuminate\\Http\\Request';
    public const GUZZLE_CLIENT = 'GuzzleHttp\\Client';
    public const HTTP_CLIENT = 'Illuminate\\Http\\Client\\PendingRequest';
    public const HTTP_FACTORY = 'Illuminate\\Http\\Client\\Factory';

    /**
     * @var array<string, string>
     */
    public const FACADES = [
        'DB' => self::FACADE_DB,
        'Auth' => self::FACADE_AUTH,
        'Http' => self::FACADE_HTTP,
        'Event' => self::FACADE_EVENT,
        'Route' => self::FACADE_ROUTE,
        'Bus' => self::FACADE_BUS,
        'Queue' => self::FACADE_QUEUE,
    ];

    public static function className(Name $name, LaravelSemanticContext $context): string
    {
        $resolved = self::resolved($name);
        $short = self::unqualified($name);
        if ($short !== null && isset(self::FACADES[$short])) {
            $facade = self::FACADES[$short];
            if ($resolved === $facade || !$context->hasClass($resolved)) {
                return $facade;
            }
        }

        return $resolved;
    }

    public static function resolved(Name $name): string
    {
        if ($name instanceof FullyQualified) {
            return $name->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name) {
            return $namespaced->toString();
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        return $name->toString();
    }

    public static function isUnqualifiedHelper(Name $name, string $helper): bool
    {
        return self::unqualified($name) === $helper;
    }

    public static function unqualified(Name $name): ?string
    {
        $original = $name->getAttribute('originalName');
        if ($original instanceof Name && $original->isUnqualified()) {
            return $original->toString();
        }

        if ($name->isUnqualified()) {
            return $name->toString();
        }

        return null;
    }
}
