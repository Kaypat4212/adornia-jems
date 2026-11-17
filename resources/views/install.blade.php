<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Store setup · Adornia Jems</title>
    <link rel="stylesheet" href="{{ asset('storefront.css') }}">
    <link rel="stylesheet" href="{{ asset('store.css') }}">
</head>
<body>
    <main class="page">
        <div class="auth-card">
            <a class="logo" href="{{ route('storefront') }}">ADORNIA<small>J E M S</small></a>
            <h1 class="page-title">Store setup</h1>
            @if($enabled)
                <p>Set up the store database and create your first administrator account.</p>
                @if($errors->any())
                    <div class="errors" role="alert">
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <form method="post" action="{{ route('install.store') }}">
                    @csrf
                    <label>Setup token
                        <input name="install_token" type="password" required minlength="32" autocomplete="off">
                    </label>
                    <label>Administrator name
                        <input name="name" required maxlength="100" autocomplete="name">
                    </label>
                    <label>Administrator email
                        <input name="email" type="email" required maxlength="254" autocomplete="email">
                    </label>
                    <label>New password
                        <input name="password" type="password" required minlength="12" autocomplete="new-password">
                    </label>
                    <label>Confirm password
                        <input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
                    </label>
                    <button class="cta">Install Adornia Jems ↗</button>
                </form>
            @else
                <p>Store setup is closed. For a fresh deployment, follow the installer steps in the repository README.</p>
                <a class="cta" href="{{ route('admin.login') }}">Admin sign-in ↗</a>
            @endif
        </div>
    </main>
    <script src="{{ asset('experience.js') }}" defer></script>
</body>
</html>
