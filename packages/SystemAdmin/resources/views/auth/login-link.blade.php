<form method="POST" action="{{ route('loginLinkLogin') }}">
    @csrf
    <input type="hidden" name="email" value="sysadmin@relaticle.com">
    <input type="hidden" name="guard" value="sysadmin">
    <input type="hidden" name="user_model" value="{{ $userModel }}">
    <input type="hidden" name="redirect_url" value="{{ $redirectUrl }}">

    <button type="submit" class="underline">
        {{ __('Developer Login') }}
    </button>
</form>
