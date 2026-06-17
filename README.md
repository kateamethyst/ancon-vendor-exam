## Files

* `FeeCalculatorService.php` - Main service
* `FeeCalculatorServiceTest.php` - Unit tests, including the sample scenario from the spec

## Assumptions

### Money Calculations

* All calculations must be performed in cents to avoid floating-point precision issues since this is monetary data.
* Amounts and percentages are normalized before calculations are performed.
* Rounding is applied at the cent level.

### Vendor Configuration

* I set the vendor configuration to be flexible, assuming that there will be a different configuration for each vendor in the future.
* Missing configuration values will fall back to default values.
* Callers only need to provide values that differ from the defaults.

### Validation

* Every invoice line must contain:

  * A valid `manifest_number`
  * A valid numeric `amount`
* Invalid data throws an `InvalidArgumentException` with the corresponding line number.

### Manifest Grouping

* Manifests are grouped by `manifest_number`.
* Manifest order is preserved based on the first occurrence in the invoice.
* Results are returned as an associative array keyed by manifest number for easier lookups.

### Supported Input Types

The service accepts:

* Integer values
* Float values
* Numeric strings

All values are normalized before calculations are performed.

## Time Spent

Approximately 1 hour.

Most of the time was spent reviewing calculation edge cases, validation rules, rounding behavior, and writing unit tests.

## Future Improvements

### Use DTOs

Replace array responses with typed DTOs such as:

* `ManifestBreakdown`
* `InvoiceResult`

This would provide stronger type safety and make the contract easier to maintain.

### Better Validation Reporting

Instead of failing on the first invalid invoice line, collect and return all validation errors to improve batch processing workflows.

### Configurable Rounding

Allow rounding behavior to be configured per vendor in case different vendors require different rounding rules.
