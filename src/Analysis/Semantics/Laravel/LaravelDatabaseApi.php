<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

final class LaravelDatabaseApi
{
    /**
     * @var list<string>
     */
    public const READ_METHODS = [
        'get',
        'first',
        'firstOrFail',
        'firstOr',
        'find',
        'findOrFail',
        'findMany',
        'findOrNew',
        'sole',
        'value',
        'pluck',
        'count',
        'exists',
        'doesntExist',
        'avg',
        'average',
        'sum',
        'min',
        'max',
        'cursor',
        'lazy',
        'paginate',
        'simplePaginate',
        'cursorPaginate',
        'all',
        'fresh',
        'refresh',
        'select',
        'selectOne',
    ];

    /**
     * @var list<string>
     */
    public const WRITE_METHODS = [
        'create',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertUsing',
        'update',
        'updateOrCreate',
        'updateOrInsert',
        'upsert',
        'delete',
        'forceDelete',
        'increment',
        'decrement',
        'save',
        'saveQuietly',
        'createMany',
        'deleteMany',
        'destroy',
        'truncate',
        'restore',
        'push',
        'firstOrCreate',
    ];

    public static function isRead(string $method): bool
    {
        return in_array($method, self::READ_METHODS, true);
    }

    public static function isWrite(string $method): bool
    {
        return in_array($method, self::WRITE_METHODS, true);
    }
}
