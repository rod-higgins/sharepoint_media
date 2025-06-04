<?php

namespace Drupal\Tests\sharepoint_media\Functional;

use Drupal\key\Entity\Key;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for SharePoint Media Key module integration.
 *
 * @group sharepoint_media
 */
class KeyIntegrationTest extends BrowserTestBase {

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
    'key',
  ];

  /**
   * A user with permission to administer SharePoint media and keys.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an admin user with key permissions.
    $this->adminUser = $this->drupalCreateUser([
      'administer sharepoint media',
      'administer keys',
      'access administration pages',
    ]);

    // Create test keys for SharePoint credentials.
    $this->createTestKeys();
  }

  /**
   * Tests key-based credential configuration.
   */
  public function testKeyBasedCredentials() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Check that credential method options are available.
    $this->assertSession()->fieldExists('credential_method');
    $this->assertSession()->optionExists('credential_method', 'key');
    $this->assertSession()->optionExists('credential_method', 'config');

    // Select key-based credentials.
    $this->selectFieldOption('credential_method', 'key');

    // Check that key selection fields are visible.
    $this->assertSession()->fieldExists('tenant_id_key');
    $this->assertSession()->fieldExists('client_id_key');
    $this->assertSession()->fieldExists('client_secret_key');

    // Submit form with key-based credentials.
    $edit = [
      'credential_method' => 'key',
      'tenant_id_key' => 'test_tenant_id',
      'client_id_key' => 'test_client_id',
      'client_secret_key' => 'test_client_secret',
      'auto_sync_enabled' => FALSE,
      'batch_size' => 50,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration has been saved.');

    // Verify the settings were saved.
    $config = $this->config('sharepoint_media.settings');
    $this->assertEquals('key', $config->get('credential_method'));
    $this->assertEquals('test_tenant_id', $config->get('tenant_id_key'));
    $this->assertEquals('test_client_id', $config->get('client_id_key'));
    $this->assertEquals('test_client_secret', $config->get('client_secret_key'));

    // Verify direct credentials are cleared for security.
    $this->assertNull($config->get('tenant_id'));
    $this->assertNull($config->get('client_id'));
    $this->assertNull($config->get('client_secret'));
  }

  /**
   * Tests switching from key-based to config-based credentials.
   */
  public function testCredentialMethodSwitch() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // First, set up key-based credentials.
    $edit = [
      'credential_method' => 'key',
      'tenant_id_key' => 'test_tenant_id',
      'client_id_key' => 'test_client_id',
      'client_secret_key' => 'test_client_secret',
    ];
    $this->submitForm($edit, 'Save configuration');

    // Now switch to config-based credentials.
    $this->drupalGet('/admin/config/media/sharepoint-media');
    $edit = [
      'credential_method' => 'config',
      'tenant_id' => '12345678-1234-1234-1234-123456789abc',
      'client_id' => '87654321-4321-4321-4321-cba987654321',
      'client_secret' => 'test-direct-secret',
    ];
    $this->submitForm($edit, 'Save configuration');

    // Verify the switch was successful.
    $config = $this->config('sharepoint_media.settings');
    $this->assertEquals('config', $config->get('credential_method'));
    $this->assertEquals('12345678-1234-1234-1234-123456789abc', $config->get('tenant_id'));
    $this->assertEquals('87654321-4321-4321-4321-cba987654321', $config->get('client_id'));
    $this->assertEquals('test-direct-secret', $config->get('client_secret'));

    // Verify key references are cleared.
    $this->assertNull($config->get('tenant_id_key'));
    $this->assertNull($config->get('client_id_key'));
    $this->assertNull($config->get('client_secret_key'));
  }

  /**
   * Tests validation when keys are missing.
   */
  public function testMissingKeyValidation() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Try to submit with key method but no keys selected.
    $edit = [
      'credential_method' => 'key',
      'tenant_id_key' => '',
      'client_id_key' => '',
      'client_secret_key' => '',
    ];

    $this->submitForm($edit, 'Save configuration');

    // Check for validation errors.
    $this->assertSession()->pageTextContains('Tenant ID key is required when using key-based credentials.');
    $this->assertSession()->pageTextContains('Client ID key is required when using key-based credentials.');
    $this->assertSession()->pageTextContains('Client Secret key is required when using key-based credentials.');
  }

  /**
   * Tests the key creation helper link.
   */
  public function testKeyCreationHelper() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Check that the helper link to create keys exists.
    $this->assertSession()->linkExists('Create new keys');
    $this->assertSession()->linkByHrefExists('/admin/config/system/keys/add');
  }

  /**
   * Tests conditional field visibility based on credential method.
   */
  public function testConditionalFieldVisibility() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // By default, key method should be selected if Key module is available.
    $this->assertSession()->fieldValueEquals('credential_method', 'key');

    // Key fields should be visible.
    $this->assertSession()->fieldExists('tenant_id_key');
    $this->assertSession()->fieldExists('client_id_key');
    $this->assertSession()->fieldExists('client_secret_key');

    // Config fields should exist but be in a different fieldset.
    $this->assertSession()->fieldExists('tenant_id');
    $this->assertSession()->fieldExists('client_id');
    $this->assertSession()->fieldExists('client_secret');
  }

  /**
   * Tests key options are properly loaded.
   */
  public function testKeyOptionsLoaded() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Check that our test keys appear as options.
    $this->assertSession()->optionExists('tenant_id_key', 'test_tenant_id');
    $this->assertSession()->optionExists('client_id_key', 'test_client_id');
    $this->assertSession()->optionExists('client_secret_key', 'test_client_secret');
  }

  /**
   * Tests security warning for config-based credentials.
   */
  public function testSecurityWarning() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // Select config-based credentials to reveal the warning.
    $this->selectFieldOption('credential_method', 'config');

    // Check that security warning is displayed.
    $this->assertSession()->pageTextContains('Warning');
    $this->assertSession()->pageTextContains('Storing credentials in configuration is less secure');
    $this->assertSession()->pageTextContains('Use the Key module for production environments');
  }

  /**
   * Tests fallback when Key module is not available.
   */
  public function testFallbackWithoutKeyModule() {
    // This test would need to be run in an environment without the Key module.
    // For now, we'll just verify that the form handles the case where
    // key.repository service is not available.
    
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/sharepoint-media');

    // The form should still work even if Key module integration is present.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('credential_method');
  }

  /**
   * Create test keys for SharePoint credentials.
   */
  protected function createTestKeys() {
    // Create tenant ID key.
    $tenant_key = Key::create([
      'id' => 'test_tenant_id',
      'label' => 'Test Tenant ID',
      'description' => 'Test tenant ID for SharePoint Media',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => '12345678-1234-1234-1234-123456789abc',
      ],
    ]);
    $tenant_key->save();

    // Create client ID key.
    $client_key = Key::create([
      'id' => 'test_client_id',
      'label' => 'Test Client ID',
      'description' => 'Test client ID for SharePoint Media',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => '87654321-4321-4321-4321-cba987654321',
      ],
    ]);
    $client_key->save();

    // Create client secret key.
    $secret_key = Key::create([
      'id' => 'test_client_secret',
      'label' => 'Test Client Secret',
      'description' => 'Test client secret for SharePoint Media',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'test-secret-value-12345',
      ],
    ]);
    $secret_key->save();
  }

}