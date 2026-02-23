<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo Login</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
        }

        .card {
            width: min(380px, 90vw);
            background: #fff;
            border: 1px solid #d9dee4;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        p {
            margin: 0 0 18px;
            color: #5d6570;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #c6cdd7;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }

        button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #1f6feb;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: default;
        }

        .note {
            margin-top: 10px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Sign In</h1>
        <p>Mock login screen for placeholder/demo use.</p>

        <form action="{{ route('fax.index') }}" method="get">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="demo.user" disabled />

            <label for="password">Password</label>
            <input id="password" name="password" type="password" value="password123" disabled />

            <button type="submit">Login</button>
        </form>

        <div class="note">This page is a UI placeholder only. No authentication logic is connected.</div>
    </main>
</body>
</html>
