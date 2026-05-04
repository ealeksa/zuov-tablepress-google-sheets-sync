# Google Sheets Setup Guide

This guide explains how to prepare a Google Sheet for synchronization with ZUOV TablePress and Google Sheets Sync.

## 1. Share the Sheet for Read Access

The WordPress server must be able to read the Google Sheet CSV export without a Google login screen.

Recommended sharing setup:

1. Open the Google Sheet.
2. Click `Share`.
3. Under `General access`, choose `Anyone with the link`.
4. Set the role to `Viewer`.
5. Copy the normal Google Sheets `/edit` link.

Do not set the public link to `Editor`.

Give `Editor` access only to trusted Google accounts that are allowed to maintain the data.

## 2. Create the TablePress Target Table

The plugin updates existing TablePress tables. It does not create them automatically.

1. Open `TablePress` in WordPress admin.
2. Create or import the target table.
3. Note the TablePress table ID.
4. Add that ID to the matching sync profile in `TP Google Sync`.

## 3. Configure a Sync Profile

In WordPress admin:

1. Open `TP Google Sync`.
2. Add or edit a sync profile.
3. Paste the Google Sheet URL.
4. Enter the TablePress table ID.
5. Optionally enter the page URL that should be purged from W3 Total Cache.
6. Choose the row processing options.
7. Choose the sort mode:
   - `Do not sort`
   - `English / Latin A-Z`
   - `Serbian Cyrillic azbuka`
8. Save settings.
9. Run a manual sync.

## 4. Add a Manual Sync Menu in Google Sheets

Use this if editors should update the website only after finishing changes.

1. In the plugin settings, copy the profile-specific webhook URL.
2. In Google Sheets, open `Extensions > Apps Script`.
3. Replace the default script with this code.
4. Replace `PASTE_PROFILE_WEBHOOK_URL_HERE` with the copied webhook URL.

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

  Logger.log('HTTP ' + code);
  Logger.log(text);

  if (code < 200 || code >= 300) {
    throw new Error('Sync failed. HTTP ' + code + ': ' + text.substring(0, 500));
  }

  SpreadsheetApp.getUi().alert('Sync request sent. Website response: ' + text.substring(0, 500));
}
```

Save the Apps Script project, run `tablePressSyncManual` once from the Apps Script editor, and approve the requested Google authorization.

Reload the Google Sheet. A `TablePress Sync` menu should appear.

## 5. Optional Automatic Trigger

Manual sync is usually safer for editorial workflows. If you still want automatic sync, create an installable trigger in Apps Script:

- Function: `tablePressSyncManual`
- Event source: `From spreadsheet`
- Event type: `On change` or `On edit`

Automatic triggers can fire while editors are still changing or sorting rows. Use with care.

## 6. Troubleshooting

If sync fails:

- Open the direct CSV URL shown in the plugin settings. It should download or display CSV, not HTML.
- Confirm that the Google Sheet public link is `Viewer`, not private.
- Confirm that the TablePress ID exists.
- Confirm that the webhook secret in Apps Script matches the current plugin setting.
- Check the profile status in the plugin settings.
- Check server PHP error logs if the WordPress admin status is not enough.

