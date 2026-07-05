# Release Notes for Container Deposits

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
