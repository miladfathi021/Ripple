<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Index;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Index\RepositoryPhpFileScanner;
use Ripple\Tests\Support\TemporaryDirectory;

final class RepositoryPhpFileScannerTest extends TestCase
{
    public function testFindsPhpFilesRecursivelyInDeterministicOrder(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('tests/Unit/PaymentServiceTest.php', "<?php\n");
        $directory->write('app/Services/PaymentService.php', "<?php\n");
        $directory->write('app/Models/Reservation.php', "<?php\n");
        $directory->write('README.md', "hello\n");

        $files = (new RepositoryPhpFileScanner())->scan($directory->path);

        $this->assertSame(
            [
                'app/Models/Reservation.php',
                'app/Services/PaymentService.php',
                'tests/Unit/PaymentServiceTest.php',
            ],
            $files,
        );
    }

    public function testIgnoresExcludedDirectories(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('app/Services/Keep.php', "<?php\n");
        $directory->write('vendor/package/Skip.php', "<?php\n");
        $directory->write('.git/hooks/Skip.php', "<?php\n");
        $directory->write('node_modules/pkg/Skip.php', "<?php\n");
        $directory->write('storage/logs/Skip.php', "<?php\n");
        $directory->write('bootstrap/cache/Skip.php', "<?php\n");
        $directory->write('bootstrap/app.php', "<?php\n");

        $files = (new RepositoryPhpFileScanner())->scan($directory->path);

        $this->assertSame(
            [
                'app/Services/Keep.php',
                'bootstrap/app.php',
            ],
            $files,
        );
    }

    public function testIncludesPhpFilesUnderTestsFixtures(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/App.php', "<?php\n");
        $directory->write('tests/Fixtures/php/Example.php', "<?php\nclass Example {}\n");
        $directory->write('tests/Unit/Keep.php', "<?php\n");

        $files = (new RepositoryPhpFileScanner())->scan($directory->path);

        $this->assertSame(
            [
                'src/App.php',
                'tests/Fixtures/php/Example.php',
                'tests/Unit/Keep.php',
            ],
            $files,
        );
    }

    public function testScansFixturePhpFilesInThisRepository(): void
    {
        $files = (new RepositoryPhpFileScanner())->scan(dirname(__DIR__, 4));

        $this->assertContains('tests/Fixtures/php/simple-class.php', $files);
        $this->assertContains('tests/Fixtures/php/namespaced-class.php', $files);
        $this->assertNotContains('tests/Fixtures/php/syntax-error.invalid', $files);
    }

    public function testIncludesUntrackedPhpFilesOnDisk(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/Tracked.php', "<?php\n");
        $directory->write('src/Untracked.php', "<?php\n");

        $this->assertSame(
            [
                'src/Tracked.php',
                'src/Untracked.php',
            ],
            (new RepositoryPhpFileScanner())->scan($directory->path),
        );
    }

    public function testDoesNotFollowSymlinkedDirectories(): void
    {
        $directory = TemporaryDirectory::create();
        $outside = TemporaryDirectory::create();
        $outside->write('Secret.php', "<?php\nclass Secret {}\n");
        $directory->write('app/Keep.php', "<?php\n");

        $link = $directory->path . '/linked';
        if (!@symlink($outside->path, $link)) {
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        $files = (new RepositoryPhpFileScanner())->scan($directory->path);

        $this->assertSame(['app/Keep.php'], $files);
        $this->assertNotContains('linked/Secret.php', $files);
    }

    public function testReturnsAnEmptyListForAMissingDirectory(): void
    {
        $this->assertSame([], (new RepositoryPhpFileScanner())->scan('/tmp/ripple-missing-' . bin2hex(random_bytes(4))));
    }
}
