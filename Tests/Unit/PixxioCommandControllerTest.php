<?php
namespace Flownative\Pixxio\Tests\Unit;

use Flownative\Pixxio\AssetSource\PixxioAssetSource;
use Flownative\Pixxio\Command\PixxioCommandController;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Testcase for the command controller
 */
class PixxioCommandControllerTest extends UnitTestCase
{
    protected static $assetSourceOptions = [
        'mapping' => [
            'categoriesMaximumDepth' => 2,
            'categories' => [
                'home*' => ['asAssetCollection' => false],
                'Kunde A*' => ['asAssetCollection' => false],
                '*' => ['asAssetCollection' => true]
            ],
        ],
    ];

    /**
     * @var MockObject|PixxioAssetSource
     */
    private $mockAssetSource;

    private MockObject|PixxioCommandController $commandController;

    public function setUp(): void
    {
        $this->commandController = new PixxioCommandController();

//        $this->mockAssetSource = $this->getAccessibleMock(PixxioAssetSource::class, ['getAssetSourceOptions']);
        $this->mockAssetSource = $this->getMockBuilder(PixxioAssetSource::class)->disableOriginalConstructor()->onlyMethods(['getAssetSourceOptions'])->getMock();
        $this->mockAssetSource->method('getAssetSourceOptions')->willReturn(self::$assetSourceOptions);
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
        self::assertSame($expected, $this->commandController->shouldBeImportedAsAssetCollection($this->mockAssetSource, $category), $category);
    }
}
