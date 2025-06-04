/**
 * @file
 * SharePoint Media Admin JavaScript functionality.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Settings form enhancements.
   */
  Drupal.behaviors.sharePointMediaSettings = {
    attach: function (context, settings) {
      // Connection test functionality
      $('.connection-test-button', context).once('connection-test').on('click', function () {
        var $button = $(this);
        var $status = $('.connection-status');
        
        $button.prop('disabled', true).text(Drupal.t('Testing...'));
        $status.removeClass('success error warning').addClass('info').text(Drupal.t('Testing connection...'));
        
        var formData = {
          tenant_id: $('#edit-tenant-id').val(),
          client_id: $('#edit-client-id').val(),
          client_secret: $('#edit-client-secret').val()
        };
        
        $.ajax({
          url: '/admin/config/media/sharepoint-media/test-connection',
          method: 'POST',
          data: formData,
          success: function (response) {
            if (response.success) {
              $status.removeClass('info error warning').addClass('success')
                .text(Drupal.t('✓ Connection successful!'));
            } else {
              $status.removeClass('info success warning').addClass('error')
                .text(Drupal.t('✗ Connection failed: @message', {'@message': response.message}));
            }
          },
          error: function (xhr) {
            var message = xhr.responseJSON ? xhr.responseJSON.message : Drupal.t('Unknown error occurred');
            $status.removeClass('info success warning').addClass('error')
              .text(Drupal.t('✗ Connection error: @message', {'@message': message}));
          },
          complete: function () {
            $button.prop('disabled', false).text(Drupal.t('Test Connection'));
          }
        });
      });

      // Drive selection enhancement
      $('.drive-item input[type="checkbox"]', context).once('drive-toggle').on('change', function () {
        var $item = $(this).closest('.drive-item');
        var $status = $item.find('.drive-status');
        
        if ($(this).is(':checked')) {
          $status.removeClass('disabled').addClass('enabled').text(Drupal.t('Enabled'));
        } else {
          $status.removeClass('enabled').addClass('disabled').text(Drupal.t('Disabled'));
        }
      });

      // Auto-save settings
      $('.sharepoint-media-settings-form input, .sharepoint-media-settings-form select', context)
        .once('auto-save').on('change', function () {
          var $field = $(this);
          if ($field.data('auto-save') !== false) {
            showAutoSaveIndicator($field);
          }
        });

      function showAutoSaveIndicator($field) {
        var $indicator = $('<span class="auto-save-indicator">').text(Drupal.t('Saved'));
        $field.after($indicator);
        
        setTimeout(function () {
          $indicator.fadeOut(function () {
            $(this).remove();
          });
        }, 2000);
      }

      // Conditional field visibility
      $('input[name="auto_sync_enabled"]', context).once('auto-sync-toggle').on('change', function () {
        var $dependent = $('.form-item-auto-sync-interval');
        if ($(this).is(':checked')) {
          $dependent.slideDown();
        } else {
          $dependent.slideUp();
        }
      }).trigger('change');

      // Form validation enhancements
      $('.sharepoint-media-settings-form', context).once('enhanced-validation').on('submit', function (e) {
        var errors = [];
        
        // Validate tenant ID format
        var tenantId = $('#edit-tenant-id').val();
        if (tenantId && !isValidGuid(tenantId)) {
          errors.push(Drupal.t('Tenant ID must be a valid GUID format.'));
        }
        
        // Validate client ID format
        var clientId = $('#edit-client-id').val();
        if (clientId && !isValidGuid(clientId)) {
          errors.push(Drupal.t('Client ID must be a valid GUID format.'));
        }
        
        if (errors.length > 0) {
          e.preventDefault();
          showValidationErrors(errors);
        }
      });

      function isValidGuid(guid) {
        var guidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
        return guidRegex.test(guid);
      }

      function showValidationErrors(errors) {
        var $errorContainer = $('.validation-errors');
        if ($errorContainer.length === 0) {
          $errorContainer = $('<div class="validation-errors admin-notification error">').prependTo('.sharepoint-media-settings-form');
        }
        
        $errorContainer.empty();
        errors.forEach(function (error) {
          $errorContainer.append('<div>' + error + '</div>');
        });
        
        $errorContainer.show();
        $('html, body').animate({ scrollTop: $errorContainer.offset().top - 50 }, 500);
      }
    }
  };

  /**
   * Sync dashboard functionality.
   */
  Drupal.behaviors.sharePointMediaSync = {
    attach: function (context, settings) {
      // Real-time sync status updates
      var $syncDashboard = $('.sync-dashboard', context);
      if ($syncDashboard.length) {
        updateSyncStatus();
        setInterval(updateSyncStatus, 30000); // Update every 30 seconds
      }

      // Manual sync form
      $('.manual-sync-form', context).once('manual-sync').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var formData = $form.serialize();
        
        startManualSync(formData);
      });

      // Quick sync buttons
      $('.quick-sync-btn', context).once('quick-sync').on('click', function () {
        var syncType = $(this).data('sync-type');
        var formData = 'sync_type=' + syncType + '&quick_sync=1';
        
        startManualSync(formData);
      });

      // Clear cache button
      $('.clear-cache-btn', context).once('clear-cache').on('click', function () {
        if (confirm(Drupal.t('Are you sure you want to clear the SharePoint media cache?'))) {
          clearCache();
        }
      });

      // Refresh URLs button
      $('.refresh-urls-btn', context).once('refresh-urls').on('click', function () {
        refreshExpiredUrls();
      });

      function updateSyncStatus() {
        $.get('/admin/sharepoint-media/sync-status')
          .done(function (data) {
            updateSyncStatistics(data.statistics);
            updateSyncInfo(data);
          })
          .fail(function () {
            console.warn('Failed to update sync status');
          });
      }

      function updateSyncStatistics(stats) {
        $('.sync-stat[data-stat="total"] .sync-stat-number').text(stats.total_media.toLocaleString());
        
        if (stats.by_type) {
          Object.keys(stats.by_type).forEach(function (type) {
            $('.sync-stat[data-stat="' + type + '"] .sync-stat-number').text(stats.by_type[type].toLocaleString());
          });
        }
        
        $('.sync-stat[data-stat="recent"] .sync-stat-number').text(stats.recent_updates.toLocaleString());
      }

      function updateSyncInfo(data) {
        if (data.last_sync_formatted) {
          $('.last-sync-time').text(data.last_sync_formatted);
        }
        
        if (data.next_sync_formatted) {
          $('.next-sync-time').text(data.next_sync_formatted);
        }
        
        var $autoSyncStatus = $('.auto-sync-status');
        if (data.auto_sync_enabled) {
          $autoSyncStatus.removeClass('disabled').addClass('enabled').text(Drupal.t('Enabled'));
        } else {
          $autoSyncStatus.removeClass('enabled').addClass('disabled').text(Drupal.t('Disabled'));
        }
      }

      function startManualSync(formData) {
        showLoadingOverlay(Drupal.t('Starting sync operation...'));
        
        $.post('/admin/sharepoint-media/sync', formData)
          .done(function (response) {
            hideLoadingOverlay();
            
            if (response.success) {
              showSyncResults(response.results, response.duration);
              updateSyncStatus(); // Refresh dashboard
            } else {
              showNotification(response.message, 'error');
            }
          })
          .fail(function (xhr) {
            hideLoadingOverlay();
            var message = xhr.responseJSON ? xhr.responseJSON.message : Drupal.t('Sync operation failed');
            showNotification(message, 'error');
          });
      }

      function clearCache() {
        showLoadingOverlay(Drupal.t('Clearing cache...'));
        
        $.post('/admin/sharepoint-media/clear-cache')
          .done(function (response) {
            hideLoadingOverlay();
            showNotification(response.message, response.success ? 'success' : 'error');
          })
          .fail(function () {
            hideLoadingOverlay();
            showNotification(Drupal.t('Failed to clear cache'), 'error');
          });
      }

      function refreshExpiredUrls() {
        showLoadingOverlay(Drupal.t('Refreshing download URLs...'));
        
        $.post('/admin/sharepoint-media/refresh-urls')
          .done(function (response) {
            hideLoadingOverlay();
            showNotification(response.message, response.success ? 'success' : 'error');
          })
          .fail(function () {
            hideLoadingOverlay();
            showNotification(Drupal.t('Failed to refresh URLs'), 'error');
          });
      }

      function showSyncResults(results, duration) {
        var message = Drupal.t('Sync completed in @duration seconds. Created: @created, Updated: @updated, Skipped: @skipped', {
          '@duration': duration,
          '@created': results.created,
          '@updated': results.updated,
          '@skipped': results.skipped
        });
        
        if (results.errors && results.errors.length > 0) {
          message += Drupal.t(' (@count errors)', {'@count': results.errors.length});
        }
        
        var resultType = results.errors && results.errors.length > 0 ? 'warning' : 'success';
        showNotification(message, resultType);
        
        // Show detailed errors if any
        if (results.errors && results.errors.length > 0) {
          showSyncErrors(results.errors);
        }
      }

      function showSyncErrors(errors) {
        var $errorModal = $('<div class="sync-errors-modal">');
        var $content = $('<div class="modal-content">');
        
        $content.append('<h3>' + Drupal.t('Sync Errors') + '</h3>');
        
        var $errorList = $('<ul class="sync-error-list">');
        errors.slice(0, 10).forEach(function (error) { // Show max 10 errors
          $errorList.append('<li><strong>' + error.name + '</strong>: ' + error.error + '</li>');
        });
        
        if (errors.length > 10) {
          $errorList.append('<li><em>' + Drupal.t('... and @count more errors', {'@count': errors.length - 10}) + '</em></li>');
        }
        
        $content.append($errorList);
        $content.append('<button class="btn-admin-secondary close-errors">' + Drupal.t('Close') + '</button>');
        
        $errorModal.append($content);
        $('body').append($errorModal);
        
        $errorModal.on('click', '.close-errors, .modal-overlay', function () {
          $errorModal.remove();
        });
      }
    }
  };

  /**
   * Drive management functionality.
   */
  Drupal.behaviors.sharePointMediaDrives = {
    attach: function (context, settings) {
      // Load available drives
      $('.load-drives-btn', context).once('load-drives').on('click', function () {
        loadAvailableDrives();
      });

      // Drive folder browser
      $('.browse-folders-btn', context).once('browse-folders').on('click', function () {
        var driveId = $(this).data('drive-id');
        if (driveId) {
          showFolderBrowser(driveId);
        }
      });

      function loadAvailableDrives() {
        showLoadingOverlay(Drupal.t('Loading available drives...'));
        
        $.get('/admin/sharepoint-media/drives')
          .done(function (response) {
            hideLoadingOverlay();
            
            if (response.success) {
              updateDrivesList(response.drives);
            } else {
              showNotification(response.message, 'error');
            }
          })
          .fail(function () {
            hideLoadingOverlay();
            showNotification(Drupal.t('Failed to load drives'), 'error');
          });
      }

      function updateDrivesList(drives) {
        var $drivesList = $('.drives-list');
        $drivesList.empty();
        
        drives.forEach(function (drive) {
          var $driveItem = $('<div class="drive-item">');
          $driveItem.html(
            '<div class="drive-info">' +
            '<div class="drive-name">' + drive.name + '</div>' +
            '<div class="drive-type">' + drive.type + '</div>' +
            '</div>' +
            '<div class="drive-actions">' +
            '<label><input type="checkbox" name="sync_drives[' + drive.id + ']" value="' + drive.id + '"> ' + Drupal.t('Enable') + '</label>' +
            '<button type="button" class="btn-admin-outline browse-folders-btn" data-drive-id="' + drive.id + '">' + Drupal.t('Browse') + '</button>' +
            '</div>'
          );
          
          $drivesList.append($driveItem);
        });
        
        // Re-attach behaviors to new elements
        Drupal.attachBehaviors($drivesList[0]);
      }

      function showFolderBrowser(driveId) {
        var $modal = $('<div class="folder-browser-modal">');
        var $content = $('<div class="modal-content">');
        
        $content.append('<h3>' + Drupal.t('Select Folder') + '</h3>');
        $content.append('<div class="folder-tree" data-drive-id="' + driveId + '">');
        $content.append('<div class="modal-actions">' +
                       '<button class="btn-admin-primary select-folder">' + Drupal.t('Select') + '</button>' +
                       '<button class="btn-admin-secondary cancel-folder">' + Drupal.t('Cancel') + '</button>' +
                       '</div>');
        
        $modal.append($content);
        $('body').append($modal);
        
        loadFolderTree(driveId, '/', $content.find('.folder-tree'));
        
        $modal.on('click', '.cancel-folder', function () {
          $modal.remove();
        });
        
        $modal.on('click', '.select-folder', function () {
          var selectedPath = $modal.find('.folder-item.selected').data('path') || '/';
          // Update the form field with selected path
          $('input[name="folder_path"]').val(selectedPath);
          $modal.remove();
        });
      }

      function loadFolderTree(driveId, path, $container) {
        $container.html('<div class="loading">' + Drupal.t('Loading folders...') + '</div>');
        
        $.get('/admin/sharepoint-media/folders', {
          drive_id: driveId,
          folder_path: path
        })
        .done(function (response) {
          $container.empty();
          
          if (response.success) {
            response.folders.forEach(function (folder) {
              var $folderItem = $('<div class="folder-item" data-path="' + folder.path + '">');
              $folderItem.html('<i class="folder-icon"></i> ' + folder.name);
              
              $folderItem.on('click', function () {
                $('.folder-item').removeClass('selected');
                $(this).addClass('selected');
              });
              
              $container.append($folderItem);
            });
          } else {
            $container.html('<div class="error">' + response.message + '</div>');
          }
        })
        .fail(function () {
          $container.html('<div class="error">' + Drupal.t('Failed to load folders') + '</div>');
        });
      }
    }
  };

  /**
   * Utility functions.
   */
  function showLoadingOverlay(message) {
    var $overlay = $('.loading-overlay');
    if ($overlay.length === 0) {
      $overlay = $('<div class="loading-overlay">' +
                   '<div class="loading-content">' +
                   '<div class="loading-spinner"></div>' +
                   '<div class="loading-text"></div>' +
                   '</div>' +
                   '</div>');
      $('body').append($overlay);
    }
    
    $overlay.find('.loading-text').text(message || Drupal.t('Loading...'));
    $overlay.show();
  }

  function hideLoadingOverlay() {
    $('.loading-overlay').hide();
  }

  function showNotification(message, type) {
    type = type || 'info';
    
    var $notification = $('<div class="admin-notification ' + type + '">' +
                         '<button class="admin-notification-close">&times;</button>' +
                         '<div>' + message + '</div>' +
                         '</div>');
    
    $notification.find('.admin-notification-close').on('click', function () {
      $notification.remove();
    });
    
    $('.sharepoint-media-admin-container').prepend($notification);
    
    // Auto-remove after 5 seconds
    setTimeout(function () {
      $notification.fadeOut(function () {
        $(this).remove();
      });
    }, 5000);
  }

  // Global admin utilities
  window.SharePointMediaAdmin = {
    showLoadingOverlay: showLoadingOverlay,
    hideLoadingOverlay: hideLoadingOverlay,
    showNotification: showNotification
  };

})(jQuery, Drupal, drupalSettings);