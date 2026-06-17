import { Passkeys } from '@laravel/passkeys';

window.Passkeys = Passkeys;
window.dispatchEvent(new CustomEvent('passkeys:ready'));
