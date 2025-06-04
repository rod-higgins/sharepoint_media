<?php

namespace Drupal\Tests\sharepoint_media\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for SharePoint Media settings form.
 *
 * @group sharepoint_media
 */
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'sharepoint_media',
    'media',
    'field',
    'image',
    'file',
    'datetime',
    'search_api',
    'search_api_postgresql',
  ];

  /**
   * A user with permission to administer SharePoint media.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer sharepoint media',
      'access administration pages',
    ]);
  }

  /**
   * Tests the settings form loads properly.
   */
  public function testSettingsFormAccess() {
    // Anonymous users should not have access.
    $this->drupalGet('/admin/config/media/sharepoint-media');
    $this->assertSession()->statusCodeEquals(403);

    // Admin users should have access.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('SharePoint Connection');
  }

  /**
   * Tests the settings form submission.
   */
  public function testSettingsFormSubmission() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Test form submission with valid data.
    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
      'auto_sync_enabled' => TRUE,
      'auto_sync_interval' => 3600,
      'batch_size' => 50,
      'debug_mode' => FALSE,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify the settings were saved.
    $config = $this->config('sharepoint_media.settings');
    $this->assertEquals($edit['tenant_id'], $config->get('tenant_id'));
    $this->assertEquals($edit['client_id'], $config->get('client_id'));
    $this->assertEquals($edit['client_secret'], $config->get('client_secret'));
    $this->assertTrue($config->get('auto_sync_enabled'));
    $this->assertEquals(3600, $config->get('auto_sync_interval'));
  }

  /**
   * Tests form validation for invalid GUIDs.
   */
  public function testFormValidation() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Test with invalid tenant ID.
    $edit = [
      'tenant_id' => 'invalid-guid',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('Tenant ID must be a valid GUID format.');

    // Test with invalid client ID.
    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => 'invalid-guid',
      'client_secret' => 'test-client-secret',
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('Client ID must be a valid GUID format.');
  }

  /**
   * Tests conditional field visibility.
   */
  public function testConditionalFields() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Auto sync interval should be visible when auto sync is enabled.
    $this->assertSession()->fieldExists('auto_sync_interval');

    // Submit form with auto sync disabled.
    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
      'auto_sync_enabled' => FALSE,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify auto sync was disabled.
    $config = $this->config('sharepoint_media.settings');
    $this->assertFalse($config->get('auto_sync_enabled'));
  }

  /**
   * Tests file type filters.
   */
  public function testFileTypeFilters() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Test enabling specific file types.
    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
      'allowed_file_types[image/]' => 'image/',
      'allowed_file_types[video/]' => 'video/',
      'max_file_size' => 100,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify the file type filters were saved.
    $config = $this->config('sharepoint_media.settings');
    $allowed_types = $config->get('allowed_file_types');
    $this->assertContains('image/', $allowed_types);
    $this->assertContains('video/', $allowed_types);
    $this->assertEquals(100, $config->get('max_file_size'));
  }

  /**
   * Tests advanced settings.
   */
  public function testAdvancedSettings() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Expand advanced settings section.
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-advanced"]');

    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
      'debug_mode' => TRUE,
      'thumbnail_cache_duration' => 48,
      'url_cache_duration' => 30,
      'concurrent_requests' => 10,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify advanced settings were saved.
    $config = $this->config('sharepoint_media.settings');
    $this->assertTrue($config->get('debug_mode'));
    $this->assertEquals(48, $config->get('thumbnail_cache_duration'));
    $this->assertEquals(30, $config->get('url_cache_duration'));
    $this->assertEquals(10, $config->get('concurrent_requests'));
  }

  /**
   * Tests performance settings.
   */
  public function testPerformanceSettings() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    $edit = [
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-client-secret',
      'enable_search_preprocessing' => FALSE,
      'cache_metadata' => FALSE,
      'lazy_load_thumbnails' => FALSE,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify performance settings were saved.
    $config = $this->config('sharepoint_media.settings');
    $this->assertFalse($config->get('enable_search_preprocessing'));
    $this->assertFalse($config->get('cache_metadata'));
    $this->assertFalse($config->get('lazy_load_thumbnails'));
  }

}
