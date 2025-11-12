# Google Drive Integration Setup Guide

This document provides instructions for setting up Google Drive integration for the Wastage Image Upload feature.

## Prerequisites

1. Google Cloud Project with Google Drive API enabled
2. Laravel application with Google Drive filesystem configuration

## Step 1: Google Cloud Project Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the Google Drive API:
   - Go to "APIs & Services" > "Library"
   - Search for "Google Drive API" and enable it

## Step 2: Create OAuth 2.0 Credentials

1. Go to "APIs & Services" > "Credentials"
2. Click "Create Credentials" > "OAuth 2.0 Client ID"
3. Select "Web application" as application type
4. Add authorized redirect URIs (if needed)
5. Create credentials and download the JSON file

## Step 3: Get Refresh Token

You have three options to get the refresh token. However, you may encounter common issues first:

### ⚠️ Common Issues and Solutions

#### 403: Access Denied (Testing Mode)
**Error:** "MyDavidUploadFiles has not completed the Google verification process"

**Quick Fix:**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Select your project → "APIs & Services" → "OAuth consent screen"
3. Scroll down to "Test users" section
4. Click "+ ADD USERS" and add your Google email
5. Click "Save" and wait 1-2 minutes
6. Try the authorization again

#### 400: Redirect URI Mismatch
**Error:** "redirect_uri_mismatch"

**Fix:** Add the exact redirect URI shown in the script to your Google Cloud Console credentials.

#### 401: Invalid Client
**Error:** "invalid_client"

**Fix:** Verify your Client ID and Client Secret are correct and match what's in Google Cloud Console.

---

### Refresh Token Options:

### Option 1: Use the Provided Web Script (Recommended)

1. **Configure the script**:
   - Open `get_google_refresh_token.php`
   - Update the configuration at the top with your Google Client ID and Client Secret
   - Set the correct redirect URI (must match what you configured in Google Console)

2. **Run the script**:
   - Place the file in your public directory or run it with PHP CLI
   - Access it in your browser: `http://your-domain.com/get_google_refresh_token.php`
   - Click "Get Refresh Token"

3. **Complete OAuth flow**:
   - You'll be redirected to Google for authorization
   - If you see a warning screen, click "Advanced" → "Go to [Your Project]" → "Allow"
   - This ensures you get a refresh token (not just an access token)

4. **Copy the refresh token**:
   - The script will display your refresh token
   - Add it to your `.env` file

### Option 2: Use the Command Line Script

1. **Configure the script**:
   - Open `artisan_google_auth.php`
   - Update the configuration with your Google Client ID and Client Secret
   - Set the correct redirect URI

2. **Generate authorization URL**:
   ```bash
   php artisan_google_auth.php
   ```

3. **Visit the URL** in your browser and complete the OAuth flow

4. **Exchange code for tokens**:
   ```bash
   php artisan_google_auth.php YOUR_AUTHORIZATION_CODE_HERE
   ```

5. **Copy the refresh token** that appears

### Option 3: Use Google OAuth 2.0 Playground

1. Go to [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground)
2. Click the gear icon (⚙️) and check "Use your own OAuth credentials"
3. Enter your Client ID and Client Secret
4. In Step 1, enter scope: `https://www.googleapis.com/auth/drive.file`
5. Click "Authorize APIs" and complete the flow
6. In Step 2, click "Exchange authorization code for tokens"
7. Copy the refresh token

## Step 4: Configure Laravel Environment

Add the following to your `.env` file:

```env
# Google Drive Configuration
GOOGLE_DRIVE_CLIENT_ID=your_google_client_id
GOOGLE_DRIVE_CLIENT_SECRET=your_google_client_secret
GOOGLE_DRIVE_REFRESH_TOKEN=your_refresh_token
GOOGLE_DRIVE_FOLDER_ID=your_google_drive_folder_id
```

### Getting Google Drive Folder ID

1. Create a folder in Google Drive for wastage images
2. Open the folder and copy the ID from the URL:
   - URL format: `https://drive.google.com/drive/folders/FOLDER_ID_HERE`
   - Copy only the `FOLDER_ID_HERE` part

## Step 5: Install Required Packages

```bash
# Install Google Drive adapter for Laravel
composer require modernmcguire/flysystem-google-drive

# If not already installed, add the service provider
# In config/app.php, add to providers array:
# Naoko\Flysystem\GoogleDrive\GoogleDriveServiceProvider::class,
```

## Step 6: Configure Filesystem

The Google Drive disk is already configured in `config/filesystems.php`:

```php
'google' => [
    'driver' => 'google',
    'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
    'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
    'folderId' => env('GOOGLE_DRIVE_FOLDER_ID'),
],
```

## Step 7: Run Database Migration

```bash
php artisan migrate
```

## Features

### Image Upload Component

The `ImageUpload.vue` component provides:
- Drag and drop image upload
- File type validation (JPEG, JPG, PNG only)
- File size validation (max 5MB)
- Image preview
- Progress indicator
- Error handling

### Backend Features

1. **GoogleDriveService**: Handles all Google Drive operations
   - Upload images to specified folder
   - Generate shareable URLs
   - Delete old images when replaced
   - Validate image files

2. **Database Integration**:
   - `image_url` field stores the Google Drive shareable link
   - Audit trail for image changes
   - Optional image field (not required)

3. **File Validation**:
   - Client-side: File type and size validation
   - Server-side: Additional validation and error handling

## Usage

### Creating Wastage Records

1. Fill in the required fields (store branch, cart items)
2. Optionally upload an image as evidence
3. Submit the form
4. Image will be uploaded to Google Drive and URL stored in database

### Editing Wastage Records

1. Existing images will be displayed
2. Users can replace the image or remove it
3. Old images are automatically deleted from Google Drive when replaced
4. Changes are tracked in the audit trail

## Security Considerations

1. Images are stored in a shared Google Drive folder
2. Access is controlled through Google Drive sharing settings
3. File uploads are validated for type and size
4. Refresh tokens should be kept secure and not exposed in version control

## Troubleshooting

### OAuth Authentication Issues

#### 403: Access Denied (Testing Mode)
**Error:** "MyDavidUploadFiles has not completed the Google verification process"

**Solution:**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Select your project → "APIs & Services" → "OAuth consent screen"
3. Scroll down to "Test users" section
4. Click "+ ADD USERS" and add your Google email address
5. Click "Save" at the bottom
6. Wait 1-2 minutes for changes to propagate
7. Try the authorization again

**Alternative Solutions:**
- Use a different Google account that's already approved
- Publish your app if it's ready for production
- Ensure your app details are properly filled out

#### 400: Redirect URI Mismatch
**Error:** "redirect_uri_mismatch"

**Solution:**
1. Go to Google Cloud Console → APIs & Services → Credentials
2. Find your OAuth 2.0 Client ID and click edit (pencil)
3. Add the exact redirect URI shown in the refresh token script
4. Click "Save" and wait for changes to propagate

#### 401: Invalid Client
**Error:** "invalid_client"

**Solution:**
1. Verify your Client ID matches exactly what's in Google Cloud Console
2. Check that your Client Secret is complete and has no extra spaces
3. Consider regenerating new OAuth credentials if needed

### Google Drive Upload Issues

1. **"Invalid credentials" error**:
   - Check Google API credentials
   - Verify refresh token is valid
   - Ensure Google Drive API is enabled

2. **"File not found" error**:
   - Check folder ID is correct
   - Ensure service account has access to the folder

3. **Upload failing**:
   - Verify file size doesn't exceed 5MB
   - Check file type is JPEG, JPG, or PNG
   - Check network connectivity

4. **Permission errors**:
   - Ensure Google Drive API is enabled
   - Check OAuth consent screen configuration
   - Verify proper scopes are granted

### Debug Mode

To debug Google Drive issues, you can enable logging:

```php
// In your controller or service
\Log::info('Google Drive upload attempt', [
    'file_name' => $file->getClientOriginalName(),
    'file_size' => $file->getSize(),
    'mime_type' => $file->getMimeType()
]);
```

## Maintenance

1. Regularly monitor Google Drive storage usage
2. Clean up orphaned files periodically
3. Refresh tokens may need to be regenerated periodically
4. Monitor error logs for upload failures

## API Rate Limits

Google Drive API has rate limits:
- Upload requests: ~1,000 per 100 seconds per user
- Download requests: ~10,000 per 100 seconds per user

Consider implementing queuing for bulk operations if needed.