<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<html>
<head>
    <title><?php esc_html_e( 'Invoice', 'hippoo' ); ?> <?php echo esc_html( $order->get_id() ); ?></title>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page {
            <?php
            $paper_size = isset($settings['invoice_paper_size']) ? $settings['invoice_paper_size'] : 'A4';
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
        .rtl{
            direction: rtl;
        }

        th, td {
            padding: 15px;
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

        /* shop details table */

        table.header-table {
            border-bottom: 1px solid #E0E0E0;
        }

        table.header-table th,
        table.header-table td {
            padding-left: 0;
        }

        td.shop-logo {
            width: 50px;
        }

        .shop-name {
            font-weight: bold;
            font-size: 16px;
        }

        .shop-desc {
            font-weight: bold;
            font-size: 14px;
        }

        td.invoice-info {
            width: 30%;
            text-align: center;
        }

        .invoice-id {
            font-weight: bold;
            font-size: 13px;
        }

        /* shipping details table */

        table.ship-table th,
        table.ship-table td {
            padding-left: 0;
            vertical-align: top;
        }

        .bill {
            width: 40%;
            padding-right: 25px;
        }

        /* order items table */

        table.item-table {
            vertical-align: middle;
        }

        table.item-table thead tr th,
        table.item-table tbody tr td,
        table.item-table tr.subtotal-row td,
        table.item-table tr.total-row td:nth-child(n+2) {
            border-top: 1px solid #E0E0E0;
        }

        .sku {
            font-size: 12px;
        }

        th,
        table.item-table tfoot td {
            font-weight: bold;
        }

        /* rtl */

        .rtl .text-left {
            text-align: right;
        }

        .rtl .text-right {
            text-align: left;
        }

        .rtl table.ship-table th,
        .rtl table.header-table th,
        .rtl table.ship-table td,
        .rtl table.header-table td {
            padding-left: 15px;
            padding-right: 0;
        }

        .rtl .bill {
            padding-right: auto;
            padding-left: 25px;
        }
    </style>
</head>
<body>
    <div class="wrapper <?php echo esc_attr( $direction ); ?>">
        <table class="header-table">
            <tbody>
                <tr>
                    <td class="shop-logo text-left">
                        <?php if ( isset( $settings['invoice_show_logo'] ) && ! empty( $shop_logo ) ) : ?>
                            <img src="data:image/jpeg;base64,<?php echo esc_attr( $shop_logo ); ?>" width="48" alt="<?php esc_attr_e( 'Shop Logo', 'hippoo' ); ?>">
                        <?php endif; ?>
                    </td>
                    <td class="shop-info text-left">
                        <h3 class="shop-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h3>
                        <h4 class="shop-desc"><?php echo esc_html( get_bloginfo( 'description' ) ); ?></h4>
                    </td>
                    <td class="invoice-info">
                        <div class="invoice-id"><?php esc_html_e( 'Invoice', 'hippoo' ); ?> <?php echo esc_html( $order->get_id() ); ?></div>
                        <div class="invoice-barcode">
                            <img src="data:image/jpeg;base64,<?php echo esc_attr( $invoice_barcode ); ?>" alt="<?php esc_attr_e( 'Invoice Barcode', 'hippoo' ); ?>">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <?php echo wp_kses_post( $shop_address ); ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <table class="ship-table">
            <thead>
                <tr>
                    <th class="text-left bill"><?php esc_html_e( 'Bill to:', 'hippoo' ); ?></th>
                    <?php if ( isset( $settings['show_customer_note'] ) && $settings['show_customer_note'] && $customer_note ) : ?>
                        <th class="text-left"><?php esc_html_e( 'Customer note:', 'hippoo' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="bill"><?php echo esc_html( $order->get_formatted_shipping_full_name() ); ?><br><?php echo esc_html( $one_line_address ); ?></td>
                    <?php if ( isset( $settings['show_customer_note'] ) && $settings['show_customer_note'] && $customer_note ) : ?>
                        <td><?php echo esc_html( $customer_note ); ?></td>
                    <?php endif; ?>
                </tr>
            </tbody>
        </table>
        <table class="item-table">
            <thead>
                <tr>
                    <th class="text-left"><?php esc_html_e( 'Item', 'hippoo' ); ?></th>
                    <?php if ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] && ! empty( $sku ) ) : ?>
                        <th class="text-center"><?php esc_html_e( 'SKU', 'hippoo' ); ?></th>
                    <?php endif; ?>
                    <th class="text-center"><?php esc_html_e( 'Qty', 'hippoo' ); ?></th>
                    <th class="text-center"><?php esc_html_e( 'Price', 'hippoo' ); ?></th>
                    <th class="text-right"><?php esc_html_e( 'Amount', 'hippoo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $item ) : 
                    $product = $item->get_product();
                    if ( $product ) {
                        $sku = $product->get_sku();
                    }
                ?>
                <tr>
                    <td class="text-left"><?php echo esc_html( $item->get_name() ); ?></td>
                    <?php if ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] && ! empty( $sku ) ) : ?>
                        <td class="text-center">
                            <img src="data:image/jpeg;base64,<?php echo esc_attr( base64_encode( generate_barcode_image( $product->get_sku() ) ) ); ?>" alt="<?php esc_attr_e( 'Product SKU Barcode', 'hippoo' ); ?>">
                            <div class="sku"><?php echo esc_html( $product->get_sku() ); ?></div>
                        </td>
                    <?php endif; ?>
                    <td class="text-center"><?php echo esc_html( $item->get_quantity() ); ?></td>
                    <td class="text-center"><?php echo esc_html( $product ? $product->get_price() : '' ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                    <td class="text-right"><?php echo esc_html( $item->get_total() ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="subtotal-row">
                    <td colspan="2"></td>
                    <td colspan="1" class="text-right"><?php esc_html_e( 'Subtotal', 'hippoo' ); ?></td>
                    <td colspan="<?php echo ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] ) ? 2 : 1; ?>" class="text-right"><?php echo esc_html( $order->get_subtotal() ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                </tr>
                <?php if ( $order->get_total_discount() ) : ?>
                    <tr class="discount-row">
                        <td colspan="2"></td>
                        <td colspan="1" class="text-right"><?php esc_html_e( 'Discount', 'hippoo' ); ?></td>
                        <td colspan="<?php echo ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] ) ? 2 : 1; ?>" class="text-right">-<?php echo esc_html( $order->get_total_discount() ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( $order->get_total_tax() ) : ?>
                    <tr class="tax-row">
                        <td colspan="2"></td>
                        <td colspan="1" class="text-right"><?php esc_html_e( 'Tax', 'hippoo' ); ?></td>
                        <td colspan="<?php echo ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] ) ? 2 : 1; ?>" class="text-right">+<?php echo esc_html( $order->get_total_tax() ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="2"></td>
                    <td colspan="1" class="text-right"><?php esc_html_e( 'Total', 'hippoo' ); ?></td>
                    <td colspan="<?php echo ( isset( $settings['show_product_sku_invoice'] ) && $settings['show_product_sku_invoice'] ) ? 2 : 1; ?>" class="text-right"><?php echo esc_html( $order->get_total() ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
                </tr>
            </tfoot>
        </table>
        <div class="text-center">
            <?php if ( isset( $settings['footer_description'] ) && $settings['footer_description'] != "" ) : ?>
                <?php echo wp_kses_post( $settings['footer_description'] ); ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>