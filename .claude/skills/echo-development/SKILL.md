---
name: echo-development
description: "Develops real-time broadcasting with Laravel Echo. Activates when setting up broadcasting (Reverb, Pusher, Ably); creating ShouldBroadcast events; defining broadcast channels (public, private, presence, encrypted); authorizing channels; configuring Echo; listening for events; implementing client events (whisper); setting up model broadcasting; broadcasting notifications; or when the user mentions broadcasting, Echo, WebSockets, real-time events, Reverb, or presence channels."
license: MIT
metadata:
  author: laravel
---

# Laravel Broadcasting & Echo

## When to Apply

Activate this skill when:

- Installing or configuring Laravel broadcasting (Reverb, Pusher, Ably)
- Creating events that implement `ShouldBroadcast`
- Defining broadcast channels and authorization
- Setting up Laravel Echo on the client side
- Listening for broadcast events, notifications, or model events
- Implementing client-to-client events (whisper)
- Working with presence channels for user awareness

## Documentation

Use `search-docs` for detailed broadcasting patterns and documentation.

## Basic Usage

### Installing Broadcasting

```bash
php artisan install:broadcasting
```

Use flags for specific drivers: `--reverb`, `--pusher`, `--ably`. This creates `config/broadcasting.php` and `routes/channels.php`.

### Creating a Broadcast Event

```bash
php artisan make:event OrderShipped
```

<!-- Broadcast Event -->
```php
namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class OrderShipped implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->order->id)];
    }
}
```

Dispatch the event:

<!-- Dispatch Event -->
```php
use App\Events\OrderShipped;

OrderShipped::dispatch($order);
```

### Authorizing Channels

Define authorization in `routes/channels.php`:

<!-- Channel Authorization -->
```php
use App\Models\Order;
use App\Models\User;

Broadcast::channel('orders.{orderId}', function (User $user, int $orderId) {
    return $user->id === Order::findOrNew($orderId)->user_id;
});
```

Create a channel class for complex authorization:

```bash
php artisan make:channel OrderChannel
```

List all registered channels:

```bash
php artisan channel:list
```

### Client-Side Setup

Install Echo and Pusher JS:

```bash
npm install --save-dev laravel-echo pusher-js
```

<!-- Echo Client Configuration -->
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### Listening for Events

<!-- Listen on Private Channel -->
```javascript
Echo.private(`orders.${orderId}`)
    .listen('OrderShipmentStatusUpdated', (e) => {
        console.log(e.order);
    });
```

### Running Required Processes

```bash
php artisan queue:work    # Required for ShouldBroadcast events

php artisan reverb:start  # Required for Reverb driver

```

## What's Possible

Use `search-docs` to find detailed code examples and configuration for each of these:

### Channel Types

- Public (`new Channel`) — no auth, anyone can subscribe. Use for app-wide announcements, public feeds, or status pages.
- Private (`new PrivateChannel`) — requires authorization. Use for user-specific data like orders, messages, or account updates.
- Presence (`new PresenceChannel`) — authorized + tracks who's online. Use for chat rooms, collaborative editing, "who's viewing this" features, or typing indicators.
- EncryptedPrivate — end-to-end encryption, Pusher/Reverb only. Use when payload must be hidden from the broadcast server (e.g., sensitive financial data or private messages).
- Drivers: `reverb` (self-hosted WebSocket server), `pusher` (managed service), `ably` (managed service), `log` (writes to Laravel log, use for debugging), `null` (no-op, use for testing)

### Event Customization

- `broadcastAs()` — custom event name (client must use dot prefix: `.listen('.custom.name')`). Use when you want stable API names decoupled from PHP class names, or shorter event names for the frontend.
- `broadcastWith()` — control exact payload. Use to avoid leaking sensitive model attributes, slim down large payloads, or add computed data not on the model.
- `broadcastWhen()` — conditional broadcasting. Use to skip broadcasting when changes are trivial (e.g., only broadcast order updates above a threshold, or skip unchanged fields).
- `broadcastQueue()` / `$queue` — route to specific queue. Use to isolate real-time broadcasts from slow background jobs so they're processed faster.
- `$connection` — set queue connection per event. Use when broadcasts should go through a faster queue backend like Redis while other jobs use the database driver.

### Broadcasting Interfaces

- `ShouldBroadcast` — queue the broadcast (default). Use for most events to avoid blocking the HTTP response.
- `ShouldBroadcastNow` — broadcast synchronously, skip queue. Use during development or for time-critical events where queue latency is unacceptable.
- `ShouldDispatchAfterCommit` — wait for DB transaction commit. Use when the event references newly created records that listeners need to query (prevents race conditions).
- `ShouldRescue` — auto-catch broadcast exceptions. Use to prevent broadcast failures (e.g., WebSocket server down) from disrupting the user's HTTP request.
- `InteractsWithSockets` — required for `toOthers()`. Use on any event where you want to exclude the sender (optimistic UI updates).
- `InteractsWithBroadcasting` — override driver per event via `broadcastVia()`. Use in multi-driver setups (e.g., some events via Reverb, others via Pusher).

### Broadcasting Helpers

- `broadcast(new Event)->toOthers()` — exclude current user's socket. Use when the client already updates optimistically from the API response to avoid duplicate updates.
- `broadcast(new Event)->via('pusher')` — override connection. Use to route specific events through a different broadcast driver than the default.
- `Broadcast::on()`, `Broadcast::private()`, `Broadcast::presence()` — anonymous broadcasting without event classes. Chain `.as('name')->with($data)->send()` or `.sendNow()`. Use for simple one-off broadcasts where creating a full event class is overkill (e.g., quick status updates, simple notifications).

### Channel Authorization

- Closure-based in `routes/channels.php` — use for simple authorization logic (e.g., checking ownership).
- Model binding: `Broadcast::channel('orders.{order}', fn (User $user, Order $order) => ...)` — use when authorization depends on the model instance (auto-resolves from route parameter).
- Channel classes via `php artisan make:channel` — use for complex authorization logic that benefits from dependency injection or reusable logic across channels.
- Multiple guards: `['guards' => ['web', 'admin']]` — use when the channel should be accessible by users authenticated via different guards (e.g., both regular users and admins).

### Model Broadcasting

- `BroadcastsEvents` trait auto-broadcasts created/updated/deleted/trashed/restored. Use to automatically keep clients in sync with Eloquent model changes without writing individual events.
- Channel convention: `App.Models.Post.{id}` — clients subscribe to model-specific channels.
- `broadcastAs($event)` and `broadcastWith($event)` for per-action customization. Use to send different payloads for create vs update, or suppress certain event types.
- `newBroadcastableEvent($event)` for event instance customization (e.g., `->dontBroadcastToCurrentUser()`). Use when you need to modify the underlying event object before it's dispatched.

### Client-Side Features

- Client events: `whisper()` / `listenForWhisper()` — peer-to-peer without server roundtrip (private/presence channels only). Use for typing indicators, cursor positions, or any ephemeral state that doesn't need server persistence.
- Presence channels: `Echo.join()` with `here()`, `joining()`, `leaving()`, `error()` callbacks. Use for showing online users, "X is viewing this document" features, or live participant counts.
- Notification broadcasting: `.notification()` on user's private channel. Use to show real-time notifications (toast, badge counts) pushed from Laravel's notification system.
- Connection management: `Echo.connectionStatus()`, `Echo.leaveAllChannels()`, `Echo.disconnect()`. Use to show connection indicators, clean up on logout, or handle offline/reconnect scenarios.
- Custom namespace: `new Echo({ namespace: 'App.Other.Namespace' })`. Use when your events live outside the default `App\Events` namespace.

## Common Pitfalls

- Queue worker must be running for `ShouldBroadcast` events. Use `ShouldBroadcastNow` during development.
- `BROADCAST_CONNECTION` not `BROADCAST_DRIVER`: Laravel 11+ renamed this env key.
- `toOthers()` requires `InteractsWithSockets` trait AND `X-Socket-ID` header. Echo auto-adds this to global Axios. For `fetch`, manually send `Echo.socketId()`.
- CORS: When frontend/backend are on different origins, add `broadcasting/auth` to `config/cors.php` paths and set `supports_credentials` to `true`.
- Missing `VITE_` prefix: Client-side env vars must start with `VITE_`.
- `channels.php` not loaded: Verify it's included in `withRouting()` in `bootstrap/app.php`.
- Reverb is long-running: Code changes require `php artisan reverb:restart`.
- Presence channel auth must return an array of user data (`['id' => $user->id, 'name' => $user->name]`), not `true`. Returning `true` silently fails.
- Dot prefix rule: When using `broadcastAs()`, client must prefix with `.` (e.g., `.listen('.custom.name')`). Without the dot, Echo looks for `App\Events\custom.name` which silently fails.
- Reverb host separation: `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` (internal bind) vs `REVERB_HOST`/`REVERB_PORT` (public address) vs `VITE_REVERB_HOST`/`VITE_REVERB_PORT` (client JS).
- Sanctum SPA auth: Ensure `/broadcasting/auth` uses `auth:sanctum` middleware and CSRF tokens are sent with `withCredentials: true`.
