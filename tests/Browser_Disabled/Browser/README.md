# Browser (E2E) Tests

This directory contains end-to-end browser tests using Pest v4's browser testing capabilities.

## Test Files

### 1. **AuthenticationFlowTest.php**
Tests user authentication flows:
- User registration
- Login and logout
- Password reset
- Validation errors

### 2. **DepositManagementTest.php**
Tests deposit CRUD operations:
- Creating deposits
- Editing deposits
- Deleting deposits
- Viewing deposit summaries

### 3. **StockTransactionFlowTest.php**
Tests stock transaction workflows:
- Creating buy transactions with stop loss/take profit
- Creating sell transactions linked to buys
- Viewing remaining quantities
- Editing transactions
- Profit/loss calculations

### 4. **MarketDataBrowsingTest.php**
Tests market data features:
- Viewing all assets (stocks and crypto)
- Viewing individual asset details
- Viewing price history
- Navigation between pages

### 5. **DashboardAndSettingsTest.php**
Tests dashboard and user settings:
- Dashboard metrics display
- Profile updates
- Password changes
- Appearance theme toggling
- Two-factor authentication
- Navigation between sections
- Empty states

### 6. **SmokeTest.php**
Quick validation of all pages:
- All public pages load without errors
- All authenticated pages load without errors
- Critical forms work correctly

## Running the Tests

### Run All Browser Tests
```bash
php artisan test tests/Feature/Browser/
```

### Run Specific Test File
```bash
php artisan test tests/Feature/Browser/AuthenticationFlowTest.php
```

### Run Specific Test
```bash
php artisan test --filter="allows users to register a new account"
```

### Run Smoke Tests (Quick Validation)
```bash
php artisan test tests/Feature/Browser/SmokeTest.php
```

## Browser Configuration

Pest v4 uses real browsers for testing. By default, it uses:
- **Chrome** (Chromium-based)
- **Headless mode** for CI/CD

### Running in Headed Mode
To see the browser during tests (useful for debugging):
```bash
HEADLESS=false php artisan test tests/Feature/Browser/
```

### Testing on Different Browsers
```bash
# Firefox
BROWSER=firefox php artisan test tests/Feature/Browser/

# Safari (macOS only)
BROWSER=webkit php artisan test tests/Feature/Browser/
```

### Testing on Different Devices
```bash
# Mobile viewport
DEVICE="iPhone 14 Pro" php artisan test tests/Feature/Browser/

# Tablet
DEVICE="iPad Pro" php artisan test tests/Feature/Browser/
```

## Test Features

### What's Being Tested
✅ User interactions (clicks, form fills, navigation)  
✅ JavaScript errors  
✅ Console logs  
✅ Form validations  
✅ Success/error messages  
✅ Data persistence  
✅ Navigation flows  
✅ CRUD operations  
✅ Authentication & authorization  

### Best Practices
- Tests run in isolated browser instances
- Database is automatically reset between tests using `RefreshDatabase`
- Tests use factories for data generation
- Tests validate both UI and database state
- Tests check for JavaScript errors and console logs

## Debugging Tests

### Take Screenshots
Add `->screenshot('debug')` to any page interaction:
```php
$page->click('button')
    ->screenshot('after-click')
    ->assertSee('Success');
```

Screenshots are saved to `tests/Browser/screenshots/`

### Pause Execution
Add `->pause()` to stop execution and inspect:
```php
$page->fill('email', 'test@test.com')
    ->pause()  // Browser stays open
    ->click('Submit');
```

Press Enter in terminal to continue.

### View Browser Console
Add `->assertNoConsoleLogs()` to see console output if tests fail.

## CI/CD Integration

Browser tests work great in CI/CD pipelines:

```yaml
# GitHub Actions example
- name: Run Browser Tests
  run: |
    php artisan test tests/Feature/Browser/
  env:
    HEADLESS: true
```

## Coverage

Current E2E test coverage includes:
- **Authentication**: Registration, login, logout, password reset
- **Deposits**: Full CRUD operations
- **Stock Transactions**: Buy/sell with stop loss/take profit
- **Market Data**: Asset browsing and price history
- **Settings**: Profile, password, appearance, 2FA
- **Navigation**: All major sections and pages
- **Smoke Tests**: All pages load without errors

## Requirements

- PHP 8.4+
- Laravel 12+
- Pest v4+
- Node.js (for building frontend assets)
- Chrome/Chromium browser installed

## Notes

- Browser tests are slower than unit tests (use sparingly for critical flows)
- Run unit/feature tests first, then browser tests
- Keep browser tests focused on user workflows, not implementation details
- Use smoke tests for quick validation of all pages
