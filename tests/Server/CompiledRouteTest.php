<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\CompiledRoute;
use PHPUnit\Framework\TestCase;

final class CompiledRouteTest extends TestCase
{
    public function testMatchesStaticPath(): void
    {
        $route = new CompiledRoute('/users', fn() => null);
        $this->assertSame([], $route->match('/users'));
        $this->assertNull($route->match('/users/15'));
    }

    public function testMatchesSingleParam(): void
    {
        $route = new CompiledRoute('/users/{id}', fn() => null);
        $this->assertSame(['id' => '15'], $route->match('/users/15'));
        $this->assertNull($route->match('/users'));
        $this->assertNull($route->match('/users/15/edit'));
    }

    public function testMatchesMultipleParams(): void
    {
        $route = new CompiledRoute('/{a}/{b}', fn() => null);
        $this->assertSame(['a' => 'foo', 'b' => 'bar'], $route->match('/foo/bar'));
    }

    public function testEscapesRegexMetacharactersInLiteralSegments(): void
    {
        // A literal segment containing regex-special characters must be
        // matched literally, not interpreted as a pattern.
        $route = new CompiledRoute('/a.b+c/{id}', fn() => null);
        $this->assertSame(['id' => '1'], $route->match('/a.b+c/1'));
        $this->assertNull($route->match('/aXbYc/1'), 'dot/plus must not act as regex wildcards');
    }

    public function testParamDoesNotCrossSlashBoundary(): void
    {
        $route = new CompiledRoute('/users/{id}', fn() => null);
        $this->assertNull($route->match('/users/15/nested'));
    }

    public function testHandlerIsPreserved(): void
    {
        $handler = fn() => 'called';
        $route = new CompiledRoute('/x', $handler);
        $this->assertSame($handler, $route->getHandler());
    }
}
