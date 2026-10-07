<?php

// Dedicated subscription sandbox credentials for the local Lando app.
if (getenv('LANDO_APP_NAME') && getenv('LANDO_MOUNT') === '/app') {
  include __DIR__ . '/settings.subscriptions.local.php';
}
