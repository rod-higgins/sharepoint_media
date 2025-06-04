# SharePoint Media Integration for Drupal

[![Drupal](https://img.shields.io/badge/Drupal-10.3%2B%20%7C%2011.x-blue.svg)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0-green.svg)](LICENSE)

A comprehensive Drupal module that integrates SharePoint drives as media sources, providing powerful search capabilities and metadata extraction without storing files locally.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security](#security)
- [Performance](#performance)
- [Development](#development)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

## Features

### 🚀 Core Functionality
- **SharePoint Integration**: Direct integration with Microsoft SharePoint via Graph API
- **Virtual Media Storage**: Reference SharePoint files without local storage
- **Rich Metadata Extraction**: Automatic extraction of EXIF, video, audio, and document metadata
- **Powerful Search**: Full-text search with faceted filtering using Search API PostgreSQL
- **Streaming Support**: Direct streaming of videos and audio files from SharePoint
- **Thumbnail Generation**: Automatic thumbnail caching and generation
- **Secure Credentials**: Integration with Drupal Key module for secure credential storage

### 📊 Advanced Search Features
- **Faceted Search**: Filter by file type, size, resolution, creation date, and more
- **Full-text Search**: Search file names, paths, metadata, and auto-generated tags
- **Smart Categorization**: Automatic file type and quality scoring
- **Search Preprocessing**: Enhanced keyword extraction and technical specifications indexing

### 🔄 Synchronization
- **Automatic Sync**: Scheduled synchronization via Drupal cron
- **Manual Sync**: On-demand synchronization with progress tracking
- **Selective Sync**: Configure specific drives and folders to sync
- **Incremental Updates**: Only sync changed or new files

### 🎯 Media Management
- **Multiple View Modes**: Grid and list views for search results
- **Bulk Operations**: Mass download, tagging, and management
- **Download Proxy**: Secure file downloads through Drupal
- **URL Management**: Automatic refresh of expired SharePoint URLs

## Requirements

### System Requirements
- **Drupal**: 10.3+ or 11.x
- **PHP**: 8.1+ with extensions:
  - `curl`
  - `json`
  - `openssl`
  - `gd` or `imagick` (for thumbnail processing)
- **Database**: PostgreSQL 12+ (recommended for Search API)
- **Memory**: 256MB+ (512MB recommended for sync operations)
- **Disk Space**: Minimal (only thumbnails are cached locally)

### Required Drupal Modules
- **Core modules**: Media, Field, Image, File, DateTime
- **Contributed modules**:
  - [Search API](https://www.drupal.org/project/search_api)
  - [Search API PostgreSQL](https://www.drupal.org/project/search_api_postgresql)
  - [Key](https://www.drupal.org/project/key) (recommended for secure credential storage)

### Microsoft 365 Requirements
- Microsoft 365 subscription with SharePoint
- Azure AD app registration with appropriate permissions
- SharePoint drive access with read permissions

## Installation

### 1. Install Dependencies

```bash
# Install required modules
composer require drupal/search_api drupal/search_api_postgresql drupal/key

# Enable modules
drush en search_api search_api_postgresql key
```

### 2. Install SharePoint Media Module

```bash
# Using Composer (recommended)
composer require drupal/sharepoint_media

# Or download and extract manually
cd modules/custom/
wget https://github.com/your-repo/sharepoint_media/archive/main.zip
unzip main.zip
mv sharepoint_media-main sharepoint_media
```

### 3. Enable the Module

```bash
drush en sharepoint_media
```

Or enable via the Drupal admin interface at `/admin/modules`.

### 4. Configure Database (PostgreSQL recommended)

```sql
-- Optimize PostgreSQL for search performance
CREATE INDEX idx_sharepoint_media_type ON media__field_mime_type (field_mime_type_value);
CREATE INDEX idx_sharepoint_media_size ON media__field_file_size (field_file_size_value);
CREATE INDEX idx_sharepoint_media_date ON media__field_created_date (field_created_date_value);
```

### 5. Configure Search API

1. Navigate to `/admin/config/search/search-api`
2. Create a PostgreSQL server configuration
3. The module will automatically create the "SharePoint Media Index"
4. Enable and configure the index

## Configuration

### 1. Azure AD App Registration

#### Create Application
1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **Azure Active Directory** > **App registrations**
3. Click **New registration**
4. Configure:
   - **Name**: "Drupal SharePoint Media Integration"
   - **Supported account types**: Accounts in this organizational directory only
   - **Redirect URI**: Not required for this integration

#### Get Credentials
After creation, note down:
- **Application (client) ID**
- **Directory (tenant) ID**

#### Create Client Secret
1. Go to **Certificates & secrets**
2. Create a new client secret
3. **Important**: Copy the secret value immediately (it won't be shown again)

#### Set API Permissions
1. Go to **API permissions**
2. Add the following Microsoft Graph permissions:
   ```
   Files.Read.All (Application)
   Sites.Read.All (Application)
   Directory.Read.All (Application)
   ```
3. **Grant admin consent** for your organization

### 2. Secure Credential Storage (Recommended)

For enhanced security, use the Key module to store Azure credentials:

#### Create Keys
1. Navigate to `/admin/config/system/keys`
2. Create three keys:
   - **Azure Tenant ID**: Store your tenant ID
   - **Azure Client ID**: Store your client ID  
   - **Azure Client Secret**: Store your client secret (use "Config" key provider for encrypted storage)

#### Configure Module to Use Keys
1. Navigate to `/admin/config/media/sharepoint-media`
2. In the connection section, select your created keys instead of entering values directly
3. Test the connection to verify setup

### 3. Alternative: Direct Configuration

If not using the Key module:

1. Navigate to `/admin/config/media/sharepoint-media`
2. Enter your Azure AD credentials:
   - **Tenant ID**: Your Azure AD tenant ID
   - **Client ID**: Application ID from app registration
   - **Client Secret**: Client secret created above

### 4. Sync Configuration

Configure synchronization settings:

1. **Automatic Sync**: Enable/disable scheduled sync
2. **Sync Interval**: How often to run automatic sync
3. **Batch Size**: Number of items to process per batch
4. **File Filters**: Restrict sync to specific file types
5. **Size Limits**: Set maximum file size for sync

### 5. Select SharePoint Drives

1. Click **Test Connection** to verify setup
2. The interface will load available drives
3. Select drives and folders to sync
4. Configure per-drive options

## Usage

### Searching Media

1. Navigate to `/admin/content/sharepoint-media/search`
2. Use the search interface to:
   - Enter keywords
   - Apply filters (file type, size, date, etc.)
   - Switch between grid and list views
   - Sort results by various criteria

### Manual Synchronization

1. Go to `/admin/content/sharepoint-media/sync`
2. Configure sync options:
   - Select drives and folders
   - Choose file type filters
   - Set batch size
3. Start synchronization
4. Monitor progress in real-time

### Streaming and Downloads

- **View/Play**: Click the view/play button for images, videos, and audio
- **Download**: Use the download button for direct file downloads
- **SharePoint**: Open files directly in SharePoint

### Managing Media

- **Bulk Operations**: Select multiple items for bulk actions
- **Tagging**: Add tags to media items for better organization
- **Metadata**: View detailed metadata in the info modal

## Security

### Access Control

The module implements several permission levels:

```yaml
# User permissions
administer sharepoint media: Full administrative access
access sharepoint media: View and download files
search sharepoint media: Use search functionality
sync sharepoint media: Trigger manual sync operations
manage sharepoint media urls: Refresh and manage URLs
view sharepoint media metadata: View detailed metadata
stream sharepoint media: Stream audio/video files
download sharepoint media: Download files via proxy
```

### Data Privacy

- **No Local Storage**: File content is never stored locally
- **Metadata Caching**: Only metadata is cached with configurable expiration
- **Proxied Downloads**: Download URLs are proxied through Drupal for access control
- **HTTPS Only**: All API communications use encrypted connections
- **Secure Credentials**: Use Key module for encrypted credential storage

### Best Practices

1. **Principle of Least Privilege**: Use minimal required Graph API permissions
2. **Regular Rotation**: Rotate client secrets every 6-12 months
3. **Access Monitoring**: Monitor file access patterns in logs
4. **Cache Management**: Regularly clear sensitive caches
5. **Network Security**: Ensure server-to-server communications are secure

## Performance

### Optimization Strategies

#### For Large Libraries (10,000+ files)
- Use selective sync with folder filtering
- Increase batch size for initial sync (100-200 items)
- Enable automatic URL refresh
- Use Drupal Queue for large operations
- Consider staggered sync schedules

#### Caching Configuration
```yaml
# Recommended cache settings
thumbnail_cache_duration: 24    # Hours
url_cache_duration: 50         # Minutes (URLs expire after ~1 hour)
metadata_cache_duration: 3600  # Seconds
```

#### Database Optimization
```sql
-- Additional PostgreSQL indexes for better performance
CREATE INDEX idx_sharepoint_drive_item ON media__field_sharepoint_drive_item_id (field_sharepoint_drive_item_id_value);
CREATE INDEX idx_sharepoint_path ON media__field_sharepoint_path (field_sharepoint_path_value);
CREATE INDEX CONCURRENTLY idx_sharepoint_search ON media__field_auto_tags USING gin(to_tsvector('english', field_auto_tags_value));
```

### Monitoring

Track performance metrics:

- Sync operation duration
- API response times
- Cache hit rates
- Memory usage during sync
- Database query performance

## Development

### Module Architecture

```
sharepoint_media/
├── config/
│   ├── install/           # Default configuration
│   └── schema/           # Configuration schema
├── css/                  # Stylesheets
├── js/                   # JavaScript files
├── src/
│   ├── Controller/       # Route controllers
│   ├── Form/            # Form classes
│   ├── Plugin/          # Plugin implementations
│   │   ├── media/Source/ # Media source plugin
│   │   └── search_api/   # Search API processors
│   └── Service/         # Service classes
│       ├── GraphApiClient.php
│       ├── SharePointSyncService.php
│       ├── DownloadUrlManager.php
│       └── MetadataExtractor.php
├── templates/           # Twig templates
├── tests/              # PHPUnit tests
│   ├── src/Unit/       # Unit tests
│   ├── src/Kernel/     # Kernel tests
│   └── src/Functional/ # Functional tests
└── README.md
```

### API Usage

#### GraphApiClient Service
```php
// Get the Graph API client
$graph_client = \Drupal::service('sharepoint_media.graph_client');

// Get drive item
$drive_item = $graph_client->getDriveItem($drive_item_id);

// Search drive items
$results = $graph_client->searchDriveItems($query, $options);

// Test connection
$is_connected = $graph_client->testConnection();
```

#### Sync Service
```php
// Get the sync service
$sync_service = \Drupal::service('sharepoint_media.sync_service');

// Sync a specific drive
$results = $sync_service->syncDriveItems($drive_id, $folder_path, $options);

// Get sync statistics
$stats = $sync_service->getSyncStatistics();

// Refresh expired URLs
$sync_service->refreshExpiredUrls();
```

#### Download URL Manager
```php
// Get the URL manager
$url_manager = \Drupal::service('sharepoint_media.url_manager');

// Get fresh download URL
$download_url = $url_manager->getFreshDownloadUrl($drive_item_id);

// Get multiple URLs efficiently
$urls = $url_manager->getMultipleDownloadUrls($drive_item_ids);

// Check if URL is expired
$is_expired = $url_manager->isUrlExpired($download_url);
```

### Hooks

#### hook_sharepoint_media_sync_alter()
```php
/**
 * Alter sync options before processing.
 */
function mymodule_sharepoint_media_sync_alter(array &$options, $drive_id, $folder_path) {
  // Modify sync options
  $options['batch_size'] = 25;
  $options['file_types'] = ['image/', 'video/'];
}
```

#### hook_sharepoint_media_metadata_alter()
```php
/**
 * Alter extracted metadata before saving.
 */
function mymodule_sharepoint_media_metadata_alter(array &$metadata, array $drive_item, MediaInterface $media) {
  // Add custom metadata processing
  $metadata['custom_field'] = 'custom_value';
}
```

## Testing

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit modules/custom/sharepoint_media/tests/

# Run specific test types
./vendor/bin/phpunit modules/custom/sharepoint_media/tests/src/Unit/
./vendor/bin/phpunit modules/custom/sharepoint_media/tests/src/Kernel/
./vendor/bin/phpunit modules/custom/sharepoint_media/tests/src/Functional/

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage modules/custom/sharepoint_media/tests/
```

### Test Categories

- **Unit Tests**: Test individual service classes and methods
- **Kernel Tests**: Test module configuration and entity operations
- **Functional Tests**: Test complete user workflows and forms

### Continuous Integration

Example GitHub Actions workflow:

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:13
        env:
          POSTGRES_PASSWORD: drupal
          POSTGRES_DB: drupal
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: ./vendor/bin/phpunit modules/custom/sharepoint_media/tests/
```

## Troubleshooting

### Common Issues

#### Authentication Errors
**Issue**: "Failed to get access token"  
**Solutions**:
- Verify Azure AD app registration is correct
- Check that tenant ID, client ID, and client secret are valid
- Ensure API permissions are granted and admin consent is provided
- Check that the Azure AD app is not expired

#### Sync Failures
**Issue**: "Sync operation failed"  
**Solutions**:
- Verify SharePoint permissions (Files.Read.All required)
- Check network connectivity to graph.microsoft.com
- Increase memory limit for PHP if processing large files
- Check SharePoint drive accessibility

#### Search Issues
**Issue**: "No search results" or "Search index not working"  
**Solutions**:
- Verify Search API PostgreSQL configuration
- Check that the search index is enabled and configured
- Run index rebuild: `drush search-api:rebuild-tracker`
- Verify field mappings in search index

#### Performance Issues
**Issue**: "Slow sync or search operations"  
**Solutions**:
- Optimize batch sizes (50-100 for sync, 20-50 for search)
- Enable caching for metadata and URLs
- Add database indexes for frequently queried fields
- Monitor PHP memory usage and increase if needed

### Debug Mode

Enable detailed logging:

1. Go to module settings
2. Enable "Debug mode"
3. Check logs at `/admin/reports/dblog`
4. Filter by "sharepoint_media" type

### Log Analysis

```bash
# Watch real-time logs
tail -f /var/log/drupal/drupal.log | grep sharepoint_media

# Check sync operation logs
drush watchdog:show --type=sharepoint_media --severity=error

# View recent search operations
drush watchdog:show --type=search_api --count=50
```

### Performance Monitoring

```bash
# Check cache performance
drush cache:get sharepoint_media

# Monitor sync statistics
drush eval "print_r(\Drupal::service('sharepoint_media.sync_service')->getSyncStatistics());"

# Check URL cache status
drush eval "print_r(\Drupal::service('sharepoint_media.url_manager')->getCacheStats());"
```

## Contributing

### Getting Started

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Follow Drupal coding standards
4. Add tests for new functionality
5. Update documentation as needed
6. Submit a pull request

### Coding Standards

```bash
# Check coding standards
./vendor/bin/phpcs --standard=Drupal modules/custom/sharepoint_media/

# Fix coding standards automatically
./vendor/bin/phpcbf --standard=Drupal modules/custom/sharepoint_media/

# Run static analysis
./vendor/bin/phpstan analyse modules/custom/sharepoint_media/
```

### Submitting Issues

When reporting issues, please include:

- Drupal version
- PHP version
- Module version
- Steps to reproduce
- Error messages or logs
- Expected vs actual behavior

## Support

### Documentation
- [Microsoft Graph API Documentation](https://docs.microsoft.com/en-us/graph/)
- [Drupal Media API](https://www.drupal.org/docs/core-modules-and-themes/core-modules/media-module)
- [Search API Documentation](https://www.drupal.org/docs/contributed-modules/search-api)
- [Key Module Documentation](https://www.drupal.org/docs/contributed-modules/key)

### Community
- [Issue Queue](https://drupal.org/project/issues/sharepoint_media)
- [Drupal Slack #media channel](https://drupal.slack.com/channels/media)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/drupal+sharepoint)

### Commercial Support
For enterprise support, custom development, or consulting services, contact the module maintainers.

## License

This module is licensed under the GNU General Public License v2.0 or later.  
See the [LICENSE](LICENSE) file for details.

## Changelog

### 1.0.0
- Initial release
- SharePoint integration via Graph API
- Search functionality with PostgreSQL backend
- Automatic metadata extraction
- Streaming and download capabilities
- Administrative interface
- Integration with Drupal Key module for secure credential storage
- Comprehensive PHPUnit test suite
- Enhanced error handling and logging
- Better performance optimization for large libraries
- Various bug fixes and stability improvements

---

**Maintainers**: Rod Higgins 
**Last Updated**: 4th June 2025  
**Drupal.org Project**: TBA

## Quick Start Checklist

- [ ] Install required modules (Search API, Key)
- [ ] Create Azure AD app registration
- [ ] Configure API permissions and admin consent
- [ ] Store credentials securely using Key module
- [ ] Enable SharePoint Media module
- [ ] Configure sync settings
- [ ] Test connection and run initial sync
- [ ] Configure search index
- [ ] Set up user permissions
- [ ] Test search and media access

For detailed instructions, see the [Configuration](#configuration) section above.