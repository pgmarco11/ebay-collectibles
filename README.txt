Complete replacement files for ebay-collectibles

Prepared from committed revision e90f0a3dbdb1041f5b8f42296db283a200a42719.
This ZIP contains update files only, not a complete installable plugin.

INSTALL
1. Back up your existing wp-content/plugins/ebay-collectibles folder and database.
2. Extract this ZIP.
3. Copy the CONTENTS of the extracted ebay-collectibles folder into your existing
   wp-content/plugins/ebay-collectibles folder. Replace the five matching PHP files
   and js/ebay-item.js. Add the includes folder and its two PHP files.
4. Keep your existing ebay.env, CSS, images, and other files. Do not delete them.
   If your copy has changed since the revision above, compare those edits before replacing.
5. Clear any WordPress page cache. Open the plugin admin page and refresh Auctions
   and Buy It Now. Check existing listings, shortcode pages, and item lookup.
6. Open a page editor and check that shortcode previews do not trigger API requests.
   Reload a shortcode page twice to check cached loading.

FILES
Replace:
  ebay-main.php
  ebay-auctions.php
  ebay-selling-fetcher.php
  ebay-check-item.php
  ebay-top-collectibles-widget.php
  js/ebay-item.js
Add:
  includes/class-tcs-ebay-api-client.php
  includes/ebay-api-cache.php

BEHAVIOR
All eBay HTTP calls in these files pass through one shared API client.
Each API family has its own conservative local trailing-day budget:
Browse 4000, Trading 4000, Taxonomy 4000, OAuth 800.
The tcs_ebay_api_budgets WordPress filter can reduce these for your actual allocation.
These local counters cannot see requests from other applications or installations.
Verify your developer account allocation before assuming these budgets fit your account.
The client also has burst guards, request reservation locks, and rate-limit cooldowns.
HTTP 429 honors Retry-After; missing Retry-After pauses that API for one hour.
Trading quota errors inside HTTP 200 XML responses also trigger a pause.

Auction listing/item caches: 5 minutes. Buy It Now listing/item caches: 15 minutes.
Shortcode empty/error caches remain short. Seller raw inventory is shared for 1 minute.
Item lookup search results are cached for 5 minutes. Item failures are cached for 1 minute.
Expiration permits a new fetch on the next request; expiration does not run a refresh itself.
The existing seller-import schedule remains. This update does not add a scheduler,
nor does it automatically rewrite imported posts whenever a cache expires.
Existing taxonomy/seller identity caches retain their original durations.

Seller imports fetch all inventory pages, then prepare required item details before
post reconciliation. Failed, incomplete, empty, or oversized inventory responses preserve
existing posts. Inventories requiring more than 100 pages stop for a batched-import change.
The existing grouping and auction/Buy It Now shortcode layouts are preserved.
AJAX item-details requests now require the nonce supplied by the updated JavaScript.

VALIDATION
Seven PHP files passed syntax checks; JavaScript passed node --check.
14 mocked cache/layout checks and 21 mocked API/import checks passed.
No real eBay requests or live localhost WordPress tests were performed.
No repository changes were pushed.
