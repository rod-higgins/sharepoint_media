/**
 * @file
 * SharePoint Media JavaScript functionality.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * SharePoint Media search functionality.
   */
  Drupal.behaviors.sharePointMediaSearch = {
    attach: function (context, settings) {
      $('.sharepoint-media-search-form', context).once('sharepoint-search').each(function () {
        var $form = $(this);
        var $searchInput = $form.find('.search-input');
        var $submitButton = $form.find('.search-submit');
        
        // Auto-search on input (debounced)
        var searchTimer;
        $searchInput.on('input', function () {
          clearTimeout(searchTimer);
          var query = $(this).val().trim();
          
          if (query.length >= 3) {
            searchTimer = setTimeout(function () {
              // Could implement live search suggestions here
              showSearchSuggestions(query);
            }, 500);
          } else {
            hideSearchSuggestions();
          }
        });

        // Enhanced form submission
        $form.on('submit', function (e) {
          var query = $searchInput.val().trim();
          if (query === '') {
            e.preventDefault();
            $searchInput.focus();
            return false;
          }
          
          // Show loading state
          $submitButton.prop('disabled', true).text(Drupal.t('Searching...'));
        });

        // Keyboard shortcuts
        $(document).on('keydown', function (e) {
          // Focus search on Ctrl+K or Cmd+K
          if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            $searchInput.focus();
          }
          
          // Clear search on Escape
          if (e.key === 'Escape' && $searchInput.is(':focus')) {
            $searchInput.val('').trigger('input');
          }
        });
      });

      // Search suggestions functionality
      function showSearchSuggestions(query) {
        // Implementation for live search suggestions
        // This could make AJAX calls to get real-time suggestions
      }

      function hideSearchSuggestions() {
        $('.search-suggestions-dropdown').remove();
      }
    }
  };

  /**
   * Media grid view switching.
   */
  Drupal.behaviors.sharePointMediaGrid = {
    attach: function (context, settings) {
      $('.view-toggle', context).once('view-toggle').each(function () {
        $(this).on('click', function () {
          var view = $(this).data('view');
          var $grid = $('.media-grid');
          
          // Update active button
          $('.view-toggle').removeClass('active');
          $(this).addClass('active');
          
          // Update grid view
          $grid.attr('data-view', view);
          
          // Save preference
          localStorage.setItem('sharepoint-media-view', view);
          
          // Trigger resize event for any responsive elements
          $(window).trigger('resize');
        });
      });

      // Restore saved view preference
      var savedView = localStorage.getItem('sharepoint-media-view');
      if (savedView) {
        $('.view-toggle[data-view="' + savedView + '"]').trigger('click');
      }
    }
  };

  /**
   * Faceted search functionality.
   */
  Drupal.behaviors.sharePointMediaFacets = {
    attach: function (context, settings) {
      $('.facet-group', context).once('facet-group').each(function () {
        var $group = $(this);
        var $showMore = $group.find('.show-more-facets');
        
        $showMore.on('click', function () {
          var $hiddenValues = $group.find('.facet-value:nth-child(n+11)');
          $hiddenValues.show();
          $(this).hide();
        });

        // Collapsible facet groups
        var $title = $group.find('.facet-title');
        $title.on('click', function () {
          var $values = $group.find('.facet-values');
          $values.slideToggle(200);
          $group.toggleClass('collapsed');
        });
      });

      // Clear all filters functionality
      $('.clear-filters-btn', context).once('clear-filters').on('click', function () {
        var url = new URL(window.location);
        var keywords = url.searchParams.get('keywords');
        
        // Clear all parameters except keywords
        url.search = '';
        if (keywords) {
          url.searchParams.set('keywords', keywords);
        }
        
        window.location.href = url.toString();
      });
    }
  };

  /**
   * Media item interactions.
   */
  Drupal.behaviors.sharePointMediaItems = {
    attach: function (context, settings) {
      $('.media-item', context).once('media-item').each(function () {
        var $item = $(this);
        var mediaId = $item.data('media-id');
        
        // Lazy load thumbnails
        var $thumbnail = $item.find('.thumbnail-image');
        if ($thumbnail.length && 'IntersectionObserver' in window) {
          var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                var $img = $(entry.target);
                var src = $img.data('src');
                if (src) {
                  $img.attr('src', src).removeAttr('data-src');
                }
                observer.unobserve(entry.target);
              }
            });
          });
          
          observer.observe($thumbnail[0]);
        }

        // Keyboard navigation
        $item.attr('tabindex', '0').on('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            var $primaryAction = $item.find('.action-view, .action-download').first();
            if ($primaryAction.length) {
              $primaryAction[0].click();
            }
          }
        });

        // Context menu for additional actions
        $item.on('contextmenu', function (e) {
          e.preventDefault();
          showContextMenu(e, mediaId);
        });
      });

      function showContextMenu(event, mediaId) {
        $('.context-menu').remove();
        
        var $menu = $('<div class="context-menu">')
          .css({
            position: 'absolute',
            left: event.pageX,
            top: event.pageY,
            background: 'white',
            border: '1px solid #ccc',
            borderRadius: '4px',
            boxShadow: '0 2px 10px rgba(0,0,0,0.1)',
            zIndex: 1000
          });

        var actions = [
          { text: Drupal.t('View Details'), action: 'info' },
          { text: Drupal.t('Copy Link'), action: 'copy-link' },
          { text: Drupal.t('Download'), action: 'download' },
          { text: Drupal.t('Open in SharePoint'), action: 'sharepoint' }
        ];

        actions.forEach(function (item) {
          var $item = $('<div class="context-menu-item">')
            .text(item.text)
            .css({
              padding: '8px 12px',
              cursor: 'pointer',
              borderBottom: '1px solid #eee'
            })
            .on('click', function () {
              handleContextAction(item.action, mediaId);
              $menu.remove();
            })
            .on('mouseenter', function () {
              $(this).css('background', '#f0f0f0');
            })
            .on('mouseleave', function () {
              $(this).css('background', 'white');
            });
          
          $menu.append($item);
        });

        $('body').append($menu);

        // Close menu on outside click
        $(document).one('click', function () {
          $menu.remove();
        });
      }

      function handleContextAction(action, mediaId) {
        switch (action) {
          case 'info':
            showMediaInfo(mediaId);
            break;
          case 'copy-link':
            copyMediaLink(mediaId);
            break;
          case 'download':
            downloadMedia(mediaId);
            break;
          case 'sharepoint':
            openInSharePoint(mediaId);
            break;
        }
      }
    }
  };

  /**
   * Media info modal functionality.
   */
  Drupal.behaviors.sharePointMediaModal = {
    attach: function (context, settings) {
      // Modal close functionality
      $('.modal-close, .modal-overlay', context).once('modal-close').on('click', function () {
        closeMediaInfo();
      });

      // Keyboard navigation in modal
      $(document).on('keydown', function (e) {
        if ($('.modal:visible').length) {
          if (e.key === 'Escape') {
            closeMediaInfo();
          }
          
          // Tab trapping
          if (e.key === 'Tab') {
            var $modal = $('.modal:visible');
            var $focusable = $modal.find('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
            var $first = $focusable.first();
            var $last = $focusable.last();
            
            if (e.shiftKey && document.activeElement === $first[0]) {
              e.preventDefault();
              $last.focus();
            } else if (!e.shiftKey && document.activeElement === $last[0]) {
              e.preventDefault();
              $first.focus();
            }
          }
        }
      });
    }
  };

  /**
   * Search filters enhancement.
   */
  Drupal.behaviors.sharePointMediaFilters = {
    attach: function (context, settings) {
      // Smart filter suggestions
      $('.filter-input', context).once('filter-input').each(function () {
        var $input = $(this);
        var filterType = $input.data('filter-type');
        
        $input.on('input', function () {
          var value = $(this).val();
          if (value.length >= 2) {
            fetchFilterSuggestions(filterType, value, $input);
          }
        });
      });

      // Filter preset functionality
      $('.filter-preset', context).once('filter-preset').on('click', function () {
        var preset = $(this).data('preset');
        applyFilterPreset(preset);
      });

      // Save current filters as preset
      $('.save-filter-preset', context).once('save-preset').on('click', function () {
        var name = prompt(Drupal.t('Enter a name for this filter preset:'));
        if (name) {
          saveFilterPreset(name);
        }
      });

      function fetchFilterSuggestions(type, query, $input) {
        // AJAX call to get filter suggestions
        $.get('/admin/sharepoint-media/suggestions/' + type, { q: query })
          .done(function (data) {
            showFilterSuggestions($input, data.suggestions);
          });
      }

      function showFilterSuggestions($input, suggestions) {
        $('.filter-suggestions').remove();
        
        if (suggestions.length === 0) return;

        var $suggestions = $('<div class="filter-suggestions">');
        suggestions.forEach(function (suggestion) {
          var $item = $('<div class="suggestion-item">')
            .text(suggestion.label)
            .on('click', function () {
              $input.val(suggestion.value);
              $suggestions.remove();
            });
          $suggestions.append($item);
        });

        $input.after($suggestions);
      }

      function applyFilterPreset(preset) {
        // Apply predefined filter combination
        var presets = {
          'images-today': {
            file_type: ['image'],
            date_from: new Date().toISOString().split('T')[0]
          },
          'large-videos': {
            file_type: ['video'],
            size: ['large', 'very-large']
          },
          'high-res-photos': {
            file_type: ['image'],
            resolution: ['high', '4K', '1080p']
          }
        };

        var config = presets[preset];
        if (config) {
          var url = new URL(window.location);
          Object.keys(config).forEach(function (key) {
            url.searchParams.delete(key);
            config[key].forEach(function (value) {
              url.searchParams.append(key, value);
            });
          });
          window.location.href = url.toString();
        }
      }

      function saveFilterPreset(name) {
        var currentFilters = getCurrentFilters();
        var savedPresets = JSON.parse(localStorage.getItem('sharepoint-media-presets') || '{}');
        savedPresets[name] = currentFilters;
        localStorage.setItem('sharepoint-media-presets', JSON.stringify(savedPresets));
        
        // Update preset dropdown
        updatePresetDropdown();
      }

      function getCurrentFilters() {
        var url = new URL(window.location);
        var filters = {};
        url.searchParams.forEach(function (value, key) {
          if (key !== 'keywords' && key !== 'page') {
            if (!filters[key]) filters[key] = [];
            filters[key].push(value);
          }
        });
        return filters;
      }

      function updatePresetDropdown() {
        var savedPresets = JSON.parse(localStorage.getItem('sharepoint-media-presets') || '{}');
        var $dropdown = $('.filter-presets-dropdown');
        
        $dropdown.empty();
        Object.keys(savedPresets).forEach(function (name) {
          var $option = $('<option>').val(name).text(name);
          $dropdown.append($option);
        });
      }
    }
  };

  /**
   * Bulk operations functionality.
   */
  Drupal.behaviors.sharePointMediaBulk = {
    attach: function (context, settings) {
      var selectedItems = [];

      // Selection checkbox functionality
      $('.media-item-checkbox', context).once('bulk-select').on('change', function () {
        var mediaId = $(this).val();
        var isChecked = $(this).is(':checked');
        
        if (isChecked) {
          selectedItems.push(mediaId);
        } else {
          selectedItems = selectedItems.filter(function (id) {
            return id !== mediaId;
          });
        }
        
        updateBulkActions();
      });

      // Select all functionality
      $('.select-all-checkbox', context).once('select-all').on('change', function () {
        var isChecked = $(this).is(':checked');
        $('.media-item-checkbox').prop('checked', isChecked).trigger('change');
      });

      // Bulk action buttons
      $('.bulk-download', context).once('bulk-download').on('click', function () {
        if (selectedItems.length === 0) {
          alert(Drupal.t('Please select items to download.'));
          return;
        }
        bulkDownload(selectedItems);
      });

      $('.bulk-tag', context).once('bulk-tag').on('click', function () {
        if (selectedItems.length === 0) {
          alert(Drupal.t('Please select items to tag.'));
          return;
        }
        showBulkTagDialog(selectedItems);
      });

      function updateBulkActions() {
        var $bulkActions = $('.bulk-actions');
        if (selectedItems.length > 0) {
          $bulkActions.show();
          $('.selected-count').text(selectedItems.length);
        } else {
          $bulkActions.hide();
        }
      }

      function bulkDownload(mediaIds) {
        // Create a zip file or initiate multiple downloads
        var downloadUrl = '/admin/sharepoint-media/bulk-download';
        var form = $('<form method="post" action="' + downloadUrl + '">');
        
        mediaIds.forEach(function (id) {
          form.append('<input type="hidden" name="media_ids[]" value="' + id + '">');
        });
        
        $('body').append(form);
        form.submit();
        form.remove();
      }

      function showBulkTagDialog(mediaIds) {
        var $dialog = $('<div class="bulk-tag-dialog">')
          .html('<h3>' + Drupal.t('Add Tags') + '</h3>' +
                '<input type="text" class="tag-input" placeholder="' + Drupal.t('Enter tags separated by commas') + '">' +
                '<div class="dialog-actions">' +
                '<button class="btn btn-primary apply-tags">' + Drupal.t('Apply Tags') + '</button>' +
                '<button class="btn btn-secondary cancel-tags">' + Drupal.t('Cancel') + '</button>' +
                '</div>');

        $dialog.find('.apply-tags').on('click', function () {
          var tags = $dialog.find('.tag-input').val();
          if (tags.trim()) {
            applyBulkTags(mediaIds, tags);
          }
          $dialog.remove();
        });

        $dialog.find('.cancel-tags').on('click', function () {
          $dialog.remove();
        });

        $('body').append($dialog);
        $dialog.find('.tag-input').focus();
      }

      function applyBulkTags(mediaIds, tags) {
        $.post('/admin/sharepoint-media/bulk-tag', {
          media_ids: mediaIds,
          tags: tags
        }).done(function () {
          location.reload();
        }).fail(function () {
          alert(Drupal.t('Failed to apply tags.'));
        });
      }
    }
  };

  /**
   * Performance optimizations.
   */
  Drupal.behaviors.sharePointMediaPerformance = {
    attach: function (context, settings) {
      // Infinite scroll for large result sets
      if ($('.search-results').length && settings.sharepoint_media && settings.sharepoint_media.infinite_scroll) {
        var loading = false;
        var currentPage = parseInt(settings.sharepoint_media.current_page || 0);
        var totalPages = parseInt(settings.sharepoint_media.total_pages || 0);

        $(window).on('scroll', function () {
          if (loading || currentPage >= totalPages - 1) return;

          var scrollTop = $(window).scrollTop();
          var windowHeight = $(window).height();
          var documentHeight = $(document).height();

          if (scrollTop + windowHeight >= documentHeight - 1000) { // 1000px threshold
            loading = true;
            loadMoreResults();
          }
        });

        function loadMoreResults() {
          var url = new URL(window.location);
          url.searchParams.set('page', currentPage + 1);
          
          $.get(url.toString())
            .done(function (data) {
              var $newResults = $(data).find('.media-item');
              $('.media-grid').append($newResults);
              currentPage++;
              loading = false;
              
              // Trigger behaviors on new content
              Drupal.attachBehaviors($newResults[0]);
            })
            .fail(function () {
              loading = false;
            });
        }
      }

      // Preload next page for faster navigation
      if (settings.sharepoint_media && settings.sharepoint_media.preload_next) {
        var nextPageUrl = $('.pager-next a').attr('href');
        if (nextPageUrl) {
          setTimeout(function () {
            $('<link rel="prefetch">').attr('href', nextPageUrl).appendTo('head');
          }, 2000);
        }
      }
    }
  };

  // Global utility functions
  window.showMediaInfo = function (mediaId) {
    var $modal = $('#media-info-modal');
    var $modalBody = $('#modal-body');
    
    $modalBody.html('<div class="loading">' + Drupal.t('Loading...') + '</div>');
    $modal.show();
    
    $.get('/sharepoint-media/' + mediaId + '/info')
      .done(function (data) {
        $modalBody.html(formatMediaInfo(data));
      })
      .fail(function () {
        $modalBody.html('<div class="error">' + Drupal.t('Failed to load media information.') + '</div>');
      });
  };

  window.closeMediaInfo = function () {
    $('#media-info-modal').hide();
  };

  window.formatMediaInfo = function (data) {
    var html = '<div class="media-info">' +
               '<h4>' + data.name + '</h4>' +
               '<dl class="info-list">' +
               '<dt>' + Drupal.t('File Size') + ':</dt><dd>' + data.formatted_size + '</dd>' +
               '<dt>' + Drupal.t('MIME Type') + ':</dt><dd>' + data.mime_type + '</dd>';
    
    if (data.width && data.height) {
      html += '<dt>' + Drupal.t('Dimensions') + ':</dt><dd>' + data.width + ' × ' + data.height + '</dd>';
    }
    
    if (data.formatted_duration) {
      html += '<dt>' + Drupal.t('Duration') + ':</dt><dd>' + data.formatted_duration + '</dd>';
    }
    
    if (data.created_by) {
      html += '<dt>' + Drupal.t('Created By') + ':</dt><dd>' + data.created_by + '</dd>';
    }
    
    if (data.created_date) {
      html += '<dt>' + Drupal.t('Created Date') + ':</dt><dd>' + data.created_date + '</dd>';
    }
    
    if (data.sharepoint_url) {
      html += '<dt>' + Drupal.t('SharePoint URL') + ':</dt><dd><a href="' + data.sharepoint_url + '" target="_blank">' + Drupal.t('Open in SharePoint') + '</a></dd>';
    }
    
    html += '</dl></div>';
    return html;
  };

  window.copyMediaLink = function (mediaId) {
    var url = window.location.origin + '/sharepoint-media/' + mediaId + '/stream';
    
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () {
        showNotification(Drupal.t('Link copied to clipboard'));
      });
    } else {
      // Fallback for older browsers
      var textArea = document.createElement('textarea');
      textArea.value = url;
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      showNotification(Drupal.t('Link copied to clipboard'));
    }
  };

  window.downloadMedia = function (mediaId) {
    window.open('/sharepoint-media/' + mediaId + '/download', '_blank');
  };

  window.openInSharePoint = function (mediaId) {
    // Get SharePoint URL from media info
    $.get('/sharepoint-media/' + mediaId + '/info')
      .done(function (data) {
        if (data.sharepoint_url) {
          window.open(data.sharepoint_url, '_blank');
        }
      });
  };

  window.showNotification = function (message, type) {
    type = type || 'success';
    var $notification = $('<div class="notification notification-' + type + '">')
      .text(message)
      .css({
        position: 'fixed',
        top: '20px',
        right: '20px',
        background: type === 'success' ? '#28a745' : '#dc3545',
        color: 'white',
        padding: '12px 20px',
        borderRadius: '4px',
        zIndex: 9999
      });

    $('body').append($notification);
    
    setTimeout(function () {
      $notification.fadeOut(function () {
        $(this).remove();
      });
    }, 3000);
  };

})(jQuery, Drupal, drupalSettings);