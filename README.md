# Google Docs Add-On for Gravity Forms

This WordPress plugin integrates Gravity Forms with Google Docs, allowing you to automatically create Google Docs from form submissions using customizable content templates.

## Features

- Create Google Docs automatically from form submissions
- Use Gravity Forms merge tags to insert form data into documents
- Specify custom document titles and content templates
- Optional Google Drive folder organization
- OAuth2 authentication with Google
- Conditional logic for when documents are created
- Automatic document creation on form submission
- Store document IDs and URLs with form entry notes
- Comprehensive logging and error handling

## Requirements

- WordPress 5.0 or higher
- Gravity Forms 2.7 or higher
- PHP 7.4 or higher
- Google Cloud Project with Google Docs API and Google Drive API enabled
- Google OAuth 2.0 credentials

## Installation

1. Download the plugin files
2. Upload the plugin folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Forms > Settings > Google Docs to configure the plugin

## Setup

1. Set Up Google Cloud Project:
   - Go to the [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project or select an existing one
   - Enable the Google Docs API and Google Drive API for your project
   - Go to Credentials and create OAuth 2.0 Client ID credentials (Web Application)
   - Add your site's domain to the authorized domains
   - Add the redirect URI: `https://your-site.com/wp-admin/admin.php?page=gf_settings&subview=google_docs`
   - Copy your Client ID and Client Secret

2. Configure the Plugin:
   - Go to Forms > Settings > Google Docs
   - Enter your Google Client ID and Client Secret
   - Click "Save Settings"
   - Click the "Connect with Google" button
   - Sign in to your Google account
   - Review and approve the requested permissions
   - You will be redirected back to your site once connected

3. Create a Feed:
   - Edit your form
   - Go to Settings > Google Docs
   - Click "Add New"
   - Enter a feed name
   - Configure your document title and content templates
   - Optionally specify a Google Drive folder ID
   - Set up conditional logic if needed
   - Save the feed

## Usage

1. Create a feed in your form settings (as described above)
2. Use Gravity Forms merge tags in your document title and content templates
3. Submit the form to automatically create a new Google Doc
4. The document will be created with the form data inserted where merge tags were used
5. Document ID and URL are automatically stored with the form entry

## Content Templates

Use Gravity Forms merge tags to insert form data into your documents:

**Document Title Example:**
```
Application from {Name:2} - {Date of Entry}
```

**Document Content Example:**
```
Name: {Name:2}
Email: {Email:3}
Phone: {Phone:4}
Message: {Message:5}

Submitted on: {Date of Entry}
Entry ID: {Entry ID}
```

## Available Merge Tags

- `{Field Label:Field ID}` - Insert field value by ID
- `{Date of Entry}` - Submission date
- `{Entry ID}` - Unique entry identifier
- `{Form Title}` - The form's title
- `{User IP}` - Submitter's IP address
- And all other standard Gravity Forms merge tags

## Advanced Features

### Google Drive Folder Organization
You can organize your documents by specifying a Google Drive folder ID in your feed settings. To find the folder ID:
1. Navigate to the desired folder in Google Drive
2. Copy the folder ID from the URL: `https://drive.google.com/drive/folders/[FOLDER_ID]`
3. Paste this ID into the "Google Drive Folder ID" field in your feed settings

## Troubleshooting

### Common Issues

**"API not authenticated" error:**
- Verify your Google Cloud Project has the correct APIs enabled
- Check that your OAuth credentials are correctly configured
- Ensure the redirect URI matches exactly
- Try disconnecting and reconnecting your Google account

**Documents not being created:**
- Check that your feed is active and properly configured
- Verify that conditional logic (if used) is being met
- Review the form's entry notes for any error messages
- Enable debug logging to see detailed error information

**Permission denied errors:**
- Ensure your Google account has permission to create documents
- Check that the Google Drive API is enabled in your Google Cloud Project
- Verify that you've granted all requested permissions during OAuth setup

```

## Changelog

### Version 1.0.0 (2025-08-11)
- Initial release

## Support

For support, please create an issue in the GitHub repository or contact the plugin author at [gravityranger.com](https://gravityranger.com/contact).

## License

This plugin is licensed under the GPL v3 or later. See the LICENSE file for details.

## Credits

- Built with the Gravity Forms Add-On Framework
- Uses the Google API Client Library for PHP
- Created by [Chris Eggleston](https://gravityranger.com)
