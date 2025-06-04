<?php

namespace Drupal\Tests\sharepoint_media\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\media\MediaInterface;
use Drupal\sharepoint_media\Service\GraphApiClient;
use Drupal\sharepoint_media\Service\SharePointSyncService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for SharePointSyncService.
 *
 * @group sharepoint_media
 * @coversDefaultClass \Drupal\sharepoint_media\Service\SharePointSyncService
 */
class SharePointSyncServiceTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $configFactory;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerFactory;

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $httpClient;

  /**
   * The mocked cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $cache;

  /**
   * The mocked Graph API client.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $graphClient;

  /**
   * The sync service under test.
   *
   * @var \Drupal\sharepoint_media\Service\SharePointSyncService
   */
  protected $syncService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->cache = $this->prophesize(CacheBackendInterface::class);
    $this->graphClient = $this->prophesize(GraphApiClient::class);

    // Mock logger channel.
    $logger = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory->get('sharepoint_media')->willReturn($logger->reveal());

    $this->syncService = new SharePointSyncService(
      $this->entityTypeManager->reveal(),
      $this->configFactory->reveal(),
      $this->loggerFactory->reveal(),
      $this->httpClient->reveal(),
      $this->cache->reveal()
    );

    $this->syncService->setGraphClient($this->graphClient->reveal());
  }

  /**
   * Tests should process item with valid file types.
   *
   * @covers ::shouldProcessItem
   * @dataProvider providerShouldProcessItem
   */
  public function testShouldProcessItem($mimeType, $fileSize, $options, $expected) {
    $driveItem = [
      'file' => [
        'mimeType' => $mimeType,
      ],
      'size' => $fileSize,
    ];

    $reflection = new \ReflectionClass($this->syncService);
    $method = $reflection->getMethod('shouldProcessItem');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->syncService, $driveItem, $options);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testShouldProcessItem.
   */
  public function providerShouldProcessItem() {
    return [
      // No filters - should allow all.
      ['image/jpeg', 1024, [], TRUE],
      ['video/mp4', 1024, [], TRUE],
      ['text/plain', 1024, [], TRUE],

      // File type filters.
      ['image/jpeg', 1024, ['file_types' => ['image/']], TRUE],
      ['image/jpeg', 1024, ['file_types' => ['video/']], FALSE],
      ['video/mp4', 1024, ['file_types' => ['image/', 'video/']], TRUE],
      ['text/plain', 1024, ['file_types' => ['image/']], FALSE],

      // File size filters.
      [
        'image/jpeg',
        1024,
        ['max_file_size' => 2048],
        TRUE,
      ],
      [
        'image/jpeg',
        3072,
        ['max_file_size' => 2048],
        FALSE,
      ],
      [
        'image/jpeg',
        1024,
        ['max_file_size' => 0],
        TRUE,
      ], // 0 means no limit

      // Combined filters.
      [
        'image/jpeg',
        1024,
        ['file_types' => ['image/'], 'max_file_size' => 2048],
        TRUE,
      ],
      [
        'video/mp4',
        1024,
        ['file_types' => ['image/'], 'max_file_size' => 2048],
        FALSE,
      ],
      [
        'image/jpeg',
        3072,
        ['file_types' => ['image/'], 'max_file_size' => 2048],
        FALSE,
      ],
    ];
  }

  /**
   * Tests map drive item to media entity.
   *
   * @covers ::mapDriveItemToMedia
   */
  public function testMapDriveItemToMedia() {
    $driveItem = [
      'id' => 'test-drive-item-id',
      'name' => 'test-file.jpg',
      'size' => 1024,
      'file' => [
        'mimeType' => 'image/jpeg',
      ],
      'webUrl' => 'https://example.sharepoint.com/sites/test/Shared%20Documents/test-file.jpg',
      '@microsoft.graph.downloadUrl' => 'https://example.sharepoint.com/download-url',
      'createdBy' => [
        'user' => [
          'displayName' => 'Test User',
        ],
      ],
      'lastModifiedBy' => [
        'user' => [
          'displayName' => 'Test User 2',
        ],
      ],
      'createdDateTime' => '2024-01-01T12:00:00Z',
      'lastModifiedDateTime' => '2024-01-02T12:00:00Z',
      'thumbnails' => [
        [
          'large' => [
            'url' => 'https://example.sharepoint.com/thumbnail-url',
          ],
        ],
      ],
    ];

    $media = $this->prophesize(MediaInterface::class);

    // Set up expectations for media entity field setting.
    $media->set('name', 'test-file.jpg')->shouldBeCalled();
    $media->set('field_sharepoint_drive_item_id', 'test-drive-item-id')->shouldBeCalled();
    $media->set('field_download_url', 'https://example.sharepoint.com/download-url')->shouldBeCalled();
    $media->set('field_web_url', 'https://example.sharepoint.com/sites/test/Shared%20Documents/test-file.jpg')->shouldBeCalled();
    $media->set('field_sharepoint_path', \Prophecy\Argument::type('string'))->shouldBeCalled();
    $media->set('field_mime_type', 'image/jpeg')->shouldBeCalled();
    $media->set('field_file_size', 1024)->shouldBeCalled();
    $media->set('field_created_by', 'Test User')->shouldBeCalled();
    $media->set('field_modified_by', 'Test User 2')->shouldBeCalled();
    $media->set('field_created_date', '2024-01-01T12:00:00Z')->shouldBeCalled();
    $media->set('field_modified_date', '2024-01-02T12:00:00Z')->shouldBeCalled();
    $media->set('field_thumbnail_url', 'https://example.sharepoint.com/thumbnail-url')->shouldBeCalled();

    $reflection = new \ReflectionClass($this->syncService);
    $method = $reflection->getMethod('mapDriveItemToMedia');
    $method->setAccessible(TRUE);

    $method->invoke($this->syncService, $media->reveal(), $driveItem);
  }

  /**
   * Tests extracting SharePoint path from web URL.
   *
   * @covers ::extractPath
   * @dataProvider providerExtractPath
   */
  public function testExtractPath($webUrl, $expected) {
    $reflection = new \ReflectionClass($this->syncService);
    $method = $reflection->getMethod('extractPath');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->syncService, $webUrl);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testExtractPath.
   */
  public function providerExtractPath() {
    return [
      [
        'https://example.sharepoint.com/sites/test/Shared Documents/folder/file.jpg',
        'folder/file.jpg',
      ],
      [
        'https://example.sharepoint.com/personal/user_example_com/Documents/file.pdf',
        'file.pdf',
      ],
      [
        'https://example.sharepoint.com/_layouts/15/WopiFrame.aspx?sourcedoc=abc',
        '',
      ],
      [
        '',
        '',
      ],
    ];
  }

  /**
   * Tests getting sync statistics.
   *
   * @covers ::getSyncStatistics
   */
  public function testGetSyncStatistics() {
    // Mock media storage.
    $mediaStorage = $this->prophesize(EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('media')->willReturn($mediaStorage->reveal());

    // Mock query for total count.
    $totalQuery = $this->prophesize(QueryInterface::class);
    $totalQuery->condition('bundle', 'sharepoint_file')->willReturn($totalQuery->reveal());
    $totalQuery->accessCheck(FALSE)->willReturn($totalQuery->reveal());
    $totalQuery->count()->willReturn($totalQuery->reveal());
    $totalQuery->execute()->willReturn(150);

    // Mock queries for type counts.
    $imageQuery = $this->prophesize(QueryInterface::class);
    $imageQuery->condition('bundle', 'sharepoint_file')->willReturn($imageQuery->reveal());
    $imageQuery->condition('field_mime_type', 'image/', 'STARTS_WITH')->willReturn($imageQuery->reveal());
    $imageQuery->accessCheck(FALSE)->willReturn($imageQuery->reveal());
    $imageQuery->count()->willReturn($imageQuery->reveal());
    $imageQuery->execute()->willReturn(50);

    $videoQuery = $this->prophesize(QueryInterface::class);
    $videoQuery->condition('bundle', 'sharepoint_file')->willReturn($videoQuery->reveal());
    $videoQuery->condition('field_mime_type', 'video/', 'STARTS_WITH')->willReturn($videoQuery->reveal());
    $videoQuery->accessCheck(FALSE)->willReturn($videoQuery->reveal());
    $videoQuery->count()->willReturn($videoQuery->reveal());
    $videoQuery->execute()->willReturn(30);

    $audioQuery = $this->prophesize(QueryInterface::class);
    $audioQuery->condition('bundle', 'sharepoint_file')->willReturn($audioQuery->reveal());
    $audioQuery->condition('field_mime_type', 'audio/', 'STARTS_WITH')->willReturn($audioQuery->reveal());
    $audioQuery->accessCheck(FALSE)->willReturn($audioQuery->reveal());
    $audioQuery->count()->willReturn($audioQuery->reveal());
    $audioQuery->execute()->willReturn(20);

    $documentQuery = $this->prophesize(QueryInterface::class);
    $documentQuery->condition('bundle', 'sharepoint_file')->willReturn($documentQuery->reveal());
    $documentQuery->condition('field_mime_type', 'application/', 'STARTS_WITH')->willReturn($documentQuery->reveal());
    $documentQuery->accessCheck(FALSE)->willReturn($documentQuery->reveal());
    $documentQuery->count()->willReturn($documentQuery->reveal());
    $documentQuery->execute()->willReturn(50);

    // Mock recent updates query.
    $recentQuery = $this->prophesize(QueryInterface::class);
    $recentQuery->condition('bundle', 'sharepoint_file')->willReturn($recentQuery->reveal());
    $recentQuery->condition('changed', \Prophecy\Argument::type('int'), '>')->willReturn($recentQuery->reveal());
    $recentQuery->accessCheck(FALSE)->willReturn($recentQuery->reveal());
    $recentQuery->count()->willReturn($recentQuery->reveal());
    $recentQuery->execute()->willReturn(10);

    // Set up media storage to return the queries.
    $mediaStorage->getQuery()
      ->willReturn(
        $totalQuery->reveal(),
        $imageQuery->reveal(),
        $videoQuery->reveal(),
        $audioQuery->reveal(),
        $documentQuery->reveal(),
        $recentQuery->reveal()
      );

    $result = $this->syncService->getSyncStatistics();

    $expected = [
      'total_media' => 150,
      'by_type' => [
        'image' => 50,
        'video' => 30,
        'audio' => 20,
        'document' => 50,
      ],
      'recent_updates' => 10,
      'last_sync' => 0,
    ];

    $this->assertEquals($expected, $result);
  }

}
