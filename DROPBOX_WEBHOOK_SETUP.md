# Dropbox Webhook Setup Guide

## Overview
Dropbox webhooks have been implemented to receive real-time notifications when users' files change in Dropbox.

## Implementation Details

### Files Created/Modified:
1. **Controller**: `app/Http/Controllers/DropboxWebhookController.php`
   - Handles webhook verification (GET)
   - Handles webhook notifications (POST)
   - Validates HMAC-SHA256 signatures
   - Processes file changes asynchronously

2. **Routes**: `routes/web.php`
   - Added: `Route::match(['get', 'post'], '/webhook/dropbox', ...)`
   - Endpoint: `/webhook/dropbox`

### Environment Configuration
Already configured in `.env`:
```env
DROPBOX_APP_KEY=42f7j5cnx6w4p87
DROPBOX_APP_SECRET=bu1yl2hhp4imihu
```

## Setup Steps

### 1. Register the Webhook URL in Dropbox App Console

1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps)
2. Select your app (or create a new one)
3. Navigate to the "Webhooks" section
4. Add your webhook URI:
   ```
   https://phpstack-1570807-6348757.cloudwaysapps.com/webhook/dropbox
   ```
5. Dropbox will send a verification request to this URI

### 2. Verification Process

When you register the webhook, Dropbox will:
- Send a GET request with a `challenge` parameter
- Your app must echo back the challenge with these headers:
  - `Content-Type: text/plain`
  - `X-Content-Type-Options: nosniff`

**The controller already handles this automatically!**

### 3. Receiving Notifications

Once verified, Dropbox will send POST requests when files change:

**Notification Payload:**
```json
{
  "list_folder": {
    "accounts": [
      "dbid:AAH4f99T0taONIb-OurWxbNQ6ywGRopQngc"
    ]
  },
  "delta": {
    "users": [12345678, 23456789]
  }
}
```

**Security:**
- Every notification includes `X-Dropbox-Signature` header
- The controller validates HMAC-SHA256 signature using your app secret
- Invalid signatures are rejected with 403 Forbidden

### 4. Processing File Changes

The controller processes changes asynchronously to respond within Dropbox's 10-second timeout.

**What happens:**
1. Webhook receives notification
2. Signature is validated
3. Account IDs are extracted
4. Processing is dispatched asynchronously
5. Response sent immediately to Dropbox

### 5. Implement Your Business Logic

Update the `processAccount()` method in `DropboxWebhookController.php`:

```php
protected function processAccount($accountId)
{
    // 1. Find the user by Dropbox account ID
    $user = User::where('dropbox_account_id', $accountId)->first();
    
    if (!$user) {
        Log::warning('User not found', ['account_id' => $accountId]);
        return;
    }
    
    // 2. Get their cursor (stored from previous sync)
    $cursor = $user->dropbox_cursor;
    
    // 3. Call Dropbox API to get file changes
    $dropboxService = new DropboxService($user);
    
    if ($cursor) {
        $result = $dropboxService->listFolderContinue($cursor);
    } else {
        $result = $dropboxService->listFolder('');
    }
    
    // 4. Process each changed file
    foreach ($result['entries'] as $entry) {
        if ($entry['.tag'] === 'file') {
            // Handle file addition/modification
            Log::info('File changed', [
                'path' => $entry['path_display'],
                'name' => $entry['name']
            ]);
        } elseif ($entry['.tag'] === 'deleted') {
            // Handle file deletion
            Log::info('File deleted', [
                'path' => $entry['path_display']
            ]);
        }
    }
    
    // 5. Update cursor for next time
    $user->update(['dropbox_cursor' => $result['cursor']]);
}
```

## Testing

### Test Verification (GET)
```bash
curl "https://phpstack-1570807-6348757.cloudwaysapps.com/webhook/dropbox?challenge=test123"
```
**Expected Response:** `test123`

### Test Notification (POST)
```bash
# Generate signature
PAYLOAD='{"list_folder":{"accounts":["dbid:test"]},"delta":{"users":[]}}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "bu1yl2hhp4imihu" | sed 's/^.* //')

# Send request
curl -X POST "https://phpstack-1570807-6348757.cloudwaysapps.com/webhook/dropbox" \
  -H "Content-Type: application/json" \
  -H "X-Dropbox-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```
**Expected Response:** HTTP 200 (empty body)

## Monitoring

Check logs for webhook activity:
```bash
tail -f storage/logs/laravel.log | grep "Dropbox webhook"
```

## Key Features

✅ Automatic webhook verification (GET)
✅ Secure signature validation (HMAC-SHA256)
✅ Asynchronous processing (responds within 10 seconds)
✅ Comprehensive logging
✅ Error handling
✅ Supports both new (list_folder) and legacy (delta) formats

## Next Steps

1. ✅ Webhook infrastructure created
2. ⏳ Register webhook URL in Dropbox App Console
3. ⏳ Test verification endpoint
4. ⏳ Implement business logic in `processAccount()`
5. ⏳ Add database fields if needed (e.g., `dropbox_cursor`, `dropbox_account_id`)
6. ⏳ Test with real Dropbox file changes

## Required Database Fields (if not exists)

Add these to your `users` table migration:
```php
$table->string('dropbox_account_id')->nullable()->index();
$table->text('dropbox_cursor')->nullable();
```

## API Documentation
- [Dropbox Webhooks Documentation](https://www.dropbox.com/developers/reference/webhooks)
- [Dropbox API Reference](https://www.dropbox.com/developers/documentation/http/overview)

---

**Status:** ✅ Ready for testing and deployment
**Created:** April 17, 2026
