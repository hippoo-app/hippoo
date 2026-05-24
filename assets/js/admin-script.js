jQuery(document).ready(function($) {

    /* Settings */
    $(document).on('click', '#hippoo_settings .nav-tab-wrapper a', function(event) {
        event.preventDefault();
        
        var tabId = $(this).attr('href').split('tab=')[1];
        
        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').removeClass('active');
        
        $('#tab-' + tabId).addClass('active');
        
        if (history.pushState) {
            var newUrl = $(this).attr('href');
            history.pushState(null, null, newUrl);
        }
    });

    /* Notice */
    $(document).on('click', '.hippoo-notice .notice-dismiss', function(event) {
        event.preventDefault();

        var nonce = $('#handle_dismiss_nonce').val();
        console.log("Notice dismissed, nonce:", nonce);

        $.ajax({
            url: ajaxurl,
            data: {
                action: 'dismiss_admin_notice',
                nonce: nonce
            },
            success: function(response) {
                console.log("Notice dismissal response:", response);
            },
            error: function(error) {
                console.error("Notice dismissal error:", error);
            }
        });
    });

    /* Carousel */
    var carousel = $('#hippoo_settings #image-carousel .carousel-inner');
    var sliderImages = carousel.find('.carousel-image');
    var slideCount = sliderImages.length;
    var currentPosition = 0;

    console.log("Carousel initialized with slide count:", slideCount);

    function updateCarouselPosition() {
        var slideWidth = sliderImages.first().outerWidth();
        carousel.css('transform', 'translateX(-' + (currentPosition * slideWidth) + 'px)');
        console.log("Carousel position updated to:", currentPosition);
    }

    function moveCarouselPrev() {
        if (currentPosition > 0) {
            currentPosition--;
            updateCarouselPosition();
        }
    }

    function moveCarouselNext() {
        if (currentPosition < slideCount - 1) {
            currentPosition++;
            updateCarouselPosition();
        }
    }

    $(document).on('click', '#hippoo_settings #image-carousel .carousel-arrow.prev', function() {
        console.log("Previous arrow clicked");
        moveCarouselPrev();
    });
    
    $(document).on('click', '#hippoo_settings #image-carousel .carousel-arrow.next', function() {
        console.log("Next arrow clicked");
        moveCarouselNext();
    });

    /* Image Optimization */
    function toggleImageSizeDropdown() {
        if ($('#image_optimization_enabled').is(':checked')) {
            $('#image_size_selection').prop('disabled', false);
        } else {
            $('#image_size_selection').prop('disabled', true);
        }
    }
    
    toggleImageSizeDropdown();

    $(document).on('click', '#hippoo_settings #image_optimization_enabled', function() {
        toggleImageSizeDropdown();
    });

    /* PWA */
    function togglePwaSettingsFields() {
        if ($('#pwa_plugin_enabled').is(':checked')) {
            $('#pwa_route_name, #pwa_custom_css').prop('disabled', false);
            $('#pwa_route_name, #pwa_custom_css').closest('tr').removeClass('disabled');
        } else {
            $('#pwa_route_name, #pwa_custom_css').prop('disabled', true);
            $('#pwa_route_name, #pwa_custom_css').closest('tr').addClass('disabled');
        }
		$('#pwa_route_name').prop('disabled', true);
    }

    togglePwaSettingsFields();

    $(document).on('click', '#hippoo_settings #pwa_plugin_enabled', function() {
        togglePwaSettingsFields();
    });

    /* Compatibility */
    function toggleCompatibilitySettingsFields() {
        if ($('#compatibility_mode').is(':checked')) {
            $('.compatibility-applies-row').removeClass('disabled');
        } else {
            $('.compatibility-applies-row').addClass('disabled');
        }
    }

    toggleCompatibilitySettingsFields();

    $(document).on('click', '#hippoo_settings #compatibility_mode', function() {
        toggleCompatibilitySettingsFields();
    });

    $(document).on('click', '#copy-compatibility-log', function(e) {
        e.preventDefault();
        
        var link = $(this);
        var originalText = link.text();
        
        link.text('Preparing log...');
        
        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_get_compatibility_log',
                nonce: hippoo.nonce
            },
            success: function(response) {
                if (response.success) {
                    var tempTextarea = $('<textarea>');
                    $('body').append(tempTextarea);
                    tempTextarea.val(response.data).select();
                    
                    try {
                        document.execCommand('copy');
                        link.text('Copied!');
                    } catch (err) {
                        link.text('Failed to copy');
                    }
                    
                    tempTextarea.remove();
                } else {
                    link.text('Error');
                }
                
                setTimeout(function() {
                    link.text(originalText);
                }, 2000);
            },
            error: function() {
                link.text('Error');
                setTimeout(function() {
                    link.text(originalText);
                }, 2000);
            }
        });
    });

    /* Review Banner */
    $(document).on('click', '.hippoo-dismiss-review', function(event) {
        event.preventDefault();

        $.ajax({
            url: hippoo.ajax_url,
            type: 'POST',
            data: {
                action: 'hippoo_dismiss_review',
                nonce: hippoo.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.hippoo-review-banner').remove();
                }
            }
        });
    });

    /* API Error */
    $(document).on('click', '.hippoo-dismiss-api-error', function(event) {
        event.preventDefault();

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_dismiss_api_error',
                nonce: hippoo.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.hippoo-rest-api-error').fadeOut();
                }
            }
        });
    });

    $(document).on('click', '.hippoo-retry-api-check', function(event) {
        event.preventDefault();

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_retry_api_check',
                nonce: hippoo.nonce
            },
            success: function(response) {
                if (response.status === 'success') {
                    $('.hippoo-rest-api-error').fadeOut();
                } else {
                    $('.hippoo-rest-api-error p:first').text(response.message);
                }
            }
        });
    });
    
    /* AI Test Connection */
    $('#test-ai-connection').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.text('Testing...').prop('disabled', true);
        
        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_test_ai_connection',
                nonce: hippoo.nonce,
                api_token: $('input[name="hippoo_ai_settings[api_token]"]').val(),
                ai_provider: $('select[name="hippoo_ai_settings[ai_provider]"]').val()
            },
            success: function(response) {
                if (response.success) {
                    alert('Connection successful!');
                } else {
                    alert('Connection failed: ' + response.data);
                }
                
                button.text(originalText).prop('disabled', false);
            },
            error: function() {
                alert('Connection test failed.');
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    $(document).on('change', 'select[name="hippoo_ai_settings[ai_provider]"]', function() {
        var provider = $(this).val();

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_get_models_by_provider',
                nonce: hippoo.nonce,
                ai_provider: provider
            },
            success: function(response) {
                if (!response.success) {
                    console.error("Failed to load models");
                    return;
                }

                var models = response.data;
                var modelSelect = $('select[name="hippoo_ai_settings[ai_model]"]');

                modelSelect.empty();

                models.forEach(function(model) {
                    modelSelect.append('<option value="'+model+'">'+model+'</option>');
                });
            }
        });
    });

    /* Integrations */
    function loadIntegrations() {
        $('#hippoo-integrations-loading').show();
        $('#hippoo-integrations-list').hide();

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_get_integrations',
                nonce: hippoo.nonce
            },
            success: function(response) {
                if (!response.success) return;

                let html = '';
                response.data.forEach(function(item) {
                    const status = item.status;
                    let button = '';
                    if (status === 'active') {
                        button = '<span class="button button-secondary">Activated</span>';
                    } else if (status === 'installed') {
                        button = `<button class="button button-primary hippoo-integrate-btn" data-slug="${item.slug}">Activate</button>`;
                    } else {
                        button = `<button class="button button-primary hippoo-integrate-btn" data-slug="${item.slug}">Install Now</button>`;
                    }

                    html += `
                        <div class="integration-item">
                            <a href="${item.detail_url}" target="_blank"><img src="${item.image}" alt="${item.name}"></a>
                            <div class="integration-info">
                                <h4><a href="${item.detail_url}" target="_blank">${item.name}</a></h4>
                                <p>${item.description.replace(/<[^>]*>/g, '')}</p>
                                <div class="integration-actions">
                                    ${button}
                                </div>
                            </div>
                        </div>`;
                });
                $('#hippoo-integrations-list').html(html).show();
            },
            complete: function () {
                $('#hippoo-integrations-loading').hide();
            }
        });
    }

    $(document).on('click', '.hippoo-integrate-btn', function(e) {
        e.preventDefault();

        var button = $(this);
        var slug = button.data('slug');
        var originalText = button.text();
        var actionsWrapper = button.closest('.integration-actions');

        if (button.prop('disabled')) return;

        button.prop('disabled', true).text('Processing...');

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_install_integration',
                nonce: hippoo.nonce,
                slug: slug
            },
            success: function(response) {
                if (response.success) {
                    const status = response.data.status;

                    if (status === 'active') {
                        actionsWrapper.html('<span class="button button-secondary">Activated</span>');
                    } else if (status === 'installed') {
                        actionsWrapper.html(`<button class="button button-primary hippoo-integrate-btn" data-slug="${slug}">Activate</button>`);
                    } else {
                        actionsWrapper.html(`<button class="button button-primary hippoo-integrate-btn" data-slug="${slug}">Install Now</button>`);
                    }
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function(error) {
                alert('Connection error. Please try again.');
                button.prop('disabled', false).text(originalText);
            }
        });
    });

    if ($('#hippoo-integrations-list').length > 0) {
        loadIntegrations();
    }

    /* Permissions */
    $(document).on('select2:select', '#select-role', function(e) {
        var role_key = e.params.data.id;
        if (!role_key) return;

        var $wrapper = $('.permissions-select-role .select-wrapper');
        $wrapper.addClass('loading');

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_add_permission_role',
                nonce: hippoo.nonce,
                role_key: role_key
            },
            success: function(response) {
                if (response.success) {
                    var $card = $(response.data.card);

                    $('.permission-block .permission-content:visible').slideUp(200, function() {
                        $(this).closest('.permission-block').find('.accordion-toggle').removeClass('open');
                    });

                    $('#permissions-list').append($card);
                    
                    $('#select-role option[value="' + role_key + '"]').prop('disabled', true);
                    $('#select-role').val(null).trigger('change');
                    $('#select-role').trigger('change.select2');

                    $('.hippoo-select2').select2();
                    
                    initPermissionToggles();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            complete: function() {
                $wrapper.removeClass('loading');
            }
        });
    });

    $(document).on('submit', '.hippoo-permission-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('.save-role-settings');
        var $notice = $form.find('.save-settings-notice');

        $button.prop('disabled', true);
        $notice.hide();

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: $form.serialize(),
            success: function(response) {
                if (response.success) {
                    $notice.show();

                    setTimeout(function() {
                        $notice.hide();
                    }, 3000);
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.permission-header', function(e) {
        if ($(e.target).is('.remove-role')) return;
        $(this).closest('.permission-block').find('.permission-content').slideToggle();
        $(this).find('.accordion-toggle').toggleClass('open');
    });

    $(document).on('click', '.remove-role', function(e) {
        e.preventDefault();
        $(this).closest('.permission-block').find('.delete-confirm-modal').fadeIn(200);
    });

    $(document).on('click', '.close-delete-modal, .cancel-delete', function(e) {
        e.preventDefault();
        $(this).closest('.delete-confirm-modal').fadeOut(200);
    });

    $(document).on('click', '.confirm-delete', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var role_key = $button.closest('.permission-block').data('role');
        var $block = $button.closest('.permission-block');
        var $modal = $button.closest('.delete-confirm-modal');

        $button.prop('disabled', true);

        $.ajax({
            url: hippoo.ajax_url,
            method: 'POST',
            data: {
                action: 'hippoo_delete_permission_role',
                nonce: hippoo.nonce,
                role_key: role_key
            },
            success: function(response) {
                if (response.success) {
                    $block.remove();
                    $('#select-role option[value="' + role_key + '"]').prop('disabled', false);
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            complete: function() {
                $button.prop('disabled', false);
                $modal.fadeOut(200);
            }
        });
    });

    $(document).on('click', '.select-all-btn', function(e) {
        e.preventDefault();
        var section = $(this).closest('.permission-section');
        section.find('input[type="checkbox"]').prop('checked', true);
    });

    $(document).on('click', '.deselect-all-btn', function(e) {
        e.preventDefault();
        var section = $(this).closest('.permission-section');
        section.find('input[type="checkbox"]').prop('checked', false);
    });

    $('.hippoo-select2').select2();

    if ($('#select-role').length) {
        $('#select-role').select2({
            minimumResultsForSearch: Infinity,
            allowClear: false,
            dropdownAutoWidth: false,
            containerCssClass: 'hippoo-role-container',
            dropdownCssClass: 'hippoo-role-dropdown',
            placeholder: $('#select-role option:first').text(),
            templateResult: function (data, container) {
                if (data.id === '' || !data.id) {
                    return null;
                }
                return data.text;
            }
        });

        $(document).on('select2:open', '#select-role', function () {
            $('.select2-dropdown').addClass('hippoo-role-dropdown-visible');
        });
    }

    $(document).on('change', '.permission-section .section-toggle', function() {
        var isChecked = $(this).is(':checked');
        var contentAfterToggle = $(this).closest('.checkbox-label').nextAll();
        
        if (isChecked) {
            contentAfterToggle.removeClass('disabled');
            contentAfterToggle.find('.sub-toggle').trigger('change');
        } else {
            contentAfterToggle.addClass('disabled');
            contentAfterToggle.find('.sub-details').addClass('disabled');
        }
    });

    $(document).on('change', '.permission-section .sub-toggle', function() {
        var isChecked = $(this).is(':checked');
        var subDetails = $(this).closest('.checkbox-label').next('.sub-details');
        
        var parentSectionToggle = $(this).closest('.permission-section').find('.section-toggle');
        var parentEnabled = parentSectionToggle.is(':checked');
        
        if (parentEnabled && isChecked) {
            subDetails.removeClass('disabled');
        } else {
            subDetails.addClass('disabled');
        }
    });

    function initPermissionToggles() {
        $(document).find('.permission-section').each(function() {
            var $section = $(this);

            var $toggle = $section.find('.section-toggle');
            if ($toggle.length) {
                $toggle.trigger('change');
            }

            $section.find('.sub-toggle').each(function() {
                $(this).trigger('change');
            });
        });
    }

    if ($('.permission-section').length) {
        initPermissionToggles();
    }
});
