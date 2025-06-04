# SharePoint Media Integration for Drupal

A comprehensive Drupal module that integrates SharePoint drives as media sources, providing powerful search capabilities and metadata extraction without storing files locally.

## Features

### 🚀 **Core Functionality**
- **SharePoint Integration**: Direct integration with Microsoft SharePoint via Graph API
- **Virtual Media Storage**: Reference SharePoint files without local storage
- **Rich Metadata Extraction**: Automatic extraction of EXIF, video, audio, and document metadata
- **Powerful Search**: Full-text search with faceted filtering using Search API PostgreSQL
- **Streaming Support**: Direct streaming of videos and audio files from SharePoint
- **Thumbnail Generation**: Automatic thumbnail caching and generation

### 📊 **Advanced Search Features**
- **Faceted Search**: Filter by file type, size, resolution, creation date, and more
- **Full-text Search**: Search file names, paths, metadata, and auto-generated tags
- **Smart Categorization**: Automatic file type and quality scoring
- **Search Preprocessing**: Enhanced keyword extraction and technical specifications indexing

### 🔄 **Synchronization**
- **Automatic Sync**: Scheduled synchronization via Drupal cron
- **Manual Sync**: On-demand synchronization with progress tracking
- **Selective Sync**: Configure specific drives and folders to sync
- **Incremental Updates**: Only sync changed or new files

### 🎯 **Media Management**
- **Multiple View Modes**: Grid and list views for search results
- **Bulk Operations**: Mass download, tagging, and management
- **Download Proxy**: Secure file downloads through Drupal
- **URL Management**: Automatic refresh of expired SharePoint URLs

## Requirements

### System Requirements
- **Drupal**: 10.3+ or 11.x
- **PHP**: 8.1+
- **Database**: PostgreSQL (recommended for Search API)
- **Memory**: 256MB+ (512MB recommended for sync operations)

### Required Drupal Modules
- Media
- Field
- Search API
- Search API PostgreSQL
- HTTP Client Manager (or similar for Graph API calls)

### Microsoft 365 Requirements
- Microsoft 365 subscription with SharePoint
- Azure AD app registration with appropriate permissions
- SharePoint drive access

## Installation

### 1. Install the Module

```bash
# Using Composer (recommended)
composer require drupal/sharepoint_media

# Or download and extract to modules/custom/sharepoint_media
```

### 2. Enable the Module

```bash
drush en sharepoint_media
```

Or enable via the Drupal admin interface at `/admin/modules`.

### 3. Configure Search API

1. Install and configure Search API PostgreSQL
2. Create a PostgreSQL server at `/admin/config/search/search-api/add-server`
3. The module will automatically create the "SharePoint Media Index"

## Configuration

### 1. Azure AD App Registration

1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **Azure Active Directory** > **App registrations**
3. Click **New registration**
4. Configure:
   - **Name**: "Drupal SharePoint Media Integration"
   - **Supported account types**: Accounts in this organizational directory only
   - **Redirect URI**: Not required for this integration

5. After creation, note down:
   - **Application (client) ID**
   - **Directory (tenant) ID**

6. Go to **Certificates & secrets**
7. Create a new client secret and note it down

8. Go to **API permissions**
9. Add the following Microsoft Graph permissions:
   - `Files.Read.All` (Application)
   - `Sites.Read.All` (Application)
   - `Directory.Read.All` (Application)

10. Grant admin consent for your organization

### 2. Module Configuration

1. Navigate to `/admin/config/media/sharepoint-media`
2. Enter your Azure AD credentials:
   - **Tenant ID**: Your Azure AD tenant ID
   - **Client ID**: Application ID from app registration
   - **Client Secret**: Client secret created above

3. Click **Test Connection** to verify setup

4. Configure sync settings:
   - Enable automatic sync if desired
   - Set sync interval
   - Configure file type filters
   - Set maximum file size limits

5. Select SharePoint drives to sync

### 3. Search Configuration

The module automatically configures the search index, but you can customize:

1. Go to `/admin/config/search/search-api/index/sharepoint_media`
2. Adjust field weights and settings
3. Configure additional processors if needed
4. Index existing content

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

## API Reference

### Services

#### SharePointSyncService
```php
// Get the sync service
$sync_service = \Drupal::service('sharepoint_media.sync_service');

// Sync a specific drive
$results = $sync_service->syncDriveItems($drive_id, $folder_path, $options);

// Get sync statistics
$stats = $sync_service->getSyncStatistics();
```

#### GraphApiClient
```php
// Get the Graph API client
$graph_client = \Drupal::service('sharepoint_media.graph_client');

// Get drive item
$drive_item = $graph_client->getDriveItem($drive_item_id);

// Search drive items
$results = $graph_client->searchDriveItems($query, $options);
```

#### DownloadUrlManager
```php
// Get the URL manager
$url_manager = \Drupal::service('sharepoint_media.url_manager');

// Get fresh download URL
$download_url = $url_manager->getFreshDownloadUrl($drive_item_id);

// Get multiple URLs efficiently
$urls = $url_manager->getMultipleDownloadUrls($drive_item_ids);
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

## Theming

### Templates

The module provides Twig templates that can be overridden:

- `sharepoint-media-search-results.html.twig`: Search results page
- `sharepoint-media-item.html.twig`: Individual media item display
- `sharepoint-media-thumbnail.html.twig`: Thumbnail display

### CSS Classes

Key CSS classes for styling:

- `.sharepoint-media-search-results`: Main search results container
- `.media-grid`: Media items grid
- `.media-item`: Individual media item
- `.media-thumbnail`: Thumbnail container
- `.media-details`: Media metadata and actions
- `.search-facets`: Faceted search sidebar

### JavaScript Events

The module triggers custom JavaScript events:

```javascript
// Listen for media item interactions
$(document).on('sharepoint_media:item_viewed', function(event, mediaId) {
  // Handle media view
});

// Listen for search updates
$(document).on('sharepoint_media:search_updated', function(event, results) {
  // Handle search results update
});
```

## Performance Optimization

### Caching Strategy

1. **Download URLs**: Cached for 50 minutes (URLs expire after 1 hour)
2. **Metadata**: Cached for 1 hour with invalidation on changes
3. **Thumbnails**: Cached locally for 24 hours
4. **Search Results**: Cached based on Search API configuration

### Large Libraries

For SharePoint libraries with 10,000+ files:

1. Use selective sync with folder filtering
2. Increase batch size for initial sync
3. Enable automatic URL refresh
4. Consider using Drupal Queue for large operations

### Database Optimization

```sql
-- Index for better search performance
CREATE INDEX idx_sharepoint_media_type ON media__field_mime_type (field_mime_type_value);
CREATE INDEX idx_sharepoint_media_size ON media__field_file_size (field_file_size_value);
CREATE INDEX idx_sharepoint_media_date ON media__field_created_date (field_created_date_value);
```

## Troubleshooting

### Common Issues

#### Authentication Errors
- **Issue**: "Failed to get access token"
- **Solution**: Verify Azure AD app registration and permissions
- **Check**: Tenant ID, Client ID, and Client Secret are correct

#### Sync Failures
- **Issue**: "Sync operation failed"
- **Solution**: Check SharePoint permissions and drive accessibility
- **Debug**: Enable debug mode in module settings

#### Search Not Working
- **Issue**: No search results
- **Solution**: Verify Search API configuration and index status
- **Check**: PostgreSQL server configuration and field mapping

#### Performance Issues
- **Issue**: Slow sync or search
- **Solution**: Optimize batch sizes and enable caching
- **Monitor**: PHP memory usage and database performance

### Debug Mode

Enable debug mode for detailed logging:

1. Go to module settings
2. Enable "Debug mode"
3. Check logs at `/admin/reports/dblog`
4. Filter by "sharepoint_media" type

### Log Analysis

```bash
# Watch Drupal logs for SharePoint Media messages
tail -f /var/log/drupal/drupal.log | grep sharepoint_media

# Check sync operation logs
drush watchdog:show --type=sharepoint_media --severity=error
```

## Security Considerations

### Access Control

The module implements several permission levels:

- `administer sharepoint media`: Full administrative access
- `access sharepoint media`: View and download files
- `search sharepoint media`: Use search functionality
- `sync sharepoint media`: Trigger manual sync operations

### Data Privacy

- No file content is stored locally
- Metadata is cached with configurable expiration
- Download URLs are proxied through Drupal for access control
- All API communications use HTTPS

### Best Practices

1. **Limit Permissions**: Use minimal required Graph API permissions
2. **Regular Rotation**: Rotate client secrets regularly
3. **Access Logging**: Monitor file access patterns
4. **Cache Management**: Clear sensitive caches when needed

## Development

### Module Structure

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
│   └── Service/         # Service classes
├── templates/           # Twig templates
├── sharepoint_media.info.yml
├── sharepoint_media.module
├── sharepoint_media.routing.yml
├── sharepoint_media.services.yml
├── sharepoint_media.permissions.yml
└── sharepoint_media.libraries.yml
```

### Contributing

1. Fork the repository
2. Create a feature branch
3. Follow Drupal coding standards
4. Add tests for new functionality
5. Submit a pull request

### Testing

```bash
# Run PHPUnit tests
./vendor/bin/phpunit modules/custom/sharepoint_media/tests/

# Run coding standards check
./vendor/bin/phpcs --standard=Drupal modules/custom/sharepoint_media/

# Run static analysis
./vendor/bin/phpstan analyse modules/custom/sharepoint_media/
```

## Support

### Documentation
- [Microsoft Graph API Documentation](https://docs.microsoft.com/en-us/graph/)
- [Drupal Media API](https://www.drupal.org/docs/core-modules-and-themes/core-modules/media-module)
- [Search API Documentation](https://www.drupal.org/docs/contributed-modules/search-api)

### Community
- [Issue Queue](https://drupal.org/project/issues/sharepoint_media)
- [Drupal Slack #media channel](https://drupal.slack.com/channels/media)

### Commercial Support
For enterprise support and custom development, contact the module maintainers.

## License

This module is licensed under the GNU General Public License v2.0 or later.

## Changelog

### 1.0.0
- Initial release
- SharePoint integration via Graph API
- Search functionality with PostgreSQL backend
- Automatic metadata extraction
- Streaming and download capabilities
- Administrative interface

---

**Maintainers**: [Rod Higgins]  
**Last Updated**: June 2025