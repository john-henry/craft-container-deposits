# Release Notes for Container Deposits

## 1.1.0 - 2026-09-25

### Added
- Other plugins can now say what's packed inside a cart line, so the cans and bottles in a bundle or gift box are charged their deposit. Listen for `DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS`.
- The Container Deposit Type field can go on the product layout to cover every variant of a product. A deposit type field on the variant layout still wins.
- The Container Deposit Type field can be queried with GraphQL.
- Variants have a `containerDeposit` field in GraphQL with the deposit type, containers, unit deposit and the text to show beside the price.
- `formatAmount()` writes sterling amounts under 1 as pence, and `minorUnitSuffix()` returns the suffix it uses.

### Changed
- A discount's minimum purchase total and quantity are now checked without the deposit lines.
- Deposits no longer count as shippable, so they're left out of shipping rules' category checks.
- A deposit type that's still assigned to a product or variant can't be deleted.
- Deposits are left alone on an order saved with recalculation switched off.
- The example receipt's TOTAL is now the order total, with shipping, discount and tax rows above it when the order has them.
- Uninstalling the plugin now removes the deposit purchasables and the "Container Deposits (No VAT)" tax category.
- The installed package no longer includes the documentation site or the test suite, so it's about a third of the size.

### Fixed
- A product posted to the cart with a `_deposit` option is no longer mistaken for a deposit line and dropped from the cart.
- Deposits are recalculated when Commerce drops a cart line or cuts its quantity to the stock left.
- Carts and orders with a custom line item no longer fail to save, and their receipts no longer error.
- Deposits keep their full amount in every store when a promotional catalog pricing rule is running.
- Deposit type validation errors now show on the edit screen.
- A blank or non-numeric amount is now rejected instead of saving as 0.
- Deposit amounts in the field's dropdown are shown in the store's currency.
- `formatAmount()` no longer writes amounts in currencies like Swiss francs in cents.
- A warning is logged when the no-VAT tax category is missing and deposits fall back to the default one.
- Reinstalling over tables left behind no longer adds their indexes and foreign keys twice.
- Deleting a deposit type on the deposit types screen now moves keyboard focus to a sensible row or button instead of dropping it on the page.
- The amount shown beside a deposit type in a preview now meets contrast requirements.

### Security
- A customer can no longer lower their deposit by posting a cheaper deposit type's line or changing a deposit line's options.
- Deposit purchasables can no longer be added to a cart directly.

## 1.0.1 - 2026-07-05

### Fixed
- Deposit types with a €0.00 amount (e.g. a promotional "free return" tier) no longer vanish from the cart.
- Changing a product's quantity in the cart now reliably updates the matching deposit line item's quantity.
- Creating a deposit type with a handle that's already taken now shows a clear validation error instead of a server error.
- A deposit type that fails to save properly no longer gets stuck in a broken state where it can't be assigned to products.
- Managing deposit types no longer needs the "Allow admin changes" setting turned on, so you can add, edit, and delete deposit tiers on a locked-down production site the same as anywhere else.

### Changed
- Adding a new site to a multi-site install no longer waits around for deposit products to sync across every site; that now runs in the background via the queue.
- Removed the unused "reorder" action for deposit types; it wasn't exposed anywhere in the control panel anyway.
- The example invoice and receipt templates are now fully translatable: every label used to be hardcoded in English.
- Improved accessibility of the deposit types list and the example invoice/receipt/notice templates: a proper accessible label on the delete button, correct table headers for screen readers, and a semantic marker on the mandatory deposit notice.

## 1.0.0 - 2026-06-04

### Added
- Initial release
