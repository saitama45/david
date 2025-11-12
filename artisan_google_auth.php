<?php
/**
 * Command Line Google OAuth Refresh Token Generator
 *
 * Usage: php artisan_google_auth.php
 *
 * This script provides a command-line alternative to get Google OAuth refresh token.
 * It will give you a URL to visit in your browser to complete the OAuth flow.
 */

// Configuration - Update these with your Google API credentials
define('GOOGLE_CLIENT_ID', 'your_google_client_id_here');
define('GOOGLE_CLIENT_SECRET', 'your_google_client_secret_here');
define('REDIRECT_URI', 'http://localhost:8000/'); // Must match what's configured in Google Console

// Google OAuth URLs
define('AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('TOKEN_URL', 'https://oauth2.googleapis.com/token');

// Required scope for Google Drive
define('SCOPE', 'https://www.googleapis.com/auth/drive.file');

// Helper function to make HTTP requests
function makeRequest($url, $data = null, $method = 'GET', $headers = []) {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => $method === 'POST'
    ]);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_error($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }

    curl_close($ch);

    return ['response' => $response, 'http_code' => $httpCode];
}

function getAuthorizationUrl($state) {
    return AUTH_URL . '?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => REDIRECT_URI,
        'response_type' => 'code',
        'scope' => SCOPE,
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
}

function exchangeCodeForTokens($code) {
    $data = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => REDIRECT_URI
    ];

    $result = makeRequest(TOKEN_URL, $data);

    if ($result['http_code'] === 200) {
        return json_decode($result['response'], true);
    } else {
        throw new Exception('Failed to exchange code for tokens: ' . $result['response']);
    }
}

function refreshToken($refreshToken) {
    $data = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ];

    $result = makeRequest(TOKEN_URL, $data);

    if ($result['http_code'] === 200) {
        return json_decode($result['response'], true);
    } else {
        throw new Exception('Failed to refresh token: ' . $result['response']);
    }
}

function testAccessToken($accessToken) {
    try {
        $headers = ['Authorization: Bearer ' . $accessToken];
        $result = makeRequest('https://www.googleapis.com/oauth2/v1/userinfo', null, 'GET', $headers);

        if ($result['http_code'] === 200) {
            return json_decode($result['response'], true);
        } else {
            throw new Exception('Failed to get user info: ' . $result['response']);
        }
    } catch (Exception $e) {
        throw new Exception('Token test failed: ' . $e->getMessage());
    }
}

// Main execution
echo "🔑 Google OAuth Refresh Token Generator (Command Line)\n";
echo str_repeat("=", 55) . "\n\n";

// Check configuration
if (GOOGLE_CLIENT_ID === 'your_google_client_id_here' ||
    GOOGLE_CLIENT_SECRET === 'your_google_client_secret_here') {

    echo "❌ ERROR: Please update the configuration at the top of this file:\n";
    echo "   - GOOGLE_CLIENT_ID\n";
    echo "   - GOOGLE_CLIENT_SECRET\n";
    echo "   - REDIRECT_URI\n\n";
    echo "Get these credentials from: https://console.cloud.google.com/\n";
    exit(1);
}

echo "✅ Configuration loaded\n";
echo "   Client ID: " . substr(GOOGLE_CLIENT_ID, 0, 20) . "...\n";
echo "   Redirect URI: " . REDIRECT_URI . "\n\n";

// Check command line arguments
if ($argc < 2) {
    // Generate authorization URL
    $state = bin2hex(random_bytes(16));
    $authUrl = getAuthorizationUrl($state);

    echo "📋 STEP 1: Authorize the application\n";
    echo str_repeat("-", 40) . "\n";
    echo "Visit this URL in your browser:\n\n";
    echo $authUrl . "\n\n";
    echo "📝 Important:\n";
    echo "   - Complete the Google OAuth flow\n";
    echo "   - If you see a warning, click 'Advanced' → 'Go to [Your Project]' → 'Allow'\n";
    echo "   - Copy the 'code' parameter from the redirect URL\n\n";
    echo "⚠️  The redirect URL will look like:\n";
    echo "   " . REDIRECT_URI . "?code=XXXXXXXXXXXXXXXXXXXXXXXX&state=XXXXXXXXXXXXXXXXXXXXXXXX\n\n";
    echo "📋 STEP 2: Run this script again with the authorization code\n";
    echo "   Command: php artisan_google_auth.php YOUR_AUTHORIZATION_CODE_HERE\n";
    echo str_repeat("=", 55) . "\n";
    exit(0);
}

// Handle authorization code exchange
$authCode = $argv[1];

echo "🔄 Exchanging authorization code for tokens...\n";

try {
    $tokens = exchangeCodeForTokens($authCode);

    if (!isset($tokens['refresh_token'])) {
        echo "\n❌ ERROR: No refresh token received!\n\n";
        echo "This usually happens when:\n";
        echo "  1. You've already authorized this application before\n";
        echo "  2. You didn't click 'Advanced' → 'Go to [Your Project]' → 'Allow'\n\n";
        echo "To fix this:\n";
        echo "  1. Go to: https://myaccount.google.com/permissions\n";
        echo "  2. Remove access for your application\n";
        echo "  3. Try the authorization process again\n\n";
        exit(1);
    }

    echo "✅ Success! Tokens received.\n\n";

    echo "🎉 REFRESH TOKEN (copy this):\n";
    echo str_repeat("=", 50) . "\n";
    echo $tokens['refresh_token'] . "\n";
    echo str_repeat("=", 50) . "\n\n";

    echo "📋 Add this to your .env file:\n";
    echo "GOOGLE_DRIVE_REFRESH_TOKEN=" . $tokens['refresh_token'] . "\n\n";

    // Test the access token
    echo "🔗 Testing access token...\n";
    try {
        $userInfo = testAccessToken($tokens['access_token']);
        echo "✅ Connection test successful!\n";
        echo "   User: " . ($userInfo['email'] ?? $userInfo['name'] ?? 'Unknown') . "\n\n";
    } catch (Exception $e) {
        echo "⚠️  Access token test failed: " . $e->getMessage() . "\n";
        echo "   (This might be normal if the token expired quickly)\n\n";
    }

    echo "🚀 Next steps:\n";
    echo "   1. Copy the refresh token above\n";
    echo "   2. Add it to your .env file\n";
    echo "   3. Run: php artisan migrate\n";
    echo "   4. Test your Google Drive integration\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "Troubleshooting:\n";
    echo "  • Check your CLIENT_ID and CLIENT_SECRET\n";
    echo "  • Verify REDIRECT_URI matches Google Console\n";
    echo "  • Ensure Google Drive API is enabled\n";
    echo "  • Make sure the authorization code is valid\n";
    echo "  • Authorization codes expire in 10 minutes\n";
    exit(1);
}

echo "\n" . str_repeat("=", 55) . "\n";
?>