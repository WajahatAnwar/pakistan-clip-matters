<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Access Denied') }} | {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #0c0e10;
            --panel: #15181d;
            --panel-2: #1c2027;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #ee1d52;
            --accent-2: #b3133a;
        }
        * { box-sizing: border-box; }
        html {
            background: radial-gradient(1200px 600px at 80% -20%, #1b2230 0%, var(--bg) 60%);
        }
        body {
            margin: 0 auto;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            min-height: 100vh;
            display: grid;
            place-items: center;
            width: min(520px, 92vw);
            padding: 24px;
        }
        @media (max-width: 480px) {
            body {
                width: min(92vw, 360px);
                padding: 20px;
            }
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(238, 29, 82, 0.12);
            color: #fda4af;
            font-weight: 600;
            font-size: 12px;
            letter-spacing: 0.3px;
        }
        .grid {
            display: grid;
            gap: 18px;
        }
        .title {
            font-size: clamp(22px, 4vw, 32px);
            font-weight: 700;
            margin: 0;
        }
        .subtitle {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .code {
            font-size: 56px;
            font-weight: 800;
            color: rgba(238, 29, 82, 0.25);
            letter-spacing: 2px;
            margin: 0;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid transparent;
            transition: transform 120ms ease, box-shadow 120ms ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: white;
            box-shadow: 0 10px 22px rgba(238, 29, 82, 0.25);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            border-color: rgba(255, 255, 255, 0.12);
        }
        .btn:hover { transform: translateY(-1px); }
        .divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
            margin: 6px 0 4px;
        }
        .glow {
            position: absolute;
            inset: auto -20% -40% auto;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(238, 29, 82, 0.35) 0%, transparent 60%);
            filter: blur(10px);
        }
    </style>
</head>
<body>
    <main role="main">
        <div class="grid">
            <div class="badge">
                {{-- <span>403</span> --}}
                <span>{{ __('Access Denied') }}</span>
            </div>
            <h1 class="title">{{ __('You do not have permission to access this page') }}</h1>
            <p class="subtitle">
                {{ $exception->getMessage() ?: __('Please contact your administrator if you believe this is a mistake.') }}
            </p>
            {{-- <p class="code" aria-hidden="true">403</p> --}}
            <div class="divider"></div>
            <div class="actions">
                <a class="btn btn-primary" href="{{ url('/') }}">{{ __('Go to Home') }}</a>
                {{-- <a class="btn btn-secondary" href="{{ url()->previous() }}">{{ __('Go Back') }}</a> --}}
            </div>
        </div>
    </main>
</body>
</html>
