<?php
/**
 * Google OAuth 2.0 Refresh Token Generator
 *
 * This script helps you get a Google OAuth refresh token for Google Drive API integration.
 *
 * Usage:
 * 1. Place this file in your public directory
 * 2. Update the configuration below with your Google Client ID and Secret
 * 3. Access this script in your browser
 * 4. Click "Get Refresh Token" and complete the Google OAuth flow
 * 5. Copy the refresh token that appears
 */

// Configuration - Update these with your Google API credentials
define('GOOGLE_CLIENT_ID', '743466415569-5pa2n1b32850h8r3p97mkitqac9rcc3n.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Auto-detect the redirect URI based on how the script is accessed
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$script_path = $_SERVER['SCRIPT_NAME'] ?? '/get_google_refresh_token.php';
define('REDIRECT_URI', $protocol . '://' . $host . $script_path);

// Google OAuth URLs
define('AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('TOKEN_URL', 'https://oauth2.googleapis.com/token');

// Required scope for Google Drive
define('SCOPE', 'https://www.googleapis.com/auth/drive.file');

// Start session to store state
session_start();

// Helper function to generate a random state parameter
function generateState() {
    return bin2hex(random_bytes(16));
}

// Helper function to make HTTP requests with cURL fallback
function makeRequest($url, $data = null, $method = 'GET', $headers = []) {
    // Check if cURL is available
    if (function_exists('curl_init')) {
        return makeRequestWithCurl($url, $data, $method, $headers);
    } else {
        return makeRequestWithFileGetContents($url, $data, $method, $headers);
    }
}

// cURL implementation
function makeRequestWithCurl($url, $data = null, $method = 'GET', $headers = []) {
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

// Fallback implementation using file_get_contents
function makeRequestWithFileGetContents($url, $data = null, $method = 'GET', $headers = []) {
    // Add Content-Type header for POST requests with data
    if ($method === 'POST' && $data && empty($headers)) {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
    }

    // Prepare headers properly
    $requestHeaders = [];

    // Add custom headers if any
    if (!empty($headers)) {
        foreach ($headers as $header) {
            $requestHeaders[] = $header;
        }
    }

    // Add Content-Length if we have POST data
    $postData = null;
    if ($data && $method === 'POST') {
        $postData = http_build_query($data);

        // Check if Content-Type is already set
        $hasContentType = false;
        foreach ($requestHeaders as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $hasContentType = true;
                break;
            }
        }

        // Add Content-Type if not already present
        if (!$hasContentType) {
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        // Add Content-Length
        $requestHeaders[] = 'Content-Length: ' . strlen($postData);
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $requestHeaders),
            'content' => $postData,
            'ignore_errors' => true,
            'timeout' => 30.0, // 30 second timeout
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'cafile' => null, // Let PHP use default CA bundle
            'capath' => null,
        ],
    ];

    $context = stream_context_create($options);

    // Log request details for debugging
    error_log("OAuth Request Debug - URL: " . $url);
    error_log("OAuth Request Debug - Method: " . $method);
    error_log("OAuth Request Debug - Headers: " . print_r($requestHeaders, true));
    if ($postData) {
        error_log("OAuth Request Debug - Data Length: " . strlen($postData));
    }

    // Make the request without suppressing errors to catch real issues
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();
        $errorMessage = $error ? $error['message'] : 'Unknown error occurred';

        // Log the error for debugging
        error_log("OAuth Request Failed - Error: " . $errorMessage);

        // Provide more helpful error message for common issues
        if (strpos($errorMessage, 'SSL') !== false || strpos($errorMessage, 'certificate') !== false) {
            throw new Exception('SSL Connection Failed: ' . $errorMessage . '. This may be due to outdated CA certificates. Try installing updated CA certificates or use the cURL version if available.');
        } elseif (strpos($errorMessage, 'Unable to connect') !== false || strpos($errorMessage, 'Connection refused') !== false) {
            throw new Exception('Connection Failed: ' . $errorMessage . '. Please check your internet connection and firewall settings.');
        } elseif (strpos($errorMessage, 'timeout') !== false) {
            throw new Exception('Request Timeout: ' . $errorMessage . '. The request took too long to complete. Please try again.');
        } else {
            throw new Exception('HTTP Request Failed: ' . $errorMessage . '. Check your PHP configuration and network connectivity.');
        }
    }

    // Get HTTP status code and response headers
    $httpCode = 200;
    $responseHeaders = [];

    if (isset($http_response_header)) {
        $status_line = $http_response_header[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $status_line, $matches)) {
            $httpCode = (int)$matches[1];
        }

        // Capture response headers for debugging
        $responseHeaders = $http_response_header;
        error_log("OAuth Response Debug - HTTP Code: " . $httpCode);
        error_log("OAuth Response Debug - Headers: " . print_r($responseHeaders, true));
    }

    // Check for empty response
    if (empty($response)) {
        error_log("OAuth Response Debug - Empty response received");
        throw new Exception('Empty response received from Google OAuth server. This could indicate a network issue, server problem, or invalid request. HTTP Status: ' . $httpCode);
    }

    // Log response details (but not full response for security)
    error_log("OAuth Response Debug - Response Length: " . strlen($response));
    error_log("OAuth Response Debug - Response Preview: " . substr($response, 0, 200));

    // Try to parse JSON responses for better error handling
    $decodedResponse = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Check for OAuth error in JSON response
        if (isset($decodedResponse['error'])) {
            $errorDesc = $decodedResponse['error_description'] ?? $decodedResponse['error'];
            throw new Exception('OAuth Error: ' . $errorDesc);
        }

        // Return JSON as-is for better processing upstream
        return ['response' => json_encode($decodedResponse), 'http_code' => $httpCode];
    }

    // Check if response looks like HTML error page
    if (stripos($response, '<!DOCTYPE html>') !== false || stripos($response, '<html>') !== false) {
        throw new Exception('Received HTML error page instead of JSON response. This usually indicates a server error or misconfiguration. HTTP Status: ' . $httpCode);
    }

    return ['response' => $response, 'http_code' => $httpCode];
}

// Check PHP requirements
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    $error = 'PHP 7.0.0 or higher is required. Current version: ' . PHP_VERSION;
}

// Check if configuration is set
if (GOOGLE_CLIENT_ID === 'your_google_client_id_here' || GOOGLE_CLIENT_SECRET === 'your_google_client_secret_here') {
    $error = 'Please update the configuration at the top of this file with your Google Client ID and Client Secret.';
}

// Check cURL availability and provide guidance if missing
if (!function_exists('curl_init')) {
    $warning = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h4>⚠️ PHP cURL Extension Not Found</h4>
        <p><strong>This script uses cURL for HTTP requests, but it\'s not installed on your system.</strong></p>
        <p><strong>Don\'t worry!</strong> The script includes a fallback method that should still work.</p>

        <h5>📋 To install cURL (recommended for better performance):</h5>
        <div style="background: #f8f9fa; border: 1px solid #e9ecef; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <p><strong>Windows:</strong></p>
            <ul>
                <li>Uncomment <code>;extension=curl</code> in <code>php.ini</code></li>
                <li>Or install via XAMPP/WAMP control panel</li>
            </ul>

            <p><strong>Linux (Ubuntu/Debian):</strong></p>
            <ul>
                <li><code>sudo apt-get install php-curl</code></li>
                <li><code>sudo apt-get install php8.1-curl</code> (for PHP 8.1)</li>
            </ul>

            <p><strong>macOS:</strong></p>
            <ul>
                <li><code>brew install php</code> (includes curl)</li>
                <li>Or enable in MAMP/XAMPP settings</li>
            </ul>
        </div>

        <h5>🔧 To verify cURL installation:</h5>
        <div class="code-block">php -m curl</div>
        <p>Or create a test file with <code>&lt;?php phpinfo(); ?&gt;</code> and look for cURL section.</p>
    </div>';
}

// Handle OAuth callback
if (isset($_GET['code'])) {
    // Verify state parameter to prevent CSRF
    if (!isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        $error = 'Invalid state parameter. Please try again.';
    } else {
        try {
            // Exchange authorization code for tokens
            $data = [
                'client_id' => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'code' => $_GET['code'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => REDIRECT_URI
            ];

            $result = makeRequest(TOKEN_URL, $data);

            if ($result['http_code'] === 200) {
                $tokens = json_decode($result['response'], true);

                if (isset($tokens['refresh_token'])) {
                    $success = true;
                    $refreshToken = $tokens['refresh_token'];
                    $accessToken = $tokens['access_token'];

                    // Store tokens in session for testing
                    $_SESSION['tokens'] = $tokens;

                    // Clear state
                    unset($_SESSION['oauth_state']);
                } else {
                    $error = 'Refresh token not found in response. Make sure to "Force approve" the consent screen.';
                }
            } else {
                $error = 'Failed to exchange authorization code for tokens. Response: ' . $result['response'];
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle initial OAuth request
if (isset($_GET['start_auth'])) {
    // Generate state parameter
    $state = generateState();
    $_SESSION['oauth_state'] = $state;

    // Build authorization URL
    $authUrl = AUTH_URL . '?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => REDIRECT_URI,
        'response_type' => 'code',
        'scope' => SCOPE,
        'state' => $state,
        'access_type' => 'offline', // Important for getting refresh token
        'prompt' => 'consent'       // Force consent screen to ensure refresh token is returned
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// Handle testing the connection
if (isset($_POST['test_connection']) && isset($_SESSION['tokens']['access_token'])) {
    try {
        // Test the access token by getting user info
        $testUrl = 'https://www.googleapis.com/oauth2/v1/userinfo';
        $headers = ['Authorization: Bearer ' . $_SESSION['tokens']['access_token']];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $userInfo = json_decode($response, true);
            $testSuccess = true;
            $testMessage = 'Successfully connected! User: ' . ($userInfo['email'] ?? $userInfo['name'] ?? 'Unknown');
        } else {
            $testError = 'Failed to connect. HTTP Status: ' . $httpCode . ', Response: ' . $response;
        }
    } catch (Exception $e) {
        $testError = 'Error testing connection: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google OAuth Refresh Token Generator</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2 {
            color: #333;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #f5c6cb;
            margin: 20px 0;
        }
        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-all;
            margin: 15px 0;
        }
        .btn {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .step {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #007bff;
            border-radius: 0 5px 5px 0;
        }
        .step h3 {
            margin-top: 0;
            color: #007bff;
        }
        .config-highlight {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 Google OAuth Refresh Token Generator</h1>
        <p>This script helps you obtain a Google OAuth refresh token for Google Drive API integration with your Laravel application.</p>

        <!-- cURL Warning -->
        <?php if (isset($warning)): ?>
            <?php echo $warning; ?>
        <?php endif; ?>

        <!-- Current Configuration Display -->
        <div class="step" style="background: #e3f2fd; border-left-color: #2196f3;">
            <h3>📡 Current Configuration</h3>
            <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
            <p><strong>Client ID:</strong> <?php echo substr(GOOGLE_CLIENT_ID, 0, 30) . '...'; ?></p>
            <p><strong>Redirect URI:</strong> <code><?php echo htmlspecialchars(REDIRECT_URI); ?></code></p>
            <p><strong>Scope:</strong> <code><?php echo htmlspecialchars(SCOPE); ?></code></p>
            <p><strong>HTTP Method:</strong> <?php echo function_exists('curl_init') ? 'cURL (Recommended)' : 'file_get_contents (Fallback)'; ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>

                <?php if (strpos($error, 'redirect_uri_mismatch') !== false): ?>
                    <div style="margin-top: 15px;">
                        <h4>🔧 Fix redirect_uri_mismatch Error:</h4>
                        <ol>
                            <li><strong>Go to Google Cloud Console:</strong> <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a></li>
                            <li>Select your project and go to "APIs & Services" → "Credentials"</li>
                            <li>Find your OAuth 2.0 Client ID and click the edit icon (pencil)</li>
                            <li>In "Authorized redirect URIs", click "+ ADD URI" and add:</li>
                            <div class="code-block"><?php echo htmlspecialchars(REDIRECT_URI); ?></div>
                            <li>Click "Save" at the bottom</li>
                            <li>Wait a few minutes for changes to propagate, then try again</li>
                        </ol>

                        <h5>💡 Alternative URIs you can add:</h5>
                        <ul>
                            <li><code>http://localhost:8000/get_google_refresh_token.php</code></li>
                            <li><code>http://127.0.0.1:8000/get_google_refresh_token.php</code></li>
                            <li><code>http://localhost/get_google_refresh_token.php</code></li>
                            <li><code><?php echo htmlspecialchars($protocol . '://127.0.0.1' . $script_path); ?></code></li>
                        </ul>
                    </div>
                <?php elseif (strpos($error, 'access_denied') !== false): ?>
                    <div style="margin-top: 15px;">
                        <h4>🚫 Fix Access Denied Error (Testing Mode):</h4>
                        <p><strong>Your app is in testing mode and needs approved test users.</strong></p>

                        <h5>⚡ Quick Fix - Add Test Users:</h5>
                        <ol>
                            <li><strong>Go to Google Cloud Console:</strong> <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a></li>
                            <li>Select your project → "APIs & Services" → "OAuth consent screen"</li>
                            <li>Scroll down to "Test users" section</li>
                            <li>Click "+ ADD USERS"</li>
                            <li>Add your Google email address: <input type="email" placeholder="your-email@gmail.com" style="margin: 5px; padding: 5px;" readonly value="<?php echo isset($_SESSION['user_email']) ? htmlspecialchars($_SESSION['user_email']) : 'your-email@gmail.com'; ?>"></li>
                            <li>Click "Save" at the bottom</li>
                            <li>Wait 1-2 minutes, then try again</li>
                        </ol>

                        <div style="background: #e3f2fd; border: 1px solid #2196f3; padding: 15px; border-radius: 5px; margin: 15px 0;">
                            <h5>📱 Alternative Solutions:</h5>
                            <ol>
                                <li><strong>Use a different Google account</strong> that's already listed as a test user</li>
                                <li><strong>Publish your app</strong> (if ready for production) - Go to OAuth consent screen → "Publishing app"</li>
                                <li><strong>Contact app developer</strong> if you're not the owner of this Google Cloud project</li>
                            </ol>
                        </div>

                        <h5>⏰ If You Added Test User:</h5>
                        <p>After adding your email as a test user, you may need to:</p>
                        <ul>
                            <li>Wait 1-2 minutes for changes to propagate</li>
                            <li>Clear your browser cache or use incognito mode</li>
                            <li>Try the authorization again</li>
                        </ul>
                    </div>
                <?php elseif (strpos($error, 'invalid_client') !== false): ?>
                    <div style="margin-top: 15px;">
                        <h4>🔑 Fix Invalid Client Error:</h4>
                        <p><strong>Your Client ID or Client Secret is incorrect.</strong></p>
                        <ol>
                            <li><strong>Verify Client ID:</strong> <code><?php echo substr(GOOGLE_CLIENT_ID, 0, 40); ?>...</code></li>
                            <li><strong>Check Client Secret:</strong> Make sure it's not truncated or has extra spaces</li>
                            <li><strong>Regenerate credentials:</strong> Go to Google Cloud Console → Credentials → Create new OAuth 2.0 Client ID</li>
                        </ol>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="success">
                <h3>🎉 Success! Refresh Token Obtained</h3>
                <p>Your refresh token has been generated successfully. Copy the token below and add it to your .env file:</p>
                <div class="code-block">GOOGLE_DRIVE_REFRESH_TOKEN=<?php echo htmlspecialchars($refreshToken); ?></div>

                <h4>Next Steps:</h4>
                <ol>
                    <li>Copy the refresh token above</li>
                    <li>Add it to your <code>.env</code> file</li>
                    <li>Run <code>php artisan migrate</code> to update your database</li>
                    <li>Test your Google Drive integration</li>
                </ol>

                <form method="post" style="margin-top: 20px;">
                    <button type="submit" name="test_connection" class="btn">🔗 Test Connection</button>
                </form>

                <?php if (isset($testSuccess)): ?>
                    <div class="success" style="margin-top: 20px;">
                        <?php echo htmlspecialchars($testMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($testError)): ?>
                    <div class="error" style="margin-top: 20px;">
                        <strong>Test Failed:</strong> <?php echo htmlspecialchars($testError); ?>
                    </div>
                <?php endif; ?>

                <p><a href="?" class="btn btn-secondary">🔄 Start Over</a></p>
            </div>
        <?php else: ?>
            <div class="config-highlight">
                <strong>⚠️ Important:</strong> Before proceeding, make sure you have updated the configuration at the top of this file with your Google Client ID and Client Secret.
            </div>

            <div class="step">
                <h3>Step 1: Google Cloud Console Setup</h3>
                <p>Make sure you have completed these steps in the <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>:</p>
                <ol>
                    <li>Created a project (or selected an existing one)</li>
                    <li>Enabled the <strong>Google Drive API</strong></li>
                    <li>Created OAuth 2.0 credentials for "Web application"</li>
                    <li><strong>IMPORTANT:</strong> In "Authorized redirect URIs", add this exact URI:
                        <div class="code-block"><?php echo htmlspecialchars(REDIRECT_URI); ?></div>
                    </li>
                </ol>

                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-top: 15px;">
                    <strong>⚠️ If you're getting redirect_uri_mismatch error:</strong><br>
                    You need to add the redirect URI shown above to your Google Cloud Console. Click the edit icon (pencil) next to your OAuth 2.0 Client ID and add the URI to the "Authorized redirect URIs" list.
                </div>
            </div>

            <div class="step">
                <h3>Step 2: Update Configuration</h3>
                <p>Edit this file and update these constants with your actual values:</p>
                <div class="code-block">define('GOOGLE_CLIENT_ID', 'your-actual-client-id-here');
define('GOOGLE_CLIENT_SECRET', 'your-actual-client-secret-here');
define('REDIRECT_URI', '<?php echo htmlspecialchars(REDIRECT_URI); ?>');</div>
            </div>

            <div class="step">
                <h3>Step 3: Get Refresh Token</h3>
                <p>Click the button below to start the OAuth flow. You will be redirected to Google to authorize the application.</p>

                <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0;">
                    <strong>Important:</strong> When Google asks for permission, make sure to click "Advanced" → "Go to [Your Project]" → "Allow" to ensure you get a refresh token.
                </div>

                <a href="?start_auth=1" class="btn">🚀 Get Refresh Token</a>
            </div>

            <div class="step">
                <h3>Step 4: Configure Laravel</h3>
                <p>After getting your refresh token, add these lines to your <code>.env</code> file:</p>
                <div class="code-block">GOOGLE_DRIVE_CLIENT_ID=your_google_client_id
GOOGLE_DRIVE_CLIENT_SECRET=your_google_client_secret
GOOGLE_DRIVE_REFRESH_TOKEN=your_refresh_token_here
GOOGLE_DRIVE_FOLDER_ID=your_google_drive_folder_id</div>
            </div>
        <?php endif; ?>

        <hr style="margin: 40px 0;">
        <h3>📋 Troubleshooting</h3>
        <details>
            <summary>Click to expand troubleshooting tips</summary>
            <div style="margin-top: 15px;">
                <p><strong>No refresh token returned?</strong></p>
                <ul>
                    <li>Make sure you click "Advanced" → "Go to [Your Project]" when Google shows the warning screen</li>
                    <li>Try revoking access and starting over</li>
                    <li>Ensure <code>access_type=offline</code> and <code>prompt=consent</code> are in the auth URL</li>
                </ul>

                <p><strong>Invalid client credentials?</strong></p>
                <ul>
                    <li>Double-check your Client ID and Client Secret</li>
                    <li>Ensure the redirect URI matches exactly what's in Google Console</li>
                    <li>Make sure your Google Cloud project has the Drive API enabled</li>
                </ul>
            </div>
        </details>
    </div>
</body>
</html>