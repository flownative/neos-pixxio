<?php
namespace Flownative\Pixxio\Tests\Unit;

use Flownative\Pixxio\Command\PixxioCommandController;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Flow\Aop;

/**
 * Testcase for the command controller
 */
class PixxioCommandControllerTest extends UnitTestCase
{
    protected static $mapping = [
        'categoriesMaximumDepth' => 2,
        'categories' => [
            'home*' => ['asAssetCollection' => false],
            'Kunde A*' => ['asAssetCollection' => false],
            '*' => ['asAssetCollection' => true]
        ],
    ];

    public function setUp(): void
    {
        $this->mockCommandController = $this->getAccessibleMock(PixxioCommandController::class, ['dummy']);
        $this->mockCommandController->_set('mapping', self::$mapping);
    }

    public function categoriesMappingProvider(): array
    {
        return [
            '/home' => ['/home', false],
            '/home/foo' => ['/home/foo', false],
            '/Kunde A' => ['/Kunde A', false],
            '/Kunde A/Projekt 1' => ['/Kunde A/Projekt 1', false],
            '/Kunde A/Projekt 1/Design' => ['/Kunde A/Projekt 1/Design', false],
            '/Kunde A/Projekt 1/Copy' => ['/Kunde A/Projekt 1/Copy', false],
            '/Kunde B' => ['/Kunde B', true],
            '/Kunde B/Projekt 1' => ['/Kunde B/Projekt 1', true],
            '/Kunde B/Projekt 1/Design' => ['/Kunde B/Projekt 1/Design', false],
            '/Kunde B/Projekt 1/Copy' => ['/Kunde B/Projekt 1/Copy', false],
            '/Kunde B/Projekt 2' => ['/Kunde B/Projekt 2', true],
            '/Kunde B/Projekt 2/Copy' => ['/Kunde B/Projekt 2/Copy', false],
            '/Kunde B/Projekt 2/Design' => ['/Kunde B/Projekt 2/Design', false],
            '/Marketing' => ['/Marketing', true],
        ];
    }

    /**
     * @test
     * @dataProvider categoriesMappingProvider
     */
    public function shouldBeImportedAsAssetCollectionWorks(string $category, bool $expected): void
    {
        self::assertSame($expected, $this->mockCommandController->_call('shouldBeImportedAsAssetCollection', $category), $category);
    }
}
