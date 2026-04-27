# Gravity Forms Google Docs Add-On

This WordPress plugin integrates Gravity Forms with Google Docs, allowing you to automatically create Google Docs from form submissions using customizable content templates.

## Features

- Create Google Docs automatically from form submissions
- Async feed processing so document creation does not block notifications or other feeds
- Use Gravity Forms merge tags to insert form data into documents
- Specify custom document titles and content templates
- Optional Google Drive folder organization
- OAuth2 authentication with Google
- Conditional logic for when documents are created
- Automatic document creation on form submission
- Document ID and URL stored on the entry (entry meta); status and errors recorded on the entry notes
- Optional debug logging via the `GF_GOOGLE_DOCS_DEBUG` constant
- One-time migration of settings, tokens, feeds, and entry meta from legacy add-on slugs (`google_docs`, `gr-google-docs`) to `gravityformsgoogledocs`

## Requirements

- WordPress 5.0 or higher
- Gravity Forms 2.7 or higher
- PHP 7.4 or higher
- [Composer](https://getcomposer.org/) dependencies installed in the plugin directory (`vendor/` must exist; see Installation)
- Google Cloud project with Google Docs API and Google Drive API enabled
- Google OAuth 2.0 credentials (Web application)

## Installation

1. Copy the plugin into your site so the main file path is **`wp-content/plugins/gravityformsgoogledocs/googledocs.php`** (folder name must match what Gravity Forms expects for this add-on).
2. From the plugin directory, install PHP dependencies:
   ```bash
   composer install --no-dev
   ```
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Go to **Forms → Settings → Google Docs** to configure OAuth and global settings.

If `vendor/autoload.php` is missing, activation will fail with an error asking you to run `composer install`.

## Setup

1. **Google Cloud project**
   - Open the [Google Cloud Console](https://console.cloud.google.com/).
   - Create or select a project.
   - Enable the **Google Docs API** and **Google Drive API**.
   - Under **Credentials**, create **OAuth 2.0 Client ID** credentials (application type: **Web application**).
   - Add your site’s domain under authorized domains if required by Google.
   - Add this **authorized redirect URI** (replace `https://your-site.com` with your site URL):
     ```
     https://your-site.com/wp-admin/admin.php?page=gf_settings&subview=gravityformsgoogledocs
     ```
   - Copy the **Client ID** and **Client Secret**.

2. **Plugin settings**
   - Go to **Forms → Settings → Google Docs**.
   - Enter the Client ID and Client Secret, save, then use **Connect with Google** and approve the requested scopes.
   - After connecting, you will be returned to the add-on settings screen.

3. **Form feed**
   - Edit a form → **Settings → Google Docs** → **Add New**.
   - Name the feed, set document title and content templates, optional Drive folder ID, and conditional logic as needed, then save.

## Usage

1. Configure at least one Google Docs feed on the form.
2. Use Gravity Forms merge tags in the document title and body templates.
3. On submission, a new Google Doc is created and populated with merged values.
4. The document link and ID are stored on the entry; notes record success or failure details.

## Content templates

Use Gravity Forms merge tags in templates, for example:

**Document title**

```
Application from {Name:2} - {Date of Entry}
```

**Body**

```
Name: {Name:2}
Email: {Email:3}
Phone: {Phone:4}
Message: {Message:5}

Submitted on: {Date of Entry}
Entry ID: {Entry ID}
```

## Available merge tags

- `{Field Label:Field ID}` — field value by field ID
- `{Date of Entry}` — submission date
- `{Entry ID}` — entry ID
- `{Form Title}` — form title
- `{User IP}` — submitter IP
- Other standard Gravity Forms merge tags supported by the merge tag UI

## Advanced

### Google Drive folder

Use a folder ID from the Drive URL: `https://drive.google.com/drive/folders/FOLDER_ID` and paste `FOLDER_ID` into the feed’s folder field.

### Debug logging

In `wp-config.php` (or anywhere before the plugin loads), you can enable verbose logging:

```php
define( 'GF_GOOGLE_DOCS_DEBUG', true );
```

### Upgrading from a legacy build

If you previously used an add-on registered as `google_docs` or `gr-google-docs`, activating this version runs a one-time migration of add-on settings, access token option, feed rows, entry meta keys (`gfgoogledocs_doc_id`, `gfgoogledocs_doc_url`, `gfgoogledocs_error`), and role capabilities (`gravityforms_googledocs`). Update your Google OAuth **redirect URI** to use `subview=gravityformsgoogledocs` as shown above.

## Troubleshooting

**“Requires Composer dependencies” / activation error**

- Run `composer install` in the `gravityformsgoogledocs` plugin directory so `vendor/autoload.php` exists.

**“API not authenticated”**

- Confirm both APIs are enabled in Google Cloud.
- Verify Client ID/Secret and that the redirect URI matches exactly (including `subview=gravityformsgoogledocs`).
- Disconnect and reconnect Google from **Forms → Settings → Google Docs**.

**Documents not created**

- Confirm the feed is active and conditional logic passes.
- Check entry notes for errors.
- Enable `GF_GOOGLE_DOCS_DEBUG` for more detail in logs.

**Permission errors**

- Ensure the connected Google account can create Docs and access the target Drive folder.
- Re-authorize so all requested scopes are granted.

## Changelog

### 1.0.0

- Initial release as **Gravity Forms Google Docs Add-On** (`gravityformsgoogledocs`): OAuth settings at `subview=gravityformsgoogledocs`, Composer-based Google API client, async feed processing, entry meta keys `gfgoogledocs_*`, capabilities `gravityforms_googledocs` / `gravityforms_googledocs_uninstall`, and automatic migration from legacy `google_docs` / `gr-google-docs` data.

## Support

For issues with this repository, open a **GitHub issue**. For Gravity Forms core product support, see [https://www.gravityforms.com](https://www.gravityforms.com).

## License

GPL v3 or later. See the LICENSE file.

## Credits

- [Gravity Forms](https://www.gravityforms.com) add-on framework
- [Google API Client Library for PHP](https://github.com/googleapis/google-api-php-client)
