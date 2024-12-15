<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>One-Time Password | DAVID System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }

        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .logo {
            display: block;
            max-width: 150px;
            margin: 0 auto 20px;
        }

        .otp {
            background-color: #f0f0f0;
            padding: 15px;
            text-align: center;
            font-size: 24px;
            letter-spacing: 5px;
            border-radius: 5px;
            font-weight: bold;
            color: #0066cc;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="{{ Vite::asset('resources/images/logo.png')}}" alt="DAVID logo" class="logo">

        <h2 style="text-align: center; color: #0066cc;">One-Time Password</h2>

        <p>Hello,</p>

        <p>You have requested a one-time password for accessing DAVID. Please use the following code to complete your login:</p>

        <div class="otp">
            {{ $otp }}
        </div>

        <p style="text-align: center;">This code will expire in 5 minutes.</p>

        <p>If you did not request this code, please ignore this email or contact our support team.</p>

        <div class="footer">
            <p>© {{ date('F d, Y') }} DAVID. All rights reserved.</p>
        </div>
    </div>
</body>

</html>