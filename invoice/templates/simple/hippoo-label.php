<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<html>
<head>
    <title><?php esc_html_e( 'Label', 'hippoo' ); ?></title>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page {
            <?php
            $paper_size = isset($settings['shipping_paper_size']) ? $settings['shipping_paper_size'] : 'A4';
            if ($paper_size === 'A4') {
                echo 'size: A4; margin: 10mm;';
            } elseif ($paper_size === 'A5') {
                echo 'size: A5; margin: 10mm;';
            }
            ?>
        }

        body {
            font-family: <?php echo ! empty( $settings['font_name'] ) ? esc_attr( $settings['font_name'] ) : 'Arial, sans-serif'; ?>;
            margin: 0;
            padding: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        th, td {
            padding: 15px;
        }
        .rtl{
            direction: rtl;
        }

        .text-left {
            text-align: left;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .no-border {
            border: 0;
        }

        /* shipping table */

        table.shipping tbody tr td {
            border-bottom: 1px solid #E0E0E0;
            padding: 15px 5px;
        }

        .invoice-id {
            font-weight: bold;
            font-size: 13px;
        }

        img.courier_logo {
            max-height: 100px;
        }

        /* rtl */

        .rtl .text-left {
            text-align: right;
        }

        .rtl .text-right {
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="wrapper <?php echo esc_attr( $direction ); ?>">
        <table class="shipping">
            <tbody>
                <tr>
                    <td class="shop-info">
                        <h4 class="text-left"><?php esc_html_e( 'From:', 'hippoo' ); ?></h4><br>
                        <div class="address"><?php echo wp_kses_post( $shop_address ); ?></div>
                    </td>
                    <td class="invoice-logo text-right">
                        <?php if ( isset( $settings['shipping_show_logo'] ) && ! empty( $shop_logo ) ) : ?>
                            <img src="data:image/jpeg;base64,<?php echo esc_attr( $shop_logo ); ?>" width="48" alt="<?php esc_attr_e( 'Shop Logo', 'hippoo' ); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <h4 class="text-left"><?php esc_html_e( 'Ship to:', 'hippoo' ); ?></h4><br>
                        <div class="address">
                            <?php echo esc_html( $order->get_formatted_shipping_full_name() ); ?><br>
                            <?php echo esc_html( $one_line_address ); ?>
                        </div>
                    </td>
                </tr>
                <?php if ( isset( $settings['shipping_calculate_weight'] ) && ! empty( $settings['shipping_calculate_weight'] ) ) : ?>
                <tr>
                    <td colspan="2" class="additional">
                        <h4><?php esc_html_e( 'Weight:', 'hippoo' ); ?> <?php echo esc_html( $weight ); ?> <?php echo esc_html( get_option( 'woocommerce_weight_unit' ) ); ?></h4>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="no-border">
                    <td colspan="2" class="text-center">
                        <h4><?php esc_html_e( 'Invoice', 'hippoo' ); ?> <?php echo esc_html( $order->get_id() ); ?></h4><br>
                        <div class="invoice-barcode">
                            <img src="data:image/jpeg;base64,<?php echo esc_attr( $invoice_barcode ); ?>" alt="<?php esc_attr_e( 'Invoice Barcode', 'hippoo' ); ?>">
                            <br><br><br>
                            <?php if ( $settings['shipping_courier_logo'] ) : ?>
                                <img class="courier_logo" src="data:image/jpeg;base64,<?php echo esc_attr( $shipping_courier_logo ); ?>" alt="<?php esc_attr_e( 'Courier Logo', 'hippoo' ); ?>">
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
