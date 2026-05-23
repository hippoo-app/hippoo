jQuery(document).ready(function($) {
    /* Settings */

    
    $(document).on('click', '#hippoo_invoice_settings .tabs .nav-tab-wrapper .nav-tab', function(event) {
        event.preventDefault();

        var selectedTab = $(this).attr('href').replace('#', '');

        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');

        $(this).addClass('nav-tab-active');
        $('#' + selectedTab).addClass('active');
    });


    /* Barcode Tooltip */


    $('.hippoo-tooltip').tooltip({
        items: '[data-src]',
        tooltipClass: 'barcode-tooltip',
        position: {
            my: "center bottom-10",
            at: "center top",
            using: function(position, feedback) {
                $(this).css(position);
                $('<div>').addClass('tooltip-arrow').appendTo(this);
            }
        },
        content: function() {
            return atob($(this).data('src')) + '<br>' + $(this).data('text');
        },
    });


    /* Notice */


    // $(document).on('click', '.hippoo-notice .notice-dismiss', function(event) {
    //     event.preventDefault();

    //     var nonce = $('#handle_dismiss_nonce').val();
    //     $.ajax({
    //         url: ajaxurl,
    //         data: {
    //             action: 'dismiss_admin_notice',
    //             nonce: nonce
    //         }
    //     });
    // });
    // $('.hippoo-notice .notice-dismiss').click(function() {
    //     var data = {
    //         action: 'dismiss_admin_notice',
    //         dismiss_admin_notice_nonce: $('#dismiss_admin_notice_nonce').val()  // Make sure to include the nonce
    //     };

    //     $.post(ajaxurl, data, function(response) {
    //         if (response.success) {
    //             $('.hippoo-notice').remove();  // Hide or remove the notice on success
    //         } else {
    //             console.log('Dismissal failed: ' + response.data.message);  // Log error if dismissal failed
    //         }
    //     });
    // });

    $('.hippoo-notice .notice-dismiss').click(function() {
        var data = {
            action: 'dismiss_admin_notice',
            dismiss_admin_notice_nonce: $('#dismiss_admin_notice_nonce').val()  // Make sure to include the nonce
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Hide or remove the notice on success
                $('.hippoo-notice').fadeOut('slow', function() {
                    $(this).remove();
                });
                // Show success message if needed
                alert('Admin notice dismissed successfully.');
            } else {
                // Log error if dismissal failed
                console.log('Dismissal failed: ' + response.data.message);
                // Show error message if needed
                alert('Dismissal failed. Please try again later.');
            }
        }).fail(function() {
            // Show error message if AJAX request fails
            alert('Error: Unable to dismiss admin notice.');
        });

        return false;  // Prevent default action of notice-dismiss button
    });


    /* Carousel */


    var carousel = $('#hippoo_invoice_settings #image-carousel .carousel-inner');
    var sliderImages = carousel.find('.carousel-image');
    var slideCount = sliderImages.length;
    var currentPosition = 0;

    function updateCarouselPosition() {
        var slideWidth = sliderImages.first().outerWidth();
        carousel.css('transform', 'translateX(-' + (currentPosition * slideWidth) + 'px)');
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

    $(document).on('click', '#hippoo_invoice_settings #image-carousel .carousel-arrow.prev', moveCarouselPrev);
    $(document).on('click', '#hippoo_invoice_settings #image-carousel .carousel-arrow.next', moveCarouselNext);


    /* Media Uploader */


    function mediaSetImage(wrapper, attachment) {
        var display = attachment ? 'block' : 'none';

        wrapper.find('input').val(attachment);
        wrapper.find('img').attr('src', attachment);
        wrapper.find('img').css('display', display);
    }

    function mediaUploaderOpen(wrapper) {
        var mediaUploader;

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Select Media',
            button: { text: 'Choose Media' },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            mediaSetImage(wrapper, attachment.url);
        });

        mediaUploader.open();
    }


    /* Shop Logo Uploader */


    var shopLogo = $('#hippoo_invoice_settings #shop_logo_field').val();
    var shopWrapper = $('#hippoo_invoice_settings .shop_logo');
    mediaSetImage(shopWrapper, shopLogo);

    $('#shop_logo_upload_button').on('click', function(e) {
        e.preventDefault();

        mediaUploaderOpen(shopWrapper);
    });

    $('#shop_logo_clear_button').on('click', function(e) {
        e.preventDefault();

        mediaSetImage(shopWrapper, null);
    });


    /* Courier Logo Uploader */


    var courierLogo = $('#hippoo_invoice_settings #courier_logo_field').val();
    var courierWrapper = $('#hippoo_invoice_settings .courier_logo');
    mediaSetImage(courierWrapper, courierLogo);

    $('#courier_logo_upload_button').on('click', function(e) {
        e.preventDefault();

        mediaUploaderOpen(courierWrapper);
    });

    $('#courier_logo_clear_button').on('click', function(e) {
        e.preventDefault();

        mediaSetImage(courierWrapper, null);
    });
});
