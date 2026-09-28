# Container Deposits: Tests

Tests use [Pest](https://pestphp.com/) on top of [`markhuot/craft-pest-core`](https://github.com/markhuot/craft-pest).

## Layout

- `Feature/`: pure PHP tests that don't need a running Craft application (model validation, helpers).
- `Integration/`: tests that require a real Craft + Commerce environment. Each test is wrapped in a DB transaction via `RefreshesDatabase` and rolled back on teardown.

## Running

From the parent Craft project (not from inside the plugin folder):

```bash
ddev exec composer test:cd
```

To run part of it, pass Pest's `--filter` through:

```bash
ddev exec composer test:cd -- --filter=DepositCartService
```

Always go through `composer test:cd`, which passes `--test-directory`. Without that flag Pest skips `Pest.php`, so `RefreshesDatabase` never runs and the tests write to your development database.

## Adding tests

- Anything that touches `Craft::$app`, `Commerce::getInstance()`, or saves an element belongs in `Integration/`.
- Pure model/enum/helper tests belong in `Feature/`, they run faster and require no DB.
- Shared helpers (e.g. `makeDepositType()`, `makeCart()`) live in [`Pest.php`](Pest.php).
