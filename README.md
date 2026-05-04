# ZUOV TablePress and Google Sheets Sync

ZUOV TablePress and Google Sheets Sync is a WordPress plugin by Aleksa Eremija that synchronizes public Google Sheets CSV exports into existing TablePress tables.

It was originally built for the ZUOV website workflow and later generalized for open-source use by Aleksa Eremija with help from OpenAI Codex.

## Features

- Sync one or more Google Sheets into one or more existing TablePress tables.
- Preserve existing TablePress table settings, shortcode usage, CSS classes, and DataTables configuration.
- Manual sync from the WordPress admin screen.
- Optional scheduled checks through WP-Cron.
- Webhook support for Google Apps Script.
- Optional manual sync menu inside Google Sheets.
- Optional W3 Total Cache purge for the page that displays each table.
- Optional empty trailing row cleanup.
- Optional automatic numbering of the first column on the website.
- Optional row sorting by the second column:
  - English / Latin A-Z
  - Serbian Cyrillic azbuka order
  - no sorting
- English admin interface by default.
- Serbian translation included through standard WordPress `.po/.mo` language files.

## Requirements

- WordPress 6.0 or newer.
- PHP 7.4 or newer.
- TablePress plugin installed and active.
- A Google Sheet that can be read by the server without a Google login screen.

## Installation

1. Download the plugin ZIP.
2. In WordPress admin, open `Plugins > Add New > Upload Plugin`.
3. Upload and activate the plugin.
4. Open `TP Google Sync` in the WordPress admin menu.
5. Add one sync profile for each Google Sheet / TablePress table pair.
6. Save settings.
7. Run a manual sync for the first test.

## Sync Profiles

Each sync profile maps one Google Sheet to one TablePress table.

Profile fields:

- `Profile name`: internal label for admins.
- `Google Sheet URL`: standard Google Sheets `/edit` URL or direct CSV export URL.
- `TablePress ID`: existing TablePress table ID to update.
- `Cache purge URL`: optional page URL to purge after a successful update.
- `Remove empty trailing rows`: removes rows that have no useful data.
- `Renumber the first column`: writes row numbers on the website after import.
- `Sort rows by the second column`: no sort, English A-Z, or Serbian azbuka.

The plugin does not create TablePress tables. Create the target table in TablePress first, then use its ID in the sync profile.

## Google Sheets Setup

See [docs/google-sheets-setup.md](docs/google-sheets-setup.md) for the full Google Sheets sharing and Apps Script setup guide.

Short version:

1. Open the Google Sheet.
2. Use `Share > General access > Anyone with the link > Viewer`.
3. Give `Editor` access only to trusted Google accounts.
4. Copy the sheet `/edit` URL into the matching sync profile.
5. Save settings and run a manual sync.
6. For a button inside Google Sheets, copy the profile-specific webhook URL into the Apps Script sample.

Do not make the public link editable.

## Google Apps Script Manual Sync Menu

Use the profile-specific webhook URL shown in the plugin settings.

```javascript
const ZUOV_SYNC_URL = 'PASTE_PROFILE_WEBHOOK_URL_HERE';

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('TablePress Sync')
    .addItem('Sync with website', 'tablePressSyncManual')
    .addToUi();
}

function tablePressSyncManual() {
  const url = ZUOV_SYNC_URL + (ZUOV_SYNC_URL.indexOf('?') === -1 ? '?' : '&') + 'force=1';
  const response = UrlFetchApp.fetch(url, {
    method: 'get',
    muteHttpExceptions: true
  });

  const code = response.getResponseCode();
  const text = response.getContentText();

  if (code < 200 || code >= 300) {
    throw new Error('Sync failed. HTTP ' + code + ': ' + text.substring(0, 500));
  }

  SpreadsheetApp.getUi().alert('Sync request sent. Website response: ' + text.substring(0, 500));
}
```

## Scheduled Sync

The plugin supports these WP-Cron intervals:

- Manual / webhook only
- Every 5 minutes
- Every 15 minutes
- Every 30 minutes
- Hourly
- Twice daily
- Daily

Cron should be treated as a fallback. For editorial workflows, the Google Sheets custom menu is usually clearer because an editor can update the site after finishing edits.

## Webhooks

Two webhook styles are available:

- Front-end query webhook: `https://example.com/?zuov_tpgs_sync=1&secret=...`
- REST webhook fallback: `https://example.com/wp-json/zuov-tpgs/v1/sync?secret=...`

The front-end query webhook is recommended on sites where security plugins restrict REST API access.

Use a profile-specific webhook URL when one Google Sheet should update only one TablePress table. Use the all-profiles webhook when one request should check all enabled profiles.

Keep the webhook secret private.

## Internationalization

The plugin uses standard WordPress translation files:

- `languages/zuov-tpgs.pot`
- `languages/zuov-tpgs-sr_RS.po`
- `languages/zuov-tpgs-sr_RS.mo`

English is the source language and default interface language. To add another language, create a new `.po` file from `zuov-tpgs.pot`, translate it, compile the matching `.mo` file, and place both files in the `languages` directory.

## Technical Notes

- The plugin imports Google Sheets through CSV export URLs.
- Table data is parsed with the TablePress CSV parser.
- Table data is saved through the TablePress table model, preserving table metadata and options.
- A short transient lock prevents parallel sync runs.
- A SHA-256 hash prevents unnecessary writes when the Google Sheet has not changed.
- W3 Total Cache URL/post flushing is attempted after successful profile syncs when the relevant functions are available.
- Reverse sync from TablePress/WordPress back to Google Sheets is intentionally not included. That would require a separate editor UI, authorization model, and Google API write access.

## Author

Created by Aleksa Eremija.

This plugin was developed and generalized with assistance from OpenAI Codex.

## License

GPL-2.0-or-later.
