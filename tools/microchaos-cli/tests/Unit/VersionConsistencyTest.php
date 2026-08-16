<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the single source of truth for the MicroChaos version.
 *
 * MC-13 was three declared versions and a README disagreeing with all of them, which
 * made it impossible to tell which build a deployed site was running. These tests make
 * that drift a test failure instead of something a reviewer has to notice.
 */
final class VersionConsistencyTest extends TestCase
{
    private const VERSION_HEADER_PATTERN = '/^\s*\*\s*Version:\s*(.+?)\s*$/m';

    private function toolPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private function headerVersion(string $relative): ?string
    {
        $contents = file_get_contents($this->toolPath($relative));
        $this->assertIsString($contents, "Could not read {$relative}");

        return preg_match(self::VERSION_HEADER_PATTERN, $contents, $matches) === 1
            ? $matches[1]
            : null;
    }

    public function test_plugin_header_declares_a_semver_version(): void
    {
        $version = $this->headerVersion('microchaos-cli.php');

        $this->assertNotNull($version, 'Plugin header has no Version line.');
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'Plugin header version must be plain semver so build.js and tags agree.'
        );
    }

    public function test_runtime_constant_matches_the_plugin_header(): void
    {
        // tests/bootstrap.php derives MICROCHAOS_VERSION the same way bootstrap.php does.
        $this->assertSame($this->headerVersion('microchaos-cli.php'), MICROCHAOS_VERSION);
    }

    public function test_built_bundle_header_matches_the_plugin_header(): void
    {
        $this->assertSame(
            $this->headerVersion('microchaos-cli.php'),
            $this->headerVersion('dist/microchaos-cli.php'),
            'dist/ is stale. Run: node build.js'
        );
    }

    public function test_built_bundle_defines_the_version_constant(): void
    {
        $bundle = file_get_contents($this->toolPath('dist/microchaos-cli.php'));

        // Match first, then compare the captured value, so a failure reports the two
        // versions rather than dumping the whole bundle into the diff.
        $found = preg_match("/define\(\s*'MICROCHAOS_VERSION',\s*'([^']+)'\s*\)/", $bundle, $matches);

        $this->assertSame(
            1,
            $found,
            'The bundle must define MICROCHAOS_VERSION, since bootstrap.php is not part of it.'
        );
        $this->assertSame(
            $this->headerVersion('microchaos-cli.php'),
            $matches[1],
            'dist/ is stale. Run: node build.js'
        );
    }

    public function test_readme_banner_matches_the_plugin_header(): void
    {
        $readme = file_get_contents($this->toolPath('README.md'));

        $this->assertSame(
            1,
            preg_match('/^v(\d+\.\d+\.\d+)\s+—/m', $readme, $matches),
            'README needs a "vX.Y.Z — name" banner line.'
        );
        $this->assertSame($this->headerVersion('microchaos-cli.php'), $matches[1]);
    }

    public function test_bundle_carries_no_build_timestamp(): void
    {
        $bundle = file_get_contents($this->toolPath('dist/microchaos-cli.php'));

        // A timestamp would make every rebuild differ, defeating the CI drift check.
        $this->assertFalse(
            str_contains($bundle, 'Generated on:'),
            'The bundle carries a build timestamp. The build must stay a pure function of the source.'
        );
    }
}
