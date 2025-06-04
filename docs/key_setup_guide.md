# SharePoint Media - Key Module Setup Guide

This guide provides detailed instructions for setting up secure credential storage using the Drupal Key module with SharePoint Media Integration.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Installing the Key Module](#installing-the-key-module)
- [Creating Azure AD Keys](#creating-azure-ad-keys)
- [Configuring SharePoint Media](#configuring-sharepoint-media)
- [Security Best Practices](#security-best-practices)
- [Key Providers](#key-providers)
- [Troubleshooting](#troubleshooting)
- [Migration from Direct Config](#migration-from-direct-config)

## Overview

The Key module provides secure storage for sensitive configuration data like API credentials. Instead of storing Azure AD credentials directly in Drupal configuration (which is exported to version control), the Key module allows you to:

- Store credentials in encrypted files
- Use environment variables
- Integrate with external key management services
- Keep sensitive data out of configuration exports

## Prerequisites

- Drupal 10.3+ or 11.x
- SharePoint Media module installed
- Azure AD app registration completed
- Administrative access to Drupal

## Installing the Key Module

### Method 1: Composer (Recommended)

```bash
# Install the Key module
composer require drupal/key

# Enable the module
drush en key

# Clear caches
drush cr
```

### Method 2: Manual Installation

1. Download the Key module from [drupal.org/project/key](https://www.drupal.org/project/key)
2. Extract to `modules/contrib/key`
3. Enable via admin interface at `/admin/modules`

## Creating Azure AD Keys

### Step 1: Navigate to Key Management

1. Go to `/admin/config/system/keys`
2. Click **"Add key"**

### Step 2: Create Tenant ID Key

1. **Key type**: Select "Authentication"
2. **Key provider**: Choose appropriate provider (see [Key Providers](#key-providers))
3. **Key settings**:
   - **Key ID**: `sharepoint_tenant_id`
   - **Label**: `SharePoint Tenant ID`
   - **Description**: `Microsoft 365 tenant ID for SharePoint Media integration`

4. **Provider settings** (varies by provider):
   - **Configuration**: Enter your tenant ID (GUID format)
   - **File**: Create secure file with tenant ID
   - **Environment**: Set environment variable

5. Click **"Save"**

### Step 3: Create Client ID Key

1. **Key type**: Select "Authentication"
2. **Key provider**: Same as tenant ID for consistency
3. **Key settings**:
   - **Key ID**: `sharepoint_client_id`
   - **Label**: `SharePoint Client ID`
   - **Description**: `Azure AD application client ID for SharePoint Media`

4. **Provider settings**: Enter your client ID (GUID format)
5. Click **"Save"**

### Step 4: Create Client Secret Key

1. **Key type**: Select "Authentication"
2. **Key provider**: Use secure provider (File or Environment recommended)
3. **Key settings**:
   - **Key ID**: `sharepoint_client_secret`
   - **Label**: `SharePoint Client Secret`
   - **Description**: `Azure AD application client secret for SharePoint Media`

4. **Provider settings**: Enter your client secret
5. Click **"Save"**

## Configuring SharePoint Media

### Step 1: Access SharePoint Media Settings

1. Navigate to `/admin/config/media/sharepoint-media`
2. Locate the "SharePoint Connection" section

### Step 2: Select Key-based Credentials

1. Under **"Credential Storage Method"**, select **"Use Key module (Recommended)"**
2. The key selection fields will appear

### Step 3: Configure Key References

1. **Tenant ID Key**: Select `sharepoint_tenant_id`
2. **Client ID Key**: Select `sharepoint_client_id`
3. **Client Secret Key**: Select `sharepoint_client_secret`

### Step 4: Test Connection

1. Click **"Test Connection"** to verify setup
2. You should see: "✓ Connection successful!"

### Step 5: Save Configuration

1. Click **"Save configuration"**
2. The system will clear caches automatically

## Security Best Practices

### File-based Keys

```bash
# Create secure directory for key files
sudo mkdir -p /etc/drupal/keys
sudo chown www-data:www-data /etc/drupal/keys
sudo chmod 700 /etc/drupal/keys

# Create key files with restricted permissions
sudo -u www-data touch /etc/drupal/keys/sharepoint_tenant_id
sudo -u www-data touch /etc/drupal/keys/sharepoint_client_id
sudo -u www-data touch /etc/drupal/keys/sharepoint_client_secret
sudo chmod 600 /etc/drupal/keys/*

# Add content to files
echo "12345678-1234-1234-1234-123456789abc" | sudo -u www-data tee /etc/drupal/keys/sharepoint_tenant_id
echo "87654321-4321-4321-4321-cba987654321" | sudo -u www-data tee /etc/drupal/keys/sharepoint_client_id
echo "your-client-secret-here" | sudo -u www-data tee /etc/drupal/keys/sharepoint_client_secret
```

### Environment Variables

```bash
# Add to your environment configuration
export SHAREPOINT_TENANT_ID="12345678-1234-1234-1234-123456789abc"
export SHAREPOINT_CLIENT_ID="87654321-4321-4321-4321-cba987654321"
export SHAREPOINT_CLIENT_SECRET="your-client-secret-here"
```

For Docker environments:

```yaml
# docker-compose.yml
services:
  drupal:
    environment:
      - SHAREPOINT_TENANT_ID=12345678-1234-1234-1234-123456789abc
      - SHAREPOINT_CLIENT_ID=87654321-4321-4321-4321-cba987654321
      - SHAREPOINT_CLIENT_SECRET=your-client-secret-here
```

### Production Recommendations

1. **Never commit keys to version control**
2. **Use file-based or environment providers in production**
3. **Regularly rotate client secrets (every 6-12 months)**
4. **Implement proper backup procedures for key files**
5. **Monitor key access in logs**
6. **Use different keys for different environments**

## Key Providers

### Configuration Provider
- **Use for**: Development/testing only
- **Security**: Low (stored in database)
- **Pros**: Easy to set up
- **Cons**: Credentials visible in configuration exports

### File Provider
- **Use for**: Production environments
- **Security**: High (if properly configured)
- **Pros**: Secure file permissions, separate from codebase
- **Cons**: Requires server file management

#### File Provider Setup
```bash
# Create key directory
mkdir /var/drupal-keys
chown www-data:www-data /var/drupal-keys
chmod 700 /var/drupal-keys

# Configure in Key module
# File path: /var/drupal-keys/sharepoint_tenant_id
# Content: Your tenant ID
```

### Environment Provider
- **Use for**: Container/cloud deployments
- **Security**: High (if environment is secure)
- **Pros**: Easy container integration
- **Cons**: Environment must be secured

#### Environment Provider Setup
```bash
# Set environment variables
export DRUPAL_KEY_SHAREPOINT_TENANT_ID="your-tenant-id"
export DRUPAL_KEY_SHAREPOINT_CLIENT_ID="your-client-id"
export DRUPAL_KEY_SHAREPOINT_CLIENT_SECRET="your-client-secret"

# Configure in Key module
# Environment variable: DRUPAL_KEY_SHAREPOINT_TENANT_ID
```

### External Key Management

For enterprise environments, consider:

- **AWS KMS**: Use AWS Key Management Service
- **Azure Key Vault**: Native Azure key storage
- **HashiCorp Vault**: Open-source key management
- **Kubernetes Secrets**: For Kubernetes deployments

## Troubleshooting

### Common Issues

#### "Key not found" Error
```
Error: One or more SharePoint Media credential keys could not be loaded.
```

**Solutions**:
1. Verify key IDs match exactly
2. Check key permissions
3. Ensure key provider is properly configured
4. Clear Drupal caches: `drush cr`

#### "Empty key value" Error
```
Error: SharePoint Media credential keys contain empty values.
```

**Solutions**:
1. Check file contents or environment variables
2. Verify file permissions (must be readable by web server)
3. Check for extra whitespace or newlines in key values

#### Connection Test Fails
```
Error: Failed to authenticate with Microsoft Graph API
```

**Solutions**:
1. Verify Azure AD app registration
2. Check API permissions and admin consent
3. Validate GUID format of tenant/client IDs
4. Test with direct configuration temporarily

### Debug Steps

1. **Check key values**:
   ```bash
   drush eval "print_r(\Drupal::service('key.repository')->getKey('sharepoint_tenant_id')->getKeyValue());"
   ```

2. **Test individual keys**:
   ```bash
   drush eval "\$key = \Drupal::service('key.repository')->getKey('sharepoint_client_secret'); var_dump(\$key ? \$key->getKeyValue() : 'Key not found');"
   ```

3. **Verify module configuration**:
   ```bash
   drush cget sharepoint_media.settings credential_method
   drush cget sharepoint_media.settings tenant_id_key
   ```

4. **Check logs**:
   ```bash
   drush watchdog:show --type=sharepoint_media --count=20
   ```

## Migration from Direct Config

If you're migrating from direct configuration storage:

### Step 1: Note Current Credentials
1. Go to `/admin/config/media/sharepoint-media`
2. Note down your current tenant ID, client ID
3. Copy client secret if changing it

### Step 2: Create Keys
Follow the [Creating Azure AD Keys](#creating-azure-ad-keys) section

### Step 3: Switch Methods
1. Select "Use Key module" under credential method
2. Select your created keys
3. Click "Save configuration"

### Step 4: Verify Migration
1. Test connection to ensure keys work
2. The old direct credentials will be automatically cleared

### Step 5: Update Configuration Management
```bash
# Export updated configuration
drush cex

# Verify sensitive data is not in config
grep -r "client_secret\|tenant_id" config/sync/
# Should not show any credential values
```

## Advanced Configuration

### Multiple Environments

Create environment-specific keys:

```bash
# Development
SHAREPOINT_TENANT_ID_DEV="dev-tenant-id"
SHAREPOINT_CLIENT_ID_DEV="dev-client-id"
SHAREPOINT_CLIENT_SECRET_DEV="dev-client-secret"

# Staging
SHAREPOINT_TENANT_ID_STAGING="staging-tenant-id"
SHAREPOINT_CLIENT_ID_STAGING="staging-client-id"
SHAREPOINT_CLIENT_SECRET_STAGING="staging-client-secret"

# Production
SHAREPOINT_TENANT_ID_PROD="prod-tenant-id"
SHAREPOINT_CLIENT_ID_PROD="prod-client-id"
SHAREPOINT_CLIENT_SECRET_PROD="prod-client-secret"
```

### Key Rotation Procedure

1. **Create new Azure AD client secret**
2. **Update key value** (don't change key ID)
3. **Test connection** with new secret
4. **Monitor logs** for any authentication issues
5. **Remove old secret** from Azure AD after verification

### Backup and Recovery

```bash
# Backup key files
tar -czf drupal-keys-backup-$(date +%Y%m%d).tar.gz /etc/drupal/keys/

# Recovery procedure
tar -xzf drupal-keys-backup-YYYYMMDD.tar.gz -C /
chown -R www-data:www-data /etc/drupal/keys/
chmod 700 /etc/drupal/keys/
chmod 600 /etc/drupal/keys/*
```

## Summary

Using the Key module with SharePoint Media provides:

- ✅ **Enhanced Security**: Credentials separate from configuration
- ✅ **Version Control Safety**: No secrets in exported config
- ✅ **Environment Flexibility**: Different keys per environment
- ✅ **Audit Trail**: Key access logging
- ✅ **Professional Standards**: Industry best practices

For additional support, see the main [README.md](../README.md) or contact the module maintainers.