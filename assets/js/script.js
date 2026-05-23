// Generate barcodes and show them in tooltips
jQuery(document).ready(function($) {
    $('.barcode-button').hover(
        function() {
            var orderId = $(this).data('order-id');
            var barcodeUrl = 'https://barcode.tec-it.com/barcode.ashx?data=' + orderId + '&code=Code128&dpi=96';
            $(this).append('<span><img src="' + barcodeUrl + '"/></span>');

        },
        function() {
            $(this).find('span').remove();
        }
    );
});

