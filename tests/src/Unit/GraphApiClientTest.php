<?php

namespace Drupal\Tests\sharepoint_media\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sharepoint_media\Service\GraphApiClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for GraphApiClient service.
 *
 * @group sharepoint_media
 * @coversDefaultClass \Drupal\sharepoint_media\Service\GraphApiClient
 */
class GraphApiClientTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $configFactory;

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
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerFactory;

  /**
   * The Graph API client under test.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient
   */
  protected $graphClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->cache = $this->prophesize(CacheBackendInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);

    // Mock logger channel.
    $logger = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory->get('sharepoint_media')->willReturn($logger->reveal());

    $this->graphClient = new GraphApiClient(
      $this->configFactory->reveal(),
      $this->httpClient->reveal(),
      $this->cache->reveal(),
      $this->loggerFactory->reveal()
    );
  }

  /**
   * Tests successful access token retrieval.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenSuccess() {
    // Mock configuration.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('test-tenant-id');
    $config->get('client_id')->willReturn('test-client-id');
    $config->get('client_secret')->willReturn('test-client-secret');
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cache miss.
    $this->cache->get('sharepoint_media:access_token')->willReturn(FALSE);

    // Mock successful token response.
    $tokenResponse = new Response(200, [], json_encode([
      'access_token' => 'test-access-token',
      'expires_in' => 3600,
    ]));

    $this->httpClient->post('https://login.microsoftonline.com/test-tenant-id/oauth2/v2.0/token', [
      'form_params' => [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
      ],
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
    ])->willReturn($tokenResponse);

    // Mock cache set.
    $this->cache->set('sharepoint_media:access_token', 'test-access-token', \Prophecy\Argument::type('int'))
      ->shouldBeCalled();

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->graphClient);
    $method = $reflection->getMethod('getAccessToken');
    $method->setAccessible(TRUE);

    $token = $method->invoke($this->graphClient);
    $this->assertEquals('test-access-token', $token);
  }

  /**
   * Tests access token retrieval failure.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenFailure() {
    // Mock configuration.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('');
    $config->get('client_id')->willReturn('');
    $config->get('client_secret')->willReturn('');
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cache miss.
    $this->cache->get('sharepoint_media:access_token')->willReturn(FALSE);

    $reflection = new \ReflectionClass($this->graphClient);
    $method = $reflection->getMethod('getAccessToken');
    $method->setAccessible(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('SharePoint Media module is not properly configured');

    $method->invoke($this->graphClient);
  }

  /**
   * Tests successful drive item retrieval.
   *
   * @covers ::getDriveItem
   */
  public function testGetDriveItemSuccess() {
    $driveItemId = 'test-drive-item-id';
    $driveItemData = [
      'id' => $driveItemId,
      'name' => 'test-file.jpg',
      'size' => 1024,
    ];

    // Mock cache miss.
    $this->cache->get("sharepoint_media:drive_item:{$driveItemId}")
      ->willReturn(FALSE);

    // Mock configuration for access token.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('test-tenant-id');
    $config->get('client_id')->willReturn('test-client-id');
    $config->get('client_secret')->willReturn('test-client-secret');
    $config->get('debug_mode')->willReturn(FALSE);
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cached access token.
    $tokenCache = (object) [
      'data' => 'test-access-token',
      'expire' => time() + 3600,
    ];
    $this->cache->get('sharepoint_media:access_token')->willReturn($tokenCache);

    // Mock successful API response.
    $apiResponse = new Response(200, [], json_encode($driveItemData));
    $this->httpClient->request('GET', "https://graph.microsoft.com/v1.0/drives/items/{$driveItemId}", \Prophecy\Argument::any())
      ->willReturn($apiResponse);

    // Mock cache set.
    $this->cache->set("sharepoint_media:drive_item:{$driveItemId}", $driveItemData, \Prophecy\Argument::type('int'))
      ->shouldBeCalled();

    $result = $this->graphClient->getDriveItem($driveItemId);
    $this->assertEquals($driveItemData, $result);
  }

  /**
   * Tests drive item retrieval with API error.
   *
   * @covers ::getDriveItem
   */
  public function testGetDriveItemFailure() {
    $driveItemId = 'test-drive-item-id';

    // Mock cache miss.
    $this->cache->get("sharepoint_media:drive_item:{$driveItemId}")
      ->willReturn(FALSE);

    // Mock configuration for access token.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('test-tenant-id');
    $config->get('client_id')->willReturn('test-client-id');
    $config->get('client_secret')->willReturn('test-client-secret');
    $config->get('debug_mode')->willReturn(FALSE);
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cached access token.
    $tokenCache = (object) [
      'data' => 'test-access-token',
      'expire' => time() + 3600,
    ];
    $this->cache->get('sharepoint_media:access_token')->willReturn($tokenCache);

    // Mock API exception.
    $this->httpClient->request('GET', "https://graph.microsoft.com/v1.0/drives/items/{$driveItemId}", \Prophecy\Argument::any())
      ->willThrow(new ClientException('Not found', $this->prophesize(\Psr\Http\Message\RequestInterface::class)->reveal()));

    $result = $this->graphClient->getDriveItem($driveItemId);
    $this->assertNull($result);
  }

  /**
   * Tests connection test success.
   *
   * @covers ::testConnection
   */
  public function testConnectionSuccess() {
    // Mock configuration.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('test-tenant-id');
    $config->get('client_id')->willReturn('test-client-id');
    $config->get('client_secret')->willReturn('test-client-secret');
    $config->get('debug_mode')->willReturn(FALSE);
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cached access token.
    $tokenCache = (object) [
      'data' => 'test-access-token',
      'expire' => time() + 3600,
    ];
    $this->cache->get('sharepoint_media:access_token')->willReturn($tokenCache);

    // Mock successful API response.
    $apiResponse = new Response(200, [], json_encode(['id' => 'test-drive-id']));
    $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/me/drive', \Prophecy\Argument::any())
      ->willReturn($apiResponse);

    $result = $this->graphClient->testConnection();
    $this->assertTrue($result);
  }

  /**
   * Tests connection test failure.
   *
   * @covers ::testConnection
   */
  public function testConnectionFailure() {
    // Mock configuration.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('tenant_id')->willReturn('test-tenant-id');
    $config->get('client_id')->willReturn('test-client-id');
    $config->get('client_secret')->willReturn('test-client-secret');
    $config->get('debug_mode')->willReturn(FALSE);
    $this->configFactory->get('sharepoint_media.settings')->willReturn($config->reveal());

    // Mock cached access token.
    $tokenCache = (object) [
      'data' => 'test-access-token',
      'expire' => time() + 3600,
    ];
    $this->cache->get('sharepoint_media:access_token')->willReturn($tokenCache);

    // Mock API exception.
    $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/me/drive', \Prophecy\Argument::any())
      ->willThrow(new ClientException('Unauthorized', $this->prophesize(\Psr\Http\Message\RequestInterface::class)->reveal()));

    $result = $this->graphClient->testConnection();
    $this->assertFalse($result);
  }

  /**
   * Tests cached access token retrieval.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenFromCache() {
    // Mock cached access token.
    $tokenCache = (object) [
      'data' => 'cached-access-token',
      'expire' => time() + 3600,
    ];
    $this->cache->get('sharepoint_media:access_token')->willReturn($tokenCache);

    // HTTP client should not be called.
    $this->httpClient->post(\Prophecy\Argument::any(), \Prophecy\Argument::any())
      ->shouldNotBeCalled();

    $reflection = new \ReflectionClass($this->graphClient);
    $method = $reflection->getMethod('getAccessToken');
    $method->setAccessible(TRUE);

    $token = $method->invoke($this->graphClient);
    $this->assertEquals('cached-access-token', $token);
  }

}
