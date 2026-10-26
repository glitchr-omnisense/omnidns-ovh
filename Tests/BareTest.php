<?php

namespace Omnidns\Ovh\Tests;

use Omnidns\Ovh\OvhProvider;
use PHPUnit\Framework\TestCase;

/**
 * omnidns/ovh with no framework: every class of the package loaded in a PHP
 * process of its own, with nothing from outside but what it requires -
 * omnisense/omnidns, the official ovh/ovh client and what it needs (Guzzle, league/oauth2-client, the PSR interfaces): no framework, no other HTTP client, no application, even
 * in the harness where they are installed beside it.
 */
final class BareTest extends TestCase
{
    private const FOREIGN = '~^(?:Symfony\\\\(?!Contracts\\\\HttpClient\\\\|Polyfill\\\\)|(?:Illuminate|Laravel|Doctrine|Twig|Nyholm|Omnibridge|Base|App)\\\\)~';
    private const ALLOWED_FILES = '~/vendor/(?:omnisense/omnidns|ovh/ovh|guzzlehttp/[a-z-]+|league/oauth2-client|psr/[a-z-]+|ralouphie/getallheaders|symfony/deprecation-contracts|paragonie/random_compat|symfony/http-client-contracts|composer)/|/vendor/autoload\.php$|^/omnidns/(?:core|plugins/ovh)/~';

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $autoload = self::autoload();
        $process = proc_open([\PHP_BINARY, __DIR__.'/Fixtures/load-all.php', $autoload, \dirname(__DIR__)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err.$out);
        $report = json_decode($out, true);
        self::assertIsArray($report, $err.$out);
        $loaded = \is_array($report['loaded'] ?? null) ? $report['loaded'] : [];
        $symbols = \is_array($report['symbols'] ?? null) ? array_values(array_filter($report['symbols'], 'is_string')) : [];
        $files = \is_array($report['files'] ?? null) ? array_values(array_filter($report['files'], 'is_string')) : [];
        $eager = \is_array($report['eager'] ?? null) ? array_map('dirname', array_values(array_filter($report['eager'], 'is_string'))) : [];

        self::assertContains(OvhProvider::class, $loaded);
        self::assertSame([], array_values(preg_grep(self::FOREIGN, $symbols) ?: []), 'no class of a framework, another HTTP client or an application');
        $root = (string) realpath(\dirname(__DIR__)).'/';
        $outside = array_filter($files, static fn (string $file) => !\in_array(\dirname($file), $eager, true) && !preg_match(self::ALLOWED_FILES, $file) && !str_starts_with($file, $root));
        self::assertSame([], array_values($outside), 'no file but the package and what it requires');
    }

    private static function autoload(): string
    {
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if ($loader->findFile(OvhProvider::class) && $loader->findFile(self::class)) {
                return $vendor.'/autoload.php';
            }
        }
        self::markTestSkipped('No autoloader knows omnidns/ovh\'s tests.');
    }
}
