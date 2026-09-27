[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-container-deposits?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-container-deposits)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/container-deposits?craft5)

![Container Deposits for Craft Commerce](https://johnhenry.ie/images/plugins/promos/container-deposits/1.png)

# Container Deposits for Craft Commerce

Container deposits for Craft Commerce, for Ireland's Re-turn scheme or any other deposit return scheme. Each deposit goes on its own cart line, moves with the quantity, counts every container in a multipack, and stays out of VAT and your discounts.

## Features

- **Per-variant deposits**: Pick a deposit type for any variant, or for a whole product, from a custom field
- **Auto-managed line items**: Deposits show up as their own line items and keep pace with quantity changes
- **Admin-managed deposit list**: Keep a list of deposit tiers in the control panel (e.g. €0.15 cans, €0.25 bottles)
- **Multipacks handled properly**: Tell it how many containers are in a unit and a 6-pack charges six deposits, a tray of 24 charges 24
- **Tax-exempt (VAT/sales tax)**: Deposit line items sit under a dedicated "Container Deposits (No VAT)" tax category
- **No discount leakage**: Deposits are never promotable or on sale, and they don't count toward a discount's minimum spend or quantity
- **Product and invoice display helpers**: Twig helpers and example templates for product cards, carts, and Re-turn-shaped producer invoices and till receipts
- **Multi-site and multi-store ready**: Deposit purchasables propagate to every site out of the box
- **Re-turn ready**: Matches the official Irish Deposit Return Scheme structure out of the box

## Documentation

Full documentation is at [johnhenry.ie/plugins/container-deposits/docs](https://johnhenry.ie/plugins/container-deposits/docs/getting-started/overview).

## Requirements

- Craft CMS 5.0 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

## Accessibility

How accessible the plugin is, what's been checked, and how to report a problem are all in the [accessibility statement](https://github.com/john-henry/craft-container-deposits/blob/craft-5/ACCESSIBILITY.md).

## Support

Need a hand? Open an issue on the [GitHub Issues page](https://github.com/john-henry/craft-container-deposits/issues).

## License

Proprietary. Copyright (c) 2026 John Henry Donovan.

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
